import { usePage } from '@inertiajs/react';
import SmartLink from '@/Components/SmartLink';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

export interface SectionTab {
    label: string;
    routeName: string;
    /** وسيط المسار إن كان يحتاجه */
    args?: unknown;
    /**
     * قسم الصلاحية — يُخفى التبويب إن لم يملكه المستخدم.
     *
     * بلا هذا كان الشريط يعرض التبويبات كاملةً لكل من فتح الصفحة، فمن مُنح
     * «المالية» ولم يُمنح «المصروفات» يرى تبويبها ويضغطه فيصطدم بـ403.
     * والقائمة الجانبية تُخفي ما لا يُملك منذ البداية — فيفترق البابان على
     * الشيء نفسه: هذا يخفيه وذاك يعرضه.
     */
    section?: string;
    /**
     * فعلُ الصلاحية إن كانت الشاشة يحرسها فعلٌ لا قسم.
     *
     * القسمُ لا يكفي لبعض الشاشات: مسيرةُ الرواتب وصرفُها تحت «الموظفين»،
     * وسنداتُ الموردين تحت «المشتريات» — ومن مُنح القسم ولم يُمنح الفعل يرى
     * التبويب ويُردّ بـ403.
     */
    action?: string;
}

interface Props {
    tabs: SectionTab[];
    /** اسم مسار التبويب النشط */
    current: string;
    className?: string;
}

/**
 * شريط تبويبات القسم — بديل partials/products-tabs و inventory-tabs و تبويبات المصروفات.
 *
 * النشط يُحدَّد باسم المسار لا بالرابط: مسارات مثل المنتجات لها صفحات فرعية
 * (إنشاء/تعديل/عرض) يجب أن تُبقي تبويبها مضيئًا.
 *
 * وشكلٌ واحد لا خيار فيه: خطٌّ تحت النشط. كان الشكل خيارًا بين `underline`
 * و`segmented`، فانقسم النظام شريطين — تسعة عشر موضعًا على هذا وخمسةٌ على
 * ذاك — لا لأن أحدًا قرّر، بل لأن كل شاشةٍ جديدة تنسخ ما جاورها. والخيار
 * الذي لا يُتَّخذ مرّةً واحدة يُتَّخذ في كل ملفٍّ من جديد، فنُزع من جذره:
 * لا يعود الانقسام إلا بتعديل هذا الملفّ نفسه.
 */
export default function SectionTabs({ tabs: all, current, className }: Props) {
    const t = useTranslate();
    const { auth } = usePage<PageProps>().props;

    // بلا قسم يظهر التبويب دائمًا — انظر SectionTab.section
    const tabs = all.filter(
        (tb) =>
            (!tb.section || (auth?.abilities.includes(tb.section) ?? false)) &&
            (!tb.action || (auth?.mayActions?.includes(tb.action) ?? false)),
    );

    /*
     * تبويب نشط واحد لا أكثر.
     *
     * المطابقة بالعائلة وحدها كانت تُضيء تبويبات القسم كلّها معًا: عائلة
     * admin.customers.index و.organizations واحدة هي admin.customers، فكل
     * صفحة عملاء تُطابقها جميعًا.
     *
     * فالمطابقة الحرفية تُقدَّم أولًا، ولا يُلجأ إلى العائلة إلا حين لا يطابق
     * أيُّ تبويب حرفيًا (صفحات فرعية مثل admin.products.create) — ويؤخذ عندها
     * أطولُ مسارٍ مطابق فيبقى الاختيار محدَّدًا لا عشوائيًا.
     */
    // تبويبٌ واحد ليس شريطًا: من لا يملك إلا قسمًا من القسم يرى عنوان صفحته
    // مرسومًا كتبويب لا ينقله إلى شيء
    if (tabs.length < 2) {
        return null;
    }

    const activeRoute =
        tabs.find((tb) => tb.routeName === current)?.routeName ??
        tabs
            .filter((tb) => current.startsWith(tb.routeName.replace(/\.[^.]+$/, '') + '.'))
            .sort((a, b) => b.routeName.length - a.routeName.length)[0]?.routeName;

    return (
        <div
            className={cn(
                'mb-6 flex items-center gap-1 overflow-x-auto border-b border-[var(--ui-border,#e8e8e8)]',
                className,
            )}
        >
            {tabs.map((tab) => {
                const active = tab.routeName === activeRoute;

                return (
                    <SmartLink
                        key={tab.routeName}
                        routeName={tab.routeName}
                        href={route(tab.routeName, tab.args as never)}
                        className={cn(
                            'whitespace-nowrap text-sm font-medium transition-colors',
                            // ‏-mb-px يرفع حدّ التبويب فوق حدّ الشريط فيحلّ محلّه،
                            // ولولاه لظهر خطّان متجاوران تحت النشط
                            '-mb-px border-b-2 px-4 py-3',
                            // النشط يتبدّل لونًا لا حجمًا — فلا يقفز ما تحته
                            active
                                ? 'border-[#111] text-[#111]'
                                : 'border-transparent text-[#6b7280] hover:text-[#374151]',
                        )}
                    >
                        {t(tab.label)}
                    </SmartLink>
                );
            })}
        </div>
    );
}

/**
 * قسم العملاء — العملاء والمورّدون والمنظّمون.
 *
 * كلُّهم جهاتٌ وأشخاصٌ تتعامل معهم الشركة: العميلُ تعمل له، والمورّدُ تشتري
 * منه، والمنظّمُ يعمل معها في الميدان. والمنظّمون كانوا قسمًا وحدَه في
 * القائمة — فصاروا تبويبًا هنا بسجلّهم وتصنيفاتهم وصلاحيتهم كما هي
 * (`organizers` · `organizers.manage`)، ولا يُخلطون بجدول العملاء.
 */
export const CUSTOMER_TABS: SectionTab[] = [
    /* الاعتباريّون أوّلًا: وزاراتٌ وشركاتٌ هي أكثرُ من تعمل له الشركة */
    { label: 'شركات وجهات', routeName: 'admin.customers.organizations', section: 'customers' },
    { label: 'العملاء', routeName: 'admin.customers.index', section: 'customers' },
    { label: 'الموردين', routeName: 'admin.suppliers.index', section: 'suppliers' },
    { label: 'المنظّمون', routeName: 'admin.organizers.index', section: 'organizers' },
];

/**
 * قسم المشاريع — المشاريعُ وتنفيذُها.
 *
 * العمليّاتُ هي المشروعُ على الأرض: موعدُه ومكانُه وفريقُه. فمكانُها بجانبه
 * لا قسمًا وحدَه في القائمة.
 */
export const PROJECT_TABS: SectionTab[] = [
    { label: 'المشاريع', routeName: 'admin.projects.index', section: 'projects' },
    { label: 'العمليات', routeName: 'admin.operations.index', section: 'operations' },
];

/**
 * قسم المنتجات — مدخلٌ واحد.
 *
 * الأقسام والإضافات حُذفتا، والشريط يختفي من نفسه حين لا يبقى إلا تبويب
 * (انظر SectionTabs: تبويبٌ واحد ليس شريطًا).
 */
export const PRODUCT_TABS: SectionTab[] = [
    { label: 'المنتجات', routeName: 'admin.products.index', section: 'products' },
];

/*
 * قسم المالية — دفترٌ واحد يُقرأ من أبوابه.
 *
 * والترتيب يتبع ما يُسأل عنه أوّلًا: «كم عندي وكم ربحت؟» ثمّ «ماذا جرى؟»
 * ثمّ «ماذا عليّ؟» — وبعدها أدواتُ المحاسب. وكانت الشاشة الأولى «الحسابات
 * البنكية»، فيفتح التاجرُ الماليةَ ليعرف أين ماله فيقع على قائمة حساباتٍ
 * لا على جوابه.
 *
 * وكشف الحساب البنكي ليس تبويبًا: هو صفحةُ حسابٍ بعينه تُفتح من قائمة
 * الحسابات، فبقاؤه تبويبًا كان يعني بابًا يقود إلى «أوّل حساب» أيًّا كان.
 */
export const FINANCE_TABS: SectionTab[] = [
    { label: 'الملخّص المالي', routeName: 'admin.finance.summary', section: 'finance' },
    { label: 'الحركة المالية', routeName: 'admin.finance.transactions', section: 'finance' },
    /* «ما لك» و«ما عليك» تبويبان متجاوران ولا يُجمعان في واحد: رقمان
       في شاشةٍ واحدة بلا فصل يجعل التاجر لا يعرف أدائنٌ هو أم مدين */
    { label: 'الذمم المدينة', routeName: 'admin.finance.receivables', section: 'finance' },
    /* والشيكاتُ تبويبٌ مستقلّ: ورقةٌ في اليد ليست ذمّةً على عميلٍ ولا مالًا في بنك */
    { label: 'الشيكات', routeName: 'admin.finance.cheques', section: 'finance' },
    { label: 'المبالغ المستحقة', routeName: 'admin.finance.dues', section: 'finance' },
    { label: 'مصاريف شهرية', routeName: 'admin.expenses.index', section: 'expenses' },
    { label: 'الحسابات البنكية', routeName: 'admin.finance.index', section: 'finance' },
    { label: 'القيود اليومية', routeName: 'admin.finance.journal', section: 'finance' },
    { label: 'شجرة الحسابات', routeName: 'admin.finance.chart', section: 'finance' },
    { label: 'أصول ثابتة', routeName: 'admin.finance.assets', section: 'finance' },
];

/*
 * قسم الموظفين — والرواتبُ تبويبان فيه يظهران لمن يملك `payroll.view` وحده.
 *
 * المسيرة والصرف شاشتان لا واحدة: الأولى تُحضّر وتعتمد، والثانية تُخرج المال.
 * ودمجُهما يجعل زرَّ الاعتماد وزرَّ الصرف متجاورين على شاشةٍ واحدة، فيُضغط
 * الثاني قبل مراجعة الأوّل.
 */
export const EMPLOYEE_TABS: SectionTab[] = [
    { label: 'الموظفون', routeName: 'admin.employees.index', section: 'employees' },
    { label: 'مسيرة الرواتب', routeName: 'admin.payroll.index', section: 'employees', action: 'payroll.view' },
    { label: 'صرف الرواتب', routeName: 'admin.payroll.payments', section: 'employees', action: 'payroll.view' },
];

/*
 * قسم المشتريات — القائمة ثمّ السندات ثمّ الأوامر.
 *
 * الترتيب يتبع ما يُسأل عنه أكثر: «ماذا اشتريتُ؟» أوّلًا، ثمّ «ماذا عليّ؟»،
 * ثمّ «ماذا طلبتُ ولم يصل؟».
 */
export const PURCHASE_TABS: SectionTab[] = [
    { label: 'قائمة المشتريات', routeName: 'admin.purchases.index', section: 'purchases' },
    { label: 'سندات الموردين', routeName: 'admin.purchases.invoices', section: 'purchases', action: 'invoice.view' },
    { label: 'أوامر الشراء', routeName: 'admin.purchases.orders', section: 'purchases' },
];

/*
 * قسمُ الإدارة — المرحلة 11: «كيف تؤدّي الشركة؟» في تبويباتٍ لا في ثلاثَ
 * عشرةَ شاشة. والمالُ يحرسه فعلُه: من فتح القسم بلا `analytics.financial`
 * لا يرى تبويبَ المال ولا النقد ولا الموازنة.
 */
export const MANAGEMENT_TABS: SectionTab[] = [
    { label: 'نظرة عامة', routeName: 'admin.management.index', section: 'management' },
    { label: 'الأداء المالي', routeName: 'admin.management.financial', section: 'management', action: 'analytics.financial' },
    { label: 'المشاريع', routeName: 'admin.management.projects', section: 'management', action: 'analytics.projects' },
    { label: 'العملاء', routeName: 'admin.management.customers', section: 'management', action: 'analytics.customers' },
    { label: 'الخدمات والقمع', routeName: 'admin.management.services', section: 'management', action: 'analytics.customers' },
    { label: 'النقد والتوقّع', routeName: 'admin.management.cash', section: 'management', action: 'analytics.financial' },
    { label: 'الموازنة', routeName: 'admin.management.budgets', section: 'management', action: 'budgets.view' },
    { label: 'التشغيل والمعدّات', routeName: 'admin.management.operations', section: 'management', action: 'analytics.operations' },
    { label: 'المراجعات', routeName: 'admin.management.reviews', section: 'management' },
    { label: 'التقارير', routeName: 'admin.management.reports', section: 'management' },
];

/*
 * قسم المخزون — رصيدُ المنتجات وحده.
 *
 * «عمليات جرد المخزون» و«سجل المخزون» و«إشعار استلام بضاعة» خرجت من الواجهة:
 * القسمُ لإدارة الرصيد لا لدفاتره. والبنيةُ تحتها باقية — `StockLedger` والتسوياتُ
 * التي تكتبها مستهلكاتُ الفعاليّة، وسندُ الاستلام الذي يُعتمد من صفحته فيُدخل
 * البضاعةَ الرفّ. والشريطُ يختفي من نفسه حين لا يبقى إلّا تبويب.
 */
export const INVENTORY_TABS: SectionTab[] = [
    { label: 'المنتجات', routeName: 'admin.inventory.index', section: 'inventory' },
];
