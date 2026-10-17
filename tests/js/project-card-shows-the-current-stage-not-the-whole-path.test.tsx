import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { router } from '@inertiajs/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ProjectsIndex from '@/Pages/Admin/Projects/Index';
import type { Workflow } from '@/lib/projectStages';

/**
 * بطاقةُ المشروع في القائمة — ما هو ولمن ومتى وكم، ومرحلتُه الحاليّة وحدَها
 * وأهمُّ تنبيهين، والبطاقةُ كلُّها رابطٌ إلى صفحته. والمسارُ كاملًا وما
 * ينقص كلَّ مرحلةٍ في الصفحة لا على البطاقة. والبحثُ والتصفيةُ باقيان.
 */

vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));

afterEach(() => vi.clearAllMocks());

const workflow = (over: Partial<Workflow> = {}): Workflow => ({
    stages: [
        { key: 'quote', state: 'done', focus: false, note: null },
        { key: 'scope', state: 'done', focus: false, note: null },
        { key: 'deposit', state: 'current', focus: true, note: null },
        { key: 'execution', state: 'todo', focus: false, note: null },
        { key: 'delivery', state: 'todo', focus: false, note: null },
        { key: 'final', state: 'todo', focus: false, note: null },
        { key: 'closing', state: 'todo', focus: false, note: null },
    ],
    focus: 'deposit',
    next: 'execution',
    pending: ['لم تُصدر فاتورة الدفعة المقدمة بعد.'],
    ...over,
});

const row = (over: Record<string, unknown> = {}) => ({
    id: 5, code: 'EP-2026-001', name: 'مؤتمر الطاقة', customer: 'وزارة الطاقة', customer_id: 2, status: 'in_progress', starts_at: '2026-11-01', ends_at: '2026-11-02',
    approved_value: 10000, current_value: 10500, owner: 'المالك', owner_id: 1, baselined: true, open_tasks: 3, late_tasks: 2, location: 'مسقط',
    created_at: '2026-10-01', quotation: { id: 3, number: 'Q-000003' }, operation: null, delivery: null, workflow: workflow(), inventory_shortages: 4,
    ...over,
});

const props = (projects = [row()]) => ({
    projects,
    quotes: [{ id: 8, number: 'Q-000008', title: 'معرض الكتاب', customer: 'وزارة الثقافة', status: 'sent', location: null, starts_at: '2026-12-01', ends_at: null, total: 2000, created_at: '2026-10-10', created_by: 1 }],
    me: 1,
    pagination: { current_page: 1, last_page: 1, from: 1, to: 2, total: 2, prev_page_url: null, next_page_url: null },
    filters: {},
    statuses: ['confirmed', 'in_progress', 'on_hold', 'closed', 'cancelled'],
    customers: [{ id: 2, name: 'وزارة الطاقة' }],
    totals: { open: 1, upcoming: 0, unbaselined: 0, pending_change_orders: 0 },
    may: { delivery: true, manage: true },
});

type IndexProps = Parameters<typeof ProjectsIndex>[0];
const list = (p = props()) => render(<ProjectsIndex {...(p as unknown as IndexProps)} />);
const card = (name: string) => screen.getByText(name).closest('article')!;

describe('the project card', () => {
    it('shows code, name, customer, date, status, current stage and value', () => {
        list();
        const c = card('مؤتمر الطاقة');

        expect(c).toHaveTextContent('#EP-2026-001');
        expect(c).toHaveTextContent('وزارة الطاقة');
        expect(c).toHaveTextContent('2026-11-01');
        expect(c).toHaveTextContent('قيد التنفيذ');
        expect(c).toHaveTextContent(/10,500\.000/);
        expect(c.querySelector('[data-stage="deposit"]')).toHaveTextContent('الدفعة المقدمة');
    });

    it('draws no full path and no explanation of what is missing — that lives in the project', () => {
        list();
        const c = card('مؤتمر الطاقة');

        expect(within(c).queryByRole('list', { name: 'مراحل المشروع' })).not.toBeInTheDocument();
        expect(within(c).queryByText('نطاق العمل')).not.toBeInTheDocument();
        expect(within(c).queryByText('لم تُصدر فاتورة الدفعة المقدمة بعد.')).not.toBeInTheDocument();
        /* وما ينقص يبقى تلميحًا على شارة المرحلة */
        expect(c.querySelector('[data-stage="deposit"]')).toHaveAttribute('title', 'لم تُصدر فاتورة الدفعة المقدمة بعد.');
    });

    it('carries at most two alerts', () => {
        list();
        const c = card('مؤتمر الطاقة');

        expect(c).toHaveTextContent('2 متأخّرة');
        expect(c).toHaveTextContent('نقص مخزون: 4 بنود');
    });

    it('hides the value from whoever may not see it', () => {
        list(props([row({ current_value: null, approved_value: null })]));

        expect(card('مؤتمر الطاقة')).not.toHaveTextContent('ر.ع');
    });

    it('opens the project from anywhere on the card, and keeps its next step and menu above the link', async () => {
        const user = userEvent.setup();
        /* الدفعةُ تمّت والفريقُ ما زال عليها — فيُقترح الانتقالُ إلى التي بعدها */
        const doneHere = workflow().stages.map((s) => (s.key === 'deposit' ? { ...s, state: 'done' as const } : s));
        list(props([row({ workflow: workflow({ stages: doneHere, pending: [] }) })]));
        const c = card('مؤتمر الطاقة');

        const link = within(c).getByRole('link', { name: 'مؤتمر الطاقة' });
        expect(link).toHaveAttribute('href', '/admin.projects.show/5');
        expect(link).toHaveClass('after:absolute', 'after:inset-0');

        const advance = within(c).getByRole('button', { name: /تم — الانتقال إلى التنفيذ/ });
        expect(advance).toHaveClass('relative', 'z-10');
        expect(within(c).getByRole('button', { name: 'خيارات' })).toHaveClass('relative', 'z-10');

        await user.click(advance);
        expect(router.post).toHaveBeenCalledWith('/admin.projects.workflow.move/5', { stage: 'execution' }, expect.anything());
    });

    it('names a quotation by its first stage and state', () => {
        list();
        const c = card('معرض الكتاب');

        expect(c.querySelector('[data-stage="quote"]')).toHaveTextContent('عرض السعر');
        expect(within(c).getByRole('link', { name: 'تسجيل قرار العميل' })).toBeInTheDocument();
    });

    it('keeps the search and the filters', () => {
        list();

        expect(screen.getByPlaceholderText('رمز المشروع أو اسمه أو العميل')).toBeInTheDocument();
        expect(screen.getByText('عروض أسعار')).toBeInTheDocument();
    });
});
