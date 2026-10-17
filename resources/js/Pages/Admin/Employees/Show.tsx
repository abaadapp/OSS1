import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import {
    Briefcase,
    Check,
    Contact,
    Crown,
    KeyRound,
    Lock,
    LockOpen,
    Mail,
    Pencil,
    ShieldAlert,
    ShieldCheck,
    TriangleAlert,
    Wallet,
} from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import BackLink from '@/Components/BackLink';
import PageHeader from '@/Components/PageHeader';
import CopyButton from '@/Components/CopyButton';
import StatCard from '@/Components/StatCard';
import Tabs from '@/Components/Tabs';
import SmartLink from '@/Components/SmartLink';
import ActivityFeed, { type ActivityItem } from '@/Components/ActivityFeed';
import AreaChart from '@/Components/charts/AreaChart';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { money, number } from '@/lib/format';
import { cn } from '@/lib/utils';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { Employee } from '@/types/models';
import type { PermissionGroup } from './partials/EmployeePermissions';

/** وصولُ الموظّف كما تصفه بطاقته — `EmployeeAccess::describe` */
interface Access {
    role: string;
    role_label: string;
    is_owner: boolean;
    mode: 'inherited' | 'custom';
    summary: { sections: number; actions: number; sensitive: number };
    /** ما يفتحه فعلًا في مجموعاته — والحسّاسُ مجموعةٌ وحدها */
    groups: PermissionGroup[];
}

interface Props {
    employee: Employee;
    orderCount: number;
    salesSeries: { labels: string[]; data: number[] };
    /*
     * ما يفتحه هذا الموظّف فعلًا — أسماءُ ما مُنح، لا خريطةُ نعم/لا ثابتة.
     *
     * كانت `Record<string, boolean>` تُملأ في الخادم بعشرة أسطرٍ مكتوبةٍ
     * باليد، متطابقةٍ لكلّ موظّف. انظر `PageController::employeesShow`.
     */
    permissions: string[];
    /** أمخصَّصةٌ بيدٍ أم تتبع وظيفته؟ — الفرقُ يُقال لا يُخمَّن */
    permissions_are_manual: boolean;
    /*
     * وهل يُمسّ حسابُ هذا الموظّف بيد من يقرأ بطاقته؟
     *
     * الحارسُ يردّ من يمسّ حسابًا يفتح أكثر ممّا يفتح — وأزرارُ «تعطيل»
     * و«إعادة تعيين كلمة المرور» و«تعديل» كانت تُرسم للجميع، فتُضغط فيُردّ
     * صاحبُها بـ٤٠٣ لا يقول شيئًا. انظر `Permissions::mayTouch`.
     */
    may_touch: boolean;
    activities: ActivityItem[];
    access: Access | null;
    /** فروعُ العمل المسجّلة — والفارغةُ «كل الفروع» */
    workBranches: string[];
    /** الراتبُ لمن يقرأ الرواتب وحده — و`null` لغيره، لا صفر */
    salary: { basic: number; allowances: number } | null;
}

/** بطاقةُ حقائق: عنوانٌ وأسطرُ «اسم — قيمة» */
function FactsCard({
    icon: Icon,
    title,
    rows,
    testId,
}: {
    icon: typeof Contact;
    title: string;
    rows: { label: string; value: React.ReactNode; ltr?: boolean }[];
    testId?: string;
}) {
    const t = useTranslate();

    return (
        <Card className="p-6" data-testid={testId}>
            <h3 className="mb-4 flex items-center gap-2 text-sm font-bold text-[#111]">
                <Icon className="size-4 text-[#6d28d9]" />
                {t(title)}
            </h3>
            <ul className="space-y-3 text-sm">
                {rows.map((r) => (
                    <li key={r.label} className="flex items-start justify-between gap-3">
                        <span className="shrink-0 text-[#6b7280]">{t(r.label)}</span>
                        <span dir={r.ltr ? 'ltr' : undefined} className="min-w-0 truncate text-end text-[#111]">
                            {r.value}
                        </span>
                    </li>
                ))}
            </ul>
        </Card>
    );
}

export default function EmployeeShow() {
    const { employee, orderCount, salesSeries, permissions, permissions_are_manual, may_touch, activities, access, workBranches, salary, context, flash } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const currency = context!.currency;
    const m = (v: number) => money(v, currency);

    const [tab, setTab] = useState<'sales' | 'activity' | 'permissions'>('sales');
    const [resetting, setResetting] = useState(false);
    /*
     * تُخفى بعد إغلاق النافذة ولو بقيت في الوميض: الوميض يعيش حتى الطلب
     * التالي، ولا يُترك رقمُ سرٍّ معروضًا على شاشةٍ في محلٍّ بعد أن قُرئ.
     */
    const [seen, setSeen] = useState(false);
    const issued = seen ? null : (flash?.password ?? null);

    const active = employee.status === 'نشط';

    const stats = [
        { label: t('إجمالي المبيعات'), value: m(employee.sales), icon: 'trending-up', color: 'success' },
        { label: t('عدد الطلبات'), value: String(orderCount), icon: 'shopping-bag', color: 'primary' },
        {
            label: t('متوسط الطلب'),
            value: m(orderCount > 0 ? employee.sales / orderCount : 0),
            icon: 'calculator',
            color: 'info',
        },
        {
            label: t('تحقيق الهدف هذا الشهر'),
            value: employee.target_pct === null || employee.target_pct === undefined ? '—' : `${number(employee.target_pct)}%`,
            icon: 'percent',
            color: 'warning',
        },
    ];

    const custom = access?.mode === 'custom' || permissions_are_manual;
    const modeLabel = custom ? t('صلاحيات مخصصة') : t('يتبع الوظيفة');

    return (
        <AdminLayout title={employee.name}>
            {/* الرجوع طريقٌ لا فعل — فخرج من صفّ الأزرار إلى فوق الترويسة */}
            <BackLink
                routeName="admin.employees.index"
                href={route('admin.employees.index')}
                label="الموظفون"
            />
            <PageHeader
                title="ملف الموظف"
                subtitle={t('عرض بيانات الموظف وأدائه وصلاحياته')}
                actions={
                    may_touch ? (
                        <Button asChild>
                            <SmartLink routeName="admin.employees.edit" href={route('admin.employees.edit', employee.id)}>
                                <Pencil />
                                {t('تعديل')}
                            </SmartLink>
                        </Button>
                    ) : undefined
                }
            />

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-1">
                    <Card className="p-6 text-center">
                        {employee.avatar ? (
                            <img
                                src={employee.avatar}
                                alt={employee.name}
                                className="mx-auto size-24 rounded-full object-cover ring-4 ring-[#f5f3ff]"
                            />
                        ) : (
                            <span className="mx-auto flex size-24 items-center justify-center rounded-full bg-[#f5f3ff] text-[28px] font-bold text-[#6d28d9]">
                                {employee.name.slice(0, 1)}
                            </span>
                        )}
                        <h2 className="mt-4 text-[17px] font-bold text-[#111]">{employee.name}</h2>

                        <div className="mt-2 flex flex-wrap items-center justify-center gap-2">
                            <Badge variant="primary">{t(employee.role)}</Badge>
                            <Badge status={employee.status}>{t(employee.status)}</Badge>
                            {access?.is_owner && (
                                <span className="inline-flex items-center gap-1 rounded-full bg-[#fffbeb] px-2.5 py-1 text-[12px] font-medium text-[#b45309]">
                                    <Crown className="size-3.5" />
                                    {t('صاحب النشاط')}
                                </span>
                            )}
                            {/*
                                حسابٌ بلا بريد لا باب له بعد رفع الدخول
                                بالرمز — والوسم يقوله هنا لا عند أوّل محاولةٍ
                                فاشلة يقف فيها الموظف أمام الشاشة.
                            */}
                            {!employee.email && (
                                <span
                                    title={t('أضِف بريدًا من «تعديل» ليستطيع الدخول')}
                                    className="inline-flex items-center gap-1 rounded-full bg-[#fef2f2] px-2.5 py-1 text-[12px] font-medium text-[#b91c1c]"
                                >
                                    <TriangleAlert className="size-3.5" />
                                    {t('بلا بريد — لا يستطيع الدخول')}
                                </span>
                            )}
                        </div>

                        <div className="mt-5 flex flex-wrap items-center justify-center gap-2">
                            <Button variant="outline" size="sm" asChild>
                                <a href={`mailto:${employee.email}`}>
                                    <Mail />
                                    {t('مراسلة')}
                                </a>
                            </Button>

                            {may_touch && (
                                <>
                                    <Button
                                        variant={active ? 'outline' : 'success'}
                                        size="sm"
                                        onClick={() =>
                                            router.post(route('admin.employees.toggle', employee.id), {}, { preserveScroll: true })
                                        }
                                    >
                                        {active ? <Lock /> : <LockOpen />}
                                        {active ? t('تعطيل الحساب') : t('تفعيل الحساب')}
                                    </Button>

                                    <Button variant="outline" size="sm" onClick={() => setResetting(true)}>
                                        <KeyRound />
                                        {t('إعادة تعيين كلمة المرور')}
                                    </Button>
                                </>
                            )}
                        </div>

                        {/* ومن لا يمسّه يُقال له لماذا — لا يُترك أمام فراغ */}
                        {! may_touch && (
                            <p className="mt-4 rounded-[10px] bg-[#fffbeb] p-3 text-center text-[12px] leading-relaxed text-[#b45309]">
                                {t('هذا الحساب يفتح ما لا تفتحه — لا يُعدَّل ولا تُغيَّر كلمة مروره إلا ممّن يملك ذلك.')}
                            </p>
                        )}
                    </Card>

                    <FactsCard
                        icon={Contact}
                        title="بيانات الموظف"
                        rows={[
                            { label: 'الهاتف', value: employee.phone || '—', ltr: true },
                            { label: 'تاريخ الالتحاق', value: employee.joined },
                            { label: 'رقم الموظف', value: `#${employee.id}` },
                        ]}
                    />

                    <FactsCard
                        icon={KeyRound}
                        title="الوصول والأمان"
                        rows={[
                            { label: 'بريد الدخول', value: employee.email || '—', ltr: true },
                            { label: 'حالة الحساب', value: t(employee.status) },
                            { label: 'ملف الصلاحيات الأساسي', value: access?.role_label ?? '—' },
                            { label: 'الصلاحيات', value: modeLabel },
                        ]}
                    />

                    <FactsCard
                        icon={Briefcase}
                        title="العمل والفروع"
                        rows={[
                            { label: 'المسمى الوظيفي', value: t(employee.role) },
                            { label: 'الفرع الأساسي للعرض', value: employee.branch || '—' },
                            { label: 'فروع العمل', value: workBranches.length > 0 ? workBranches.join('، ') : t('كل الفروع') },
                        ]}
                    />

                    {/* والراتبُ لمن يقرأ الرواتب وحده — الخادمُ لا يرسله لغيره */}
                    {salary && (
                        <FactsCard
                            icon={Wallet}
                            title="الراتب"
                            testId="salary-card"
                            rows={[
                                { label: 'الراتب الأساسي', value: m(salary.basic) },
                                { label: 'البدلات', value: m(salary.allowances) },
                            ]}
                        />
                    )}
                </div>

                <div className="space-y-6 lg:col-span-2">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        {stats.map((s, i) => (
                            <StatCard key={s.label} stat={s} index={i} />
                        ))}
                    </div>

                    <Card className="overflow-hidden">
                        {/* داخل بطاقة: الحشو يُبعد التبويبات عن حدّها */}
                        <Tabs
                            tabs={[
                                { key: 'sales', label: 'المبيعات' },
                                { key: 'activity', label: 'سجل النشاط' },
                                { key: 'permissions', label: 'الصلاحيات' },
                            ]}
                            current={tab}
                            onChange={(k) => setTab(k as 'sales' | 'activity' | 'permissions')}
                            className="px-5"
                        />

                        {tab === 'sales' && (
                            <div className="p-6">
                                <h4 className="mb-4 font-bold text-[#111]">
                                    {t('مبيعات الموظف هذه السنة')}
                                </h4>
                                <AreaChart labels={salesSeries.labels} data={salesSeries.data} format={m} />
                            </div>
                        )}

                        {tab === 'activity' && (
                            <div className="p-6">
                                <ActivityFeed items={activities} empty="لا يوجد نشاط لهذا الموظف بعد" />
                            </div>
                        )}

                        {/*
                            ═══ ما يفتحه هو — لا قائمةٌ ثابتة ═══

                            كانت هنا عشرةُ أسطرٍ مكتوبةٍ في الخادم باليد، تُعرض
                            لكلّ موظّفٍ على حدة ولا تُقرأ من صفّه: «إدارة
                            الموظفين: ممنوع» لمن يملكها. فيفتح صاحبُ النشاط
                            بطاقةَ موظّفه ليتحقّق فيقرأ ما ليس صحيحًا ويطمئنّ.

                            وتقريرُ أمانٍ كاذب أسوأ من غياب التقرير: من لا يجد
                            شاشةً يذهب ليتحقّق، ومن يجدها تكذب لا يذهب.

                            ويُعرض الممنوحُ وحده: قائمةُ «ممنوع» تطول بطول
                            الأقسام والأفعال فتدفن ما مُنح فعلًا.
                        */}
                        {tab === 'permissions' && (
                            <div className="p-6">
                                <p className="mb-1 flex items-center gap-2 text-sm font-semibold text-[#111]">
                                    <ShieldCheck className="size-4 text-[#6d28d9]" />
                                    {t('الوضع: :mode', { mode: modeLabel })}
                                </p>
                                <p className="mb-4 text-sm text-[#6b7280]">
                                    {custom
                                        ? t('صلاحيات مخصَّصة لهذا الموظف — تُفرض على الخادم.')
                                        : t('ما يسمح به ملف «:role» — يُفرض على الخادم.', {
                                              role: access?.role_label ?? t(employee.role),
                                          })}
                                </p>

                                {permissions.length === 0 || !access ? (
                                    <p className="rounded-[12px] border border-dashed border-[var(--ui-border,#e8e8e8)] p-5 text-center text-[13px] text-[#9ca3af]">
                                        {t('لا يفتح هذا الموظف شيئًا — لم تُمنح له صلاحية.')}
                                    </p>
                                ) : (
                                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2" data-testid="effective-permissions">
                                        {access.groups.map((g) => (
                                            <div
                                                key={g.key}
                                                className={cn(
                                                    'rounded-[12px] border p-4',
                                                    g.sensitive ? 'border-[#fde68a] bg-[#fffbeb] sm:col-span-2' : 'border-[var(--ui-border,#e8e8e8)]',
                                                )}
                                            >
                                                <p className="mb-2 flex items-center gap-1.5 text-[12px] font-semibold text-[#374151]">
                                                    {g.sensitive && <ShieldAlert className="size-3.5 text-[#b45309]" />}
                                                    {g.label}
                                                </p>
                                                <ul className="space-y-1.5">
                                                    {g.items.map((i) => (
                                                        <li key={i.key} className="flex items-start gap-1.5 text-[13px] text-[#4b4b4b]">
                                                            <Check className="mt-0.5 size-3.5 shrink-0 text-[#047857]" />
                                                            <span className="min-w-0">
                                                                <span className="block">{i.label}</span>
                                                                {g.sensitive && i.hint && (
                                                                    <span className="block text-[12px] text-[#92400e]">{i.hint}</span>
                                                                )}
                                                            </span>
                                                        </li>
                                                    ))}
                                                </ul>
                                            </div>
                                        ))}
                                    </div>
                                )}

                                <p className="mt-5 text-[12px] text-[#9ca3af]">
                                    {t('لتغيير الصلاحيات، افتح صفحة تعديل الموظف.')}
                                </p>
                            </div>
                        )}
                    </Card>
                </div>
            </div>

            {/*
                النافذة تبقى مفتوحةً على الكلمة بعد توليدها.
                كانت تُغلق ويُرسل نصُّها في توستٍ يختفي بعد ثوانٍ — والكلمة
                لا تُسترجَع لأنّ المحفوظ تجزئتُها. فيعيد المدير التوليد مرّةً
                بعد مرّة، وفي كلّ مرّة يُخرج الموظفَ من حسابه.
            */}
            <Dialog
                open={resetting}
                onOpenChange={(open) => {
                    setResetting(open);
                    if (!open) setSeen(true);
                }}
            >
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle>{t('إعادة تعيين كلمة المرور')}</DialogTitle>
                    </DialogHeader>
                    <div className="px-5 pb-5">
                        {issued ? (
                            <>
                                <p className="text-sm text-[#4b4b4b]">
                                    {t('انسخها الآن وأرسلها إلى الموظف — لا تُعرض بعد إغلاق النافذة.')}
                                </p>
                                <div className="mt-3 flex items-center justify-between gap-2 rounded-[10px] border border-[#bbf7d0] bg-[#f0fdf4] px-3 py-2">
                                    <code className="text-[15px] font-semibold tracking-wide text-[#047857]" dir="ltr">
                                        {issued}
                                    </code>
                                    <CopyButton text={issued} label="نسخ" done="نُسخت" />
                                </div>
                                <div className="mt-5 flex justify-end">
                                    <Button
                                        variant="outline"
                                        onClick={() => {
                                            setSeen(true);
                                            setResetting(false);
                                        }}
                                    >
                                        {t('إغلاق')}
                                    </Button>
                                </div>
                            </>
                        ) : (
                            <>
                                <p className="text-sm text-[#4b4b4b]">{t('توليد كلمة مرور مؤقتة جديدة؟')}</p>
                                {/* الكلمة القائمة لا تُعرض: تُحفظ مُجزَّأةً بلا طريقٍ يعود منها إلى نصّها */}
                                <p className="mt-2 text-[12px] text-[#9ca3af]">
                                    {t('الكلمة الحالية لا تُعرض — لا تُحفظ إلا مُجزَّأة، فلا تُقرأ.')}
                                </p>
                                <div className="mt-5 flex justify-end gap-2">
                                    <Button variant="outline" onClick={() => setResetting(false)}>
                                        {t('إلغاء')}
                                    </Button>
                                    <Button
                                        onClick={() => {
                                            setSeen(false);
                                            router.post(route('admin.employees.resetPassword', employee.id), {}, {
                                                preserveScroll: true,
                                            });
                                        }}
                                    >
                                        {t('توليد')}
                                    </Button>
                                </div>
                            </>
                        )}
                    </div>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
