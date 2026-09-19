<?php

namespace App\Http\Controllers;

use App\Models\DeliveryPackage;
use App\Support\Communication\Communications;
use App\Support\DeliveryPackages;
use App\Support\InvoiceBranding;
use App\Support\Paper;
use App\Support\PublicDocument;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * حزمةُ التسليم كما يفتحها العميل — ويقرّر منها.
 *
 * ولا تسجيلَ دخولٍ هنا: الواقف أمام الشاشة عميلٌ لا موظّف. وحارسُها رمزُ
 * `document_links` الذي لا يُخمَّن — الرمزُ نفسُه الذي تُفتح به عروضُ
 * الأسعار والفواتير، لا نظامُ روابطَ ثانٍ. وبابُها مستقلٌّ عن
 * `PublicDocumentController` كي لا يُمسّ ذاك: الرمزُ يقود إلى هنا
 * بـ`/d/`، وإلى هناك بـ`/i/`، وكلٌّ يردّ ٤٠٤ على ما ليس له.
 *
 * ═══ وما يُعرض ═══
 *
 * ما خرج فقط: مرسَلةٌ أو مقرَّرٌ فيها. المسودّةُ والجاهزةُ والملغاةُ لا
 * تُفتح ولو عُرف رمزُها — رمزٌ يُبنى قبل الإرسال لا يُري العميلَ ما لم
 * يُرسَل إليه.
 */
class PublicDeliveryController extends Controller
{
    /** الحزمةُ من رمزها — أو ٤٠٤ فلا شيء يُقال */
    private function resolve(string $token): array
    {
        $link = PublicDocument::find($token);

        abort_if($link === null || $link->business === null, 404);

        $package = $link->linkable;

        abort_unless($package instanceof DeliveryPackage, 404);
        abort_unless($package->isPubliclyVisible(), 404);

        return [$link, $package];
    }

    public function show(string $token): View
    {
        [$link, $package] = $this->resolve($token);

        $package->loadMissing('items', 'customer');

        /* والفتحُ يُختم قبل الرسم: من رأى الحزمة رآها، وإن انقطع اتّصالُه بعدها */
        DeliveryPackages::markViewed($package);

        $package->loadMissing('approval');

        return InvoiceBranding::render($link->business_id, null, fn () => view('public.delivery', [
            'package' => $package,
            'label' => DeliveryPackages::label($package),
            'company' => (string) ($link->business->name ?? ''),
            'brand' => Paper::brand($link->business, Paper::vatNumber($link->business_id)),
            'decidable' => $package->isDecidable(),
            'approval' => $package->approval,
            'decideUrl' => route('delivery.decide', $token),
            'fileUrl' => fn (int $itemId) => route('delivery.file', [$token, $itemId]),
            'consent' => self::consent($link->business->name ?? ''),
        ]));
    }

    /**
     * قرارُ العميل — قبولُ التسليم أو ردُّه.
     *
     * بلا حسابٍ وبحارسين: الرمزُ الذي لا يُخمَّن، وخنقٌ بالعنوان — عشرُ
     * محاولاتٍ في ربع ساعة. والعدّادُ يُزاد **قبل** التحقّق لا بعده.
     */
    public function decide(Request $request, string $token): RedirectResponse
    {
        $key = 'delivery:decide:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages([
                'decision' => __('محاولات كثيرة. حاول بعد :seconds ثانية.', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        RateLimiter::hit($key, 900);

        [$link, $package] = $this->resolve($token);

        $data = $request->validate([
            'decision' => ['required', 'in:accepted,declined'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'title' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'decline_reason' => ['nullable', 'string', 'max:500'],
            'consent' => ['accepted_if:decision,accepted'],
        ], [
            'consent.accepted_if' => __('لا يُقبل التسليم بلا الإقرار.'),
        ], [
            'name' => __('الاسم'), 'email' => __('البريد الإلكتروني'), 'decline_reason' => __('سبب الرفض'),
        ]);

        try {
            $decided = DeliveryPackages::decide($package, $data['decision'], [
                'name' => $data['name'],
                'title' => $data['title'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'decline_reason' => $data['decline_reason'] ?? null,
                'consent' => $data['decision'] === 'accepted' ? self::consent($link->business->name) : null,
            ], [
                'ip' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
            ]);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['decision' => $e->getMessage()]);
        }

        RateLimiter::clear($key);

        /* والخبرُ إلى الشركة بعد أن ثبت القرار — وبأفضل جهد */
        $decided->loadMissing('approval');
        if ($decided->approval !== null) {
            Communications::about($decided, fn () => DeliveryPackages::notifyDecision($decided, $decided->approval));
        }

        return redirect()->route('delivery.show', $token)->with('decided', $data['decision']);
    }

    /**
     * ملفُّ بندٍ من رابط العميل — لحزمةٍ خرجت إليه، ولبندٍ فيها وحدَه.
     *
     * والمعرّفُ يُقرأ **من الحزمة** لا من الجدول: رقمُ بندٍ من حزمةٍ أخرى
     * يردّ ٤٠٤ لا ملفَّ جهةٍ أخرى.
     */
    public function file(string $token, int|string $item): StreamedResponse
    {
        [, $package] = $this->resolve($token);

        $row = $package->items()->whereKey($item)->firstOrFail();

        abort_if(! $row->hasFile() || ! Storage::disk('local')->exists($row->path), 404);

        return Storage::disk('local')->response($row->path, $row->name ?: $row->title, ['Content-Disposition' => 'inline']);
    }

    /** نصُّ الإقرار — يُعرض ويُحفظ بحرفه */
    public static function consent(string $company): string
    {
        return __('أقرّ بأنني مخوّل باستلام هذا التسليم نيابة عن الجهة المذكورة فيه، وأنني راجعت بنوده وملفّاته المرفقة، وأقبل ما سلّمته :company كما هو موصوف أعلاه.', [
            'company' => $company,
        ]);
    }
}
