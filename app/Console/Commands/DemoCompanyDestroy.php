<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Support\DemoStore;
use Illuminate\Console\Command;

/** محوُ الشركات التجريبيّة كلِّها — والحقيقيّةُ لا تُمسّ (`DemoGuard`) */
class DemoCompanyDestroy extends Command
{
    protected $signature = 'demo:destroy {--force : بلا سؤال}';

    protected $description = 'حذف كل شركة تجريبيّة (is_demo) وكل بياناتها';

    public function handle(): int
    {
        $demos = Business::demo()->orderBy('id')->get();

        if ($demos->isEmpty()) {
            $this->info('لا شركة تجريبيّة.');

            return self::SUCCESS;
        }

        foreach ($demos as $b) {
            $this->line("  #{$b->id}  {$b->name}");
        }

        if (! $this->option('force') && ! $this->confirm('تُحذف هذه الشركات وكلّ بياناتها نهائيًّا. متابعة؟')) {
            return self::FAILURE;
        }

        foreach ($demos as $b) {
            DemoStore::destroy($b);
        }

        $this->info('✓ حُذفت '.$demos->count().' شركة تجريبيّة. الشركات الحقيقيّة كما هي: '.Business::count());

        return self::SUCCESS;
    }
}
