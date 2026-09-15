<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\ExpenseType;
use App\Models\JobTitle;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * بذرة نظيفة — نظام خالٍ من بيانات العرض.
 *
 * تُنشئ الحدّ الأدنى الذي يحتاجه النظام ليعمل ويُسجَّل الدخول إليه: شركةٌ
 * واحدة بمالكها، وفرعٌ رئيسيّ، وعملةٌ أساسيّة، والمسمّيات الوظيفيّة،
 * وأنواعُ المصروفات.
 *
 * لا منتجات ولا طلبات ولا عملاء ولا معاملات ولا موردين — تُدخلها بنفسك.
 *
 * ولبيانات عرضٍ ممتلئة: `App\Support\DemoStore::create()` — وهي لا تكتب
 * إلا في شركةٍ موسومةٍ `is_demo`، انظر `Support\DemoGuard`.
 */
class DatabaseSeeder extends Seeder
{
    /** بيانات الدخول — غيّرها من .env قبل النشر */
    private function cfg(string $key, string $default): string
    {
        return (string) env($key, $default);
    }

    public function run(): void
    {
        /* ---------------- شركة واحدة فارغة ---------------- */
        $ownerName = $this->cfg('SEED_OWNER_NAME', 'مالك الشركة');
        $business = Business::create([
            'name' => $this->cfg('SEED_BUSINESS_NAME', 'Event Plus'),
            'type' => 'شركة تقنية',
            'owner_name' => $ownerName,
            'phone' => '',
            'email' => $this->cfg('SEED_ADMIN_EMAIL', 'admin@eventplus.local'),
            'country' => 'عُمان',
            'city' => '',
            'address' => '',
            'status' => 'نشط',
        ]);

        /* ---------------- مالك الشركة ---------------- */
        User::create([
            'business_id' => $business->id,
            'name' => $ownerName,
            'email' => $this->cfg('SEED_ADMIN_EMAIL', 'admin@eventplus.local'),
            'role' => 'admin',
            'password' => Hash::make($this->cfg('SEED_ADMIN_PASSWORD', 'password')),
            'status' => 'نشط',
            'branch' => 'الفرع الرئيسي',
            'job_title' => 'مدير',
            'last_login_at' => now(),
        ]);

        /* ---------------- الافتراضيات ---------------- */
        // فرع رئيسي — حركات المخزون تحتاج فرعًا واحدًا على الأقل
        Branch::create([
            'business_id' => $business->id,
            'name' => 'الفرع الرئيسي',
            'phone' => '',
            'address' => '',
        ]);

        // العملة الأساسية — واجهة العرض تحتاج عملة أساسية واحدة
        Currency::create([
            'business_id' => $business->id,
            'code' => 'OMR',
            'name' => 'ريال عماني',
            'symbol' => 'ر.ع',
            'rate' => 1,
            'is_base' => true,
            'active' => true,
        ]);

        // الوظائف الافتراضية مرتبطة بصلاحيات النظام
        foreach (JobTitle::roles() as $roleKey => $roleLabel) {
            JobTitle::create([
                'business_id' => $business->id,
                'name' => $roleLabel,
                'role' => $roleKey,
            ]);
        }

        // أنواع المصروفات الافتراضية
        $expenseTypes = [
            'إيجار' => 'إيجار المحل والمستودعات',
            'رواتب' => 'رواتب وأجور الموظفين',
            'كهرباء وماء' => 'فواتير الكهرباء والمياه',
            'مواد خام' => 'شراء المواد والبضاعة',
            'تسويق' => 'الإعلانات والحملات التسويقية',
            'صيانة' => 'صيانة المعدات والمحل',
            'نقل وتوصيل' => 'مصاريف الشحن والتوصيل',
        ];
        foreach ($expenseTypes as $name => $description) {
            ExpenseType::create([
                'business_id' => $business->id,
                'name' => $name,
                'description' => $description,
            ]);
        }

        /* ---------------- الإعدادات ---------------- */
        /*
         * وعلى مستوى المنصّة `vat_rate` وحده — يقرؤه `Vat`.
         *
         * كانت معه ثلاثةُ صفوفٍ لا يقرؤها سطر: `platform_name` (اسمٌ قديم،
         * والشاشةُ تكتب `app_name`)، و`currency`، و`currency_decimals` —
         * وكلاهما يُقرأ من صفّ المتجر لا من صفّ المنصّة. وافتراضاتُ شاشة
         * الإعدادات مصدرُها واحد: `PageController::SETTING_DEFAULTS`.
         */
        $settings = [
            [null, 'vat_rate', '5'],
            [$business->id, 'vat_rate', '5'],
        ];
        foreach ($settings as [$businessId, $key, $value]) {
            Setting::create(['business_id' => $businessId, 'key' => $key, 'value' => $value]);
        }
    }
}
