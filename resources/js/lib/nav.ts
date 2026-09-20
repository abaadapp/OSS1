import type { LucideIcon } from 'lucide-react';
import {
    CUSTOMER_TABS,
    EMPLOYEE_TABS,
    FINANCE_TABS,
    INVENTORY_TABS,
    MANAGEMENT_TABS,
    PURCHASE_TABS,
    type SectionTab,
} from '@/Components/SectionTabs';
import {
    BarChart3,
    Boxes,
    CalendarCheck,
    Gauge,
    Database,
    Briefcase,
    FileSignature,
    FileText,
    LayoutDashboard,
    MessageSquare,
    Radar,
    Package,
    PackageCheck,
    Settings,
    ShoppingCart,
    Truck,
    UserCog,
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
}

export interface NavGroup {
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
 * القائمة الجانبية للوحة التاجر.
 *
 * المجموعات تفصلها خطوط لا عناوين: العناوين («المتجر»، «الإدارة») تسمية
 * لا تُقرأ — لا أحد يبحث عن «المالية» تحت «الإدارة»، ويبحث عن «المالية».
 * والخطّ يفصل بلا أن يشغل سطرًا ولا أن يدّعي معنى.
 */
export const NAV: NavGroup[] = [
    {
        items: [
            { label: 'لوحة التحكم', icon: LayoutDashboard, route: 'admin.dashboard', section: 'dashboard' },
            /* ومركزُ العمليات بعدها مباشرةً: ما يحتاج يدًا اليوم — المرحلة 7 */
            { label: 'مركز العمليات', icon: Radar, route: 'admin.command.index', section: 'command' },
            /* والإدارةُ: كيف تؤدّي الشركة — لا ما يحتاج يدًا — المرحلة 11 */
            {
                label: 'الإدارة',
                icon: Gauge,
                route: 'admin.management.index',
                section: 'management',
                covers: [...covers(MANAGEMENT_TABS), 'admin.management.reviews.show'],
            },
            {
                label: 'العملاء',
                icon: Users,
                route: 'admin.customers.index',
                section: 'customers',
                covers: covers(CUSTOMER_TABS),
            },
        ],
    },
    {
        items: [
            { label: 'المنتجات', icon: Package, route: 'admin.products.index', section: 'products' },
            /*
             * وعروضُ الأسعار قبل الفواتير: هي أوّلُ ما يُكتب في دورة العمل —
             * يُعرض، ثمّ يُقبل، ثمّ يُفوتَر. وترتيبُ القائمة ترتيبُ اليوم لا
             * ترتيبُ الجداول.
             */
            {
                label: 'عروض الأسعار',
                icon: FileSignature,
                route: 'admin.quotations.index',
                section: 'quotations',
                covers: ['admin.quotations.show', 'admin.quotations.create'],
            },
            /*
             * والمشاريعُ بعد العروض: العرضُ المقبول يصير مشروعًا. وأوامرُ
             * التغيير صفحاتٌ تحته لا مدخلَ لها.
             */
            {
                label: 'المشاريع',
                icon: Briefcase,
                route: 'admin.projects.index',
                section: 'projects',
                covers: ['admin.projects.show', 'admin.changeOrders.show', 'admin.changeOrders.create', 'admin.changeOrders.edit'],
            },
            /*
             * والعمليات بعد المشاريع: تنفيذُ الفعاليّة على الأرض — الموعدُ
             * والفريقُ والمعدّاتُ وشاشةُ الموقع — المرحلة 10
             */
            {
                label: 'العمليات',
                icon: CalendarCheck,
                route: 'admin.operations.index',
                section: 'operations',
                covers: ['admin.operations.show', 'admin.operations.onsite', 'admin.operations.templates'],
            },
            // وحزمُ التسليم: ما تسلّمه الشركةُ في ختام العمل ويقبله العميل — المرحلة 5
            {
                label: 'حزم التسليم',
                icon: PackageCheck,
                route: 'admin.deliveries.index',
                section: 'quotations',
                covers: ['admin.deliveries.show', 'admin.deliveries.create'],
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
        ],
    },
    {
        items: [
            {
                label: 'المالية',
                icon: Wallet,
                route: 'admin.finance.index',
                section: 'finance',
                covers: [...covers(FINANCE_TABS), 'admin.finance.statement'],
            },
            {
                label: 'الرواتب والموظفين',
                icon: UserCog,
                route: 'admin.employees.index',
                section: 'employees',
                covers: covers(EMPLOYEE_TABS),
            },
        ],
    },
    {
        items: [
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
        /* التقارير مجموعةٌ وحدها: بابٌ لا شاشةُ أرقام — تجمع ما تفرّق وتقود إليه */
        items: [
            { label: 'التقارير', icon: BarChart3, route: 'admin.reports.index', section: 'reports' },
            /*
             * والمراسلات بجانبها: سجلٌّ يُقرأ لا شاشةُ عمل — ما قيل للعملاء
             * وماذا صار إليه. والإرسالُ نفسُه من شاشة المستند — المرحلة 6
             */
            {
                label: 'المراسلات',
                icon: MessageSquare,
                route: 'admin.communications.index',
                section: 'communications',
                covers: ['admin.communications.show', 'admin.communications.templates'],
            },
            /* والأتمتة بعدها: ما يتابعه النظام وحده — قواعدُه وسجلُّه — المرحلة 8 */
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
        ],
    },
    {
        /*
         * الإعدادات — مجموعةُ القاع.
         *
         * `footer` يدفع مجموعتَه وحدها بـ`mt-auto` إلى أسفل الشريط: الإعدادات
         * تُفتح مرّةً في الشهر ولا تُفتح مرّةً في الساعة، فمكانها أسفل القائمة
         * لا وسطها بين ما يُستعمل كلّ يوم.
         */
        footer: true,
        items: [
            {
                label: 'الإعدادات',
                icon: Settings,
                route: 'admin.settings.index',
                section: 'settings',
            },
        ],
    },
];
