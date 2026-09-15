import { useEffect, useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    BellOff,
    BellRing,
    ChevronLeft,
    Download,
    FileText,
    Image as ImageIcon,
    Languages,
    Save,
    Percent,
    ShieldCheck,
    Store,
    Trash2,
    TriangleAlert,
    Upload,
} from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import Field, { Select } from '@/Components/Field';
import Toggle from '@/Components/Toggle';
import {
    PageActions,
    SettingsGroup,
    SettingsPage,
    SettingsSection,
    type SettingsWidth,
} from '@/Components/Settings';
import { Button } from '@/Components/ui/button';
import { Input, Textarea } from '@/Components/ui/input';
import CustomAlerts, {
    type AlertMetric,
    type CustomAlertRow,
} from './partials/CustomAlerts';
import { SETTINGS_NAV } from './partials/SettingsNav';
import BackToSettings from './partials/BackToSettings';
import BranchesPanel from './panels/BranchesPanel';
import EmployeesPanel, { type JobTitle } from './panels/EmployeesPanel';
import ActivityPanel, { type ActivityData } from './panels/ActivityPanel';
import TrashPanel, { type TrashData } from './panels/TrashPanel';
import ChartPanel, { type ChartData } from './panels/ChartPanel';
import RecoveryEmailSection, { type Recovery } from './panels/RecoveryEmailSection';
import { useConfirm } from '@/Components/ConfirmDialog';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import type { Branch, Employee } from '@/types/models';

type Settings = Record<string, string>;

interface NotificationRow {
    key: string;
    text: string;
    time?: string;
    icon?: string;
    color?: string;
    url?: string;
}

interface Props {
    settings: Settings;
    business: { name: string; phone: string | null; email: string | null; address: string | null; logo: string | null };
    recovery: Recovery;
    /** أيصل البريد فعلًا — وسببُ التعذّر إن لم يصل (انظر App\Support\Mailer) */
    mail: { deliverable: boolean; reason: string | null };
    /** بطاقاتُ القوالب — تُبنى من `DocumentTemplates` لا تُكتب في الشاشة */
    templates?: { key: string; label: string; desc: string; section: string }[];
    notificationsAll: NotificationRow[];
    customAlerts: CustomAlertRow[];
    staffPermissions: { id: number; name: string; job_title: string; manual: boolean; count: number }[];
    alertMetrics: AlertMetric[];
    alertSections: Record<string, string>;
    locale: string;
    /* لا تصل إلا حين يُطلب قسمها في الرابط — انظر settingsSection في PageController */
    branches?: Branch[];
    employees?: Employee[];
    jobTitles?: JobTitle[];
    logs?: ActivityData['logs'];
    pagination?: ActivityData['pagination'];
    filters?: ActivityData['filters'];
    products?: TrashData['products'];
    trashedBranches?: TrashData['trashedBranches'];
    customers?: TrashData['customers'];
    expenses?: TrashData['expenses'];
    windowDays?: number;
    accounts?: ChartData['accounts'];
    trial?: ChartData['trial'];
    types?: ChartData['types'];
}

/**
 * أقسام الإعدادات معروضةً كلوحة بطاقات مستطيلة أفقية.
 *
 * كانت شريطًا أفقيًّا من ستة عشر تبويبًا ثم عمودًا جانبيًّا؛ صارت اللوحة
 * تُظهرها كلّها دفعةً واحدة، كلٌّ في بطاقةٍ تحمل أيقونتها واسمها ووصفًا
 * قصيرًا تحته — فيُعرف ما وراء القسم قبل فتحه. والنقر على بطاقةٍ يفتح قسمها
 * مكان اللوحة، ويعود إليها زرُّ رجوع.
 */
const NAV = SETTINGS_NAV;

type TabKey = (typeof NAV)[number]['items'][number]['key'];

/**
 * عرضُ كلِّ قسمٍ بحسب ما فيه — لا سقفٌ واحد للجميع.
 *
 * ═══ لماذا ═══
 *
 * كان القسم المفتوح يأخذ عرض اللوحة كلَّه (١٦٠٠ بكسل): نموذجٌ حقلاه حقلان
 * يمتدّ حتى تقع التسمية في طرفٍ وقيمتُها في الطرف الآخر، والعينُ تقطع
 * الشاشة عرضًا لتقرأ سطرًا. وكُتب حينها أنّ سقفًا للإعدادات وحدها «يجعلها
 * أضيق من كلّ ما جاورها» — وهو صحيحٌ لو كان السقف على الصفحة. والسقفُ هنا
 * على **النموذج** لا على الصفحة: الجداولُ والشجرةُ والسجلّ تبقى على عرض
 * اللوحة كما كانت، لأنّ فيها ما يحتاجه.
 *
 * وما ليس في الخريطة يبقى بلا سقف — القاعدة الافتراضية هي القديمة.
 */
const SECTION_WIDTH: Partial<Record<TabKey, SettingsWidth>> = {
    business: 'form',
    finance: 'form',
    permissions: 'form',
    templates: 'form',
    notifications: 'form',
    'notifications-log': 'form',
    backup: 'form',
};

/**
 * أقسام «النظام» — بياناتها تُحسب على الخادم، فتُطلب في الرابط
 * (?section=branches) لا في المرساة التي لا تصل إليه أصلًا.
 */
/** صفحةٌ واحدة فارغة — تُستعمل قبل وصول بيانات السجلّ لا كحالةٍ دائمة */
const EMPTY_PAGINATION: ActivityData['pagination'] = {
    current_page: 1,
    last_page: 1,
    from: 0,
    to: 0,
    total: 0,
    prev_page_url: null,
    next_page_url: null,
};

const SERVER_TABS = ['branches', 'employees', 'activity', 'trash', 'chart'] as const;

/** مفاتيح كل الأقسام التي تُفتح داخل هذه الصفحة — وهي اليوم كلّها. */
const TAB_KEYS = NAV.flatMap((g) => g.items.map((i) => i.key)) as readonly TabKey[];

/**
 * القسم المطلوب من عنوان الصفحة.
 *
 * موضعان: `?section=branches` لأقسام النظام لأن الخادم يحتاج أن يقرأه،
 * و`#finance` لبقيّتها. وكلاهما يُفتح مباشرةً، فرابطٌ مثل «أضِف الرقم الضريبي
 * من الإعدادات» في صفحة الضريبة يُنزل المستخدم في قسم الضريبة لا في اللوحة.
 *
 * والمعامل يُقدَّم على المرساة: هو وحده يصل الخادم، فلو غلبته المرساة لعُرض
 * قسمٌ بلا البيانات التي يحتاجها.
 *
 * والقيمة الغريبة تعود إلى اللوحة (null) بدل أن تُظهر قسمًا فارغًا.
 */
function tabFromUrl(): TabKey | null {
    if (typeof window === 'undefined') return null;
    const url = new URL(window.location.href);
    const key = url.searchParams.get('section') || url.hash.replace(/^#/, '');
    return (TAB_KEYS as readonly string[]).includes(key) ? (key as TabKey) : null;
}

/** طرق الدفع المتاحة — المفاتيح تطابق ما كان يحفظه القالب السابق (pay_*) */
const PAYMENT_METHODS = [
    { key: 'pay_cash', label: 'نقدي', hint: 'الدفع النقدي عند الشراء' },
    { key: 'pay_card', label: 'بطاقة (فيزا)', hint: 'الدفع عبر بطاقات الصراف والائتمان' },
    { key: 'pay_transfer', label: 'تحويل بنكي', hint: 'التحويل المباشر للحساب البنكي' },
] as const;

/** صفحات مستقلة يصل إليها المستخدم من الإعدادات */

const NOTIF_COLORS: Record<string, string> = {
    danger: 'bg-[#fef2f2] text-[#dc2626]',
    warning: 'bg-[#fffbeb] text-[#d97706]',
    info: 'bg-[#eff6ff] text-[#2563eb]',
    success: 'bg-[#f0fdf4] text-[#16a34a]',
};

export default function SettingsIndex() {
    const { settings, business, recovery, mail, templates, notificationsAll, customAlerts, alertMetrics,
        alertSections, staffPermissions, locale, branches, employees, jobTitles, logs, pagination, filters, products,
        expenses, customers: trashedCustomers, trashedBranches, windowDays, accounts, trial, types } =
        usePage<PageProps<Props>>().props;
    const { auth } = usePage<PageProps>().props;
    const t = useTranslate();
    // نافذةُ التأكيد من النظام لا من المتصفّح — انظر ConfirmDialog
    const [ask, confirmDialog] = useConfirm();
    const abilities = auth?.abilities ?? [];
    // القسمُ مكتوبٌ مع البند لا هنا: مفتاحٌ يُسمّى في شرطٍ كان ينسى أخاه
    const visible = (item: { key: string; section?: string }) =>
        !item.section || abilities.includes(item.section);

    const [tab, setTab] = useState<TabKey | null>(tabFromUrl);

    /*
     * المرساة تُقرأ عند كل تنقّل لا عند أوّل تركيبٍ وحده.
     *
     * الصفحة تُقرأ مرّةً ثم تبقى، فرابطٌ إلى ‎#business‎ من داخلها كان يغيّر
     * العنوان ولا يغيّر المعروض: لا تركيب جديد فلا قراءة جديدة. ومن ضغط
     * الزرّ وهو واقفٌ على الإعدادات لا يرى شيئًا يتحرّك.
     */
    /*
     * وما لا يُعرض لا يُفتح ولو كُتب في العنوان.
     *
     * إخفاء البطاقة يمنع النقر لا الكتابة: من يعرف `#chart` يكتبها. والخادم
     * لا يرسل بياناتها له، فتُرسم شجرةٌ فارغة تقول «لا حسابات» — وهي كذبة:
     * الحسابات موجودة وهو لا يملك رؤيتها.
     */
    useEffect(() => {
        if (tab === 'chart' && !abilities.includes('finance')) {
            goHub();
        }
    }, [tab, abilities]);

    useEffect(() => {
        const sync = () => setTab(tabFromUrl());
        window.addEventListener('hashchange', sync);
        const off = router.on('navigate', sync);

        return () => {
            window.removeEventListener('hashchange', sync);
            off();
        };
    }, []);
    const [pickedLocale, setPickedLocale] = useState(locale === 'en' ? 'en' : 'ar');
    const [backupFile, setBackupFile] = useState<File | null>(null);
    const [notifs, setNotifs] = useState<NotificationRow[]>(notificationsAll ?? []);

    const pick = (key: string) => {
        const tabKey = key as TabKey;

        /*
         * أقسام «النظام» بياناتها عند الخادم، فيُطلب القسم في الرابط.
         *
         * preserveState: النموذج المفتوح وحقوله لا تُمحى بزيارةٍ لجلب جدول.
         * وreplace: التنقّل بين الأقسام ليس تصفّحًا يستحقّ أن يمتلئ به زرّ
         * الرجوع — وهو ما تفعله المرساة في بقيّة الأقسام.
         */
        if ((SERVER_TABS as readonly string[]).includes(key)) {
            router.get(route('admin.settings.index'), { section: key }, {
                preserveState: true,
                preserveScroll: false,
                replace: true,
                onSuccess: () => setTab(tabKey),
            });

            return;
        }

        setTab(tabKey);
        /*
         * العنوان يتبع القسم المفتوح، فيبقى قابلًا للنسخ والمشاركة وإعادة
         * التحميل. replaceState لا pushState: التنقّل بين الأقسام ليس تصفّحًا
         * يستحقّ أن يمتلئ به زرّ الرجوع.
         *
         * و`?section=` يُمحى معه، ولا يُكتفى بتبديل المرساة.
         *
         * `tabFromUrl` يقدّم المعامل على المرساة — فمن فتح «شجرة الحسابات»
         * (‏?section=chart‎) ثمّ عاد إلى «بيانات النشاط» صار عنوانه
         * ‎?section=chart#business‎: يقرأ صحيحًا الآن، فإذا حفظ عاد الخادم
         * بـback() وقرأ المزامنُ المعاملَ فقفز به إلى الشجرة. يُحفظ ما كتب
         * ويجد نفسه في شاشةٍ أخرى، فيظنّ الحفظ ضاع.
         */
        window.history.replaceState(null, '', `${window.location.pathname}#${key}`);
        // القسم يحلّ محلّ اللوحة كاملةً، فنبدأ المستخدم من رأسه لا من موضع
        // البطاقة التي نقرها.
        window.scrollTo({ top: 0 });
    };

    /** العودة من قسمٍ إلى لوحة البطاقات، ومحو المرساة كي يعكس العنوان اللوحة. */
    const goHub = () => {
        setTab(null);
        window.history.replaceState(null, '', window.location.pathname);
        window.scrollTo({ top: 0 });
    };

    /*
     * بطاقةٌ لا تُعرض لمن لا يملك قسمها.
     *
     * «شجرة الحسابات» صلاحيتها «المالية»: تُفتح من هنا بمسار الإعدادات، فلا
     * يحرسها `CheckAbility` — يشتقّ القسم من اسم المسار وهو `settings`.
     * فيُحرس في الموضعين: الخادم لا يحسبها، والشاشة لا تعرض بابها. وإخفاء
     * الباب وحده تجميل، وحجبُ البيانات وحده يترك بطاقةً تفتح على فراغ.
     */
    const get = (k: string, fallback = '') => settings[k] ?? fallback;
    const on = (k: string, fallback = '1') => (settings[k] ?? fallback) === '1';

    const form = useForm({
        shop_name: business.name ?? '',
        phone: business.phone ?? '',
        email: business.email ?? '',
        address: business.address ?? '',

        vat_enabled: on('vat_enabled'),
        vat_rate: get('vat_rate', '5'),
        vat_number: get('vat_number'),
        vat_filed_through: get('vat_filed_through'),
        tax_mode: get('tax_mode', 'exclusive'),

        currency: get('currency', 'OMR'),
        decimals: get('decimals', '3'),
        symbol_pos: get('symbol_pos', 'after'),

        pay_cash: on('pay_cash'),
        pay_card: on('pay_card'),
        pay_transfer: on('pay_transfer'),

        /*
         * حُذف من هنا ما كان يُحفظ ولا يقرؤه سطرٌ واحد: مربّعات الصلاحيات
         * التسعة (perm_*)، وشعارُ الفاتورة المكرَّر (invoice_show_logo وله
         * توأمٌ حيّ هو tpl_show_logo)، وعدد النسخ والطباعة التلقائية وطباعة
         * التجهيز — والطباعة التلقائية تعمل فعلًا لكن من «الأجهزة» لكلّ
         * طابعة على حدة — وبادئة الطلب وحالته الافتراضية وتعديله وتأكيد
         * إلغائه، والتوصيل الثلاثة. المقبض الذي لا يُمسك أسوأ من غيابه.
         */
        inv_prefix: get('inv_prefix', 'INV-'),
        inv_start: get('inv_start', '1'),

        staff_sees_performance: on('staff_sees_performance'),

        notify_new_order: on('notify_new_order'),
        notify_smart_alerts: on('notify_smart_alerts'),
        notify_daily_summary: on('notify_daily_summary'),

        /*
         * لا نقاط ولا وردية هنا.
         *
         * الولاء أقسامُه حيّةٌ في التسويق ‹ برنامج ولاء بالمفاتيح نفسها ومعها
         * الأعضاء والنقاط — فبطاقتُه هنا كانت نسخةً ثانية لشيءٍ واحد، وتبديلُ
         * إحداهما يترك الأخرى تقول غير الواقع.
         *
         * ووردية الصندوق أُزيلت بطلب صاحب النظام، ومفتاحُها أُطفئ في ترحيلٍ
         * مرافق كي لا يبقى منعُ بيعٍ سارٍ بلا شاشةٍ تُطفئه.
         */

        /*
         * ولا حقلَ قالبٍ هنا بعد اليوم.
         *
         * كانت `tpl_*` و`paper` تُقرأ عند فتح الصفحة وتُرسل مع كلّ حفظ من
         * أيّ تبويب. فمن عدّل ورقته في محرّرها ثمّ حفظ «بيانات النشاط» أعاد
         * القيمَ التي قرأتها الشاشة قبل تعديله — يُنسَخ القديم فوق الجديد بلا
         * خطأ ولا رسالة، ولا يُكتشف إلّا على ورقٍ أمام زبون.
         *
         * ومحرّرُ القوالب يحفظها وحده الآن — انظر `DocumentTemplates`.
         */
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(route('admin.settings.update'), { preserveScroll: true });
    };

    /*
     * الشعار مسارٌ آخر، فحالتُه هنا لا في `siteForm`.
     *
     * والمعاينة فوريّة من الملفّ المختار لا بعد الحفظ: من يرفع شعارًا يريد
     * أن يراه قبل أن يعتمده، ورفعُ الخطأ ثم اكتشافُه على فاتورةٍ مطبوعة
     * أسوأ من نقرةٍ زائدة.
     */
    const [logoFile, setLogoFile] = useState<File | null>(null);
    const [logoPreview, setLogoPreview] = useState<string | null>(null);
    const [logoBusy, setLogoBusy] = useState(false);

    const pickLogo = (file: File | null) => {
        setLogoFile(file);
        setLogoPreview(file ? URL.createObjectURL(file) : null);
    };

    const sendLogo = (payload: { logo: File } | { remove: true }) => {
        setLogoBusy(true);
        router.post(route('admin.settings.logo'), payload, {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => {
                setLogoBusy(false);
                pickLogo(null);
            },
        });
    };

    /*
        قدمُ النموذج — وموضعُها تحت ما تحفظه لا في آخر الصفحة.

        كانت واحدةً في ذيل البطاقة الكبرى مهما كان القسم، فيقف زرُّ «حفظ
        التغييرات» تحت «لغة النظام» ولزرِّها حفظُه الخاص — زرّان متجاوران
        يحفظ كلٌّ منهما غير ما يحفظ الآخر، ولا شيء يقول ذلك.
    */
    const saveBar = (
        <PageActions className="mt-6">
            <Button type="submit" loading={form.processing}>
                <Save />
                {t('حفظ التغييرات')}
            </Button>
        </PageActions>
    );

    return (
        <AdminLayout title="الإعدادات">
            <PageHeader
                title="الإعدادات"
                subtitle={t('إعدادات المتجر والضرائب والفواتير والإشعارات')}
            />

            {tab === null ? (
                /* لوحة البطاقات — كل قسمٍ بطاقةٌ مستطيلة أفقية: أيقونته ثم
                   اسمه ووصفٌ خافتٌ تحته. النقر يفتح القسم مكان اللوحة.
                   ولا سقف عرضٍ هنا: السقف في AdminLayout يشمل الصفحات كلّها،
                   وسقفٌ ثانٍ فوقه كان يجعل الإعدادات أضيق من كل ما جاورها. */
                <div className="space-y-8">
                    {NAV.map((g) => (
                        <section key={g.group}>
                            <h3 className="mb-3 text-[13px] font-semibold text-[#6b7280]">{t(g.group)}</h3>
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                {g.items.filter(visible).map((x) => {
                                    const body = (
                                        <>
                                            <span className="flex size-[52px] shrink-0 items-center justify-center rounded-[14px] bg-[#f5f5f4] text-[#111] transition-colors group-hover:bg-[#111] group-hover:text-white">
                                                <x.icon className="size-6" />
                                            </span>
                                            <span className="min-w-0 flex-1">
                                                <span className="block text-[15px] font-semibold text-[#111]">{t(x.label)}</span>
                                                <span className="mt-1 block text-[13px] leading-snug text-[#9ca3af]">{t(x.desc)}</span>
                                            </span>
                                            <ChevronLeft className="size-4 shrink-0 text-[#d1d5db] transition-colors group-hover:text-[#6b7280]" />
                                        </>
                                    );
                                    const cls =
                                        'group flex items-center gap-4 rounded-[16px] border border-[var(--ui-border,#e8e8e8)] bg-white p-5 text-start transition hover:border-[#d4d4d4] hover:bg-[#fafafa]';
                                    // كل البطاقات تفتح قسمها هنا؛ لا واحدة تقفز بك إلى هيئةٍ أخرى
                                    return (
                                        <button key={x.key} type="button" onClick={() => pick(x.key)} className={cls}>
                                            {body}
                                        </button>
                                    );
                                })}
                            </div>
                        </section>
                    ))}
                </div>
            ) : (
                /* قسمٌ مفتوح — عرضُه من وظيفته: انظر SECTION_WIDTH.
                   min-w-0: يمنع جدولًا عريضًا من دفع الحاوية فتتجاوز الشاشة. */
                <div className="min-w-0 scroll-mt-4">
                    {/* زرٌّ لا رابط: القسم يتبدّل هنا في مكانه بلا تنقّل */}
                    <BackToSettings as="button" onClick={goHub} />
                    <SettingsPage width={SECTION_WIDTH[tab] ?? 'full'}>
            {tab === 'chart' ? (
                <ChartPanel accounts={accounts ?? []} trial={trial ?? { total_debit: 0, total_credit: 0, balanced: true }} types={types ?? []} />
            ) : tab === 'custom-alerts' ? (
                <CustomAlerts
                    alerts={customAlerts ?? []}
                    metrics={alertMetrics ?? []}
                    sections={alertSections ?? {}}
                />
            ) : tab === 'notifications-log' ? (
                <SettingsSection
                    title="التنبيهات المرسلة"
                    description="جميع التنبيهات التي أُرسلت إليك — مخزون منخفض وطلبات بانتظار التجهيز."
                    icon={BellRing}
                    status={
                        <span className="inline-flex items-center gap-1.5 rounded-full bg-[#f3f4f6] px-3 py-1 text-[12px] font-medium text-[#374151]">
                            <BellRing className="size-3.5" />
                            {notifs.length} {t('تنبيه')}
                        </span>
                    }
                    action={
                        notifs.length > 0 && (
                            <Button
                                type="button"
                                size="sm"
                                variant="danger"
                                onClick={async () => {
                                    if (! await ask({ message: 'حذف جميع التنبيهات المرسلة؟', danger: true, action: 'حذف' })) return;
                                    router.post(
                                        route('admin.notifications.clear'),
                                        {},
                                        { preserveScroll: true, onSuccess: () => setNotifs([]) },
                                    );
                                }}
                            >
                                <Trash2 />
                                {t('حذف الكل')}
                            </Button>
                        )
                    }
                >
                    {notifs.length === 0 ? (
                        <div className="py-12 text-center">
                            <BellOff className="mx-auto mb-3 size-8 text-[#d1d5db]" />
                            <p className="font-medium text-[#374151]">{t('لا توجد تنبيهات')}</p>
                            <p className="mt-1 text-[13px] text-[#9ca3af]">
                                {t('ستظهر هنا عند انخفاض المخزون أو ورود طلبات جديدة.')}
                            </p>
                        </div>
                    ) : (
                        <div className="divide-y divide-[var(--ui-border,#e8e8e8)]">
                            {notifs.map((n) => (
                                <div key={n.key} className="flex items-start gap-3 py-3">
                                    <span
                                        className={cn(
                                            'flex size-9 shrink-0 items-center justify-center rounded-full',
                                            NOTIF_COLORS[n.color ?? 'info'] ?? NOTIF_COLORS.info,
                                        )}
                                    >
                                        <BellRing className="size-[18px]" />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        {n.url ? (
                                            <Link href={n.url} className="text-sm text-[#111] hover:underline">
                                                {n.text}
                                            </Link>
                                        ) : (
                                            <p className="text-sm text-[#111]">{n.text}</p>
                                        )}
                                        {n.time && <p className="mt-0.5 text-[12px] text-[#9ca3af]">{n.time}</p>}
                                    </div>
                                    <button
                                        type="button"
                                        title={t('حذف')}
                                        onClick={() =>
                                            router.post(
                                                route('admin.notifications.dismiss'),
                                                { key: n.key },
                                                {
                                                    preserveScroll: true,
                                                    onSuccess: () =>
                                                        setNotifs((prev) => prev.filter((x) => x.key !== n.key)),
                                                },
                                            )
                                        }
                                        className="flex size-8 shrink-0 items-center justify-center rounded-full text-[#d1d5db] transition-colors hover:bg-[#fef2f2] hover:text-[#dc2626]"
                                    >
                                        <Trash2 className="size-4" />
                                    </button>
                                </div>
                            ))}
                        </div>
                    )}
                </SettingsSection>
            ) : tab === 'branches' ? (
                /* بياناته تصل مع الرابط؛ وغيابها يعني فتحًا بلا `?section` */
                <BranchesPanel branches={branches ?? []} />
            ) : tab === 'employees' ? (
                <EmployeesPanel employees={employees ?? []} jobTitles={jobTitles ?? []} />
            ) : tab === 'activity' ? (
                /* العنوان يُمرَّر: التصفية والتصفّح يعودان إلى الإعدادات لا
                   إلى الصفحة المستقلّة، وإلا قفز المستخدم عند أوّل «التالي» */
                <ActivityPanel
                    logs={logs ?? []}
                    pagination={pagination ?? EMPTY_PAGINATION}
                    filters={filters ?? {}}
                    endpoint={route('admin.settings.index')}
                    endpointParams={{ section: 'activity' }}
                />
            ) : tab === 'trash' ? (
                <TrashPanel
                    products={products ?? []}
                    expenses={expenses ?? []}
                    customers={trashedCustomers ?? []}
                    trashedBranches={trashedBranches ?? []}
                    windowDays={windowDays ?? 0}
                />
            ) : tab === 'backup' ? (
                <div className="space-y-6">
                    <SettingsSection
                        title="تنزيل نسخة احتياطية"
                        description="يشمل الملف كامل بيانات متجرك: المنتجات، الأقسام، العملاء، الطلبات، المصروفات وغيرها."
                        icon={Download}
                    >
                        <PageActions>
                            <Button asChild>
                                <a href={route('admin.backup.download')}>
                                    <Download />
                                    {t('تنزيل النسخة الآن')}
                                </a>
                            </Button>
                        </PageActions>
                    </SettingsSection>

                    <SettingsSection
                        title="استعادة من نسخة احتياطية"
                        description="ما في الملفّ يحلّ محلّ ما في متجرك — فاقرأ التحذير قبل أن تختار."
                        icon={Upload}
                    >
                        <p className="mb-4 flex items-start gap-2 rounded-[12px] bg-[#fef2f2] px-3 py-2.5 text-[12px] text-[#b91c1c]">
                            <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                            {t('تحذير: ستحل بيانات النسخة محل بيانات متجرك الحالية.')}
                        </p>
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                if (!backupFile) return;
                                router.post(
                                    route('admin.backup.restore'),
                                    { backup: backupFile },
                                    { forceFormData: true },
                                );
                            }}
                        >
                            <Field label="اختر ملف النسخة الاحتياطية (JSON)">
                                <Input
                                    type="file"
                                    accept=".json,application/json"
                                    onChange={(e) => setBackupFile(e.target.files?.[0] ?? null)}
                                    className="h-auto py-2 file:me-3 file:rounded-lg file:bg-[#111] file:px-4 file:py-2 file:text-white"
                                />
                            </Field>
                            <PageActions className="mt-4">
                                <Button type="submit" variant="danger" disabled={!backupFile}>
                                    <Upload />
                                    {t('استعادة البيانات')}
                                </Button>
                            </PageActions>
                        </form>
                    </SettingsSection>
                </div>
            ) : (
                /*
                    نموذجٌ واحد وأقسامٌ عدّة — لا بطاقةٌ كبرى واحدة.

                    كانت البطاقة الواحدة تحمل القسم كلَّه وتفصل ما فيه بخطوطٍ
                    أفقيّة: بياناتُ النشاط ثمّ الشعار ثمّ بريد الاستعادة ثمّ
                    اللغة — أربعةُ مواضيعَ في صندوقٍ واحد لا يُرى أوّلُه من
                    آخره على شاشةٍ صغيرة. والحقولُ هي هي، والحفظُ هو هو —
                    وإنّما صار لكلّ موضوعٍ سطحُه.
                */
                <form onSubmit={submit} className="space-y-6">
                        {tab === 'business' && (
                            <>
                                <SettingsSection
                                    title="بيانات النشاط"
                                    description="ما يُطبع في رأس فواتيرك وإيصالاتك — واسمُ متجرك أوّلُه."
                                    icon={Store}
                                >
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <Field label="اسم المتجر" required error={form.errors.shop_name}>
                                        <Input value={form.data.shop_name} onChange={(e) => form.setData('shop_name', e.target.value)} required />
                                    </Field>
                                    <Field label="رقم الهاتف" error={form.errors.phone}>
                                        <Input dir="ltr" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} />
                                    </Field>
                                    <Field label="البريد الإلكتروني" error={form.errors.email}>
                                        <Input type="email" dir="ltr" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                                    </Field>
                                    <Field label="العنوان" className="sm:col-span-2" error={form.errors.address}>
                                        <Textarea rows={2} value={form.data.address} onChange={(e) => form.setData('address', e.target.value)} />
                                    </Field>
                                </div>

                                {saveBar}
                                </SettingsSection>

                                {/*
                                    الشعار مع بيانات النشاط لا في بطاقةٍ وحده:
                                    هو ما يُطبع على الفاتورة والإيصال، مع الاسم
                                    والهاتف والعنوان التي تُطبع معه.
                                */}
                                <SettingsSection
                                    title="الشعار"
                                    description="يظهر في الفواتير والإيصالات — وقالب فاتورة البيع يُظهره أو يُخفيه."
                                    icon={ImageIcon}
                                >
                                    <div className="flex flex-wrap items-center gap-5">
                                        <span className="flex size-24 shrink-0 items-center justify-center overflow-hidden rounded-[14px] border border-[var(--ui-border,#e8e8e8)] bg-[#fafafa]">
                                            {logoPreview || business.logo ? (
                                                <img
                                                    src={logoPreview ?? business.logo ?? ''}
                                                    alt=""
                                                    className="size-full object-contain"
                                                />
                                            ) : (
                                                <ImageIcon className="size-8 text-[#d1d5db]" />
                                            )}
                                        </span>

                                        <div className="min-w-0 flex-1">
                                            <Input
                                                type="file"
                                                accept="image/png,image/jpeg,image/webp,image/svg+xml"
                                                onChange={(e) => pickLogo(e.target.files?.[0] ?? null)}
                                                className="h-auto py-2 file:me-3 file:rounded-lg file:bg-[#111] file:px-4 file:py-2 file:text-white"
                                            />
                                            <p className="mt-2 text-[12px] text-[#9ca3af]">
                                                {t('أفضل مقاس: 400×100 بكسل · PNG بخلفيّة شفّافة · حتّى ٢ ميغابايت')}
                                            </p>

                                            <div className="mt-3 flex flex-wrap gap-2">
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    disabled={!logoFile}
                                                    loading={logoBusy && !!logoFile}
                                                    onClick={() => logoFile && sendLogo({ logo: logoFile })}
                                                >
                                                    <Upload />
                                                    {t('رفع الشعار')}
                                                </Button>
                                                {business.logo && (
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        loading={logoBusy && !logoFile}
                                                        onClick={() => sendLogo({ remove: true })}
                                                    >
                                                        <Trash2 />
                                                        {t('حذف الشعار')}
                                                    </Button>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                </SettingsSection>

                                {/*
                                    بريد الاستعادة — من جنس ما فوقه: بيانٌ
                                    يُضبط مرّةً عند التجهيز. ومكانُه هنا لا في
                                    صفحةٍ جديدة، ولا يراه صاحبه إلا حيث يضبط
                                    اسم متجره وهاتفه.
                                */}
                                <RecoveryEmailSection recovery={recovery} />

                                {/*
                                    اللغة هنا لا في بطاقةٍ وحدها.

                                    مفتاحٌ واحد بين خيارين كان يشغل بطاقةً في اللوحة
                                    وصفحةً كاملة، وهو أوّل ما يُضبط مع اسم المتجر
                                    وعنوانه ثمّ لا يُفتح مرّةً أخرى.

                                    ويُحفظ بزرّه لا بزرّ النموذج: مساره آخر
                                    (`admin.language.update`)، واتجاه المستند يُحسم
                                    في قالب الجذر فتلزم إعادة تحميلٍ بعده — وجمعُهما
                                    في زرٍّ واحد يعيد تحميل الصفحة على كل حفظ اسم.
                                */}
                                <SettingsSection
                                    title="لغة النظام"
                                    description="اتجاهُ الواجهة يتبع اللغة — ويُحفظ بزرّه وحده، لأنّ الصفحة تُعاد بعده."
                                    icon={Languages}
                                >
                                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        {[
                                            { code: 'ar', label: 'العربية', hint: 'من اليمين إلى اليسار (RTL)' },
                                            { code: 'en', label: 'English', hint: 'من اليسار إلى اليمين (LTR)' },
                                        ].map((l) => (
                                            <label
                                                key={l.code}
                                                className={cn(
                                                    'flex cursor-pointer items-center justify-between rounded-[12px] border px-4 py-3.5 transition',
                                                    pickedLocale === l.code
                                                        ? 'border-[#111] bg-[#fafafa]'
                                                        : 'border-[var(--ui-border,#e8e8e8)] hover:bg-[#fafafa]',
                                                )}
                                            >
                                                <span>
                                                    <span className="block text-sm font-medium text-[#111]">{l.label}</span>
                                                    <span className="block text-[12px] text-[#9ca3af]">{t(l.hint)}</span>
                                                </span>
                                                <input
                                                    type="radio"
                                                    name="locale"
                                                    checked={pickedLocale === l.code}
                                                    onChange={() => setPickedLocale(l.code)}
                                                    className="size-5"
                                                />
                                            </label>
                                        ))}
                                    </div>
                                    <PageActions className="mt-4">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            disabled={pickedLocale === locale}
                                            onClick={() =>
                                                router.post(
                                                    route('admin.language.update'),
                                                    { locale: pickedLocale },
                                                    { onSuccess: () => window.location.reload() },
                                                )
                                            }
                                        >
                                            <Languages />
                                            {t('حفظ اللغة')}
                                        </Button>
                                    </PageActions>
                                </SettingsSection>
                            </>
                        )}

                        {/*
                            المالية صفحةٌ واحدة لا ثلاث.

                            الضريبة والعملة وطرق الدفع كانت ثلاث بطاقاتٍ وثلاث
                            صفحات، وهي تُضبط في جلسةٍ واحدة عند تجهيز المتجر ثمّ
                            لا تُفتح إلا نادرًا. وثلاثُ نقراتٍ لثمانية حقول
                            تُفرّق ما يُقرأ معًا: نسبة الضريبة بلا خانات العملة
                            العشرية نصفُ الجواب.
                        */}
                        {tab === 'finance' && (
                            <>
                                <SettingsSection
                                    title="الضرائب والعملة والدفع"
                                    description="ثلاثةٌ تُضبط في جلسةٍ واحدة عند تجهيز المتجر — وحفظةٌ واحدة تكتبها كلَّها."
                                    icon={Percent}
                                    divided
                                >
                                <SettingsGroup title="الضرائب">
                                <Toggle
                                    on={form.data.vat_enabled}
                                    onChange={(v) => form.setData('vat_enabled', v)}
                                    label="تفعيل ضريبة القيمة المضافة"
                                    hint="تُحتسب على كل فاتورة بيع"
                                />
                                <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <Field label="نسبة الضريبة (%)" error={form.errors.vat_rate}>
                                        <Input type="number" step="0.01" min="0" dir="ltr" value={form.data.vat_rate} onChange={(e) => form.setData('vat_rate', e.target.value)} />
                                    </Field>
                                    <Field label="الرقم الضريبي (TRN)" error={form.errors.vat_number}>
                                        <Input dir="ltr" value={form.data.vat_number} onChange={(e) => form.setData('vat_number', e.target.value)} placeholder="OM1100XXXXXX" />
                                    </Field>
                                    <Field
                                        label="آخر إقرار قُدِّم"
                                        hint="آخرُ يومٍ دخل في إقرارٍ سلّمتَه. وفواتيرُ ما قبله لا تُلغى — الورقةُ سُلِّمت، وتصحيحُها بإشعارِ دائنٍ في فترةٍ مفتوحة. اتركه فارغًا إن لم تقدّم بعد."
                                        error={form.errors.vat_filed_through}
                                    >
                                        <Input
                                            type="date"
                                            dir="ltr"
                                            value={form.data.vat_filed_through}
                                            onChange={(e) => form.setData('vat_filed_through', e.target.value)}
                                        />
                                    </Field>
                                    <Field label="طريقة الاحتساب" error={form.errors.tax_mode}>
                                        <Select
                                            value={form.data.tax_mode}
                                            onChange={(e) => form.setData('tax_mode', e.target.value)}
                                            options={[
                                                { label: 'تُضاف على السعر', value: 'exclusive' },
                                                { label: 'مشمولة في السعر', value: 'inclusive' },
                                            ]}
                                        />
                                    </Field>
                                </div>

                                </SettingsGroup>

                                <SettingsGroup title="العملة">
                                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <Field label="العملة" error={form.errors.currency}>
                                            <Input dir="ltr" value={form.data.currency} onChange={(e) => form.setData('currency', e.target.value)} />
                                        </Field>
                                        <Field label="عدد الخانات العشرية" error={form.errors.decimals}>
                                            <Select
                                                value={form.data.decimals}
                                                onChange={(e) => form.setData('decimals', e.target.value)}
                                                options={[0, 1, 2, 3].map((n) => ({ label: String(n), value: n }))}
                                            />
                                        </Field>
                                        <Field label="موضع الرمز" error={form.errors.symbol_pos}>
                                            <Select
                                                value={form.data.symbol_pos}
                                                onChange={(e) => form.setData('symbol_pos', e.target.value)}
                                                options={[
                                                    { label: 'بعد المبلغ', value: 'after' },
                                                    { label: 'قبل المبلغ', value: 'before' },
                                                ]}
                                            />
                                        </Field>
                                    </div>
                                </SettingsGroup>

                                <SettingsGroup title="طرق الدفع">
                                    <div className="divide-y divide-[var(--ui-border,#e8e8e8)]">
                                        {PAYMENT_METHODS.map((m) => (
                                            <div key={m.key} className="py-2 first:pt-0 last:pb-0">
                                                <Toggle
                                                    on={form.data[m.key]}
                                                    onChange={(v) => form.setData(m.key, v)}
                                                    label={m.label}
                                                    hint={m.hint}
                                                />
                                            </div>
                                        ))}
                                    </div>
                                </SettingsGroup>
                                </SettingsSection>

                                {saveBar}
                            </>
                        )}

                        {tab === 'permissions' && (
                            <>
                                <SettingsSection
                                    title="صلاحيات الموظفين"
                                    description="الصلاحية تُحدَّد لكل موظف على حدة من ملفه — ولا قسم يُفتح ما لم يُمنح."
                                    icon={ShieldCheck}
                                >
                                {/*
                                    ما يراه الموظّف عن نفسه — لا ما يفتحه من أقسام.
                                    وموضعُه هنا لأنّ صاحبه واحد: من يضبط صلاحيات
                                    موظّفيه هو من يقرّر ما يقرؤونه عن أنفسهم.
                                */}
                                <Toggle
                                    on={form.data.staff_sees_performance}
                                    onChange={(v) => form.setData('staff_sees_performance', v)}
                                    label="أظهِر للموظّف أداءه في «حسابي»"
                                    hint="مبيعاتُه اليوم والشهر وإجمالي طلباته. وراتبه يراه دائمًا."
                                />

                                {/*
                                    كان هنا جدول مربّعات لكل دور يحفظ مفاتيح perm_*
                                    لا يقرؤها أي كود: تُبدَّل فلا يتغيّر شيء. استُبدل
                                    بقائمة الموظفين الفعليين وحالة صلاحية كلٍّ منهم،
                                    ومنها يُفتح ملفه حيث تُحدَّد فعلًا.
                                */}
                                {(staffPermissions ?? []).length === 0 ? (
                                    <p className="py-8 text-center text-[13px] text-[#9ca3af]">
                                        {t('لا يوجد موظفون بعد')}
                                    </p>
                                ) : (
                                    <ul className="space-y-2">
                                        {(staffPermissions ?? []).map((e) => (
                                            <li
                                                key={e.id}
                                                className="flex items-center gap-3 rounded-[12px] border border-[var(--ui-border,#e8e8e8)] p-3"
                                            >
                                                <div className="min-w-0 flex-1">
                                                    <p className="truncate text-sm font-medium text-[#111]">{e.name}</p>
                                                    <p className="truncate text-[12px] text-[#9ca3af]">
                                                        {e.job_title} ·{' '}
                                                        {e.manual
                                                            ? t(':n قسم مخصّص', { n: e.count })
                                                            : t('لم تُحدَّد بعد')}
                                                    </p>
                                                </div>
                                                <Button variant="outline" size="sm" asChild>
                                                    <Link href={route('admin.employees.edit', e.id)}>
                                                        {t('تحديد الصلاحيات')}
                                                    </Link>
                                                </Button>
                                            </li>
                                        ))}
                                    </ul>
                                )}

                                {saveBar}
                                </SettingsSection>
                            </>
                        )}

                        {tab === 'templates' && (
                            <>
                                {/*
                                    بطاقةٌ لكلّ ورقة — والتحرير في صفحتها لا هنا.

                                    كان القالبُ واحدًا يحكم ورقةَ البيع وحدها،
                                    وسائرُ أوراق النظام لا تُطبع أصلًا: أمرُ
                                    شراءٍ يُرسل إلى مورّد، وسندُ استلامٍ يُوقَّع
                                    عند الباب، وسندُ نقلٍ يمشي مع البضاعة بين
                                    فرعين. فما في النظام لا يُثبت شيئًا عند خلاف.

                                    والمعاينةُ انتقلت معها إلى صفحةٍ تسعُها:
                                    صندوقٌ بعرض مئتي بكسل بجانب عشرين حقلًا لا
                                    يُرى فيه شكل ورقة.
                                */}
                                <SettingsSection
                                    title="قوالب الأوراق"
                                    description="لكل ورقةٍ في النظام قالبٌ يُحرَّر وحده — اختر ورقةً لتفتح محرّرها ومعاينتها."
                                    icon={FileText}
                                >
                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    {(templates ?? []).map((x) => (
                                        <Link
                                            key={x.key}
                                            href={route('admin.settings.templates.edit', x.key)}
                                            className="group flex items-center gap-4 rounded-[16px] border border-[var(--ui-border,#e8e8e8)] bg-white p-5 text-start transition hover:border-[#d4d4d4] hover:bg-[#fafafa]"
                                        >
                                            <FileText className="size-5 shrink-0 text-[#9ca3af]" />
                                            <span className="min-w-0 flex-1">
                                                <span className="block text-[15px] font-semibold text-[#111]">{x.label}</span>
                                                <span className="mt-1 block text-[13px] leading-snug text-[#9ca3af]">{x.desc}</span>
                                                <span className="mt-1 block text-[11px] text-[#d1d5db]">{x.section}</span>
                                            </span>
                                            <ChevronLeft className="size-4 shrink-0 text-[#d1d5db] transition-colors group-hover:text-[#6b7280]" />
                                        </Link>
                                    ))}
                                </div>

                                {/*
                                    والترقيم يبقى هنا: بادئةُ الرقم وأوّلُه ليسا
                                    شكلَ ورقةٍ بعينها بل ترقيمُ الفواتير كلّها،
                                    ووضعُهما في محرّر ورقةٍ واحدة يجعل تعديلَهما
                                    من ورقةٍ يغيّر ما تطبعه أخرى بلا أن يقول.
                                */}
                                </SettingsSection>

                                <SettingsSection
                                    title="ترقيم الفواتير"
                                    description="بادئةُ الرقم وأوّلُه ليسا شكلَ ورقةٍ بعينها بل ترقيمُ الفواتير كلّها."
                                >
                                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <Field label="بادئة رقم الفاتورة" error={form.errors.inv_prefix}>
                                            <Input dir="ltr" value={form.data.inv_prefix} onChange={(e) => form.setData('inv_prefix', e.target.value)} />
                                        </Field>
                                        <Field
                                            label="رقم البداية"
                                            error={form.errors.inv_start}
                                            hint="يسري على أوّل فاتورةٍ بالبادئة الجديدة، ولا يمسّ ما صدر"
                                        >
                                            <Input type="number" min="1" dir="ltr" value={form.data.inv_start} onChange={(e) => form.setData('inv_start', e.target.value)} />
                                        </Field>
                                    </div>
                                    <p className="mt-3 text-[11px] leading-relaxed text-[#9ca3af]">
                                        {t('الطباعة التلقائية بعد البيع تُضبط لكل طابعة من «الأجهزة» — لأن الصندوق الذي فيه طابعة يطبع، وغيره لا.')}
                                    </p>

                                    {saveBar}
                                </SettingsSection>
                            </>
                        )}

                        {tab === 'notifications' && (
                            <>
                                <SettingsSection
                                    title="الإشعارات"
                                    description="ما يصلك على بريدك — والمقبضُ قرارُك، ووصولُ البريد شرطٌ يُقال أعلاه إن تعذّر."
                                    icon={BellRing}
                                >
                                {/*
                                    والحقيقةُ فوق المفاتيح لا بعد شهرٍ من صمتها.

                                    الثلاثةُ تَعِد صراحةً بأنّ بريدًا يصل. و`Mail::send`
                                    تنجح مهما كان المُرسِل، فمن كان مُرسِلُه `log` كُتبت
                                    رسائلُه في ملفٍّ على الخادم ولم تغادره — بلا خطأ ولا
                                    أثر. والمفتاحُ يبقى يُحفظ: الإعدادُ اختيارُ صاحبه.
                                */}
                                {mail.reason && (
                                    <div className="mb-4 flex flex-wrap items-center gap-2 rounded-[12px] border border-[#fde68a] bg-[#fffbeb] p-3 text-[13px] text-[#92400e]">
                                        <TriangleAlert className="size-4 shrink-0" />
                                        <span>{t(mail.reason)}</span>
                                    </div>
                                )}
                                <Toggle
                                    on={form.data.notify_new_order}
                                    onChange={(v) => form.setData('notify_new_order', v)}
                                    label="إرسال بريد إلكتروني عند كل طلب جديد"
                                    hint="يُرسل إلى بريد صاحب النشاط عند إتمام أي عملية بيع."
                                />
                                <Toggle
                                    on={form.data.notify_smart_alerts}
                                    onChange={(v) => form.setData('notify_smart_alerts', v)}
                                    label="التنبيهات الذكية"
                                    hint="نفاد المخزون، ركود المنتجات، وتغيّر الأداء."
                                />
                                <Toggle
                                    on={form.data.notify_daily_summary}
                                    onChange={(v) => form.setData('notify_daily_summary', v)}
                                    label="ملخّص الأداء اليومي"
                                    hint="يصل آخر اليوم بمبيعات اليوم وأبرز أرقامه."
                                />

                                {saveBar}
                                </SettingsSection>
                            </>
                        )}

                </form>
            )}
                    </SettingsPage>
                </div>
            )}

            {confirmDialog}
        </AdminLayout>
    );
}
