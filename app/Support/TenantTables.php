<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ما يملكه المتجر من جداول — قائمةٌ واحدة تقرأ منها النسخةُ والاستعادة.
 *
 * كانت النسخةُ الاحتياطية تحفظ **سبعةَ عشرَ** جدولًا، وفي القاعدة ستّةٌ
 * وخمسون جدولًا للمتجر وأحدَ عشرَ جدولَ أبناءٍ تحتها. والاستعادةُ تحذف
 * الآباء ثمّ تعيد ما في الملفّ — فكلُّ ما لم يُنسخ يسقط بالتتالي ولا يعود:
 *
 *   `branch_stocks` لا يُنسخ ولا يُحذف — فيبقى بأرقامٍ قديمة و`products.quantity`
 *   يأتي من الملفّ. **تباعدٌ مضمونٌ في كلّ صنف**، لا يظهر إلا في الجرد.
 *
 *   `pos_devices` يسقط بالتتالي من `branches` — **فكلُّ صندوقٍ يتعطّل ولا يعود**.
 *
 *   `supplier_invoices` مرتبطٌ بمورّده بـ`restrict` — **فالاستعادة تنهار في
 *   منتصفها** لكلّ تاجرٍ سجّل سندَ مورّد، بعد أن حذفت منتجاته.
 *
 * والرسالةُ تقول «تمّت الاستعادة بنجاح» في الحالات الثلاث.
 *
 * فالقائمةُ هنا واحدة، و**حارسٌ يسقط يوم يُضاف جدولٌ لا يُصنَّف** — لا يمرّ
 * جدولٌ جديد صامتًا كما مرّ ثمانيةٌ وثلاثون.
 */
class TenantTables
{
    /**
     * جداولُ المتجر مرتَّبةً: الأبُ قبل ابنه.
     *
     * الترتيبُ ليس ذوقًا: الإدراجُ يمشي به، والحذفُ يمشي بعكسه. وسطرٌ يُدرَج
     * قبل أبيه يُردّ بمفتاحٍ خارجيّ فتسقط الاستعادة كلُّها.
     *
     * وصحّتُه محروسةٌ بالمفاتيح الخارجية نفسها لا بالعين — انظر
     * `ARestoreReturnsTheShopTest`.
     */
    public const ORDER = [
        // أصولٌ لا أبَ لها
        'accounts', 'branches', 'users', 'categories', 'currencies', 'suppliers',
        'expense_types', 'job_titles', 'settings',
        'import_batches', 'activity_logs', 'branch_stocks',

        // ما يتفرّع عنها
        'branch_user',
        'custom_alerts', 'products', 'product_variants', 'product_images',
        'addons', 'product_addons', 'recipe_items',
        /* وبنودُ الباقة بعد المنتجات: الباقةُ وعضوُها كلاهما صفٌّ فيها */
        'package_items',
        'customers', 'customer_addresses',
        /*
         * وسياساتُ الشركة بعد العملاء والمنتجات: نطاقُها يشير إليها بلا
         * مفتاحٍ خارجيّ، وترتيبُها هنا للقراءة لا للقيد. و`created_by`
         * يشير إلى `users` وقد سبقت.
         */
        'company_policies',
        /*
         * وعروضُ الأسعار بعد العملاء والمنتجات — وتشير إلى نفسها بـ`root_id`.
         *
         * والإشارةُ الذاتيّة لا تحتاج `DEFERRED`: الجذرُ يُكتب قبل تنقيحه
         * دائمًا (معرّفُه أصغر)، والإدراجُ يمشي بترتيب المعرّفات.
         *
         * والمشروعُ بعد العرض ويسبق المستنداتِ الماليّةَ كلَّها: الفاتورةُ
         * والمصروفُ وأمرُ الشراء وسندُ المورّد وتسويةُ المخزون تشير إليه.
         * وأمرُ التغيير قبل بنود المشروع: البندُ يقول أيُّ أمرٍ أضافه — وبندُ
         * الأمر يقول أيَّ بندٍ يعدّل، فالحلقةُ الوحيدة تُحلّ بالتأجيل (انظر
         * `DEFERRED`).
         */
        'quotations', 'quotation_items',
        'projects', 'project_members', 'project_tasks', 'change_orders',
        'project_items', 'project_requirements', 'change_order_items',
        'bank_accounts', 'bank_statement_lines',
        'journal_entries', 'journal_lines',
        'transactions', 'expenses',
        'fixed_assets', 'inventory_movements', 'stock_adjustments',
        'purchase_orders', 'purchase_order_items', 'supplier_invoices',
        'goods_receipt_notes', 'goods_receipt_note_items',
        'orders', 'order_items', 'order_item_addons', 'order_edits',
        /*
         * فواتيرُ العملاء وذممُهم — بعد الطلبات لأنّها قد تُولد منها.
         *
         * والتخصيصُ بعد الدفعة والفاتورة معًا: صفٌّ يشير إلى ما لم يُكتب
         * بعدُ يُردّه المفتاحُ الأجنبيّ، فتسقط الاستعادةُ في منتصفها.
         */
        'customer_invoices', 'customer_invoice_items', 'customer_invoice_orders',
        /*
         * ومرفقاتُ الفاتورة صفوفُها لا ملفّاتُها.
         *
         * النسخُ يحمل القاعدة، والملفّاتُ على القرص خارجَه — كما هي حالُ
         * مرفقات المشتريات والمصروفات منذ اليوم الأوّل. فالصفُّ يُستعاد
         * ويشير إلى ملفٍّ قد لا يعود، وهو أصدقُ من ألّا يُستعاد فيختفي أنّ
         * ثمّة مستندًا كان.
         */
        'customer_invoice_attachments',
        'customer_payments', 'customer_payment_allocations',
        'customer_credit_notes',
        /*
         * وما يتبع العرضَ والمشروعَ بعدهما: قرارُ العميل (دليلٌ متعدّدُ الأشكال)
         * واستثناءاتُ السياسات — وكلاهما يحمل `business_id` فيُقرأ رأسًا لا من
         * خلال أبيه.
         */
        'document_approvals', 'policy_exceptions',
        'delivery_notes', 'delivery_note_items',
        /* وحزمُ التسليم بعد العروض والعملاء: تشير إليهما — المرحلة 5 */
        'delivery_packages', 'delivery_package_items',
        'payroll_runs', 'payroll_lines',
        /*
         * والمراسلاتُ آخرَ الكلّ: الرسالةُ تشير إلى العميل والمشروع والموظّف،
         * وإلى موضوعٍ متعدّد الأشكال بلا مفتاح. وإشارتُها إلى أصلها
         * (`retry_of_id`) ذاتيّة: الأصلُ أصغرُ معرّفًا فيُكتب أوّلًا — المرحلة 6
         */
        'communications', 'communication_events', 'communication_templates',
        /* والأتمتةُ بعد المراسلات: التنفيذُ يشير إلى الرسالة التي أخرجها — المرحلة 8 */
        'automation_rules', 'automation_runs', 'internal_notifications',
        /*
         * وسجلُّ الاستيراد آخرَ الكلّ — المرحلة 9: المجموعةُ تشير إلى قالبها
         * وإلى تنفيذها (حلقةٌ تُحلّ بالتأجيل)، والتنفيذُ والصفوفُ والأثرُ إليها.
         * والأثرُ متعدّدُ الأشكال بلا مفتاح — يُقرأ بعد كلّ ما يشير إليه.
         */
        'import_mapping_templates', 'import_datasets', 'import_runs', 'import_rows', 'import_links',
        /*
         * والتشغيلُ — المرحلة 10 — بعد المشاريع والمستخدمين والمنتجات ودفتر
         * الإهلاك: القطعةُ تشير إلى صفّ إهلاكها، والفعاليّةُ إلى مشروعها، والحجزُ
         * إلى القطعة والفعاليّة والمتطلَّب، والقائمةُ إلى قالبها، والحادثُ إلى
         * القطعة. وبنودُ القالب بلا `business_id` تُقرأ من خلاله.
         */
        'assets', 'operation_checklist_templates', 'operation_checklist_template_items',
        'event_operations', 'operation_schedule_items', 'operation_equipment_requirements',
        'asset_reservations', 'operation_crew_assignments',
        'operation_checklists', 'operation_checklist_items', 'operation_incidents',
        /*
         * والتخطيطُ الإداريّ — المرحلة 11: الموازنةُ تشير إلى الحسابات وإلى
         * نسخةٍ سابقة منها (ذاتيّةٌ فتُكتب بترتيب معرّفاتها)، والتوقّعُ إلى
         * سيناريو، والقرارُ إلى مراجعته، وصرفُ المستهلَك إلى الفعاليّة والمنتج
         * والتعديل. وبنودُ الموازنة والافتراضاتُ بلا `business_id` تُقرأ من أبيها.
         */
        'budgets', 'budget_lines', 'financial_scenarios', 'financial_scenario_assumptions', 'financial_forecasts',
        'management_reviews', 'management_decisions', 'operation_consumable_issues',
    ];

    /**
     * أعمدةٌ تُترك فارغةً عند الإدراج ثمّ تُكتب في جولةٍ ثانية.
     *
     * لحلقةِ مفتاحين لا تُحلّ بترتيب: جدولٌ يشير إلى آخرَ يشير إليه.
     *
     * والحلقةُ الوحيدة اليوم بين بند المشروع وبند أمر التغيير: بندُ الأمر
     * يقول أيَّ بندٍ يعدّل (`project_item_id`)، والبندُ الذي **ولّده** أمرٌ
     * يقول أيَّ سطرٍ ولّده (`change_order_item_id`). فيُدرَج البندُ بلا
     * الأخير، ويُكتب في جولةٍ ثانية بعد بنود الأمر.
     */
    public const DEFERRED = [
        'project_items' => ['change_order_item_id'],
        /*
         * وحلقةٌ ثانية بين المشروع وفاتورة العميل: الفاتورةُ تقول أيَّ مشروعٍ
         * تتبع (`project_id`)، والمشروعُ الملغى يقول أيَّ فاتورةٍ حملت رسمَ
         * إلغائه. فيُدرَج المشروعُ بلا الأخيرة، وتُكتب بعد الفواتير.
         */
        'projects' => ['cancellation_fee_invoice_id'],
        /* والمجموعةُ تشير إلى تنفيذها وهو يشير إليها — تُكتب بعده (المرحلة 9) */
        'import_datasets' => ['dry_run_id', 'import_run_id'],
    ];

    /**
     * جداولُ الأبناء: لا `business_id` فيها، وتُقرأ من خلال أبيها.
     *
     * وبلا هذا تُنسخ سطورُ متجرٍ آخر أو لا تُنسخ سطورُ هذا المتجر أصلًا.
     */
    public const THROUGH = [
        'branch_user' => ['branches', 'branch_id'],
        'customer_addresses' => ['customers', 'customer_id'],
        'delivery_note_items' => ['delivery_notes', 'delivery_note_id'],
        'goods_receipt_note_items' => ['goods_receipt_notes', 'goods_receipt_note_id'],
        'journal_lines' => ['journal_entries', 'journal_entry_id'],
        'order_item_addons' => ['order_items', 'order_item_id'],
        'order_items' => ['orders', 'order_id'],
        'customer_invoice_items' => ['customer_invoices', 'customer_invoice_id'],
        'quotation_items' => ['quotations', 'quotation_id'],
        'change_order_items' => ['change_orders', 'change_order_id'],
        'customer_invoice_orders' => ['customer_invoices', 'customer_invoice_id'],
        'customer_payment_allocations' => ['customer_payments', 'customer_payment_id'],
        'payroll_lines' => ['payroll_runs', 'payroll_run_id'],
        'purchase_order_items' => ['purchase_orders', 'purchase_order_id'],
        'operation_checklist_template_items' => ['operation_checklist_templates', 'template_id'],
        'budget_lines' => ['budgets', 'budget_id'],
        'financial_scenario_assumptions' => ['financial_scenarios', 'scenario_id'],
    ];

    /**
     * ما لا يخصّ المتجر — ولكلٍّ سببُه مكتوبًا.
     *
     * والسببُ شرطٌ لا زينة: جدولٌ يُستثنى بلا سبب يُقرأ بعد سنةٍ فلا يُعرف
     * أَخرجَ عمدًا أم نُسي.
     */
    public const NOT_MINE = [
        'businesses' => 'صفُّ الشركة نفسها — يُحدَّث بحقولٍ آمنة لا يُحذف ويُدرَج',
        'password_recovery_challenges' => 'محاولاتُ استرجاعِ كلمة مرور — سرّيّةٌ ومؤقّتة، لا تُكتب في ملفٍّ يُنزَّل',
        'password_recovery_otps' => 'رموزُها — مثلُها',
        'dismissed_notifications' => 'ما أخفاه مستخدمٌ من تنبيهاته — تفضيلُ عرضٍ لا بيانات',
        /*
         * ورمزُ الرابط عمودٌ إلزاميّ لا يقبل الفراغ.
         *
         * فإمّا أن يخرج في ملفٍّ يُنزَّل — ومن ملَكه فتح المستندَ بلا حساب —
         * وإمّا أن يُنزع فيُردّ الإدراج. والرابطُ يُولَّد عند الطلب أصلًا،
         * والمعرّفاتُ تعود كما كانت فتبقى الروابطُ القديمة عاملة.
         */
        'document_links' => 'رموزُ مشاركةِ المستندات — مفاتيحُ فتحٍ بلا حساب، وتُولَّد عند الطلب',
        /*
         * ═══ محادثاتُ الدعم — ليست من نسخة التاجر ═══
         *
         * وهي بين طرفين لا طرف. وفيها `is_internal`: ملاحظاتُ فريق افنت بلس عن
         * هذا التاجر بعينه — «يماطل في السداد»، «راجعوا شكواه السابقة».
         * فملفٌّ يُنزَّل على جهازه ويحمل ذلك ليس نسخةً احتياطيّة بل تسريب.
         *
         * والاستعادةُ أسوأ: صفوفٌ من ملفٍّ يرفعه التاجر تدخل صندوقَ وارد
         * المنصّة بحالاتٍ ومسؤولين وأرقامِ محادثاتٍ يختارها هو.
         *
         * وما يخصّه منها — ما كتبه وما رُدَّ عليه — يبقى في «المساعدة
         * والدعم» يقرؤه متى شاء. لا يضيع، ولا يُسلَّم في ملفّ.
         */

        /*
         * ═══ دفترُ مبيعات افنت بلس — ليس من نسخة التاجر بحال ═══
         *
         * وهو دفترُ **المنصّة** عن من تبيعه: مراحلُ بيعٍ، وقيمٌ متوقّعة،
         * وأسبابُ خسارة، وملاحظاتٌ داخليّةٌ يكتبها فريقُ المبيعات — «يماطل»،
         * «اعرض عليه خصمًا إن رفض». فملفٌّ يُنزَّل على جهاز التاجر ويحمل ذلك
         * ليس نسخةً احتياطيّة بل تسريب.
         *
         * والاستعادةُ أسوأ: صفوفٌ من ملفٍّ **يرفعه التاجر** تدخل دفترَ مبيعات
         * المنصّة بمراحلَ ومسؤولين يختارهم هو — وبصفوفٍ تربط متاجرَ غيره.
         *
         * ولا عمودَ `business_id` في هذه الجداول أصلًا: `converted_business_id`
         * رابطٌ إلى متجرٍ صار مشتركًا، لا ملكيّةُ متجرٍ لصفّ.
         */
        /*
         * ورسائلُ واتساب في دفتر المبيعات: خيطُ **افنت بلس** مع من يريد شراءها.
         *
         * وليست مراسلاتِ الشركة مع عملائها — تلك في جدولٍ آخر وهي
         * تُنسخ مع المتجر. وهذه تخرج من رقم مبيعاتنا، وفيها ما قاله عن
         * منافسيه وعن ميزانيّته.
         */
    ];

    /**
     * أعمدةٌ لا تخرج في ملفٍّ يُنزَّل على جهاز التاجر.
     *
     * والرموزُ الخام وحدها: `pos_devices.token_hash` بصمةٌ لا مفتاح، وحذفُها
     * يُعطّل كلَّ صندوقٍ بعد الاستعادة — وهو العطبُ الذي نُصلحه لا نُكرّره.
     */
    public const SECRETS = [
        'users' => ['password', 'remember_token'],
        /*
         * ورمزا Google لا يخرجان في ملفٍّ يُنزَّل.
         *
         * من يملك رمزَ التجديد يقرأ تقييمات المتجر **ويردّ عليها باسمه** حتّى
         * يُلغيه صاحبُه بيده. وملفُّ نسخةٍ يُرسَل في بريدٍ أو يُنسى في مجلّد
         * تنزيلاتٍ ليس مكانَه.
         *
         * وليسا إلزاميَّين فلا يُولَّدان: المتجرُ يعود بعد الاستعادة «غيرَ
         * مربوط»، فيربط بضغطتين — وذلك أهونُ من إذنٍ يُنسخ مع الملفّ.
         */
    ];

    /**
     * أعمدةٌ نُزعت وهي إلزاميّة — فتُولَّد عند الاستعادة.
     *
     * وعمودٌ إلزاميٌّ يُنزع ولا يُولَّد يُسقط الاستعادة كلَّها بـ`NOT NULL`،
     * فتصير الحمايةُ عطبًا. والحارسُ يسأل عن هذه القائمة بالضبط.
     *
     * و`users.password` تُولَّد عشوائيّةً في `restoreUsers`: الحسابُ القائم
     * يبقى بكلمته، والجديدُ يُلزَم بإعادة التعيين.
     */
    public const REGENERATED = [
        'users' => ['password'],
    ];

    /** كلُّ ما يُنسخ — بالترتيب */
    public static function all(): array
    {
        return self::ORDER;
    }

    /** الاستعلامُ الذي يقرأ سطورَ هذا المتجر من هذا الجدول */
    public static function scope(string $table, int $businessId): Builder
    {
        $q = DB::table($table);

        if (! isset(self::THROUGH[$table])) {
            return $q->where('business_id', $businessId);
        }

        [$parent, $key] = self::THROUGH[$table];

        return $q->whereIn($key, self::scope($parent, $businessId)->select('id'));
    }
}
