import { useEffect, useId, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Briefcase, Check, KeyRound, Plus, ShieldCheck, Target, UserRound, Wallet } from 'lucide-react';
import SmartLink from '@/Components/SmartLink';
import Field, { Select } from '@/Components/Field';
import Toggle from '@/Components/Toggle';
import { Avatar, AvatarFallback, AvatarImage } from '@/Components/ui/avatar';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { PasswordInput } from '@/Components/ui/password-input';
import { UsernameInput, usernameOf } from '@/Components/ui/username-input';
import { initials } from '@/lib/format';
import { cn } from '@/lib/utils';
import { useTranslate } from '@/lib/i18n';
import type { Branch } from '@/types/models';
import AccessProfileField, { type RoleOption } from './AccessProfileField';
import EmployeePermissions, {
    type PermissionGroup,
    type PermissionMode,
    type RoleProfile,
} from './EmployeePermissions';

export interface EmployeeFormValues {
    id?: number;
    name: string;
    job_title: string | null;
    branch: string | null;
    phone: string | null;
    email: string;
    /** الاسم قبل النطاق — يملأ الحقل، والنطاق مُلحق ثابت */
    username?: string;
    /** هل عنوانه على نطاق افنت بلس؟ الحسابات القديمة خارجه لا تُنقل بلا قصد */
    on_domain?: boolean;
    avatar?: string | null;
    status?: string;
    monthly_target?: number | string | null;
    basic_salary?: number | string | null;
    allowances?: number | string | null;
    /** null تعني «يتبع الوظيفة»؛ مصفوفة تعني قائمة مخصّصة */
    permissions?: string[] | null;
    /** ملفُّ صلاحياته الآن — يُقال إن كان المسمّى المختار سيغيّره */
    role?: string;
    /** فروع العمل المسجّلة. الفارغة = كل فروع المتجر */
    branches?: number[];
}

/** مسمًّى وظيفيّ بملفّه — `EmployeeAccess::jobTitles` */
export interface JobTitleOption {
    name: string;
    role: string;
    role_label: string;
    /** ملفُّه يحمل ما لا يملكه الفاعل — `Permissions::mayAssignRole` */
    blocked: boolean;
}

interface Props {
    branches: Branch[];
    /** فروع المتجر كخيارات — لا الحقل النصّي القديم */
    branchOptions?: { value: number; label: string }[];
    jobTitles: JobTitleOption[];
    employee?: EmployeeFormValues;
    defaultBranch?: string | null;
    /** المجموعاتُ والأفعالُ الحسّاسة — `EmployeeAccess::catalog` */
    permissionCatalog: PermissionGroup[];
    /** ما يحمله كلُّ ملفّ — `Permissions::roleGrants` */
    roleProfiles: Record<string, RoleProfile>;
    /** ملفّاتُ الموظّفين الخمسة لإضافة مسمًّى من هنا */
    roleOptions: RoleOption[];
    /**
     * ما يملك الفاعلُ منحَه — وسواه يُعطَّل بسببه مكتوبًا.
     *
     * والخادمُ يردّ من يمنح ما لا يملك بـ٤٠٣ (`refuseGrantingMoreThanIHave`)، ويُقرأ
     * هذا من `Permissions::grantable` نفسِها التي يقيس بها الحارس.
     */
    grantable: string[];
    /** لا يُعدّل المرءُ صلاحيات حسابه */
    canEditPermissions?: boolean;
    /*
     * ومن لا يقرأ الرواتب لا يرى حقولَها.
     *
     * والخادمُ يرفع الحقلين من الحمولة كذلك (`withoutPayrollFields`)، فلا يكفي إخفاؤهما.
     */
    mayReadPayroll?: boolean;
}

/** قسم داخل النموذج: عنوان وشرح سطر، ثم حقوله */
function Section({
    icon: Icon,
    title,
    hint,
    children,
}: {
    icon: typeof UserRound;
    title: string;
    hint: string;
    children: React.ReactNode;
}) {
    const t = useTranslate();

    return (
        <Card className="p-6">
            <div className="mb-5 flex items-start gap-3">
                <span className="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-[#f3f4f6] text-[#111]">
                    <Icon className="size-[18px]" />
                </span>
                <div className="min-w-0">
                    <h3 className="font-bold text-[#111]">{t(title)}</h3>
                    <p className="mt-0.5 text-[12px] text-[#9ca3af]">{t(hint)}</p>
                </div>
            </div>
            {children}
        </Card>
    );
}

/**
 * نموذج الموظف — يخدم الإضافة والتعديل بحقل واحد لكل معنى.
 *
 * ═══ المسمّى ليس صلاحية ═══
 *
 * كان الحقلُ «الوظيفة / الدور»: اسمٌ يُكتب للبطاقة وصلاحيةٌ تُشتقّ منه في خانةٍ
 * واحدة. فصار «المسمى الوظيفي» اسمًا، ويُقال تحته ملفُّ الصلاحيات الأساسيّ
 * الذي يحمله — والصلاحياتُ قسمٌ وحدها: «صلاحيات الوظيفة» أو «تخصيص».
 *
 * ═══ وحفظُ الموظّف لا يغيّر صلاحياته بلا قصد ═══
 *
 * كان النموذجُ يرسل قائمةً مخصّصةً في كلّ حفظ، تبدأ من أقسام الدور وحدها. فمن
 * فُتح ملفُّه لتصحيح هاتفه صار «مخصّصًا» وخسر أفعالَ دوره (المحاسبُ يفقد «عرض
 * مسيرة الرواتب»). والآن «صلاحيات الوظيفة» ترسل `manual_permissions=false`
 * فيبقى العمودُ `null`، والمخصّصةُ ترسل قائمتها كما هي.
 */
export default function EmployeeForm({
    branches,
    branchOptions = [],
    jobTitles,
    employee,
    defaultBranch,
    permissionCatalog,
    roleProfiles,
    roleOptions,
    grantable,
    canEditPermissions = true,
    mayReadPayroll = true,
}: Props) {
    const t = useTranslate();
    const editing = !!employee;
    const ids = { title: useId(), branch: useId(), name: useId(), phone: useId(), salary: useId(), allowances: useId(), target: useId() };

    /*
     * القائمة محلّية لأن الوظيفة الجديدة تُضاف بلا إعادة تحميل الصفحة:
     * الاعتماد على الخاصية القادمة من الخادم كان سيتطلّب reload يمحو ما
     * كُتب في بقيّة الحقول.
     */
    const [titles, setTitles] = useState<JobTitleOption[]>(jobTitles);
    const [addingTitle, setAddingTitle] = useState(false);
    /*
     * الوظيفة المضافة تُختار بعد أن تصير القائمة تعرفها لا معها: ضبط القيمة
     * في اللحظة نفسها يجعل قائمة الاختيار ترفض قيمةً ليست بين خياراتها بعد.
     */
    const [pendingTitle, setPendingTitle] = useState<string | null>(null);

    const form = useForm({
        name: employee?.name ?? '',
        job_title: employee?.job_title ?? '',
        branch: employee?.branch ?? defaultBranch ?? '',
        branches: employee?.branches ?? [],
        phone: employee?.phone ?? '',
        login_username: employee ? (employee.on_domain === false ? '' : (employee.username ?? usernameOf(employee.email))) : '',
        password: '',
        status: (employee?.status ?? 'نشط') === 'نشط',
        // صفرٌ يعني «بلا هدف» — يُعرض فارغًا كما يقول التلميح، لا رقمًا مضبوطًا
        monthly_target: Number(employee?.monthly_target ?? 0) ? String(employee!.monthly_target) : '',
        basic_salary: Number(employee?.basic_salary ?? 0) ? String(employee!.basic_salary) : '',
        allowances: Number(employee?.allowances ?? 0) ? String(employee!.allowances) : '',
        // موظّفٌ جديد يبدأ بصلاحيات وظيفته — والتخصيصُ اختيارٌ لا افتراض
        permission_mode: (Array.isArray(employee?.permissions) ? 'custom' : 'inherited') as PermissionMode,
        permissions: employee?.permissions ?? [],
    });

    const chosen = titles.find((x) => x.name === form.data.job_title) ?? null;
    const profile = chosen ? (roleProfiles[chosen.role] ?? null) : null;
    // تبدّلُ ملفّ الصلاحيات لا يقع بصمت — يُقال قبل الحفظ
    const roleChange = editing && chosen && employee?.role && chosen.role !== employee.role ? chosen : null;

    const titleForm = useForm({ name: '', role: '' });

    /*
     * حسابٌ قديم خارج نطاق افنت بلس — يُعرض عنوانه ولا يُنقل حتى يُطلب النقل.
     */
    const [moving, setMoving] = useState(false);
    const legacyEmail = editing && employee?.on_domain === false && !moving;

    useEffect(() => {
        if (pendingTitle && titles.some((x) => x.name === pendingTitle)) {
            form.setData('job_title', pendingTitle);
            setPendingTitle(null);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [pendingTitle, titles]);

    /*
     * تُحفظ عبر مسار الوظائف نفسه — لا مسار ثانٍ يكرّر التحقق ويفترق عنه.
     * ومعها ملفُّها صريحًا: الخادمُ يرفضها بلا ملفّ، ولا يسقط بها على «مبيعات».
     */
    const saveTitle = () => {
        titleForm.post(route('admin.jobTitles.store'), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                const name = titleForm.data.name.trim();
                const role = titleForm.data.role;
                const label = roleOptions.find((o) => o.value === role)?.label ?? role;
                setTitles((list) =>
                    list.some((x) => x.name === name)
                        ? list
                        : [...list, { name, role, role_label: label, blocked: false }].sort((a, b) => a.name.localeCompare(b.name)),
                );
                setPendingTitle(name);
                titleForm.reset();
                setAddingTitle(false);
            },
        });
    };

    /** الانتقالُ إلى التخصيص يبدأ ممّا يملكه الآن — لا من صفحةٍ بيضاء */
    const changeMode = (mode: PermissionMode) => {
        if (mode === 'custom') {
            const start = form.data.permissions.length > 0 ? form.data.permissions : (profile?.grants ?? []);
            form.setData((d) => ({ ...d, permission_mode: 'custom', permissions: [...start] }));
        } else {
            form.setData((d) => ({ ...d, permission_mode: 'inherited', permissions: [] }));
        }
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        /*
         * ما يصل الخادمَ عن الصلاحيات:
         *   - من يعدّل نفسه: لا شيء — الخادمُ يردّ تعديلَ صلاحيات النفس، ولو أُرسل
         *     العلمُ لرُدّ حفظُ هاتفه معه.
         *   - «صلاحيات الوظيفة»: `manual_permissions=false` ← العمودُ `null`.
         *   - «تخصيص»: القائمةُ كما اختيرت ← هي كلُّ ما يملكه.
         */
        form.transform(({ permission_mode, permissions, ...rest }) => {
            if (!canEditPermissions) return rest;
            return permission_mode === 'custom'
                ? { ...rest, manual_permissions: true, permissions }
                : { ...rest, manual_permissions: false };
        });
        if (editing) form.put(route('admin.employees.update', employee!.id));
        else form.post(route('admin.employees.store'));
    };

    return (
        <form onSubmit={submit} className="max-w-3xl space-y-5">
            {editing && (
                <Card className="flex items-center gap-4 p-5">
                    <Avatar className="size-14">
                        {employee!.avatar && <AvatarImage src={employee!.avatar} alt="" />}
                        <AvatarFallback>{initials(employee!.name)}</AvatarFallback>
                    </Avatar>
                    <div className="min-w-0">
                        <p className="truncate font-bold text-[#111]">{employee!.name}</p>
                        <p className="truncate text-[12px] text-[#9ca3af]" dir="ltr">
                            {employee!.email}
                        </p>
                    </div>
                </Card>
            )}

            <Section icon={UserRound} title="بيانات الموظف" hint="ما يظهر في القوائم والتقارير">
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <Field label="الاسم الكامل" required htmlFor={ids.name} error={form.errors.name}>
                        <Input
                            id={ids.name}
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            required
                        />
                    </Field>

                    <Field label="رقم الهاتف" htmlFor={ids.phone} error={form.errors.phone}>
                        <Input
                            id={ids.phone}
                            type="tel"
                            dir="ltr"
                            value={form.data.phone}
                            onChange={(e) => form.setData('phone', e.target.value)}
                            placeholder="+968 9xxxxxxx"
                        />
                    </Field>
                </div>
            </Section>

            <Section icon={Briefcase} title="العمل والفروع" hint="المسمى الوظيفي يُسند ملف صلاحياته الأساسي">
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <Field label="المسمى الوظيفي" required htmlFor={ids.title} error={form.errors.job_title} className="md:col-span-2">
                        {/*
                            زرّ الإضافة بجانب القائمة: وظيفةٌ ناقصة كانت تعني
                            ترك النموذج والذهاب إلى تبويب الوظائف ثم العودة
                            وإعادة تعبئة ما كُتب.
                        */}
                        <div className="flex items-center gap-2">
                            <div className="min-w-0 flex-1">
                                <Select
                                    id={ids.title}
                                    value={form.data.job_title}
                                    onChange={(e) => form.setData('job_title', e.target.value)}
                                    /*
                                        والوظيفةُ التي لا يُسندها الفاعلُ تبقى معروضةً
                                        معطَّلةً بسببها: ملفُّها يفتح ما لا يفتحه هو، ومن
                                        أسند ملفًّا أسند ما يحمله — `Permissions::mayAssignRole`.
                                    */
                                    options={titles.map((j) => ({
                                        label: j.blocked
                                            ? `${j.name} — ${t('ملفها «:profile» يفتح ما لا تفتحه', { profile: j.role_label })}`
                                            : j.name,
                                        value: j.name,
                                        disabled: j.blocked,
                                    }))}
                                    placeholder="اختر المسمى الوظيفي…"
                                />
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                title={t('إضافة مسمى وظيفي')}
                                aria-label={t('إضافة مسمى وظيفي')}
                                onClick={() => setAddingTitle(true)}
                            >
                                <Plus />
                            </Button>
                        </div>
                        {chosen && (
                            <p className="mt-1.5 text-[12px] text-[#6b7280]" data-testid="title-profile">
                                {t('ملف الصلاحيات الأساسي: :profile', { profile: chosen.role_label })}
                            </p>
                        )}
                        {roleChange && (
                            <p className="mt-1.5 rounded-[10px] bg-[#fffbeb] px-3 py-2 text-[12px] text-[#b45309]" data-testid="role-change">
                                {t('سيتغيّر ملف الصلاحيات الأساسي من «:from» إلى «:to» عند الحفظ.', {
                                    from: roleProfiles[employee!.role!]?.label ?? employee!.role!,
                                    to: roleChange.role_label,
                                })}
                            </p>
                        )}
                    </Field>

                    <Field
                        label="الفرع الأساسي للعرض"
                        htmlFor={ids.branch}
                        hint="يظهر في القوائم والتقارير — لا يمنح ولا يمنع شيئًا"
                        error={form.errors.branch}
                    >
                        <Select
                            id={ids.branch}
                            value={form.data.branch}
                            onChange={(e) => form.setData('branch', e.target.value)}
                            options={branches.map((b) => ({ label: b.name, value: b.name }))}
                            placeholder="اختر الفرع…"
                        />
                    </Field>

                    {/*
                        فروع العمل — تُحفظ في جدولها (`branch_user`) ويفرضها
                        الخادم (`BranchAccess`): مبدّلُ الفروع وما يُكتب في
                        فرعٍ و«كل الفروع» نفسُها. والتلميح يقول ذلك كما هو.
                    */}
                    {branchOptions.length > 0 && (
                        <Field
                            label="فروع العمل"
                            className="md:col-span-2"
                            hint="بلا تحديد = كل الفروع. ومن حُدّدت له فروعٌ لا يرى غيرها ولا يعمل فيها."
                            error={form.errors.branches}
                        >
                            <div className="flex flex-wrap gap-2" role="group" aria-label={t('فروع العمل')}>
                                {branchOptions.map((b) => {
                                    const on = form.data.branches.includes(b.value);
                                    return (
                                        <button
                                            key={b.value}
                                            type="button"
                                            aria-pressed={on}
                                            onClick={() =>
                                                form.setData(
                                                    'branches',
                                                    on
                                                        ? form.data.branches.filter((v) => v !== b.value)
                                                        : [...form.data.branches, b.value],
                                                )
                                            }
                                            className={cn(
                                                'rounded-[10px] border px-3 py-2 text-[13px] transition-colors',
                                                on
                                                    ? 'border-[#111] bg-[#111] text-white'
                                                    : 'border-[var(--ui-border,#e8e8e8)] bg-white text-[#4b4b4b] hover:bg-[#f7f7f5]',
                                            )}
                                        >
                                            {b.label}
                                        </button>
                                    );
                                })}
                            </div>
                        </Field>
                    )}
                </div>
            </Section>

            <Section
                icon={KeyRound}
                title="الدخول والأمان"
                hint="بالبريد وكلمة المرور يدخل الموظف إلى اللوحة."
            >
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    {/*
                        إلزاميّ: هو الباب الوحيد بعد رفع الدخول بالرمز.
                        وهو فريدٌ في النظام كلّه، فحسابان يريدان العنوان
                        نفسَه يصطدمان — والتلميح يقول ذلك قبل الاصطدام.
                    */}
                    <Field
                        label="اسم المستخدم"
                        required={!legacyEmail}
                        hint="به يدخل الموظف — ولا يتكرّر في النظام"
                        error={form.errors.login_username}
                    >
                        {legacyEmail ? (
                            <div className="flex items-center justify-between gap-3 rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-[#f7f7f5] px-3 py-2">
                                <span className="text-[13px] text-[#4b4b4b]" dir="ltr">
                                    {employee?.email}
                                </span>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => {
                                        setMoving(true);
                                        form.setData('login_username', usernameOf(employee?.email));
                                    }}
                                >
                                    {t('انقله إلى نطاق افنت بلس')}
                                </Button>
                            </div>
                        ) : (
                            <UsernameInput
                                value={form.data.login_username}
                                onChange={(v) => form.setData('login_username', v)}
                                required
                            />
                        )}
                        {moving && (
                            <p className="mt-1.5 text-[12px] text-[#b45309]">
                                {t('سيتغيّر بريد دخوله عند الحفظ — أبلغه بالجديد.')}
                            </p>
                        )}
                    </Field>

                    {/*
                        الكلمة القائمة لا تُعرض هنا ولا في أيّ شاشة: تُحفظ
                        مُجزَّأةً بلا طريقٍ يعود منها إلى نصّها.
                    */}
                    <Field
                        label={editing ? 'كلمة مرور جديدة' : 'كلمة المرور'}
                        hint={
                            editing
                                ? 'اتركها فارغة للإبقاء على الحالية — والقديمة لا تُعرض لأنها لا تُقرأ'
                                : 'أربعة أحرف على الأقل'
                        }
                        error={form.errors.password}
                    >
                        <PasswordInput
                            autoComplete="new-password"
                            value={form.data.password}
                            onChange={(e) => form.setData('password', e.target.value)}
                        />
                    </Field>

                    {editing && (
                        <Field label="حالة الحساب" hint="الحساب المعطَّل لا يستطيع الدخول" error={form.errors.status}>
                            <div className="pt-1.5">
                                <Toggle
                                    on={form.data.status}
                                    onChange={(v) => form.setData('status', v)}
                                    label={form.data.status ? 'نشط' : 'معطل'}
                                />
                            </div>
                        </Field>
                    )}
                </div>
            </Section>

            {/*
                الراتب: منه تُملأ مسيرة الرواتب. ولا تُرسم حقولُه لمن لا يملك
                «عرض مسيرة الرواتب» — لا مخفيّةً في الصفحة ولا ظاهرة.
            */}
            <Section
                icon={Wallet}
                title="الراتب"
                hint={mayReadPayroll ? 'منه تُملأ مسيرة رواتب الشهر' : 'صلاحيةٌ لا تملكها'}
            >
                {mayReadPayroll ? (
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <Field label="الراتب الأساسي" htmlFor={ids.salary} hint="اتركه فارغًا لمن لا راتب له" error={form.errors.basic_salary}>
                            <Input
                                id={ids.salary}
                                inputMode="decimal"
                                dir="ltr"
                                value={form.data.basic_salary}
                                onChange={(e) => form.setData('basic_salary', e.target.value)}
                                placeholder="0"
                            />
                        </Field>

                        <Field label="البدلات" htmlFor={ids.allowances} hint="سكن، مواصلات، وما يجري مجراها" error={form.errors.allowances}>
                            <Input
                                id={ids.allowances}
                                inputMode="decimal"
                                dir="ltr"
                                value={form.data.allowances}
                                onChange={(e) => form.setData('allowances', e.target.value)}
                                placeholder="0"
                            />
                        </Field>
                    </div>
                ) : (
                    <p className="rounded-[10px] bg-[#fffbeb] p-3 text-[12px] leading-relaxed text-[#b45309]">
                        {t('قراءة الرواتب صلاحيةٌ لا تملكها — يكتبها من يملكها، ولا يتغيّر ما هو مسجَّل بحفظك.')}
                    </p>
                )}
            </Section>

            <Section icon={Target} title="الأداء" hint="عليه يُحتسب «تحقيق الهدف» في قائمة الموظفين">
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <Field label="الهدف الشهري" htmlFor={ids.target} hint="اتركه فارغًا لبلا هدف" error={form.errors.monthly_target}>
                        <Input
                            id={ids.target}
                            inputMode="decimal"
                            dir="ltr"
                            value={form.data.monthly_target}
                            onChange={(e) => form.setData('monthly_target', e.target.value)}
                            placeholder="0"
                        />
                    </Field>
                </div>
            </Section>

            <Section icon={ShieldCheck} title="الصلاحيات" hint="ما يفتحه هذا الموظف — ويُفرض على الخادم">
                {!canEditPermissions ? (
                    <p className="text-[13px] text-[#6b7280]">{t('لا يمكنك تعديل صلاحيات حسابك الخاص.')}</p>
                ) : (
                    <EmployeePermissions
                        catalog={permissionCatalog}
                        profile={profile}
                        titleName={chosen?.name ?? null}
                        ownerProfile={chosen?.role === 'admin'}
                        mode={form.data.permission_mode}
                        selected={form.data.permissions}
                        onModeChange={changeMode}
                        onSelectedChange={(keys) => form.setData('permissions', keys)}
                        grantable={grantable}
                        error={form.errors.permissions}
                    />
                )}
            </Section>

            <div className="flex items-center gap-3">
                <Button type="submit" loading={form.processing}>
                    <Check />
                    {editing ? t('حفظ التغييرات') : t('حفظ الموظف')}
                </Button>
                <Button variant="outline" asChild>
                    <SmartLink routeName="admin.employees.index" href={route('admin.employees.index')}>
                        {t('إلغاء')}
                    </SmartLink>
                </Button>
            </div>

            <Dialog open={addingTitle} onOpenChange={setAddingTitle}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{t('إضافة مسمى وظيفي')}</DialogTitle>
                    </DialogHeader>
                    {/*
                        النموذج هنا ليس <form> متداخلًا: نموذجٌ داخل نموذج
                        يجعل زرّ الحفظ يُرسل الاثنين، فيُحفظ الموظف ناقصًا.
                    */}
                    <div className="space-y-4 px-5 pb-5">
                        <Field label="المسمى الوظيفي" required error={titleForm.errors.name}>
                            <Input
                                value={titleForm.data.name}
                                onChange={(e) => titleForm.setData('name', e.target.value)}
                                placeholder={t('مثال: مشرف الفعالية')}
                                aria-label={t('المسمى الوظيفي')}
                            />
                        </Field>

                        <AccessProfileField
                            value={titleForm.data.role}
                            onChange={(role) => titleForm.setData('role', role)}
                            options={roleOptions}
                            error={titleForm.errors.role}
                        />

                        <div className="flex justify-end gap-2 pt-1">
                            <Button type="button" variant="ghost" onClick={() => setAddingTitle(false)}>
                                {t('إلغاء')}
                            </Button>
                            <Button type="button" loading={titleForm.processing} disabled={titleForm.data.role === ''} onClick={saveTitle}>
                                <Check />
                                {t('إضافة')}
                            </Button>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
        </form>
    );
}
