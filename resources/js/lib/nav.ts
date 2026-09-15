import type { LucideIcon } from 'lucide-react';
import {
    CUSTOMER_TABS,
    EMPLOYEE_TABS,
    FINANCE_TABS,
    INVENTORY_TABS,
    PURCHASE_TABS,
    type SectionTab,
} from '@/Components/SectionTabs';
import {
    BarChart3,
    Boxes,
    FileText,
    LayoutDashboard,
    Package,
    Settings,
    ShoppingCart,
    Truck,
    UserCog,
    Users,
    Wallet,
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
        ],
    },
    {
        /* التقارير مجموعةٌ وحدها: بابٌ لا شاشةُ أرقام — تجمع ما تفرّق وتقود إليه */
        items: [{ label: 'التقارير', icon: BarChart3, route: 'admin.reports.index', section: 'reports' }],
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
