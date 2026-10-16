import { useState } from 'react';
import { fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { router as coreRouter } from '@inertiajs/core';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import EmployeePermissions, {
    type PermissionGroup,
    type PermissionMode,
    type RoleProfile,
} from '@/Pages/Admin/Employees/partials/EmployeePermissions';
import EmployeeForm, { type JobTitleOption } from '@/Pages/Admin/Employees/partials/EmployeeForm';
import EmployeesPanel from '@/Pages/Admin/Settings/panels/EmployeesPanel';
import type { Employee } from '@/types/models';

/**
 * صلاحياتُ الموظّف في الشاشة — «صلاحيات الوظيفة» أو «تخصيص».
 *
 * المعاينةُ من ملفّ الوظيفة كما يصفه الخادم، والتخصيصُ يبدأ ممّا يملكه الموظّف،
 * والمتقدّمةُ مطويّة، والحسّاسةُ وحدها، وما لا يُمنح معطّلٌ وسببُه مكتوب.
 */

beforeAll(() => {
    // Radix يسأل عن التقاط المؤشّر — وjsdom لا يعرفه
    Element.prototype.hasPointerCapture ??= () => false;
    Element.prototype.releasePointerCapture ??= () => {};
});

const item = (key: string, label: string, kind: 'section' | 'action' = 'section', extra: Partial<PermissionGroup['items'][number]> = {}) => ({
    key, label, kind, risk: 'normal' as const, hint: null, parents: [], ...extra,
});

const catalog: PermissionGroup[] = [
    { key: 'basics', label: 'الأساسيات', sensitive: false, items: [item('dashboard', 'الملخص')] },
    {
        key: 'sales', label: 'العملاء والمبيعات', sensitive: false,
        items: [item('customers', 'العملاء'), item('quotations', 'عروض الأسعار'), item('quotation.create', 'كتابة عرض سعر', 'action', { parents: ['quotations'] })],
    },
    {
        key: 'purchasing', label: 'المشتريات والمخزون', sensitive: false,
        items: [item('inventory', 'المخزون'), item('purchases', 'المشتريات')],
    },
    { key: 'system', label: 'الإدارة والنظام', sensitive: false, items: [item('settings', 'الإعدادات')] },
    {
        key: 'sensitive', label: 'صلاحيات حساسة', sensitive: true,
        items: [
            item('invoice.pay', 'سداد سندات الموردين', 'action', { risk: 'sensitive', hint: 'يخرج مالًا من الصندوق أو البنك لسداد سندات الموردين.', parents: ['purchases'] }),
            item('payroll.pay', 'صرف الرواتب', 'action', { risk: 'sensitive', hint: 'يسجّل صرف مسيرة الرواتب — مالٌ يخرج للموظفين.', parents: ['employees'] }),
        ],
    },
];

const profiles: Record<string, RoleProfile> = {
    sales: { label: 'موظف مبيعات', grants: ['dashboard', 'customers', 'quotations', 'quotation.create'] },
    inventory: { label: 'مسؤول مخزون', grants: ['dashboard', 'inventory', 'purchases'] },
};

const ALL = ['dashboard', 'customers', 'quotations', 'quotation.create', 'inventory', 'purchases', 'settings', 'invoice.pay', 'payroll.pay'];

/** يحمل الوضعَ والمختارَ كما يحملهما النموذج */
function Harness({ role = 'sales', initialMode = 'inherited', initial = [], grantable = ALL }: {
    role?: string; initialMode?: PermissionMode; initial?: string[]; grantable?: string[];
}) {
    const [mode, setMode] = useState<PermissionMode>(initialMode);
    const [selected, setSelected] = useState<string[]>(initial);

    return (
        <EmployeePermissions
            catalog={catalog}
            profile={profiles[role]}
            titleName={role === 'sales' ? 'مسؤول مبيعات' : 'مشرف مخزن'}
            mode={mode}
            selected={selected}
            onModeChange={(m) => {
                setMode(m);
                setSelected(m === 'custom' ? (selected.length ? selected : profiles[role].grants) : []);
            }}
            onSelectedChange={setSelected}
            grantable={grantable}
        />
    );
}

describe('EmployeePermissions', () => {
    it('starts on the job title defaults and previews exactly what the profile grants', () => {
        render(<Harness />);

        expect(screen.getByRole('radio', { name: /صلاحيات الوظيفة الافتراضية/ })).toBeChecked();
        const preview = screen.getByTestId('inherited-preview');
        expect(preview).toHaveTextContent('صلاحيات وظيفة «مسؤول مبيعات» — ملف «موظف مبيعات»:');
        for (const label of ['الملخص', 'العملاء', 'عروض الأسعار', 'كتابة عرض سعر']) {
            expect(within(preview).getByText(label)).toBeInTheDocument();
        }
        expect(within(preview).queryByText('المخزون')).not.toBeInTheDocument();
        // ولا مربّعات في وضع الوظيفة — معاينةٌ لا قائمة
        expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
    });

    it('changes the preview when the job title changes', () => {
        const { rerender } = render(
            <EmployeePermissions catalog={catalog} profile={profiles.sales} titleName="مسؤول مبيعات" mode="inherited"
                selected={[]} onModeChange={() => {}} onSelectedChange={() => {}} grantable={ALL} />,
        );
        expect(screen.getByTestId('inherited-preview')).toHaveTextContent('عروض الأسعار');

        rerender(
            <EmployeePermissions catalog={catalog} profile={profiles.inventory} titleName="مشرف مخزن" mode="inherited"
                selected={[]} onModeChange={() => {}} onSelectedChange={() => {}} grantable={ALL} />,
        );
        const preview = screen.getByTestId('inherited-preview');
        expect(preview).toHaveTextContent('ملف «مسؤول مخزون»');
        expect(within(preview).getByText('المشتريات')).toBeInTheDocument();
        expect(within(preview).queryByText('عروض الأسعار')).not.toBeInTheDocument();
    });

    it('customizing starts from what the employee has, with advanced permissions folded away', async () => {
        const user = userEvent.setup();
        render(<Harness />);

        await user.click(screen.getByRole('radio', { name: /تخصيص صلاحيات هذا الموظف/ }));

        const related = screen.getByRole('region', { name: 'صلاحيات مرتبطة بعمله' });
        expect(within(related).getByRole('checkbox', { name: /عروض الأسعار/ })).toBeChecked();
        expect(within(related).getByRole('checkbox', { name: /الملخص/ })).toBeChecked();
        // والمتقدّمةُ مطويّة: «الإعدادات» ليست من عمله فلا تظهر حتى تُطلب
        expect(screen.queryByRole('checkbox', { name: /الإعدادات/ })).not.toBeInTheDocument();
        const toggle = screen.getByRole('button', { name: 'إظهار الصلاحيات المتقدمة' });
        expect(toggle).toHaveAttribute('aria-expanded', 'false');

        await user.click(toggle);
        const advanced = screen.getByTestId('advanced-permissions');
        expect(within(advanced).getByRole('checkbox', { name: /الإعدادات/ })).not.toBeChecked();
        expect(within(advanced).getByRole('checkbox', { name: /المخزون/ })).toBeInTheDocument();
    });

    it('leaves a box checked under advanced where it is, and counts it when folded', async () => {
        const user = userEvent.setup();
        render(<Harness initialMode="custom" initial={['dashboard']} />);

        await user.click(screen.getByRole('button', { name: 'إظهار الصلاحيات المتقدمة' }));
        await user.click(within(screen.getByTestId('advanced-permissions')).getByRole('checkbox', { name: /الإعدادات/ }));
        // لا يقفز إلى «المرتبطة» من تحت الإصبع
        expect(within(screen.getByTestId('advanced-permissions')).getByRole('checkbox', { name: /الإعدادات/ })).toBeChecked();

        await user.click(screen.getByRole('button', { name: /إخفاء الصلاحيات المتقدمة/ }));
        expect(screen.getByRole('button', { name: /إظهار الصلاحيات المتقدمة/ })).toHaveTextContent('1 مختارة');
    });

    it('keeps an existing grant visible even when it is outside the job profile', () => {
        render(<Harness initialMode="custom" initial={['dashboard', 'settings', 'invoice.pay']} />);

        // «الإعدادات» و«سداد سندات الموردين» ليستا من ملفّ المبيعات — ومختارتان، فتظهران
        expect(screen.getByRole('checkbox', { name: /الإعدادات/ })).toBeChecked();
        expect(screen.getByRole('checkbox', { name: /سداد سندات الموردين/ })).toBeChecked();
    });

    it('shows sensitive actions apart from the sections, each with what it does', () => {
        render(<Harness initialMode="custom" initial={['dashboard', 'purchases', 'invoice.pay']} />);

        const sensitive = screen.getByRole('region', { name: 'صلاحيات حساسة' });
        expect(within(sensitive).getByRole('checkbox', { name: /سداد سندات الموردين/ })).toBeChecked();
        expect(within(sensitive).getByText('يخرج مالًا من الصندوق أو البنك لسداد سندات الموردين.')).toBeInTheDocument();
        // ولا تقع وسط الأقسام العاديّة
        const related = screen.getByRole('region', { name: 'صلاحيات مرتبطة بعمله' });
        expect(within(related).queryByText('سداد سندات الموردين')).not.toBeInTheDocument();
    });

    it('disables what the actor cannot grant and says why', async () => {
        const user = userEvent.setup();
        render(<Harness initialMode="custom" initial={['dashboard']} grantable={['dashboard', 'customers', 'quotations', 'quotation.create']} />);

        await user.click(screen.getByRole('button', { name: 'إظهار الصلاحيات المتقدمة' }));
        const settings = screen.getByRole('checkbox', { name: /الإعدادات/ });
        expect(settings).toBeDisabled();
        expect(settings.closest('label')).toHaveTextContent('لا تملكها — ولا تُمنح ما لا تملك.');
        expect(screen.getByRole('checkbox', { name: /عروض الأسعار/ })).toBeEnabled();
    });

    it('says an action needs its section and offers to enable it', async () => {
        const user = userEvent.setup();
        render(<Harness initialMode="custom" initial={['dashboard', 'invoice.pay']} />);

        expect(screen.getByText('يتطلب الوصول إلى قسم المشتريات.')).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'فعّل المشتريات' }));
        expect(screen.queryByText('يتطلب الوصول إلى قسم المشتريات.')).not.toBeInTheDocument();
        expect(screen.getByRole('checkbox', { name: /^المشتريات/ })).toBeChecked();
    });

    it('asks before returning to the job title, and only then drops the customization', async () => {
        const user = userEvent.setup();
        render(<Harness initialMode="custom" initial={['dashboard', 'settings']} />);

        await user.click(screen.getByRole('radio', { name: /صلاحيات الوظيفة الافتراضية/ }));
        const dialog = screen.getByRole('dialog');
        expect(dialog).toHaveTextContent('سيتم حذف التخصيصات وسيعود الموظف إلى صلاحيات وظيفته.');
        // الإلغاءُ يبقيه مخصّصًا
        await user.click(within(dialog).getByRole('button', { name: 'إلغاء' }));
        expect(screen.getByRole('radio', { name: /تخصيص صلاحيات هذا الموظف/ })).toBeChecked();

        await user.click(screen.getByRole('radio', { name: /صلاحيات الوظيفة الافتراضية/ }));
        await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'نعم، أعده إلى صلاحيات وظيفته' }));
        expect(screen.getByRole('radio', { name: /صلاحيات الوظيفة الافتراضية/ })).toBeChecked();
        expect(screen.getByTestId('inherited-preview')).toBeInTheDocument();
    });

    it('keeps a custom list when the job title changes', () => {
        const props = { catalog, mode: 'custom' as const, selected: ['dashboard', 'settings'], onModeChange: () => {}, onSelectedChange: () => {}, grantable: ALL };
        const { rerender } = render(<EmployeePermissions {...props} profile={profiles.sales} titleName="مسؤول مبيعات" />);
        rerender(<EmployeePermissions {...props} profile={profiles.inventory} titleName="مشرف مخزن" />);

        expect(screen.getByRole('radio', { name: /تخصيص صلاحيات هذا الموظف/ })).toBeChecked();
        expect(screen.getByRole('checkbox', { name: /الإعدادات/ })).toBeChecked();
    });
});

const titles: JobTitleOption[] = [
    { name: 'مسؤول مبيعات', role: 'sales', role_label: 'موظف مبيعات', blocked: false },
    { name: 'مشرف مخزن', role: 'inventory', role_label: 'مسؤول مخزون', blocked: false },
    { name: 'مدير فرع', role: 'manager', role_label: 'مدير فرع', blocked: true },
];

const formProps = {
    branches: [],
    jobTitles: titles,
    permissionCatalog: catalog,
    roleProfiles: profiles,
    roleOptions: [
        { value: 'manager', label: 'مدير فرع', assignable: false },
        { value: 'sales', label: 'موظف مبيعات', assignable: true },
        { value: 'inventory', label: 'مسؤول مخزون', assignable: true },
    ],
    grantable: ALL,
};

describe('EmployeeForm', () => {
    /*
     * `useForm` يرسل بـ`router` النواة لا بنسخة `@inertiajs/react` الممثَّلة في
     * الإعداد — فيُراقَب هنا ولا يُرسل شيئًا.
     */
    beforeEach(() => {
        vi.restoreAllMocks();
        vi.spyOn(coreRouter, 'put').mockImplementation(() => {});
        vi.spyOn(coreRouter, 'post').mockImplementation(() => {});
    });

    const sent = (fn: typeof coreRouter.put) => vi.mocked(fn).mock.calls[0]?.[1] as Record<string, unknown>;
    const submit = () => fireEvent.submit(document.querySelector('form')!);

    it('saves an employee on the job defaults as «follows the job title» — no list, the column stays null', () => {
        render(<EmployeeForm {...formProps} employee={{ id: 7, name: 'سارة', job_title: 'مسؤول مبيعات', branch: null, phone: null, email: 'sara@eventplusapp.om', permissions: null, role: 'sales' }} />);

        submit();
        const data = sent(coreRouter.put);
        expect(data.manual_permissions).toBe(false);
        expect(data).not.toHaveProperty('permissions');
        expect(data).not.toHaveProperty('permission_mode');
    });

    it('saves a custom employee with his explicit list', () => {
        render(<EmployeeForm {...formProps} employee={{ id: 7, name: 'سارة', job_title: 'مسؤول مبيعات', branch: null, phone: null, email: 'sara@eventplusapp.om', permissions: ['dashboard', 'settings'], role: 'sales' }} />);

        submit();
        expect(sent(coreRouter.put)).toMatchObject({ manual_permissions: true, permissions: ['dashboard', 'settings'] });
    });

    it('sends nothing about permissions when the employee edits himself', () => {
        render(<EmployeeForm {...formProps} canEditPermissions={false} employee={{ id: 7, name: 'سارة', job_title: 'مسؤول مبيعات', branch: null, phone: null, email: 'sara@eventplusapp.om', permissions: null, role: 'sales' }} />);

        expect(screen.getByText('لا يمكنك تعديل صلاحيات حسابك الخاص.')).toBeInTheDocument();
        submit();
        const data = sent(coreRouter.put);
        expect(data).not.toHaveProperty('manual_permissions');
        expect(data).not.toHaveProperty('permissions');
    });

    it('calls the field «المسمى الوظيفي», shows its profile, and starts a new employee on the job defaults', async () => {
        const user = userEvent.setup();
        render(<EmployeeForm {...formProps} />);

        expect(screen.queryByText('الوظيفة / الدور')).not.toBeInTheDocument();
        const title = screen.getByRole('combobox', { name: /المسمى الوظيفي/ });
        expect(screen.getByRole('radio', { name: /صلاحيات الوظيفة الافتراضية/ })).toBeChecked();
        expect(screen.getByText('اختر المسمى الوظيفي لتظهر صلاحياته.')).toBeInTheDocument();

        await user.click(title);
        // ومسمًّى لا يُسنده الفاعلُ معطّلٌ وسببُه في اسمه
        expect(screen.getByRole('option', { name: /مدير فرع — ملفها «مدير فرع» يفتح ما لا تفتحه/ })).toHaveAttribute('aria-disabled', 'true');
        await user.click(screen.getByRole('option', { name: 'مشرف مخزن' }));

        expect(screen.getByTestId('title-profile')).toHaveTextContent('ملف الصلاحيات الأساسي: مسؤول مخزون');
        expect(screen.getByTestId('inherited-preview')).toHaveTextContent('ملف «مسؤول مخزون»');
    });

    it('says before saving that a new job title changes the employee’s profile', async () => {
        const user = userEvent.setup();
        render(<EmployeeForm {...formProps} employee={{ id: 7, name: 'سارة', job_title: 'مسؤول مبيعات', branch: null, phone: null, email: 'sara@eventplusapp.om', permissions: null, role: 'sales' }} />);

        expect(screen.queryByTestId('role-change')).not.toBeInTheDocument();
        await user.click(screen.getByRole('combobox', { name: /المسمى الوظيفي/ }));
        await user.click(screen.getByRole('option', { name: 'مشرف مخزن' }));
        expect(screen.getByTestId('role-change')).toHaveTextContent('سيتغيّر ملف الصلاحيات الأساسي من «موظف مبيعات» إلى «مسؤول مخزون» عند الحفظ.');
    });

    it('opens a custom employee on his own list and does not wipe it when the job title changes', async () => {
        const user = userEvent.setup();
        render(<EmployeeForm {...formProps} employee={{ id: 7, name: 'سارة', job_title: 'مسؤول مبيعات', branch: null, phone: null, email: 'sara@eventplusapp.om', permissions: ['dashboard', 'settings'], role: 'sales' }} />);

        expect(screen.getByRole('radio', { name: /تخصيص صلاحيات هذا الموظف/ })).toBeChecked();
        await user.click(screen.getByRole('combobox', { name: /المسمى الوظيفي/ }));
        await user.click(screen.getByRole('option', { name: 'مشرف مخزن' }));
        expect(screen.getByRole('radio', { name: /تخصيص صلاحيات هذا الموظف/ })).toBeChecked();
        expect(screen.getByRole('checkbox', { name: /الإعدادات/ })).toBeChecked();
    });

    it('draws no salary fields for someone who cannot read payroll, and no PIN or commission anywhere', () => {
        const { unmount } = render(<EmployeeForm {...formProps} mayReadPayroll={false} />);
        expect(screen.queryByRole('textbox', { name: /الراتب الأساسي/ })).not.toBeInTheDocument();
        expect(screen.queryByText('البدلات')).not.toBeInTheDocument();
        expect(screen.getByText('قراءة الرواتب صلاحيةٌ لا تملكها — يكتبها من يملكها، ولا يتغيّر ما هو مسجَّل بحفظك.')).toBeInTheDocument();
        unmount();

        render(<EmployeeForm {...formProps} mayReadPayroll />);
        expect(screen.getByRole('textbox', { name: /الراتب الأساسي/ })).toBeInTheDocument();
        expect(document.body).not.toHaveTextContent(/PIN|رمز الدخول|العمولة|نسبة العمولة/);
    });

    it('asks for the base permission profile when adding a job title from the form', async () => {
        const user = userEvent.setup();
        render(<EmployeeForm {...formProps} />);

        await user.click(screen.getByRole('button', { name: 'إضافة مسمى وظيفي' }));
        const dialog = screen.getByRole('dialog');
        expect(within(dialog).getByRole('combobox', { name: /ملف الصلاحيات الأساسي/ })).toBeInTheDocument();
        expect(within(dialog).getByText('يحدد الصلاحيات الافتراضية، ويمكن تخصيص صلاحيات الموظف لاحقًا.')).toBeInTheDocument();
        // بلا ملفٍّ لا يُرسل — لا يسقط على «مبيعات» بصمت
        expect(within(dialog).getByRole('button', { name: /إضافة/ })).toBeDisabled();
    });
});

const employee = (over: Partial<Employee>): Employee => ({
    id: 1, name: 'سارة', avatar: null, role: 'مسؤول مبيعات', branch: 'الرئيسي', phone: '', email: 'sara@eventplusapp.om',
    sales: 0, status: 'نشط', joined: '2026-10-01', achieved: 0, target: 0, target_pct: null,
    is_owner: false, job_title: 'مسؤول مبيعات', access_role: 'sales', access_role_label: 'موظف مبيعات',
    permissions_mode: 'inherited', permissions_summary: null, work_branches: [], ...over,
});

describe('EmployeesPanel', () => {
    it('shows the owner apart with his badge and lists each employee’s title, profile and permission status', () => {
        render(
            <EmployeesPanel
                jobTitles={[]}
                employees={[
                    employee({ id: 1, name: 'المالك', is_owner: true, access_role: 'admin', access_role_label: 'مدير نشاط' }),
                    employee({ id: 2, name: 'سارة' }),
                    employee({ id: 3, name: 'خالد', permissions_mode: 'custom', permissions_summary: { sections: 7, actions: 4, sensitive: 3 }, work_branches: ['الخوير'] }),
                ]}
            />,
        );

        const owners = screen.getByTestId('owner-accounts');
        expect(within(owners).getByText('المالك')).toBeInTheDocument();
        expect(within(owners).getByText('صاحب النشاط')).toBeInTheDocument();
        const table = screen.getByRole('table');
        expect(within(table).queryByText('المالك')).not.toBeInTheDocument();
        expect(within(table).getByText('يتبع الوظيفة')).toBeInTheDocument();
        expect(within(table).getByText('صلاحيات مخصصة · 7 أقسام · 3 أفعال حساسة')).toBeInTheDocument();
        expect(within(table).getAllByText('ملف: موظف مبيعات')).toHaveLength(2);
        expect(within(table).getByText('الخوير')).toBeInTheDocument();
        expect(within(table).getByText('كل الفروع')).toBeInTheDocument();
    });
});
