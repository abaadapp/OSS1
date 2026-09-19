<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Support\Communication\Communications;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * خبرُ Meta عن رسالة واتساب — أُرسلت، وصلت، قُرئت، فشلت.
 *
 * ═══ التسجيلُ ثمّ التوقيع ═══
 *
 * عند تسجيل الـwebhook تنادي Meta بـ`GET` تحمل `hub.verify_token` الذي
 * اخترناه وتنتظر `hub.challenge` كما هو. وبعدها كلُّ `POST` موقَّعٌ في
 * `X-Hub-Signature-256` بـHMAC على الجسد الخامّ بسرّ التطبيق — يُحسب هنا
 * ويُقارَن بزمنٍ ثابت. وبلا سرٍّ مضبوطٍ لا يُقبل طلبٌ: بابٌ بلا قفلٍ خيرٌ
 * منه بابٌ مغلق.
 *
 * ═══ الحالاتُ وحدَها ═══
 *
 * `statuses[]` هي ما يُقرأ. و`messages[]` — ردودُ العملاء — تُقبل بـ٢٠٠
 * وتُترك: هذه المرحلةُ إرسالٌ تشغيليٌّ وتتبّعٌ، لا صندوقُ وارد.
 *
 * ═══ ولا يُكتب مرّتين ═══
 *
 * Meta تعيد الإرسال إن لم تُجَب بـ٢٠٠، وقد تكرّر الخبرَ نفسَه. فمعرّفُ
 * الخبر مركَّبٌ من الرسالة والحال والوقت — والقيدُ الفريد يردّ الثاني.
 */
class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $expected = (string) config('services.whatsapp.verify_token');
        $given = (string) $request->query('hub_verify_token', '');

        abort_if($expected === '', 404);
        abort_unless($request->query('hub_mode') === 'subscribe' && $given !== '' && hash_equals($expected, $given), 403);

        return response((string) $request->query('hub_challenge', ''), 200)->header('Content-Type', 'text/plain');
    }

    public function handle(Request $request): Response
    {
        $secret = (string) config('services.whatsapp.app_secret');

        abort_if($secret === '', 404);

        $signature = (string) $request->header('X-Hub-Signature-256', '');
        $expected = 'sha256='.hash_hmac('sha256', (string) $request->getContent(), $secret);

        abort_unless($signature !== '' && hash_equals($expected, $signature), 403);

        $payload = $request->json()->all();

        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                foreach ((array) ($change['value']['statuses'] ?? []) as $status) {
                    $this->apply((array) $status);
                }
            }
        }

        return response('ok', 200);
    }

    /** خبرُ حالٍ واحد — يُترجَم ويُكتب مرّة */
    private function apply(array $status): void
    {
        $messageId = trim((string) ($status['id'] ?? ''));
        $kind = strtolower(trim((string) ($status['status'] ?? '')));

        if ($messageId === '' || $kind === '') {
            return;
        }

        $timestamp = (string) ($status['timestamp'] ?? '');
        /* وطابعُ Meta ثوانٍ منذ 1970 — يُحوَّل إلى توقيت الشركة قبل أن يُكتب بلا منطقة */
        $occurredAt = ctype_digit($timestamp)
            ? Carbon::createFromTimestampUTC((int) $timestamp)->setTimezone(config('app.timezone'))
            : now();

        $error = (array) (($status['errors'] ?? [])[0] ?? []);
        $code = filled($error['code'] ?? null) ? (string) $error['code'] : null;
        $description = filled($error['message'] ?? $error['title'] ?? null)
            ? trim((string) ($error['title'] ?? '').' — '.(string) ($error['error_data']['details'] ?? $error['message'] ?? ''), ' —')
            : null;

        Communications::applyEvent('meta', $messageId, $kind, $messageId.':'.$kind.':'.$timestamp, $occurredAt, $code, $description, $status);
    }
}
