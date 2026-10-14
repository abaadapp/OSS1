<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\DemoStore;
use Illuminate\Console\Command;

/**
 * الشركةُ التجريبيّة — تُبنى بأمرٍ وتُمحى بأمر.
 *
 * `demo:create` تبني شركةً موسومةً `is_demo` بكلّ ما في النظام: كتالوجٌ
 * ومبيعاتٌ ومشترياتٌ ورواتب (`DemoStore`)، وعروضٌ ومشاريعُ وفعاليّاتٌ
 * ومعدّاتٌ وفواتيرُ وواتساب (`DemoEvents`). والشركةُ الحقيقيّة لا تُمسّ:
 * البياناتُ في شركةٍ أخرى بحسابِ دخولٍ آخر.
 *
 * `demo:destroy` تمحو كلَّ شركةٍ تجريبيّة وكلَّ صفٍّ تحتها — ولا تمسّ غيرها
 * (`DemoGuard`).
 */
class DemoCompany extends Command
{
    protected $signature = 'demo:create {name=Event Plus — تجريبي} {--size=متوسط : صغير | متوسط | كبير}';

    protected $description = 'بناء شركة تجريبيّة ممتلئة بكل أقسام النظام — تُحذف بـ demo:destroy';

    public function handle(): int
    {
        $size = (string) $this->option('size');
        if (! isset(DemoStore::SIZES[$size])) {
            $this->error('الحجم: صغير | متوسط | كبير');

            return self::FAILURE;
        }

        $this->info('يُبنى… قد يستغرق دقيقة.');
        $business = DemoStore::create((string) $this->argument('name'), $size);

        $this->newLine();
        $this->info("✓ الشركة التجريبيّة «{$business->name}» (#{$business->id})");
        /* بريدُ المالك كما كُتب — `ownerEmail` تُسأل عن بريدٍ حرٍّ فتجيب غيرَه بعد أن أُخذ */
        $owner = User::where('business_id', $business->id)->where('role', 'admin')->orderBy('id')->value('email');
        $this->line('  الدخول:  '.$owner.'  /  '.DemoStore::PASSWORD);
        $this->line('  الحذف:   php artisan demo:destroy');

        return self::SUCCESS;
    }
}
