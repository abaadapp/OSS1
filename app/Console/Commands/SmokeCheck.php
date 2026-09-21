<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * دخّانُ ما بعد النشر — قراءاتٌ آمنة على الموقع الحيّ، لا كتابةَ فيها (المرحلة 12 §74).
 *
 *   php artisan eventplus:smoke --url=https://app.eventplusapp.om
 *
 * يسأل: أحيٌّ الموقع؟ أتردّ الصحّةُ «نعم» من القاعدة والتخزين والذاكرة؟
 * أتُفتح شاشةُ الدخول؟ أتُغلق اللوحةُ على الغريب؟ أتردّ الروابطُ العامّة
 * المجهولةُ ٤٠٤ لا ٥٠٠؟ أتُغلق أبوابُ المزوّدين بلا سرّ؟ — ويخرج بـ1 عند أوّل
 * جوابٍ خاطئ ليصلح لخطّ النشر.
 */
class SmokeCheck extends Command
{
    protected $signature = 'eventplus:smoke {--url= : عنوان الموقع (الافتراضي APP_URL)} {--timeout=15}';

    protected $description = 'فحص دخّان آمن للموقع الحيّ بعد النشر — قراءات فقط';

    public function handle(): int
    {
        $base = rtrim((string) ($this->option('url') ?: config('app.url')), '/');
        $timeout = (int) $this->option('timeout');
        $failed = 0;

        $probe = function (string $label, string $method, string $path, callable $judge) use ($base, $timeout, &$failed): void {
            try {
                $response = Http::timeout($timeout)->withOptions(['allow_redirects' => false])->withHeaders(['Accept' => 'text/html,application/json'])->{$method}($base.$path);
                $ok = $judge($response->status(), (string) $response->body(), $response);
                $note = 'HTTP '.$response->status();
            } catch (\Throwable $e) {
                $ok = false;
                $note = get_class($e);
            }
            $this->line(($ok ? '  <fg=green>✓</>' : '  <fg=red>✗</>')." {$label} <fg=gray>({$note})</>");
            if (! $ok) {
                $failed++;
            }
        };

        $this->line("  <options=bold>دخّانُ {$base}</>");
        $probe('الصحّة تقول نعم (قاعدة · تخزين · ذاكرة)', 'get', '/health', fn ($s, $b) => $s === 200 && (json_decode($b, true)['ok'] ?? false) === true);
        $probe('شاشةُ الدخول تُفتح', 'get', '/login', fn ($s, $b) => $s === 200 && str_contains($b, 'csrf-token'));
        $probe('اللوحةُ مقفلةٌ على الغريب', 'get', '/admin/dashboard', fn ($s) => in_array($s, [302, 401, 403], true));
        $probe('رابطٌ عامّ مجهول يردّ 404 لا 500', 'get', '/i/'.bin2hex(random_bytes(11)), fn ($s) => $s === 404);
        $probe('رابطُ تسليمٍ مجهول يردّ 404', 'get', '/d/'.bin2hex(random_bytes(11)), fn ($s) => $s === 404);
        $probe('بابُ Postmark مقفلٌ بلا سرّ', 'post', '/webhooks/postmark', fn ($s) => in_array($s, [403, 404], true));
        $probe('بابُ Meta مقفلٌ بلا توقيع', 'post', '/webhooks/whatsapp', fn ($s) => in_array($s, [403, 404], true));
        $probe('لا صفحةَ تصحيحٍ مكشوفة', 'get', '/this-route-does-not-exist-'.random_int(1000, 9999), fn ($s, $b) => $s === 404 && ! str_contains($b, 'Whoops') && ! str_contains($b, 'Ignition'));
        $probe('أصولُ الواجهة تُخدَم', 'get', '/build/manifest.json', fn ($s) => in_array($s, [200, 403, 404], true));

        $this->newLine();
        if ($failed > 0) {
            $this->line("  <fg=red;options=bold>✗ {$failed} فحص فشل — لا تُعلن النشرَ ناجحًا</>");

            return self::FAILURE;
        }
        $this->line('  <fg=green;options=bold>✓ الدخّان نظيف</>');

        return self::SUCCESS;
    }
}
