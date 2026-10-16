<?php

namespace App\Support;

use App\Models\JobTitle;
use App\Models\User;

/**
 * كيف تُعرض صلاحياتُ الموظّف — لا كيف تُحسب.
 *
 * المحرّكُ في `Permissions`: الأقسامُ تفتح الشاشات، والأفعالُ تُمنح بأسمائها،
 * و`users.permissions` إمّا `null` («يتبع الوظيفة») وإمّا قائمةٌ صريحة هي كلُّ ما
 * يملكه صاحبها. وهذا الصنفُ لا يغيّر من ذلك حرفًا: يرتّب المفاتيحَ نفسَها في
 * مجموعاتٍ يقرؤها صاحبُ العمل، ويعزل ما يُخرج مالًا أو يتجاوز قرارًا، ويقول
 * لكلّ فعلٍ من أيّ قسمٍ يُستعمل.
 *
 * ═══ ولماذا في الخادم لا في الواجهة ═══
 *
 * قائمةٌ تُكتب في React تفترق عن `Permissions` يومَ يُضاف فعل: يُمنح ولا يظهر
 * في الشاشة، أو يظهر باسمٍ قديم. فالواجهةُ تقرأ هذا، وحارسٌ في الاختبارات يمنع
 * أن يبقى مفتاحٌ بلا مجموعة.
 */
final class EmployeeAccess
{
    /**
     * المجموعاتُ بترتيب العرض — الأقسامُ وأفعالُها العاديّة.
     *
     * كلُّ قسمٍ في مجموعةٍ واحدة، وكلُّ فعلٍ غيرِ حسّاسٍ كذلك. والحسّاسُ في
     * `SENSITIVE` وحده: لا يُعرض وسط الأقسام فيُؤشَّر سهوًا.
     */
    public const GROUPS = [
        'basics' => [
            'label' => 'الأساسيات',
            'sections' => ['dashboard'],
            'actions' => [],
        ],
        'sales' => [
            'label' => 'العملاء والمبيعات',
            'sections' => ['customers', 'products', 'quotations', 'orders'],
            'actions' => [
                Permissions::QUOTATION_CREATE, Permissions::QUOTATION_SEND, Permissions::QUOTATION_RECORD_DECISION,
                Permissions::CUSTOMER_INVOICE_ISSUE,
            ],
        ],
        'projects' => [
            'label' => 'المشاريع والفعاليات',
            'sections' => ['projects', 'operations', 'organizers', 'assets'],
            'actions' => [
                Permissions::PROJECT_MANAGE, Permissions::PROJECT_BUDGET_VIEW, Permissions::PROJECT_CLOSE,
                Permissions::CHANGE_ORDER_CREATE, Permissions::CHANGE_ORDER_SEND, Permissions::CHANGE_ORDER_RECORD_DECISION,
                Permissions::OPERATIONS_MANAGE, Permissions::OPERATIONS_STATUS, Permissions::OPERATIONS_INCIDENTS,
                Permissions::CREW_MANAGE,
                Permissions::ASSETS_MANAGE, Permissions::ASSETS_RESERVE, Permissions::ASSETS_CHECKOUT, Permissions::ASSETS_RETURN,
                Permissions::ORGANIZERS_MANAGE,
            ],
        ],
        'purchasing' => [
            'label' => 'المشتريات والمخزون',
            'sections' => ['inventory', 'suppliers', 'purchases'],
            'actions' => [
                Permissions::RECEIPT_VIEW, Permissions::RECEIPT_CREATE, Permissions::RECEIPT_REJECT,
                Permissions::INVOICE_VIEW, Permissions::INVOICE_CREATE, Permissions::INVOICE_REJECT,
            ],
        ],
        'finance' => [
            'label' => 'المالية والرواتب',
            'sections' => ['finance', 'expenses', 'employees', 'reports'],
            'actions' => [
                Permissions::PAYROLL_VIEW, Permissions::CUSTOMER_PAYMENT_CREATE, Permissions::ATTACHMENT_VIEW,
            ],
        ],
        'communications' => [
            'label' => 'التواصل والتشغيل الآلي',
            'sections' => ['communications', 'automation'],
            'actions' => [
                Permissions::MESSAGING_SEND, Permissions::COMMUNICATION_VIEW, Permissions::COMMUNICATION_RETRY,
                Permissions::COMMUNICATION_TEMPLATES_MANAGE, Permissions::AUTOMATION_PAUSE,
            ],
        ],
        'system' => [
            'label' => 'الإدارة والنظام',
            'sections' => ['settings', 'management', 'documents', 'imports', 'command'],
            'actions' => [
                Permissions::DOCUMENTS_MANAGE, Permissions::IMPORT_MANAGE,
                Permissions::ANALYTICS_FINANCIAL, Permissions::ANALYTICS_PROJECTS, Permissions::ANALYTICS_CUSTOMERS,
                Permissions::ANALYTICS_OPERATIONS,
                Permissions::BUDGETS_VIEW, Permissions::FORECASTS_VIEW, Permissions::FORECASTS_MANAGE,
                Permissions::SCENARIOS_MANAGE, Permissions::MANAGEMENT_REVIEWS_MANAGE, Permissions::MANAGEMENT_THRESHOLDS,
            ],
        ],
    ];

    /**
     * الأفعالُ الحسّاسة — وما يفعله كلٌّ منها بكلمات صاحب العمل.
     *
     * ما يُخرج مالًا، أو يعتمد التزامًا، أو يُلغي مستندًا صدر، أو يتجاوز حدًّا
     * وضعه صاحبُ العمل، أو يعيد فتح ما أُقفل. تُعرض مجموعةً وحدها ومعها وصفُها —
     * والمفتاحُ نفسُه لا يتغيّر.
     */
    public const SENSITIVE = [
        Permissions::INVOICE_PAY => 'يخرج مالًا من الصندوق أو البنك لسداد سندات الموردين.',
        Permissions::PAYROLL_PAY => 'يسجّل صرف مسيرة الرواتب — مالٌ يخرج للموظفين.',
        Permissions::PAYROLL_APPROVE => 'يُقرّ مسيرة الرواتب التزامًا على الشركة.',
        Permissions::INVOICE_APPROVE => 'يُقرّ بأنّ الشركة مدينةٌ للمورّد بقيمة السند.',
        Permissions::INVOICE_OVERRIDE => 'قرارٌ استثنائيّ: اعتماد سندٍ لا يطابق ما استُلم.',
        Permissions::RECEIPT_APPROVE => 'يُدخل البضاعة المستلمة إلى المخزون ويغيّر تكلفتها.',
        'order.edit' => 'يعيد كتابة فاتورة صدرت للعميل.',
        Permissions::CUSTOMER_INVOICE_CANCEL => 'يُلغي فاتورةً صادرة ويعكس قيدها.',
        Permissions::CUSTOMER_CREDIT_NOTE => 'يُنقص ما على العميل أو يردّ له مالًا.',
        'credit.override' => 'يضبط حدّ ائتمان العميل ويبيع فوقه.',
        Permissions::QUOTATION_DISCOUNT => 'يُنقص سعر البيع على عرضٍ أو أمر تغيير.',
        Permissions::QUOTATION_APPROVE => 'يعتمد خصمًا تجاوز الحدّ الذي وضعته الشركة.',
        Permissions::QUOTATION_OVERRIDE_PRICE => 'يبيع بسعرٍ أعلى من سعر الكتالوج المعلن.',
        Permissions::QUOTATION_CANCEL => 'يُلغي عرض سعرٍ أُرسل للعميل.',
        Permissions::CHANGE_ORDER_CANCEL => 'يُلغي أمر تغيير على مشروع.',
        Permissions::POLICY_OVERRIDE => 'يعتمد استثناءً من سياسةٍ كتبتها الشركة.',
        Permissions::PROJECT_REOPEN => 'يعيد فتح مشروعٍ أُقفلت أرقامه.',
        Permissions::OPERATIONS_OVERRIDE => 'يتجاوز موانع جاهزيّة الفعاليّة.',
        Permissions::CHECKLIST_MANAGE => 'يضبط قوائم التحقّق ويُعفي من بندٍ إلزاميّ.',
        Permissions::AUTOMATION_MANAGE => 'يشغّل قواعد ترسل للعملاء وحدها.',
        Permissions::IMPORT_EXECUTE => 'يعتمد استيراد بياناتٍ وينفّذه على النظام.',
        Permissions::IMPORT_FINANCIAL => 'يكتب بياناتٍ ماليّة مستوردة في الدفتر.',
        Permissions::IMPORT_ROLLBACK => 'يمحو ما أُدخل باستيرادٍ سابق.',
        Permissions::BUDGETS_MANAGE => 'يُعدّ موازنات الشركة وينقّحها.',
        Permissions::BUDGETS_APPROVE => 'يعتمد الموازنة فتصير المرجع.',
        Permissions::FORECASTS_APPROVE => 'يعتمد التوقّع النقديّ فيصير المرجع.',
    ];

    /**
     * أقسامٌ يلتبس اسمها بما لا تمنحه — تُقال تحتها.
     */
    public const SECTION_HINTS = [
        'employees' => 'الموظفون والمسمّيات — والرواتب تُمنح بأفعالها لا بهذا القسم.',
        'dashboard' => 'الصفحة الأولى بعد الدخول.',
        'settings' => 'بيانات الشركة والفروع والنسخ الاحتياطيّ.',
        'command' => 'ما يحتاج يدًا اليوم من كلّ الأقسام.',
    ];

    /**
     * أوصافٌ لأفعالٍ عاديّةٍ يلتبس أثرها.
     */
    public const ACTION_HINTS = [
        Permissions::PAYROLL_VIEW => 'يرى راتب كلّ موظّف وبدلاته.',
        Permissions::ATTACHMENT_VIEW => 'يفتح أوراق الموردين وفيها أسعار الشراء.',
        Permissions::PROJECT_BUDGET_VIEW => 'يرى تكلفة المشروع وربحه.',
    ];

    /**
     * القسمُ الذي يُستعمل الفعلُ من شاشاته — وأيٌّ منها يكفي.
     *
     * الفعلُ لا يفتح شاشة (`Permissions::entersPanel`): من مُنح «سداد سندات
     * الموردين» ولم يُمنح «المشتريات» يملك فعلًا لا يصل إليه. فتقول الشاشةُ ذلك
     * وتقترح القسم — ولا يُفرض في الخادم: بعضُ الأفعال تُستعمل من أكثر من قسم،
     * والقائمةُ هنا من تتبّع مساراتها فعلًا.
     *
     * وما يُكتب هنا القسمُ الذي **يُفحص** فيه الفعل، لا الذي يُرسم فيه زرُّه:
     * «اعتماد الاستلام» زرُّه في شاشة المخزون ومسارُه تحت المشتريات، فقسمُه
     * المشتريات. والأوّلُ في القائمة هو الأصل.
     *
     * @var array<string, list<string>>
     */
    public const PARENTS = [
        'order.edit' => ['orders'],
        'credit.override' => ['finance'],
        Permissions::RECEIPT_VIEW => ['inventory'],
        Permissions::RECEIPT_CREATE => ['purchases'],
        Permissions::RECEIPT_APPROVE => ['purchases'],
        Permissions::RECEIPT_REJECT => ['purchases'],
        Permissions::INVOICE_VIEW => ['purchases', 'suppliers', 'projects'],
        Permissions::INVOICE_CREATE => ['purchases'],
        Permissions::INVOICE_APPROVE => ['purchases'],
        Permissions::INVOICE_REJECT => ['purchases'],
        Permissions::INVOICE_OVERRIDE => ['purchases'],
        Permissions::INVOICE_PAY => ['purchases'],
        Permissions::PAYROLL_VIEW => ['employees'],
        Permissions::PAYROLL_APPROVE => ['employees'],
        Permissions::PAYROLL_PAY => ['employees'],
        Permissions::CUSTOMER_INVOICE_ISSUE => ['orders', 'finance', 'projects'],
        Permissions::CUSTOMER_INVOICE_CANCEL => ['orders'],
        Permissions::CUSTOMER_CREDIT_NOTE => ['orders'],
        Permissions::CUSTOMER_PAYMENT_CREATE => ['finance'],
        Permissions::ATTACHMENT_VIEW => ['purchases', 'inventory', 'expenses', 'orders'],
        Permissions::QUOTATION_CREATE => ['quotations'],
        Permissions::QUOTATION_SEND => ['quotations'],
        Permissions::QUOTATION_DISCOUNT => ['quotations', 'projects'],
        Permissions::QUOTATION_OVERRIDE_PRICE => ['quotations', 'projects'],
        Permissions::QUOTATION_APPROVE => ['quotations', 'projects'],
        Permissions::QUOTATION_CANCEL => ['quotations'],
        Permissions::QUOTATION_RECORD_DECISION => ['quotations'],
        Permissions::POLICY_OVERRIDE => ['quotations', 'projects'],
        Permissions::MESSAGING_SEND => ['quotations', 'projects', 'orders', 'customers', 'communications'],
        Permissions::COMMUNICATION_VIEW => ['quotations', 'projects', 'orders', 'customers'],
        Permissions::COMMUNICATION_RETRY => ['communications'],
        Permissions::COMMUNICATION_TEMPLATES_MANAGE => ['communications'],
        Permissions::AUTOMATION_MANAGE => ['automation'],
        Permissions::AUTOMATION_PAUSE => ['automation'],
        Permissions::IMPORT_MANAGE => ['imports'],
        Permissions::IMPORT_EXECUTE => ['imports'],
        Permissions::IMPORT_FINANCIAL => ['imports'],
        Permissions::IMPORT_ROLLBACK => ['imports'],
        Permissions::OPERATIONS_MANAGE => ['operations'],
        Permissions::OPERATIONS_STATUS => ['operations'],
        Permissions::OPERATIONS_OVERRIDE => ['operations'],
        Permissions::OPERATIONS_INCIDENTS => ['operations'],
        Permissions::ASSETS_MANAGE => ['assets'],
        Permissions::ASSETS_RESERVE => ['operations'],
        Permissions::ASSETS_CHECKOUT => ['operations'],
        Permissions::ASSETS_RETURN => ['operations', 'assets'],
        Permissions::CREW_MANAGE => ['operations'],
        Permissions::CHECKLIST_MANAGE => ['operations'],
        Permissions::ORGANIZERS_MANAGE => ['organizers'],
        Permissions::DOCUMENTS_MANAGE => ['documents'],
        Permissions::ANALYTICS_FINANCIAL => ['management'],
        Permissions::ANALYTICS_PROJECTS => ['management', 'projects'],
        Permissions::ANALYTICS_CUSTOMERS => ['management'],
        Permissions::ANALYTICS_OPERATIONS => ['management'],
        Permissions::BUDGETS_VIEW => ['management'],
        Permissions::BUDGETS_MANAGE => ['management'],
        Permissions::BUDGETS_APPROVE => ['management'],
        Permissions::FORECASTS_VIEW => ['management'],
        Permissions::FORECASTS_MANAGE => ['management'],
        Permissions::FORECASTS_APPROVE => ['management'],
        Permissions::SCENARIOS_MANAGE => ['management'],
        Permissions::MANAGEMENT_REVIEWS_MANAGE => ['management'],
        Permissions::MANAGEMENT_THRESHOLDS => ['management'],
        Permissions::PROJECT_MANAGE => ['projects'],
        Permissions::PROJECT_BUDGET_VIEW => ['projects', 'command'],
        Permissions::PROJECT_CLOSE => ['projects'],
        Permissions::PROJECT_REOPEN => ['projects'],
        Permissions::CHANGE_ORDER_CREATE => ['projects'],
        Permissions::CHANGE_ORDER_SEND => ['projects'],
        Permissions::CHANGE_ORDER_RECORD_DECISION => ['projects'],
        Permissions::CHANGE_ORDER_CANCEL => ['projects'],
    ];

    /**
     * الكتالوجُ كما تعرضه الشاشة: المجموعاتُ ثمّ «صلاحيات حسّاسة».
     *
     * @return list<array{key: string, label: string, sensitive: bool, items: list<array<string, mixed>>}>
     */
    public static function catalog(): array
    {
        $sections = Permissions::sectionLabels();
        $actions = Permissions::actionLabels();

        $groups = [];
        foreach (self::GROUPS as $key => $group) {
            $groups[] = [
                'key' => $key,
                'label' => __($group['label']),
                'sensitive' => false,
                'items' => [
                    ...array_map(fn ($s) => self::sectionItem($s, $sections), $group['sections']),
                    ...array_map(fn ($a) => self::actionItem($a, $actions, false), $group['actions']),
                ],
            ];
        }

        $groups[] = [
            'key' => 'sensitive',
            'label' => __('صلاحيات حساسة'),
            'sensitive' => true,
            'items' => array_map(fn ($a) => self::actionItem($a, $actions, true), array_keys(self::SENSITIVE)),
        ];

        return $groups;
    }

    /** @param array<string, string> $labels */
    private static function sectionItem(string $key, array $labels): array
    {
        return [
            'key' => $key,
            'label' => $labels[$key] ?? $key,
            'kind' => 'section',
            'risk' => 'normal',
            'hint' => isset(self::SECTION_HINTS[$key]) ? __(self::SECTION_HINTS[$key]) : null,
            'parents' => [],
        ];
    }

    /** @param array<string, string> $labels */
    private static function actionItem(string $key, array $labels, bool $sensitive): array
    {
        $hint = $sensitive ? self::SENSITIVE[$key] : (self::ACTION_HINTS[$key] ?? null);

        return [
            'key' => $key,
            'label' => $labels[$key] ?? $key,
            'kind' => 'action',
            'risk' => $sensitive ? 'sensitive' : 'normal',
            'hint' => $hint !== null ? __($hint) : null,
            'parents' => self::PARENTS[$key] ?? [],
        ];
    }

    /**
     * ما يحمله كلُّ ملفّ صلاحياتٍ أساسيّ — من `Permissions::roleGrants` نفسِها.
     *
     * @return array<string, array{label: string, grants: list<string>}>
     */
    public static function roleProfiles(): array
    {
        return collect(Roles::keys())->mapWithKeys(fn ($role) => [$role => [
            'label' => Roles::label($role),
            'grants' => Permissions::roleGrants($role),
        ]])->all();
    }

    /**
     * ملفّاتُ الموظّفين الخمسة لاختيار ملفّ وظيفة — وما لا يُسنده الفاعلُ معطّلٌ بسببه.
     *
     * @return list<array{value: string, label: string, assignable: bool}>
     */
    public static function roleOptions(?User $actor): array
    {
        return array_map(fn ($role) => [
            'value' => $role,
            'label' => Roles::label($role),
            'assignable' => Permissions::mayAssignRole($actor, $role),
        ], Roles::STAFF);
    }

    /**
     * المسمّياتُ بملفّاتها — وما لا يُسنده الفاعلُ معطّلٌ بسببه (`mayAssignRole`).
     *
     * @return list<array{name: string, role: string, role_label: string, blocked: bool}>
     */
    public static function jobTitles(int $businessId, ?User $actor): array
    {
        return JobTitle::where('business_id', $businessId)->orderBy('name')->get()
            ->map(fn (JobTitle $t) => [
                'name' => $t->name,
                'role' => $t->role,
                'role_label' => Roles::label($t->role),
                'blocked' => ! Permissions::mayAssignRole($actor, $t->role),
            ])->values()->all();
    }

    /**
     * ما يحتاجه نموذجُ الموظّف — للإضافة والتعديل من موضعٍ واحد.
     *
     * @return array<string, mixed>
     */
    public static function formOptions(int $businessId, ?User $actor): array
    {
        return [
            'jobTitles' => self::jobTitles($businessId, $actor),
            'roleProfiles' => self::roleProfiles(),
            'roleOptions' => self::roleOptions($actor),
            'permissionCatalog' => self::catalog(),
            // وما يملك الفاعلُ منحَه — سواه يُعطَّل بسببه مكتوبًا لا يُرفع
            'grantable' => Permissions::grantable($actor),
        ];
    }

    /**
     * عددُ ما في قائمةٍ مخصّصة: أقسامُها، وأفعالُها، والحسّاسُ منها.
     *
     * @param  list<string>  $keys
     * @return array{sections: int, actions: int, sensitive: int}
     */
    public static function summary(array $keys): array
    {
        $keys = array_values(array_unique($keys));

        return [
            'sections' => count(array_intersect($keys, Permissions::SECTIONS)),
            'actions' => count(array_intersect($keys, Permissions::actions())),
            'sensitive' => count(array_intersect($keys, array_keys(self::SENSITIVE))),
        ];
    }

    /**
     * وصولُ موظّفٍ كما تعرضه بطاقته: ملفُّه، ووضعُه، وما يفتحه فعلًا في مجموعاته.
     *
     * والفعليُّ يُقرأ بـ`allows`/`may` — الحارسُ نفسُه — لا من القائمة وحدها.
     *
     * @return array<string, mixed>
     */
    public static function describe(User $user): array
    {
        $effective = [
            ...array_values(array_filter(Permissions::SECTIONS, fn ($s) => $user->allows($s))),
            ...array_values(array_filter(Permissions::actions(), fn ($a) => $user->may($a))),
        ];

        return [
            'role' => $user->role,
            'role_label' => $user->roleLabel(),
            'is_owner' => ! $user->isEmployee(),
            'mode' => $user->hasManualPermissions() ? 'custom' : 'inherited',
            'summary' => self::summary($effective),
            'groups' => collect(self::catalog())
                ->map(fn ($g) => [...$g, 'items' => array_values(array_filter($g['items'], fn ($i) => in_array($i['key'], $effective, true)))])
                ->filter(fn ($g) => $g['items'] !== [])
                ->values()->all(),
        ];
    }
}
