<?php

namespace App\Console\Commands;

use App\Support\Automation\Engine;
use Illuminate\Console\Command;

/**
 * دورةُ الأتمتة — يشغّلها المجدولُ كلَّ ربع ساعة.
 *
 * `withoutOverlapping` في الجدول يمنع دورتين معًا على الخادم نفسِه، لكنّه
 * ليس الحارسَ: القيدُ الفريد على `automation_runs.idempotency_key` هو الذي
 * يضمن أنّ الخطوةَ الواحدة لا تُنفَّذ مرّتين ولو تداخل عاملان.
 */
class AutomationRun extends Command
{
    protected $signature = 'automation:run {--business= : شركةٌ واحدة بمعرّفها}';

    protected $description = 'تقييمُ قواعد الأتمتة وتنفيذُ ما حان وقتُه';

    public function handle(): int
    {
        $bid = $this->option('business');
        $stats = Engine::tick($bid !== null ? (int) $bid : null);

        $this->info(sprintf('شركات: %d · قُيّم: %d · نُفّذ: %d · رُوقب: %d · تُخطّي: %d · فشل: %d · معلَّق: %d',
            $stats['businesses'], $stats['evaluated'], $stats['executed'], $stats['observed'], $stats['skipped'], $stats['failed'], $stats['pending']));

        return self::SUCCESS;
    }
}
