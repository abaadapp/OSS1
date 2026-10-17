<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * أسماءٌ محفوظةٌ في القاعدة كانت تقول «مبيعات» — تقول اليوم ما تحمله.
 *
 * ═══ ما الذي يُسمّى من جديد ═══
 *
 * - **الوظيفة الافتراضيّة «موظف مبيعات»** (`job_titles.name`) وما يحملها في
 *   `users.job_title`: صار اسمُ الدور `sales` «موظف عروض ومشاريع» في
 *   `Roles::LABELS`، والوظيفةُ نسخةٌ منه كُتبت يومَ أُنشئ النشاط
 *   (2026_07_21_000040 و`MerchantAccount`). فلو بقيت لقرأ صاحبُ العمل
 *   «موظف مبيعات» في قائمة الموظفين وقسمُ المبيعات لم يعد موجودًا.
 * - **حسابا الإيراد في الشجرة الافتراضيّة**: `sales` «إيراد المبيعات» ⇦
 *   «إيرادات الفواتير»، و`sales_returns` «مردودات المبيعات» ⇦ «مردودات
 *   الفواتير». يُرحَّل إليهما من `CustomerInvoices::post` وإشعار الدائن وحدهما.
 *
 * ═══ وما لا يُمسّ ═══
 *
 * - **المفاتيح**: `users.role = 'sales'` و`job_titles.role` و`accounts.system_key`
 *   كما هي — بها تُقرأ الصلاحيات ويُرحَّل القيد. الاسمُ الظاهر وحده يتغيّر.
 * - **ما سمّاه صاحبُ العمل بنفسه**: لا يُعاد تسميةُ إلّا الاسم الافتراضيّ حرفًا
 *   بحرف. وظيفةٌ سمّاها «مسؤول مبيعات» أو حسابٌ أعاد تسميتَه يبقى كما كتبه.
 * - **نشاطٌ فيه وظيفةٌ بالاسم الجديد** يُترك: الاسمُ فريدٌ في النشاط، وضمُّ
 *   وظيفتين قرارٌ لصاحبه.
 * - **الجداول التاريخيّة** (`orders` وما يتبعها) والقيود: لا شيء هنا يقرؤها.
 *
 * ═══ ولماذا يحمل أسماءه بنفسه ═══
 *
 * كما فعل سلفُه: ما يُقرأ من الكود يتغيّر بعد سنة، والهجرةُ تُقرأ كما رُحّلت.
 */
return new class extends Migration
{
    private const TITLE_FROM = 'موظف مبيعات';

    private const TITLE_TO = 'موظف عروض ومشاريع';

    /** المفتاح ← [الاسم الافتراضيّ القديم، الجديد] */
    private const ACCOUNTS = [
        'sales' => ['إيراد المبيعات', 'إيرادات الفواتير'],
        'sales_returns' => ['مردودات المبيعات', 'مردودات الفواتير'],
    ];

    public function up(): void
    {
        $this->renameTitle(self::TITLE_FROM, self::TITLE_TO);

        foreach (self::ACCOUNTS as $key => [$from, $to]) {
            DB::table('accounts')->where('system_key', $key)->where('name', $from)->update(['name' => $to]);
        }
    }

    public function down(): void
    {
        $this->renameTitle(self::TITLE_TO, self::TITLE_FROM);

        foreach (self::ACCOUNTS as $key => [$from, $to]) {
            DB::table('accounts')->where('system_key', $key)->where('name', $to)->update(['name' => $from]);
        }
    }

    /**
     * الوظيفةُ وحاملوها معًا في كلّ نشاط: `users.job_title` نصٌّ يُطابَق باسم
     * الوظيفة (`EmployeeController::findJobTitle`)، فتسميةُ أحدهما دون الآخر تقطع
     * الموظفَ عن وظيفته.
     */
    private function renameTitle(string $from, string $to): void
    {
        $businesses = DB::table('job_titles')->where('name', $from)->pluck('business_id')
            ->merge(DB::table('users')->where('job_title', $from)->whereNotNull('business_id')->pluck('business_id'))
            ->unique();

        foreach ($businesses as $bid) {
            if (DB::table('job_titles')->where('business_id', $bid)->where('name', $to)->exists()) {
                continue;
            }

            DB::table('job_titles')->where('business_id', $bid)->where('name', $from)->update(['name' => $to]);
            DB::table('users')->where('business_id', $bid)->where('job_title', $from)->update(['job_title' => $to]);
        }
    }
};
