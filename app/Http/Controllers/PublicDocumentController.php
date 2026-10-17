<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Admin\ChangeOrderController;
use App\Http\Controllers\Admin\QuotationController;
use App\Models\Business;
use App\Models\ChangeOrder;
use App\Models\CustomerInvoice;
use App\Models\Order;
use App\Models\Quotation;
use App\Models\ScopeOfWork;
use App\Support\ChangeOrders;
use App\Support\Communication\Communications;
use App\Support\Document\Branding;
use App\Support\DocumentPaper;
use App\Support\InvoiceBranding;
use App\Support\Money;
use App\Support\Paper;
use App\Support\PublicDocument;
use App\Support\Quotations;
use App\Support\ScopesOfWork;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * الورقةُ أونلاين — ما يفتحه من مسح الرمز أسفل ورقته.
 *
 * ولا تسجيلَ دخولٍ هنا: الواقف أمام الشاشة زبونٌ لا موظّف، ولا حساب له
 * في النظام ولا يجب أن يكون. وحارسُها أنّ رمزها لا يُخمَّن — اثنان
 * وعشرون حرفًا من ستّةٍ وستّين احتمالًا — لا أنّ أحدًا يُسأل من هو.
 *
 * وهي صفحةٌ قائمةٌ بذاتها لا شاشةٌ من اللوحة: لا Inertia ولا قائمةٌ
 * جانبية ولا أصولُ البناء. تُفتح على هاتفٍ في المحلّ بشبكةٍ ضعيفة، وكلُّ
 * ما فيها في ملفٍّ واحد.
 *
 * ═══ وصفحتان لا واحدة، ولكلٍّ سببُها ═══
 *
 * • **الطلب** يُعرض كاملًا (`public.paper`): الإيصالُ الحراريّ يبهت في
 *   جيبٍ خلال أشهر ويُبلَّل ويضيع، وزبونٌ يعود بضمانٍ بعد سنةٍ يحمل قصاصةً
 *   لا تُقرأ. فما يريده هو **نسختُه** لا التحقّق منها.
 *
 * • **فاتورةُ العميل** تُعرض ملخّصًا (`public.verify`): هي تصل الجهةَ
 *   كاملةً بالبريد أو على ورق، والسؤالُ عند مسح الرمز واحد — أهذه صحيحة؟
 *   فلا تُحمَّل الصفحةُ ببنودٍ وآيبانٍ ومراكزِ تكلفةٍ لا يحتاجها من يسأل.
 *
 * • **عرضُ السعر** يُعرض **كاملًا**: هو ورقةُ قرارٍ لا ورقةَ تحقّق. من
 *   يفتح الرابط يقرأ البنودَ والأسعارَ والنطاقَ والشروط ليقرّر — وملخّصٌ
 *   يقول «الإجمالي ١٢٠٠» لا يُقرَّر به شيء. وتُختم واقعةُ الفتح: «فُتح»
 *   خبرٌ لصاحب العرض لا يقوله مرفقُ بريدٍ أبدًا. **ويُقرَّر منه**: زرّان
 *   واسمٌ وإقرار — انظر `decide`.
 *
 * وما عداها لا يبلغ هنا أصلًا: `PublicDocument::allows` لا يبني رمزًا
 * لأمر الشراء ولا لسند الاستلام — انظر سياستَها هناك.
 */
class PublicDocumentController extends Controller
{
    public function show(string $token): View
    {
        $link = PublicDocument::find($token);

        abort_if($link === null, 404);

        $document = $link->linkable;

        /*
         * ورقةٌ فقدت أصلها لا تُعرض.
         *
         * الطلب يُحذف حذفًا ناعمًا، ونشاطُه قد يُحذف كلُّه — و`morphTo` تردّ
         * null بلا شكوى. فبلا هذا كانت الصفحة تنهار بـ500 في يد زبونٍ لا
         * يعرف ما الذي كسر، بدل «هذه الورقة لم تعد متاحة».
         */
        abort_if($document === null || $link->business === null, 404);

        /*
         * وقفلُ الاشتراك لا يقفل إيصالًا سُلّم.
         *
         * متجرُ من انتهى اشتراكه يُغلق متجرَه ولوحتَه — وهذا صحيح: كلاهما
         * خدمةٌ تُباع. أمّا هذه فسجلُّ معاملةٍ تمّت ووصلت يدَ الزبون قبل
         * أن ينتهي شيء، ومنعُها يجعل مشكلةَ الفوترة بين التاجر وافنت بلس
         * تقع على من لا شأن له بها.
         */
        /*
         * والطلبُ القديم للقراءة وحدها: إيصالٌ سُلّم قبل خروج «المبيعات» ورمزُه
         * مطبوعٌ عليه. لا شاشةَ في اللوحة تقود إليه، ولا رمزَ جديدَ يُبنى لطلب.
         */
        if ($document instanceof Order) {
            $document->loadMissing('items');

            return view('public.paper', [
                'order' => $document,
                /*
                 * والبيانُ من `DocumentPaper` — هو نفسُه الذي تُرسم به الورقة.
                 *
                 * وكانت الصفحةُ تجمعه بيدها: تقرأ أعمدةَ الطلب وتقرّر أيَّ
                 * سطرٍ يُطبع وتكتب صيغةَ المال. فصارت مستندًا ثانيًا يفترق
                 * عن المطبوع كلّما تغيّر أحدُهما.
                 */
                'paper' => DocumentPaper::forSale($document),
                'brand' => Paper::brand($link->business, Paper::vatNumber($link->business_id)),
                'stampedAt' => now()->format('Y-m-d H:i'),
            ]);
        }

        if ($document instanceof Quotation) {
            /*
             * ومسودّةٌ لا تُفتح ولو عُرف رمزُها.
             *
             * الرمزُ لا يُبنى لمسودّةٍ أصلًا — لكنّ عرضًا أُرسل ثمّ نُقّح
             * يبقى رمزُه ويصير جذرُه `superseded`، وتنقيحُه الجديد يبدأ
             * مسودّةً برمزٍ قد يُطلب. والمسودّةُ ورقةٌ لم تخرج بعد، وفتحُها
             * للعميل يُريه سعرًا لم يُقرَّر.
             */
            abort_if($document->status === Quotation::DRAFT
                || $document->status === Quotation::PENDING_APPROVAL, 404);

            $document->loadMissing('items');

            /* والفتحُ يُختم قبل الرسم: من رأى الورقة رآها، وإن انقطع اتّصالُه بعدها */
            Quotations::markViewed($document);

            $document->loadMissing('approval');

            return InvoiceBranding::render($link->business_id, null, fn () => view('public.quotation', [
                'paper' => QuotationController::paper($link->business_id, $document)->render(),
                'quotation' => $document,
                'label' => Quotations::label($document),
                'company' => (string) ($link->business->name ?? ''),
                /* وزرّا القرار لما ينتظر قرارًا وحدَه — و`decide` تعيد السؤال تحت قفل */
                'decidable' => $document->isDecidable(),
                'approval' => $document->approval,
                'decideUrl' => route('paper.decide', $token),
                'consent' => self::consent($link->business->name ?? ''),
            ]));
        }

        /*
         * ونطاقُ العمل يمرّ من الباب نفسِه — صفحتُه للهاتف أوّلًا، يُقرأ كاملًا
         * ويُوقَّع منها. والمسودّةُ والجاهزةُ لا تُفتحان ولو عُرف رمزُهما.
         */
        if ($document instanceof ScopeOfWork) {
            abort_if(! $document->isPubliclyVisible(), 404);

            ScopesOfWork::markViewed($document);
            $document->refresh()->loadMissing('approval');

            return InvoiceBranding::render($link->business_id, null, fn () => view('public.scope', self::scopeView($link->business, $document, [
                'decideUrl' => route('paper.decide', $token),
            ])));
        }

        /* وأمرُ التغيير يمرّ من الباب نفسِه — ورقةٌ تُقرأ وتُقبل كالعرض */
        if ($document instanceof ChangeOrder) {
            abort_if($document->status === ChangeOrder::DRAFT
                || $document->status === ChangeOrder::PENDING_APPROVAL, 404);

            $document->loadMissing('items', 'project');
            ChangeOrders::markViewed($document);
            $document->loadMissing('approval');

            return InvoiceBranding::render($link->business_id, null, fn () => view('public.quotation', [
                'paper' => ChangeOrderController::paper($link->business_id, $document)->render(),
                'quotation' => $document,
                'label' => ChangeOrders::label($document),
                'docTitle' => __('أمر تغيير'),
                'company' => (string) ($link->business->name ?? ''),
                'decidable' => $document->isDecidable(),
                'approval' => $document->approval,
                'decideUrl' => route('paper.decide', $token),
                'consent' => self::consent($link->business->name ?? '', $document),
            ]));
        }

        abort_unless($document instanceof CustomerInvoice, 404);

        /*
         * وبلغة الورقة لا بلغة المتصفّح.
         *
         * من يمسح الرمزَ يحمل ورقةً مطبوعة. وصفحةٌ بغير لغتها تجعله يقارن
         * نصّين مختلفين ليعرف أنّهما الشيءُ نفسُه.
         */
        return InvoiceBranding::render($link->business_id, null, fn () => view('public.verify', [
            'doc' => $this->summary($document),
            'brand' => Paper::brand(InvoiceBranding::paper($link->business_id)),
            'primary' => Branding::tokens($link->business_id)['primary_ink'],
            'stampedAt' => now()->format('Y-m-d H:i'),
        ]));
    }

    /**
     * قرارُ العميل من رابطه — قبولٌ أو اعتذار.
     *
     * ═══ بلا حساب، وبحارسين ═══
     *
     * الواقفُ أمام الشاشة عميلٌ لا حسابَ له. فالحارسُ الأوّل الرمزُ الذي لا
     * يُخمَّن، والثاني خنقٌ بالعنوان: بابٌ يكتب في القاعدة بلا حسابٍ أسهلُ
     * ما يُستنزف، وعشرُ محاولاتٍ في ربع ساعةٍ تكفي من يقرّر ولا تكفي من
     * يُغرق. والعدّادُ يُزاد **قبل** التحقّق لا بعده — انظر `RegisterController`.
     *
     * ═══ وما يُكتب دليلٌ ═══
     *
     * `Quotations::decide` تقارن بصمةَ الصفّ الآن بما خُتم عند الإرسال،
     * وتكتب اللقطةَ والموقّعَ والعنوانَ والمتصفّح، تحت قفل. وهذه لا تفعل
     * شيئًا سوى أن تسأل من هو وتُبلّغ الشركة.
     */
    public function decide(Request $request, string $token): RedirectResponse
    {
        $key = 'paper:decide:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages([
                'decision' => __('محاولات كثيرة. حاول بعد :seconds ثانية.', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        RateLimiter::hit($key, 900);

        $link = PublicDocument::find($token);

        abort_if($link === null || $link->business === null, 404);

        $document = $link->linkable;

        /* عرضُ سعرٍ أو أمرُ تغييرٍ أو نطاقُ عمل — وما لم يخرج منها لا يُقرَّر فيه */
        abort_unless($document instanceof Quotation || $document instanceof ChangeOrder || $document instanceof ScopeOfWork, 404);
        abort_if($document instanceof ScopeOfWork
            ? ! $document->isPubliclyVisible()
            : $document->status === Quotation::DRAFT || $document->status === Quotation::PENDING_APPROVAL, 404);

        $scope = $document instanceof ScopeOfWork;

        $data = $request->validate([
            'decision' => ['required', 'in:accepted,declined'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'title' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'decline_reason' => ['nullable', 'string', 'max:500'],
            /* والإقرارُ شرطُ القبول لا الاعتذار: من يعتذر لا يُقرّ بشيء */
            'consent' => ['accepted_if:decision,accepted'],
            /* وبصمةُ ما قرأه العميلُ في صفحته — النطاقُ لا يُوقَّع على غير ما رآه */
            'hash' => [$scope ? 'required' : 'nullable', 'string', 'size:64'],
        ], [
            'consent.accepted_if' => $scope ? __('لا يُعتمد نطاق العمل بلا الإقرار.') : __('لا يُقبل العرض بلا الإقرار.'),
            'hash.required' => __('حدّث الصفحة واقرأ المستند من جديد قبل التوقيع.'),
            'hash.size' => __('حدّث الصفحة واقرأ المستند من جديد قبل التوقيع.'),
        ], [
            'name' => __('الاسم'), 'email' => __('البريد الإلكتروني'), 'decline_reason' => $scope ? __('ما يحتاج تعديلًا') : __('سبب الاعتذار'),
        ]);

        $signer = [
            'name' => $data['name'],
            'title' => $data['title'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'decline_reason' => $data['decline_reason'] ?? null,
            'consent' => $data['decision'] === 'accepted'
                ? ($scope ? ScopesOfWork::consent((string) $link->business->name) : self::consent($link->business->name, $document))
                : null,
        ];
        $meta = ['ip' => $request->ip(), 'user_agent' => (string) $request->userAgent()];

        try {
            $decided = match (true) {
                $document instanceof ScopeOfWork => ScopesOfWork::decide($document, $data['decision'], $signer, $meta, $data['hash'] ?? null),
                $document instanceof ChangeOrder => ChangeOrders::decide($document, $data['decision'], $signer, $meta),
                default => Quotations::decide($document, $data['decision'], $signer, $meta),
            };
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['decision' => $e->getMessage()]);
        }

        RateLimiter::clear($key);

        /* والخبرُ إلى الشركة بعد أن ثبت القرار — وبأفضل جهد: بريدٌ يسقط لا يُسقطه */
        Communications::about($decided, fn () => match (true) {
            $decided instanceof ScopeOfWork => ScopesOfWork::notifyDecision($decided, $decided->approval),
            $decided instanceof ChangeOrder => ChangeOrders::notifyDecision($decided, $decided->approval),
            default => Quotations::notifyDecision($decided, $decided->approval),
        });

        return redirect()->route('paper.show', $token)->with('decided', $data['decision']);
    }

    /**
     * ما تحتاجه صفحةُ نطاق العمل — للعميل من رابطه، وللفريق معاينةً قبل الإرسال.
     *
     * صفحةٌ واحدة لكليهما: ما يُعاين هو ما يخرج، لا نسختان تفترقان.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function scopeView(?Business $business, ScopeOfWork $scope, array $extra = []): array
    {
        $company = (string) ($business?->name ?? '');
        $snap = (array) ($scope->scope_snapshot ?? []);

        return $extra + [
            'scope' => $scope,
            'snap' => $snap,
            'label' => ScopesOfWork::label($scope),
            'company' => $company,
            'brand' => Paper::brand($business, Paper::vatNumber((int) $scope->business_id)),
            'primary' => Branding::tokens((int) $scope->business_id)['primary_ink'],
            'currency' => Money::of((int) $scope->business_id),
            'approval' => $scope->approval,
            /* والتوقيعُ للنسخة الأحدث وحدَها وهي تنتظره — والقديمةُ تقول إنّها قديمة */
            'decidable' => $scope->isDecidable() && ! ScopesOfWork::hasNewer($scope),
            'superseded' => $scope->status === ScopeOfWork::SUPERSEDED || ScopesOfWork::hasNewer($scope),
            'consent' => ScopesOfWork::consent($company),
            'preview' => false,
            'decideUrl' => null,
        ];
    }

    /**
     * نصُّ الإقرار الذي يوافق عليه العميل — بحرفه، ويُخزَّن مع قراره.
     *
     * ويُبنى في موضعٍ واحدٍ تقرؤه الشاشةُ والكتابةُ معًا: نصٌّ يُعرض
     * ونصٌّ آخر يُحفظ يجعل الدليلَ يشهد على ما لم يُقرأ.
     */
    public static function consent(string $company, mixed $document = null): string
    {
        if ($document instanceof ChangeOrder) {
            return __('أقرّ بأنني مخوّل بقبول أمر التغيير هذا نيابة عن الجهة المذكورة فيه، وأنني قرأت ما يغيّره على النطاق المعتمَد وأثره على القيمة والموعد وسياسات :company المرفقة، وأوافق عليه.', [
                'company' => $company,
            ]);
        }

        return __('أقرّ بأنني مخوّل بقبول هذا العرض نيابة عن الجهة المذكورة فيه، وأنني قرأت بنوده ونطاق العمل وشروطه وسياسات :company المرفقة، وأوافق عليها.', [
            'company' => $company,
        ]);
    }

    /**
     * حقائقُ التحقّق من فاتورةٍ — ما يُعرض، ولا شيء سواه.
     *
     * و«الحال» تُحسب هنا من الدفتر لا تُقرأ عمودًا: `outstanding()` يجمع
     * ما سُدِّد وما رُدّ. فورقةٌ سُدِّدت أمسِ تقول «مسدَّدة» اليوم بلا أن
     * يكتب أحدٌ شيئًا في صفّها.
     *
     * @return array<string, string>
     */
    private function summary(CustomerInvoice $invoice): array
    {
        $outstanding = $invoice->outstanding();

        [$state, $status] = match (true) {
            $invoice->status === CustomerInvoice::CANCELLED => ['void', __('ملغاة')],
            $invoice->status === CustomerInvoice::DRAFT => ['void', __('مسودة')],
            $outstanding <= 0 => ['ok', __('مسدَّدة بالكامل')],
            $invoice->due_at && $invoice->due_at->endOfDay()->isPast() => ['late', __('متأخرة عن الاستحقاق')],
            default => ['due', __('مستحقة')],
        };

        return [
            'type' => $invoice->tax_total > 0 ? __('فاتورة ضريبية') : __('فاتورة'),
            'number' => (string) ($invoice->number ?: __('مسودة')),
            'date' => (string) (optional($invoice->issued_at)->format('Y-m-d') ?: ''),
            'due' => (string) (optional($invoice->due_at)->format('Y-m-d') ?: ''),
            /* وبعملة المتجر — لا بثلاث منازلَ و«ر.ع» مثبَّتتين. انظر `Support\Money` */
            'total' => Money::format((float) $invoice->total, Money::of((int) $invoice->business_id)),
            'state' => $state,
            'status' => $status,
        ];
    }
}
