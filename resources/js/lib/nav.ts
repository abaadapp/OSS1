import type { LucideIcon } from 'lucide-react';
import {
    CUSTOMER_TABS,
    EMPLOYEE_TABS,
    FINANCE_TABS,
    INVENTORY_TABS,
    MANAGEMENT_TABS,
    PROJECT_TABS,
    PURCHASE_TABS,
    type SectionTab,
} from '@/Components/SectionTabs';
import {
    BarChart3,
    Boxes,
    Gauge,
    Database,
    Briefcase,
    FileText,
    FolderOpen,
    LayoutDashboard,
    ListChecks,
    MessageCircle,
    MessageSquare,
    Radar,
    Package,
    Settings,
    ShoppingCart,
    Truck,
    UserCog,
    UsersRound,
    Wrench,
    Users,
    Wallet,
    Zap,
} from 'lucide-react';

export interface NavItem {
    label: string;
    icon: LucideIcon;
    route: string;
    /** قسم الصلاحية — يُخفى العنصر إن لم يملكه المستخدم. وبلا قيمة يظهر دائمًا */
    section?: string;
    /**
     * صفحات تتبع هذا العنصر ولا مدخل لها في القائمة — تُبقيه مضيئًا.
     *
     * كلّ ما يُفتح من شريط تبويبات القسم: «القيود اليومية» و«شجرة الحسابات»
     * صفحتا المالية، و«سندات الموردين» صفحة المشتريات. وبلا هذا كان الضغط
     * عليها يُطفئ القائمة كلّها: لا عنصر مضيء، فلا يعرف من فتحها أين هو.
     */
    covers?: string[];
    /** قائمةٌ منسدلة تحت العنصر — لا وجهةَ له هو، بل يفتح ما تحته */
    children?: NavItem[];
    /**
     * لا يُرسم في الشريط، ويبقى في دليل الصفحات وتُضيء صفحاتُه قسمَه.
     *
     * «المراسلات» خرجت من الشريط، وسجلُّها وقوالبُها ما زالت صفحاتٍ تُفتح
     * من البحث ومن روابط المستندات — فتبقى معروفةً لقسمها لا يتيمة.
     */
    hidden?: boolean;
    /**
     * مفتاحُ عدّادٍ في `badges` المشتركة — رقمٌ صغير بجوار العنصر حين يزيد على صفر.
     *
     * «المستندات ٣»: ما يحتاج إجراءً وحده (انتهى أو قرُب انتهاؤه)، لا كلُّ ما في القسم.
     */
    badge?: string;
}

export interface NavGroup {
    /** مفتاحٌ ثابتٌ للمجموعة — يُرسم به ولا يُعرض */
    key?: string;
    heading?: string;
    /**
     * مجموعةٌ تُدفع إلى أسفل الشريط.
     *
     * «الإعدادات» تُفتح مرّةً في الشهر ولا تُفتح مرّةً في الساعة، فمكانها
     * أسفل القائمة لا وسطها بين ما يُستعمل كلّ يوم.
     */
    footer?: boolean;
    items: NavItem[];
}

/**
 * صفحات الشريط مصدرها شريط تبويبات القسم نفسه.
 *
 * قائمةٌ ثانية مكتوبة باليد كانت ستفترق عن الأولى عند أوّل تبويب يُضاف —
 * فيظهر تبويبٌ لا يُضيء قسمه في القائمة.
 */
const covers = (tabs: SectionTab[]) => tabs.map((tb) => tb.routeName);

/**
 * القائمة الجانبية للوحة التاجر — خمسُ مجموعاتٍ بعناوينها.
 *
 * كلُّها في الشريط معًا، والعنوانُ يقول ما تحته: «التشغيل» لما يُعمل كلَّ
 * يوم، ثمّ المشاريعُ والمالُ والكتالوجُ والإعدادات.
 */
export const NAV: NavGroup[] = [
    {
        key: 'operations',
        heading: 'التشغيل',
        items: [
            /*
             * الملخصُ خارجَ الشريط: صفحةُ الدخول، تُفتح منه ولا تُطلب من القائمة.
             * ومركزُ العمليات مثلُه — حلّت محلَّه «المهام»: عملُ كلّ مشروعٍ بموعده
             */
            { label: 'الملخص', icon: LayoutDashboard, route: 'admin.dashboard', section: 'dashboard', hidden: true },
            { label: 'مركز العمليات', icon: Radar, route: 'admin.command.index', section: 'command', hidden: true },
            { label: 'المهام', icon: ListChecks, route: 'admin.tasks.index', section: 'projects' },
            {
                label: 'العملاء',
                icon: Users,
                route: 'admin.customers.index',
                section: 'customers',
                /* وصفحةُ المورّد تحت تبويب «الموردين» هنا — المرحلة 14 */
                covers: [...covers(CUSTOMER_TABS), 'admin.suppliers.show'],
            },
            /* وواتساب: صندوقُ وارد الشركة — ما يكتبه العملاءُ ويُجاب من هنا */
            { label: 'واتساب', icon: MessageCircle, route: 'admin.whatsapp.index', section: 'communications' },
            /* والمستنداتُ الداخليّة — وشارتُها ما انتهى أو قرُب انتهاؤه */
            { label: 'المستندات', icon: FolderOpen, route: 'admin.documents.index', section: 'documents', covers: ['admin.documents.show'], badge: 'documents' },
            /*
             * والمراسلات خارجَ الشريط: سجلُّ ما أُرسل وقوالبُه يُفتحان من
             * البحث ومن روابط المستندات — المرحلة 6
             */
            {
                label: 'المراسلات',
                icon: MessageSquare,
                route: 'admin.communications.index',
                section: 'communications',
                covers: ['admin.communications.show', 'admin.communications.templates'],
                hidden: true,
            },
        ],
    },
    {
        key: 'projects',
        heading: 'المشاريع والمعارض',
        items: [
            /* العرضُ المقبول يصير مشروعًا. وأوامرُ التغيير صفحاتٌ تحته لا مدخلَ لها */
            {
                label: 'المشاريع',
                icon: Briefcase,
                route: 'admin.projects.index',
                section: 'projects',
                covers: [
                    'admin.projects.show',
                    'admin.changeOrders.show',
                    'admin.changeOrders.create',
                    'admin.changeOrders.edit',
                    /* والعملياتُ تبويبٌ في المشاريع: تنفيذُ الفعاليّة على الأرض — المرحلة 10 */
                    ...covers(PROJECT_TABS),
                    'admin.operations.show',
                    'admin.operations.onsite',
                    'admin.operations.templates',
                    'admin.quotations.index',
                    'admin.quotations.show',
                    'admin.quotations.create',
                    'admin.deliveries.index',
                    'admin.deliveries.show',
                    'admin.deliveries.create',
                ],
            },
            /*
             * والمنظّمون الميدانيّون — المرحلة 14: سجلٌّ مستقلّ بتصنيفاته، يُعيَّنون
             * منه على الفعاليّة. قسمٌ له بابُه، لا تبويبٌ في المشروع.
             */
            {
                label: 'المنظّمون',
                icon: UsersRound,
                route: 'admin.organizers.index',
                section: 'organizers',
                covers: ['admin.organizers.show'],
            },
            /*
             * وعروضُ الأسعار وحزمُ التسليم خرجت من الشريط: هي مراحلُ المشروع لا
             * أقسامٌ بجانبه — العرضُ بطاقةٌ في قائمة المشاريع حتى يُقبل، والتسليمُ
             * مرحلةٌ على بطاقته. وصفحاتُهما تبقى وتُضيء «المشاريع»
             */
        ],
    },
    {
        key: 'finance',
        heading: 'المالية',
        items: [
            /* والإدارةُ: كيف تؤدّي الشركة — لا ما يحتاج يدًا — المرحلة 11 */
            {
                label: 'الإدارة',
                icon: Gauge,
                route: 'admin.management.index',
                section: 'management',
                covers: [...covers(MANAGEMENT_TABS), 'admin.management.reviews.show'],
            },
            // «الطلبات» سابقًا: المتجر يبيع ولا يستقبل طلبات وحسب
            { label: 'المبيعات', icon: ShoppingCart, route: 'admin.orders.index', section: 'orders' },
            // فواتيرُ العملاء بابٌ واحدٌ لا يتكرّر في «المالية»: ميزةٌ في
            // موضعين تجعل التاجر يعدّل في أحدهما ويقرأ من الآخر
            {
                label: 'فواتير العملاء',
                icon: FileText,
                route: 'admin.customerInvoices.index',
                section: 'orders',
                covers: ['admin.customerInvoices.show', 'admin.customerInvoices.create'],
            },
            {
                label: 'المشتريات',
                icon: Truck,
                route: 'admin.purchases.index',
                section: 'purchases',
                covers: covers(PURCHASE_TABS),
            },
            {
                label: 'المالية',
                icon: Wallet,
                route: 'admin.finance.index',
                section: 'finance',
                covers: [...covers(FINANCE_TABS), 'admin.finance.statement'],
            },
            {
                // القسمُ «الموظفون»، والرواتبُ تبويباتٌ داخله تظهر بأفعالها (`EMPLOYEE_TABS`)
                label: 'الموظفون',
                icon: UserCog,
                route: 'admin.employees.index',
                section: 'employees',
                covers: covers(EMPLOYEE_TABS),
            },
            /* التقارير بابٌ لا شاشةُ أرقام — تجمع ما تفرّق وتقود إليه */
            { label: 'التقارير', icon: BarChart3, route: 'admin.reports.index', section: 'reports' },
        ],
    },
    {
        key: 'catalog',
        heading: 'المنتجات والخدمات',
        items: [
            { label: 'المنتجات', icon: Package, route: 'admin.products.index', section: 'products' },
            {
                label: 'المخزون',
                icon: Boxes,
                route: 'admin.inventory.index',
                section: 'inventory',
                covers: covers(INVENTORY_TABS),
            },
            /* والمعدّاتُ ذاتُ الهويّة بجانب المخزون — وليست منه: قطعٌ لا كمّيّات — المرحلة 10 */
            {
                label: 'المعدّات',
                icon: Wrench,
                route: 'admin.assets.index',
                section: 'assets',
                covers: ['admin.assets.show', 'admin.assets.calendar'],
            },
        ],
    },
    {
        key: 'settings',
        heading: 'الإعدادات',
        items: [
            /* الأتمتة: ما يتابعه النظام وحده — قواعدُه وسجلُّه — المرحلة 8 */
            {
                label: 'الأتمتة',
                icon: Zap,
                route: 'admin.automation.index',
                section: 'automation',
                covers: ['admin.automation.history'],
            },
            /* واستيرادُ البيانات التاريخيّة: يُفتح مرّةً عند التحويل — المرحلة 9 */
            {
                label: 'استيراد البيانات',
                icon: Database,
                route: 'admin.imports.index',
                section: 'imports',
                covers: ['admin.imports.show'],
            },
            { label: 'الإعدادات', icon: Settings, route: 'admin.settings.index', section: 'settings' },
        ],
    },
];
