import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { router } from '@inertiajs/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ProcessTab, { type ProcessData } from '@/Pages/Admin/Projects/partials/ProcessTab';
import ScopeOfWork from '@/Pages/Admin/Projects/ScopeOfWork';
import { toStages, type Workflow } from '@/lib/projectStages';

/**
 * شاشاتُ المسار تُركَّب بحمولةٍ كالتي يرسلها الخادم — وتُضغط.
 *
 * الدفعةُ المقدّمة تفتح على ٥٠٪ وتسأل الخادمَ عن حسابها، ولا ترسل مبلغًا
 * حسبته هي: ترسل الطريقةَ والنسبةَ ومفتاحَ الطلب. وشاشةُ نطاق العمل تحفظ
 * المسودّة ولا تُجهّزها للإرسال وفيها تعديلٌ لم يُحفظ.
 */

vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));

const fetchMock = vi.fn();

beforeEach(() => {
    fetchMock.mockReset();
    fetchMock.mockImplementation(async (url: string) => ({
        ok: true,
        status: 200,
        json: async () =>
            String(url).includes('billing.preview')
                ? { quote: { method: 'percent', percent: 50, share: 50, amount: 5250, net: 5000, tax: 250, total: 5250, rate: 5, value: 10500, billable_before: 10500, remaining_after: 5250 }, error: null }
                : { communications: [], may: { send: true, retry: false, templates: false, view: true, hub: false } },
    }));
    globalThis.fetch = fetchMock as never;
});

afterEach(() => vi.clearAllMocks());

const flow: Workflow = {
    stages: [
        { key: 'quote', state: 'done', done: true, focus: false, reachable: true, note: null, pending: [], blocked_by: [] },
        { key: 'scope', state: 'done', done: true, focus: false, reachable: true, note: null, pending: [], blocked_by: [] },
        { key: 'deposit', state: 'current', done: false, focus: true, reachable: true, note: null, pending: ['لم تُصدر فاتورة الدفعة المقدمة بعد.'], blocked_by: [] },
        { key: 'execution', state: 'todo', done: false, focus: false, reachable: false, note: null, pending: ['لا فعاليّة تشغيليّة على هذا المشروع — أنشئ فعاليّة التنفيذ وأتمّها.'], blocked_by: ['لم تكتمل مرحلة «الدفعة المقدمة» بعد', 'لم تُصدر فاتورة الدفعة المقدمة بعد.'] },
        { key: 'delivery', state: 'todo', done: false, focus: false, reachable: false, note: null, pending: [], blocked_by: [] },
        { key: 'final', state: 'todo', done: false, focus: false, reachable: false, note: null, pending: [], blocked_by: [] },
        { key: 'closing', state: 'todo', done: false, focus: false, reachable: false, note: null, pending: [], blocked_by: [] },
    ],
    focus: 'deposit',
    frontier: 'deposit',
    next: 'execution',
    previous: 'scope',
    can_advance: false,
    can_back: true,
};

const data = (): ProcessData => ({
    workflow: flow,
    quotation: {
        id: 3, label: 'Q-000003', title: 'مؤتمر الطاقة', total: 10500, sent_at: '2026-10-01 10:00', decided_at: '2026-10-02 12:00', decided_by_name: 'سالم الهنائي',
        content_hash: 'a'.repeat(64), public_url: '/i/abc',
        evidence: { signer_name: 'سالم الهنائي', signer_title: 'مدير الفعاليات', signer_email: 'salem@energy.gov.om', signer_phone: null, decided_at: '2026-10-02 12:00', ip: '10.0.0.7', consent_text: 'أقرّ', content_hash: 'a'.repeat(64), verified: true },
    },
    scope: {
        required: true,
        latest: { id: 9, number: 'EP-2026-001/SOW-R1', revision: 1, status: 'signed', state: 'signed', sent_at: '2026-10-03 09:00', decided_at: '2026-10-03 11:00', decided_by_name: 'سالم الهنائي', view_count: 2, url: '/i/sow', approval: { signer_name: 'سالم الهنائي', signer_title: null, decided_at: '2026-10-03 11:00', ip: '10.0.0.7', verified: true } },
        signed: { id: 9, number: 'EP-2026-001/SOW-R1', revision: 1, status: 'signed', state: 'signed', sent_at: '2026-10-03 09:00', decided_at: '2026-10-03 11:00', decided_by_name: 'سالم الهنائي', approval: { signer_name: 'سالم الهنائي', signer_title: null, decided_at: '2026-10-03 11:00', ip: '10.0.0.7', verified: true } },
        revisions: [],
    },
    requirements: { active: 1 },
    members: [],
    tasks: [],
    change_orders: [],
    operations: [],
    delivery: { readiness: { ready: false, blockers: ['لا حزمة تسليم بعد.'] }, packages: [] },
    billing: {
        billed: false, paid: false, value: 10500, invoiced: 0, gross_invoiced: 0, credited: 0, drafts: 0, unbilled: 10500, billable: 10500, collected: 0, outstanding: 0, rate: 5,
        deposit: { done: false, issued: 0, drafts: 0, waived: false, paid: false, waived_at: null, waiver_reason: null, invoices: [] },
        final: { quote: { value: 10500, invoiced: 0, credited: 0, net_invoiced: 0, amount: 10500, net: 10000, tax: 500, total: 10500, rate: 5, drafts: 0 }, invoices: [] },
        may: { create: true, issue: true, waive: true },
    },
    invoices: [],
    close_blockers: ['لم يوقّع العميل نطاق العمل بعد — أرسله للتوقيع قبل الإقفال.'],
    channels: { email: true, whatsapp: false, scope_whatsapp_template: false },
    users: [{ id: 1, name: 'المالك' }],
});

const project = {
    id: 5, name: 'مؤتمر الطاقة', customer: 'وزارة الطاقة', status: 'confirmed', starts_at: '2026-11-01', ends_at: '2026-11-02', location: 'مسقط',
    owner: 'المالك', owner_id: 1, baselined: true, requirements_baselined_at: '2026-10-03 09:00', contact_person: 'سالم', contact_phone: '+96891234567',
    contact_email: 'salem@energy.gov.om', event_type: null, notes: null, internal_notes: null, closed_at: null, current_value: 10500, approved_value: 10500,
};

const may = { manage: true, close: true, reopen: false, change_order: true, delivery: true, operations: true, operations_manage: true, invoice: true, commercial: true };

describe('the project path', () => {
    it('opens on the stage the team stands on, with the acceptance evidence one click away', async () => {
        const user = userEvent.setup();
        render(<ProcessTab p={project} d={data()} stages={toStages(flow)} may={may} m={(v) => String(v ?? '—')} focus={null} onTab={() => undefined} />);

        expect(screen.getByRole('button', { name: /إنشاء فاتورة الدفعة المقدمة/ })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /تم — الانتقال إلى/ })).toBeDisabled();

        /* والمرحلةُ المقفلة تقول ما يمنعها */
        const stage = (key: string) => within(document.getElementById(`stage-${key}`)!).getAllByRole('button')[0];
        await user.click(stage('execution'));
        expect(screen.getByText('لا تُفتح هذه المرحلة بعد')).toBeInTheDocument();

        await user.click(stage('quote'));
        expect(screen.getByText('دليل القبول')).toBeInTheDocument();
        expect(screen.getByText('10.0.0.7')).toBeInTheDocument();
    });

    it('asks the server for the deposit at 50% and sends the method — not a computed amount', async () => {
        const user = userEvent.setup();
        render(<ProcessTab p={project} d={data()} stages={toStages(flow)} may={may} m={(v) => String(v ?? '—')} focus={null} onTab={() => undefined} />);

        await user.click(screen.getByRole('button', { name: /إنشاء فاتورة الدفعة المقدمة/ }));
        const dialog = await screen.findByRole('dialog');

        await waitFor(() => expect(fetchMock).toHaveBeenCalled());
        expect(String(fetchMock.mock.calls[0][0])).toContain('billing.preview');
        expect(String(fetchMock.mock.calls[0][0])).toContain('50');
        expect(await within(dialog).findByText('مبلغ الدفعة')).toBeInTheDocument();
        expect(within(dialog).getAllByText('5250')).toHaveLength(2);

        await user.click(within(dialog).getByRole('button', { name: /إصدار الدفعة/ }));
        expect(router.post).toHaveBeenCalledTimes(1);
        const [url, payload] = vi.mocked(router.post).mock.calls[0];
        expect(url).toBe('/admin.projects.billing.deposit/5');
        expect(payload).toMatchObject({ method: 'percent', percent: '50', amount: '', issue: '1' });
        expect(payload).not.toHaveProperty('total');
        expect(String((payload as Record<string, unknown>).request_key).length).toBeGreaterThan(8);
    });
});

describe('the scope of work screen', () => {
    const current = {
        id: 9, number: 'EP-2026-001/SOW-R1', revision: 1, status: 'draft', state: 'draft', editable: true,
        customer_name: 'وزارة الطاقة', contact_person: 'سالم', contact_phone: null, contact_email: null, project_name: 'مؤتمر الطاقة',
        event_type: null, location: 'مسقط', starts_at: '2026-11-01', ends_at: '2026-11-02', schedule_notes: null, description: 'تشغيل', inclusions: null,
        exclusions: null, company_responsibilities: null, client_responsibilities: null, terms: null, show_value: false,
        snapshot: { quotation: { label: 'Q-000003', hash: 'x', decided_at: '' }, change_orders: [], items: [{ description: 'تشغيل نظام تسجيل', unit: 'يوم', quantity: '10.000', unit_price: '1000.000', line_total: '10500.000' }], requirements: [], starts_at: '', ends_at: '', value: '10500.000' },
        content_hash: null, ready_at: null, sent_at: null, sent_by: null, first_viewed_at: null, last_viewed_at: null, view_count: 0, decided_at: null,
        decided_by_name: null, decline_reason: null, url: null, stale: false, approval: null,
    };

    it('saves a draft before it can be marked ready, and reads its lines from the approved scope', async () => {
        const user = userEvent.setup();
        render(
            <ScopeOfWork
                project={{ id: 5, code: 'EP-2026-001', name: 'مؤتمر الطاقة', customer: 'وزارة الطاقة', status: 'confirmed', open: true, baselined: false, required: true }}
                current={current}
                revisions={[{ id: 9, number: 'EP-2026-001/SOW-R1', revision: 1, status: 'draft', state: 'draft', sent_at: null, decided_at: null, decided_by_name: null, superseded_at: null, url: null }]}
                channels={{ email: false, whatsapp: false, whatsapp_template: { ar: false, en: false } }}
                may={{ manage: true, send: true, communications: true, templates: true, commercial: true }}
            />,
        );

        expect(screen.getByText('تشغيل نظام تسجيل')).toBeInTheDocument();
        expect(screen.getByText('واتساب غير موصول على الخادم')).toBeInTheDocument();
        const save = screen.getByRole('button', { name: /حفظ المسودّة/ });
        const ready = screen.getByRole('button', { name: /جاهز للإرسال/ });
        expect(save).toBeDisabled();
        expect(ready).toBeEnabled();

        await user.type(screen.getAllByRole('textbox').find((el) => (el as HTMLTextAreaElement).value === 'تشغيل')!, ' كامل');
        expect(save).toBeEnabled();
        expect(ready).toBeDisabled();

        await user.click(save);
        expect(router.put).toHaveBeenCalledWith('/admin.projects.scope.update/5/9', expect.objectContaining({ description: 'تشغيل كامل', show_value: '0' }), expect.anything());
    });
});
