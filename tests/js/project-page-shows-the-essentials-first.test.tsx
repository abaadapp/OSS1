import { cleanup, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { router } from '@inertiajs/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ProjectShow from '@/Pages/Admin/Projects/Show';
import ProjectStepper from '@/Components/ProjectStepper';
import { projectSignals, type SignalSource } from '@/Components/project/ProjectSignals';
import { toStages, type Workflow } from '@/lib/projectStages';

/**
 * صفحةُ المشروع هادئة — والهدوءُ لا يحذف شيئًا.
 *
 * الرأسُ يقول الأساسيَّ وفعلًا أساسيًّا واحدًا وقائمةَ «⋯» لما سواه، وشريطُ
 * المراحل سطرٌ مضغوط، وما يُنتبه إليه شاراتٌ قصيرة تفتح تبويبَها. والتبويباتُ
 * الأربعَ عشرةَ كلُّها باقيةٌ بأسمائها، وكلٌّ يبدأ بسطر خلاصةٍ ثمّ فعلِه ثمّ
 * بياناتِه. والجوّالُ لا يمرّ أفقيًّا: ما يطول يمرّ داخل شريطه.
 */

vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));

beforeEach(() => {
    /* والتبويبُ يُكتب في الرابط — فكلُّ اختبارٍ يبدأ من رابطٍ نظيف */
    window.history.replaceState(null, '', '/');
    globalThis.fetch = vi.fn(async () => ({ ok: true, status: 200, json: async () => ({ timeline: [], may: { send: true, retry: false, templates: false, view: true, hub: false } }) })) as never;
});

afterEach(() => vi.clearAllMocks());

const TABS = ['المسار', 'البيانات', 'النطاق', 'المتطلّبات', 'الفريق', 'المهامّ', 'أوامر التغيير', 'التسليم', 'المراسلات', 'العمليات', 'المشتريات', 'المخزون', 'المال', 'الأداء'];

const workflow: Workflow = {
    stages: [
        { key: 'quote', state: 'done', done: true, focus: false, reachable: true, note: null, pending: [], warnings: [] },
        { key: 'scope', state: 'done', done: true, focus: false, reachable: true, note: 'فُتح', pending: [], warnings: [] },
        { key: 'deposit', state: 'current', done: false, focus: true, reachable: true, note: null, pending: ['لم تُصدر فاتورة الدفعة المقدمة بعد.'], warnings: [] },
        { key: 'execution', state: 'todo', done: false, focus: false, reachable: true, note: null, pending: ['لا فعاليّة تشغيليّة على هذا المشروع — أنشئ فعاليّة التنفيذ وأتمّها.'], warnings: [] },
        { key: 'delivery', state: 'todo', done: false, focus: false, reachable: true, note: null, pending: [], warnings: [] },
        { key: 'final', state: 'todo', done: false, focus: false, reachable: true, note: null, pending: [], warnings: [] },
        { key: 'closing', state: 'todo', done: false, focus: false, reachable: true, note: null, pending: [], warnings: [] },
    ],
    focus: 'deposit',
    frontier: 'deposit',
    next: 'execution',
    previous: 'scope',
};

const draft = { id: 31, number: null, status: 'مسودة', role: 'project_deposit', payment_state: 'غير مدفوعة', issued_at: null, due_at: null, total: 5250, outstanding: null, percent: 50, method: 'percent' };

const props = () => ({
    project: {
        id: 5, code: 'EP-2026-001', name: 'مؤتمر الطاقة', customer: 'وزارة الطاقة', status: 'in_progress', starts_at: '2026-11-01', ends_at: '2026-11-02',
        approved_value: 10000, current_value: 10500, quoted_value: 10000, owner: 'المالك', owner_id: 1, baselined: false, event_type: 'مؤتمر', location: 'مسقط',
        contact_person: 'سالم', contact_phone: '+96891234567', contact_email: 'salem@energy.gov.om', customer_contact_id: null, notes: null, internal_notes: null,
        requirements_baselined_at: null as string | null, requirements_baselined_by: null, closed_at: null, cancellation_reason: null, cancellation_policy: null,
        cancellation_fee_invoice: null, financials_frozen: null, quotation_id: null, scope_broken: [] as string[],
    },
    scope: {
        original: { quotation: null, items: [], requirements: [], approved_value: 10000, baselined_at: null },
        changes: [],
        current: { items: [{ id: 1, description: 'تشغيل نظام تسجيل', unit: 'يوم', quantity: 10, unit_price: 1000, line_total: 10500, delivered_quantity: 0, status: 'open', origin: 'quotation' as const }], requirements: [], starts_at: '2026-11-01', ends_at: '2026-11-02', current_value: 10500 },
    },
    requirements: [
        { id: 1, title: 'شاشة رئيسية بعرض ٦ أمتار', details: null, project_item_id: null, item: null, baseline: false, active: true, added_by: null, removed_by: null },
        { id: 2, title: 'مدخل لكبار الشخصيات', details: null, project_item_id: null, item: null, baseline: false, active: true, added_by: null, removed_by: null },
    ],
    members: [{ id: 1, user_id: 1, name: 'المالك', role: 'مدير المشروع' }],
    tasks: [
        { id: 1, title: 'حجز القاعة', description: null, assignee_id: 1, assignee: 'المالك', due_at: '2020-01-01', status: 'open', priority: 'high', completed_at: null },
        { id: 2, title: 'تصميم الشاشة', description: null, assignee_id: null, assignee: null, due_at: '2999-01-01', status: 'open', priority: 'normal', completed_at: null },
        { id: 3, title: 'توقيع العقد', description: null, assignee_id: 1, assignee: 'المالك', due_at: '2020-01-01', status: 'done', priority: 'normal', completed_at: '2026-10-01 10:00' },
    ],
    change_orders: [],
    financials: {
        quoted_value: 10000, approved_value: 10000, current_value: 10500, invoiced: 5250, credited: 0, net_invoiced: 5250, collected: 2000, customer_outstanding: 3250,
        supplier_actual: 0, expenses_paid: 0, stock_consumed: 0, actual: 0, open_purchase_orders: 0, pending_supplier_invoices: 0, expenses_unpaid: 0, committed: 0,
        supplier_outstanding: 0, expected_cost: 0, realized_gross_profit: 0, realized_margin_percent: 0, forecast_margin: 0, forecast_margin_percent: 0, invoiced_above_approved: false,
    },
    delivery: { readiness: { ready: false, blockers: ['لا حزمة تسليم بعد.'], accepted_count: 0, awaiting_count: 0, unresolved_decline_count: 0, last_accepted_at: null }, packages: [] },
    cancellation: null,
    operations: [],
    inventory_forecast: {
        days: 14, from: '2026-10-17', to: '2026-10-31', in_window: true, date: '2026-11-01', shortages: 2, timelines: {},
        lines: [
            { product_id: 9, name: 'كرسي مؤتمرات', sku: null, available: 10, committed: 0, required: 12, projected: -2, shortage: 2, source: 'scope' as const },
            { product_id: 10, name: 'طاولة مستديرة', sku: null, available: 1, committed: 0, required: 3, projected: -2, shortage: 2, source: 'operation' as const },
        ],
    },
    task_operations: [],
    performance: {
        baseline: 10000, change_orders_delta: 500, contracted: 10500, invoiced: 5250, credited: 0, net_invoiced: 5250, revenue: 5000, collected: 2000, receivable: 3250,
        direct_costs: { supplier_invoices: 0, expenses: 0, stock_consumed: 0, total: 0 }, committed: 0, expected_cost: 0, contribution: 5000, margin_percent: 100,
        forecast_margin: 10500, forecast_margin_percent: 100, invoiced_above_approved: false, completeness: { status: 'complete' as const, reasons: [] }, basis: 'صافٍ من الضريبة', timeline: [],
    },
    billing: {
        billed: false, paid: false, value: 10500, invoiced: 0, gross_invoiced: 0, credited: 0, drafts: 5250, unbilled: 10500, billable: 5250, collected: 0, outstanding: 0, rate: 5,
        deposit: { done: false, issued: 0, drafts: 1, waived: false, paid: false, waived_at: null, waiver_reason: null, invoices: [draft] },
        final: { quote: null, invoices: [] },
        may: { create: true, issue: true, waive: true },
    },
    invoices: [draft],
    close_blockers: [],
    workflow,
    quotation: null,
    scope_of_work: { required: true, latest: null, signed: null, revisions: [] },
    channels: { email: true, whatsapp: false, scope_whatsapp_template: false },
    users: [{ id: 1, name: 'المالك' }, { id: 2, name: 'سارة' }],
    contacts: [],
    purchases: {
        summary: { orders: 3, drafts: 0, total: 1500, received: 1, pending: 1, invoices: 0, invoiced: 0, paid: 400, outstanding: 600 },
        orders: [
            { id: 11, number: 'PO-000011', supplier_id: 5, supplier: 'شركة النور', status: 'مستلم', receipt: 'received' as const, closed: false, ordered_at: '2026-10-01', expected_delivery_at: null, total: 1000, kinds: ['service'], items: 1 },
            { id: 12, number: 'PO-000012', supplier_id: 6, supplier: 'مخازن الكراسي', status: 'مُرسل', receipt: 'pending' as const, closed: false, ordered_at: '2026-10-02', expected_delivery_at: null, total: 300, kinds: ['product'], items: 2 },
            { id: 13, number: 'PO-000013', supplier_id: 6, supplier: 'مخازن الكراسي', status: 'مسودة', receipt: 'draft' as const, closed: false, ordered_at: '2026-10-03', expected_delivery_at: null, total: 200, kinds: ['product'], items: 1 },
        ],
        invoices: [], suppliers: [], kinds: ['product', 'service'], statuses: [], money: true, may: { create: true },
    },
    may: { manage: true, budget: true, close: true, reopen: false, change_order: true, override_policy: false, delivery: true, operations: true, operations_manage: true, performance: true, invoice: true, commercial: true, purchases: true },
});

type ShowProps = Parameters<typeof ProjectShow>[0];
const show = (over: Partial<ReturnType<typeof props>> = {}) => render(<ProjectShow {...({ ...props(), ...over } as unknown as ShowProps)} />);
const tablist = () => screen.getByRole('tablist');
const tab = (name: string) => within(tablist()).getAllByRole('tab').find((el) => el.textContent?.startsWith(name))!;
const summary = () => document.querySelector('[data-tab-summary]')!;

describe('the project page keeps every tab', () => {
    it('shows all fourteen tabs by their current names and in their order', () => {
        show();

        const names = within(tablist()).getAllByRole('tab').map((el) => TABS.find((n) => el.textContent?.startsWith(n)));
        expect(names).toEqual(TABS);
    });

    it('hides only what a permission hides — and nothing else', () => {
        show({ operations: null, purchases: null, inventory_forecast: null, performance: null, financials: null, may: { ...props().may, budget: false, performance: false, purchases: false } } as never);

        const names = within(tablist()).getAllByRole('tab').map((el) => TABS.find((n) => el.textContent?.startsWith(n)));
        expect(names).toEqual(['المسار', 'البيانات', 'النطاق', 'المتطلّبات', 'الفريق', 'المهامّ', 'أوامر التغيير', 'التسليم', 'المراسلات']);
    });

    it('writes no zero on a tab, and a red dot only where something needs attention', () => {
        show();

        /* المهامّ: مفتوحتان وواحدةٌ متأخّرة — عددٌ ونقطة */
        expect(tab('المهامّ')).toHaveTextContent('المهامّ2');
        expect(within(tab('المهامّ')).getByLabelText('يحتاج تصحيحًا')).toBeInTheDocument();
        /* أوامر التغيير صفر — لا عدد ولا نقطة */
        expect(tab('أوامر التغيير').textContent).toBe('أوامر التغيير');
        /* أمرُ شراءٍ ينتظر الاستلام مجرى عملٍ لا مشكلة — العددُ بلا نقطة */
        expect(tab('المشتريات')).toHaveTextContent('المشتريات3');
        expect(within(tab('المشتريات')).queryByLabelText('يحتاج تصحيحًا')).not.toBeInTheDocument();
        expect(within(tab('النطاق')).queryByLabelText('يحتاج تصحيحًا')).not.toBeInTheDocument();
    });
});

describe('the header says the essentials', () => {
    it('names the project, its code, customer, date, status and current value — and drops the old card stack', () => {
        show();
        const header = screen.getByRole('banner');

        expect(within(header).getByRole('heading', { level: 1 })).toHaveTextContent('مؤتمر الطاقة');
        const meta = header.querySelector('[data-project-meta]')!;
        expect(meta).toHaveTextContent('EP-2026-001');
        expect(meta).toHaveTextContent('وزارة الطاقة');
        expect(meta).toHaveTextContent('2026-11-01 → 2026-11-02');
        expect(meta).toHaveTextContent('قيد التنفيذ');
        expect(meta).toHaveTextContent(/10,500\.000/);

        /* ما كان فوق التبويبات صار في «البيانات» أو شارة: لا شريطَ تنبيهٍ ولا رقاقةَ تثبيتٍ ولا «الأصل» */
        expect(screen.queryByText(/افتح تبويب المخزون/)).not.toBeInTheDocument();
        expect(screen.queryByText(/المتطلّبات لم تُثبَّت بعد/)).not.toBeInTheDocument();
        expect(within(header).queryByText(/الأصل/)).not.toBeInTheDocument();
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('offers one primary action from the stage the team stands on — and puts the rest behind ⋯', async () => {
        const user = userEvent.setup();
        show();
        const header = screen.getByRole('banner');

        /* الدفعةُ المقدّمة لم تتمّ: الفعلُ الأساسيّ يفتحها في «المسار» */
        const primary = within(header).getByRole('button', { name: 'الدفعة المقدمة' });
        expect(primary).toBeVisible();
        for (const secondary of ['أمر تغيير', 'تثبيت المتطلّبات', 'تأجيل', 'إقفال', 'إلغاء المشروع']) {
            expect(within(header).queryByRole('button', { name: secondary })).not.toBeInTheDocument();
        }

        const more = within(header).getByRole('button', { name: 'خيارات' });
        more.focus();
        await user.keyboard('{Enter}');
        const menu = await screen.findByRole('menu');
        expect(within(menu).getAllByRole('menuitem').map((el) => el.textContent)).toEqual(['أمر تغيير', 'تثبيت المتطلّبات', 'تأجيل', 'إقفال', 'إلغاء المشروع']);

        await user.click(within(menu).getByRole('menuitem', { name: 'تأجيل' }));
        expect(router.post).toHaveBeenCalledWith('/admin.projects.transition/5', { status: 'on_hold' }, expect.anything());
    });

    it('suggests moving on from the header once the stage is done — a suggestion, not a gate', async () => {
        const user = userEvent.setup();
        show({ workflow: { ...workflow, stages: workflow.stages.map((s) => (s.key === 'deposit' ? { ...s, state: 'done' as const, done: true, pending: [] } : s)) } });

        await user.click(within(screen.getByRole('banner')).getByRole('button', { name: /تم — الانتقال إلى التنفيذ/ }));
        expect(router.post).toHaveBeenCalledWith('/admin.projects.workflow.move/5', { stage: 'execution' }, expect.anything());
    });

    it('says a critical problem in full — not as a badge', () => {
        show({ project: { ...props().project, scope_broken: ['تشغيل نظام تسجيل'] } });

        expect(screen.getByRole('alert')).toHaveTextContent('كميّةُ بندٍ لا توافق الأصلَ وأوامرَ التغيير المقبولة: تشغيل نظام تسجيل');
    });
});

describe('the stepper is compact', () => {
    it('draws seven stages, ✓ on the done ones, the current one named and ringed — and no explanations', () => {
        show();
        const stepper = screen.getByRole('list', { name: 'مراحل المشروع' });
        const items = within(stepper).getAllByRole('listitem');

        expect(items).toHaveLength(7);
        expect(items.map((li) => li.textContent)).toEqual(['عرض السعر', 'نطاق العمل', 'الدفعة المقدمة', '4التنفيذ', '5التسليم', '6الفاتورة النهائية', '7الإغلاق']);
        expect(items.slice(0, 2).every((li) => li.querySelector('svg.lucide-check'))).toBe(true);
        expect(items.slice(2).some((li) => li.querySelector('svg.lucide-check'))).toBe(false);

        /* الحاليّةُ وحدها باسمٍ ظاهر وبحلقة؛ والبقيّةُ للقارئ الآليّ حتى يتّسع العرض */
        expect(items[2]).toHaveAttribute('aria-current', 'step');
        expect(within(items[2]).getByText('الدفعة المقدمة')).not.toHaveClass('sr-only');
        expect(within(items[0]).getByText('عرض السعر')).toHaveClass('sr-only', 'xl:not-sr-only');
        expect(items[2].querySelector('.ring-2')).not.toBeNull();

        /* ولا شرحَ تحت المراحل: ما ينقص وما يمنع في «المسار» — ولا ملاحظةُ «فُتح» بجانب الاسم */
        expect(stepper).not.toHaveTextContent('لم تُصدر فاتورة الدفعة المقدمة بعد');
        expect(stepper).not.toHaveTextContent('لم تكتمل مرحلة');
        expect(stepper).not.toHaveTextContent('فُتح');
        expect(stepper).toHaveClass('py-1.5');
    });

    it('moves the project to whichever stage a manager presses — back, forward or past an unfinished one — and opens it in «المسار»', async () => {
        const user = userEvent.setup();
        show();
        const stepper = () => screen.getByRole('list', { name: 'مراحل المشروع' });

        await user.click(tab('المهامّ'));
        await user.click(within(stepper()).getByRole('button', { name: /نطاق العمل/ }));
        expect(router.post).toHaveBeenLastCalledWith('/admin.projects.workflow.move/5', { stage: 'scope' }, expect.anything());
        expect(tab('المسار')).toHaveAttribute('aria-selected', 'true');
        expect(document.getElementById('stage-scope')).not.toBeNull();

        /* قفزٌ فوق «التنفيذ» وهو لم يكتمل — لا زرَّ معطَّلٌ ولا رسالةُ منع */
        await user.click(within(stepper()).getByRole('button', { name: /الفاتورة النهائية/ }));
        expect(router.post).toHaveBeenLastCalledWith('/admin.projects.workflow.move/5', { stage: 'final' }, expect.anything());
        expect(within(stepper()).getAllByRole('button').every((b) => !b.hasAttribute('disabled'))).toBe(true);
        expect(screen.getByText(/اضغط أيّ مرحلة لتنقل المشروع إليها/)).toBeInTheDocument();
    });

    it('only opens the current stage when it is pressed — nothing is posted', async () => {
        const user = userEvent.setup();
        show();

        await user.click(within(screen.getByRole('list', { name: 'مراحل المشروع' })).getByRole('button', { name: /الدفعة المقدمة/ }));
        expect(router.post).not.toHaveBeenCalled();
        expect(tab('المسار')).toHaveAttribute('aria-selected', 'true');
    });

    it('lets whoever does not manage the project open a stage — and move nothing', async () => {
        const user = userEvent.setup();
        show({ may: { ...props().may, manage: false } });

        await user.click(within(screen.getByRole('list', { name: 'مراحل المشروع' })).getByRole('button', { name: /نطاق العمل/ }));
        expect(router.post).not.toHaveBeenCalled();
        expect(document.getElementById('stage-scope')).not.toBeNull();
        expect(screen.queryByText(/اضغط أيّ مرحلة لتنقل المشروع إليها/)).not.toBeInTheDocument();
    });

    it('still names a stop on a cancelled project', () => {
        render(<ProjectStepper stages={toStages({ stages: workflow.stages.map((s) => (s.key === 'deposit' ? { ...s, state: 'failed', note: 'ملغى' } : s)) })} />);
        const items = screen.getAllByRole('listitem');

        expect(within(items[2]).getByText('ملغى')).not.toHaveClass('sr-only');
        expect(items[2].querySelector('svg.lucide-x')).not.toBeNull();
    });
});

describe('what needs attention is a short badge that opens its tab', () => {
    it('reads the shortages, the late tasks, the draft invoice and the open requirements from the page itself', () => {
        show();
        const badges = within(screen.getByRole('list', { name: 'تنبيهات المشروع' })).getAllByRole('button');

        expect(badges.map((b) => b.textContent)).toEqual(['نقص مخزون 2', 'مهام متأخرة 1', 'فاتورة مسودة 1', 'متطلّبات غير مثبَّتة 2']);
    });

    it('switches to the tab it names', async () => {
        const user = userEvent.setup();
        show();

        await user.click(screen.getByRole('button', { name: 'مهام متأخرة 1' }));
        expect(tab('المهامّ')).toHaveAttribute('aria-selected', 'true');

        await user.click(screen.getByRole('button', { name: 'نقص مخزون 2' }));
        expect(tab('المخزون')).toHaveAttribute('aria-selected', 'true');
        expect(screen.getByText('كرسي مؤتمرات')).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'متطلّبات غير مثبَّتة 2' }));
        expect(tab('المتطلّبات')).toHaveAttribute('aria-selected', 'true');

        await user.click(screen.getByRole('button', { name: 'فاتورة مسودة 1' }));
        expect(tab('المسار')).toHaveAttribute('aria-selected', 'true');
    });

    it('invents nothing: a calm project has no badge, and a purchase awaiting receipt is not a problem', () => {
        const calm: SignalSource = {
            open: true, baselined: true, requirements: [{ active: true }], tasks: [{ status: 'open', due_at: '2999-01-01' }, { status: 'done', due_at: '2020-01-01' }],
            inventory_forecast: { shortages: 0 }, billing: { deposit: { invoices: [{ status: 'صادرة' }] }, final: { invoices: [] } }, invoices: null,
            operations: [{ status: 'ready', ready: false }, { status: 'planning', ready: true }], delivery: { readiness: { ready: false, blockers: ['x'] }, packages: [] },
            performance: { contribution: 10 }, today: '2026-10-17',
        };
        expect(projectSignals(calm)).toEqual([]);

        expect(projectSignals({ ...calm, operations: [{ status: 'planning', ready: false }], performance: { contribution: -1 } }).map((s) => s.label)).toEqual(['مساهمة سالبة', 'فعاليّة غير جاهزة']);
    });
});

describe('every tab starts with a summary line, then its action, then its data', () => {
    it('tasks: open · overdue · done, then «إضافة مهمّة» in a dialog, then the list — and the buttons still work', async () => {
        const user = userEvent.setup();
        show();
        await user.click(tab('المهامّ'));

        expect(summary()).toHaveTextContent('المفتوحة 2 · المتأخّرة 1 · المكتملة 1');
        expect(screen.getByText('حجز القاعة')).toBeInTheDocument();
        /* ولا نموذجٌ مفتوحٌ بجانب القائمة */
        expect(screen.queryByRole('textbox')).not.toBeInTheDocument();

        /* والخادمُ يقبل: النافذةُ تُغلق بعد النجاح لا قبله */
        vi.mocked(router.post).mockImplementation(((_url: string, _data: unknown, options?: { onSuccess?: () => void }) => options?.onSuccess?.()) as never);
        await user.click(screen.getByRole('button', { name: 'إضافة مهمّة' }));
        const dialog = await screen.findByRole('dialog');
        await user.type(within(dialog).getAllByRole('textbox')[0], 'تجهيز الشاشات');
        await user.click(within(dialog).getByRole('button', { name: 'إضافة' }));
        expect(router.post).toHaveBeenCalledWith('/admin.projects.tasks.store/5', expect.objectContaining({ title: 'تجهيز الشاشات', priority: 'normal' }), expect.anything());
        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());

        await user.click(within(screen.getByText('تصميم الشاشة').closest('li')!).getByRole('button', { name: /تمّ/ }));
        expect(router.put).toHaveBeenCalledWith('/admin.projects.tasks.update/5/2', { title: 'تصميم الشاشة', status: 'done' }, expect.anything());
    });

    it('team: the member count, then «إضافة عضو», then the members', async () => {
        const user = userEvent.setup();
        show();
        await user.click(tab('الفريق'));

        expect(summary()).toHaveTextContent('الأعضاء 1');
        expect(screen.getByText('المالك')).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'إضافة عضو' }));
        expect(await screen.findByRole('dialog')).toBeInTheDocument();
    });

    it('purchases: the orders and what awaits receipt, then «إضافة شراء», then the orders', async () => {
        const user = userEvent.setup();
        show();
        await user.click(tab('المشتريات'));

        expect(summary()).toHaveTextContent('أوامر الشراء 3 · بانتظار الاستلام 1 · الإجمالي');
        expect(screen.getByRole('link', { name: /إضافة شراء/ })).toHaveAttribute('href', '/admin.purchases.create/5');
        expect(screen.getByText('PO-000013')).toHaveAttribute('href', '/admin.purchases.show/13');
        /* والأرقامُ المفصّلة مطويّةٌ لا محذوفة */
        expect(screen.getByText('المدفوع').closest('details')).not.toHaveAttribute('open');
    });

    it('inventory: the items short, then the forecast itself', async () => {
        const user = userEvent.setup();
        show();
        await user.click(tab('المخزون'));

        expect(summary()).toHaveTextContent('بنود فيها نقص 2 · المنتجات 2');
        expect(screen.getByText('طاولة مستديرة')).toBeInTheDocument();
        /* وشرحُ الأعمدة تحت الجدول لا فوقه */
        const note = screen.getByText(/لكل منتج مخزّن: المتوفر الآن/);
        expect(screen.getByRole('table').compareDocumentPosition(note) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    });

    it('money: invoiced · collected · outstanding, then revenue, cost and profit', async () => {
        const user = userEvent.setup();
        show();
        await user.click(tab('المال'));

        expect(summary()).toHaveTextContent(/المفوتَر 5,250\.000 .* · المحصَّل 2,000\.000 .* · الباقي على العميل 3,250\.000/);
        for (const heading of ['الإيراد', 'التكلفة', 'الربحية']) expect(screen.getByRole('heading', { name: heading })).toBeInTheDocument();
    });

    it('scope: the current scope first, the original and its changes folded under it', async () => {
        const user = userEvent.setup();
        show();
        await user.click(tab('النطاق'));

        expect(summary()).toHaveTextContent('البنود 1');
        expect(screen.getByText('تشغيل نظام تسجيل')).toBeInTheDocument();
        const folded = screen.getByText('الأصل وما تغيّر').closest('details')!;
        expect(folded).not.toHaveAttribute('open');
        /* والشرحُ مطويٌّ معه لا فوق الجدول */
        expect(folded).toContainElement(screen.getByText(/ما يجب على الفريق تنفيذه الآن/));
    });

    it('data: what left the header — the requirements baseline and the original value — is read here', async () => {
        const user = userEvent.setup();
        show();
        await user.click(tab('البيانات'));

        expect(summary()).toHaveTextContent(/^القيمة الأصلية 10,000\.000/);
        expect(screen.getByRole('button', { name: 'تعديل' })).toBeInTheDocument();
        cleanup();

        show({ project: { ...props().project, baselined: true, requirements_baselined_at: '2026-10-03 09:00' } });
        await user.click(tab('البيانات'));
        expect(summary()).toHaveTextContent('متطلّبات مثبَّتة 2026-10-03 09:00 · القيمة الأصلية 10,000.000');
    });
});

describe('a phone 390px wide never scrolls the page sideways', () => {
    /*
     * jsdom لا يرسم فلا يقيس — فيُختبر البناءُ الذي يضمن ذلك: الصفحةُ لا تمدّ
     * عرضَها، وما يطول (التبويبات والمراحل) يمرّ داخل شريطه، والرأسُ يلتفّ.
     */
    it('lets the tab strip and the stepper scroll inside themselves, and wraps the header', () => {
        show();

        expect(document.querySelector('[data-project-page]')).toHaveClass('min-w-0');
        expect(tablist()).toHaveClass('overflow-x-auto');
        expect(within(tablist()).getAllByRole('tab').every((el) => el.className.includes('whitespace-nowrap'))).toBe(true);

        const stepper = screen.getByRole('list', { name: 'مراحل المشروع' });
        expect(stepper).toHaveClass('overflow-x-auto', 'min-w-0');
        /* والمرحلةُ لا تضيق عن اسمها فتركب جارتَها — ما يزيد يمرّ داخل الشريط */
        expect(within(stepper).getAllByRole('listitem').every((li) => li.classList.contains('min-w-fit'))).toBe(true);
        /* والاسمُ المطويّ للقارئ الآليّ يُحسب داخل زرّه — لا يمدّ الصفحة */
        expect(within(stepper).getAllByRole('button').every((b) => b.classList.contains('relative'))).toBe(true);

        const header = screen.getByRole('banner');
        expect(header).toHaveClass('min-w-0');
        /* الاسمُ فوق الأفعال على الجوّال، وبجانبها حين يتّسع */
        expect(header.firstElementChild).toHaveClass('flex-col', 'sm:flex-row', 'sm:flex-wrap');
        expect(header.querySelector('[data-project-meta]')).toHaveClass('flex-wrap');
    });

    it('gives nothing above the tab content a fixed width', () => {
        show();
        const fixed = /(^|\s)(min-)?w-\[\d+(px|rem)\]/;
        const above = [screen.getByRole('banner'), screen.getByRole('list', { name: 'مراحل المشروع' }), tablist()];

        for (const region of above) {
            for (const el of [region, ...region.querySelectorAll('*')]) {
                expect(typeof el.className === 'string' ? el.className : '').not.toMatch(fixed);
            }
        }
    });
});
