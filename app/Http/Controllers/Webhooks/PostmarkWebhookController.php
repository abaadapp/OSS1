<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Support\Communication\Communications;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * خبرُ Postmark عن بريدٍ — وصل، ارتدّ، فُتح.
 *
 * ═══ الباب مقفلٌ بسرّ ═══
 *
 * Postmark لا توقّع طلباتها؛ تقبل بدلًا من ذلك هويّةً في عنوان الـwebhook
 * (`https://postmark:SECRET@hadath.om/webhooks/postmark`). فيُقارَن السرُّ
 * بزمنٍ ثابت، وبلا سرٍّ مضبوطٍ في الخادم لا بابَ أصلًا — ٤٠٤ لا ٢٠٠.
 *
 * ═══ ولا يُكتب مرّتين ═══
 *
 * لكلّ خبرٍ معرّفٌ يُشتقّ ممّا ترسله: الارتدادُ برقمه، والوصولُ برسالته،
 * والفتحُ برسالته ووقته. والقيدُ الفريد على المعرّف يردّ التكرار —
 * انظر `Communications::applyEvent`.
 *
 * ═══ ويُردّ ٢٠٠ دائمًا بعد الفحص ═══
 *
 * Postmark تعيد المحاولة على غير ٢٠٠. ورسالةٌ لا نعرفها (أُرسلت من خارج
 * النظام) ليست خطأً يستحقّ إعادة: تُهمَل وتُشكَر.
 */
class PostmarkWebhookController extends Controller
{
    public function handle(Request $request): Response
    {
        $secret = (string) config('services.postmark.webhook_secret');

        abort_if($secret === '', 404);

        $given = (string) ($request->getPassword() ?? $request->header('X-Postmark-Webhook-Secret', ''));

        abort_unless($given !== '' && hash_equals($secret, $given), 403);

        $payload = $request->json()->all();
        $type = (string) ($payload['RecordType'] ?? '');
        $messageId = trim((string) ($payload['MessageID'] ?? ''));

        if ($messageId === '') {
            return response('ignored', 200);
        }

        [$kind, $eventId, $at, $code, $description] = match ($type) {
            'Delivery' => ['delivered', 'delivery:'.$messageId, $payload['DeliveredAt'] ?? null, null, $payload['Details'] ?? null],
            'Bounce' => ['bounced', 'bounce:'.($payload['ID'] ?? $messageId), $payload['BouncedAt'] ?? null,
                (string) ($payload['Type'] ?? 'Bounce'), $payload['Description'] ?? ($payload['Name'] ?? null)],
            'SpamComplaint' => ['complaint', 'complaint:'.($payload['ID'] ?? $messageId), $payload['BouncedAt'] ?? null,
                'SpamComplaint', $payload['Description'] ?? __('أبلغ المستلم عن الرسالة كبريدٍ مزعج')],
            'Open' => ['opened', 'open:'.$messageId.':'.($payload['ReceivedAt'] ?? ''), $payload['ReceivedAt'] ?? null, null, null],
            'Click' => ['clicked', 'click:'.$messageId.':'.($payload['ReceivedAt'] ?? ''), $payload['ReceivedAt'] ?? null, null, $payload['OriginalLink'] ?? null],
            default => [null, null, null, null, null],
        };

        if ($kind === null) {
            return response('ignored', 200);
        }

        /* وطابعُ Postmark بتوقيت UTC (`…Z`) — يُحوَّل إلى توقيت الشركة قبل أن يُكتب بلا منطقة */
        $occurredAt = filled($at)
            ? rescue(fn () => Carbon::parse($at)->setTimezone(config('app.timezone')), now(), false)
            : now();

        /* والحمولةُ تُحفظ خبرًا لا نسخة: بلا نصّ الرسالة الكامل الذي يرسله الارتداد */
        $trimmed = collect($payload)->except(['Content', 'Metadata'])->all();

        Communications::applyEvent('postmark', $messageId, $kind, $eventId, $occurredAt, $code, $description, $trimmed);

        return response('ok', 200);
    }
}
