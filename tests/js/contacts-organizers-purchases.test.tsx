import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { router } from '@inertiajs/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ContactsCard from '@/Pages/Admin/Customers/partials/ContactsCard';
import ContactPicker from '@/Components/ContactPicker';
import OrganizersTab, { type OperationOrganizerRow } from '@/Pages/Admin/Operations/partials/OrganizersTab';
import PurchasesTab, { type ProjectPurchases } from '@/Pages/Admin/Projects/partials/PurchasesTab';
import type { CustomerContact } from '@/lib/contacts';
import { assignmentCategories } from '@/lib/organizers';
import { receiptMode, RECEIPT_WORDS } from '@/lib/receipts';

/**
 * شاشاتُ المرحلة 14 — تعرض ما يقوله الخادم وتطلب من أبوابه.
 *
 * أشخاصُ الاتصال بأساسيٍّ واحد يُنقل من بابه · المنظّمون على الفعاليّة بتصنيفهم
 * وحالهم وتعارضهم · ومشترياتُ المشروع أوامرُ شراءٍ حقيقيّة يقود زرُّها إلى
 * شاشة أمر الشراء نفسِها والمشروعُ مختار.
 */

afterEach(() => vi.clearAllMocks());

const contact = (over: Partial<CustomerContact>): CustomerContact => ({
    id: 1, name: 'محمد أحمد', job_title: 'مدير التسويق', department: null, phone: '91111111', whatsapp: null,
    email: 'm@culture.gov.om', notes: null, is_primary: false, active: true, ...over,
});

describe('ContactsCard', () => {
    const contacts = [
        contact({ id: 1, name: 'محمد أحمد', is_primary: true }),
        contact({ id: 2, name: 'سارة', job_title: 'منسّقة', phone: '92222222', whatsapp: '97777777' }),
    ];

    it('marks the one primary and moves it through its own door', async () => {
        const user = userEvent.setup();
        render(<ContactsCard customerId={9} contacts={contacts} />);

        expect(screen.getByText('أشخاص الاتصال')).toBeInTheDocument();
        expect(screen.getAllByText('أساسي')).toHaveLength(1);
        expect(screen.getByText('97777777')).toBeInTheDocument();

        const sara = screen.getByText('سارة').closest('li')!;
        await user.click(within(sara).getByRole('button', { name: /تحديد كأساسي/ }));
        expect(router.post).toHaveBeenCalledWith('/admin.customers.contacts.primary/9/2', {}, expect.anything());

        const mohammed = screen.getByText('محمد أحمد').closest('li')!;
        expect(within(mohammed).queryByRole('button', { name: /تحديد كأساسي/ })).not.toBeInTheDocument();
        await user.click(within(mohammed).getByRole('button', { name: /تعطيل/ }));
        expect(router.post).toHaveBeenCalledWith('/admin.customers.contacts.active/9/1', { active: 0 }, expect.anything());
    });

    it('opens the form to add a contact', async () => {
        const user = userEvent.setup();
        render(<ContactsCard customerId={9} contacts={[]} />);

        expect(screen.getByText('لا أشخاص اتصال بعد — أضف من تكلّمه في هذه الجهة.')).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: /إضافة شخص اتصال/ }));
        expect(await screen.findByRole('dialog')).toHaveTextContent('شخص اتصال جديد');
    });
});

describe('ContactPicker', () => {
    it('draws nothing for a company without contacts and a list for one with them', () => {
        const { container, rerender } = render(<ContactPicker contacts={[]} value="" onPick={() => undefined} />);
        expect(container).toBeEmptyDOMElement();

        rerender(<ContactPicker contacts={[contact({ is_primary: true })]} value="" onPick={() => undefined} />);
        expect(screen.getByText('اختر من أشخاص الاتصال')).toBeInTheDocument();
    });
});

describe('OrganizersTab', () => {
    const rows: OperationOrganizerRow[] = [
        { id: 3, organizer_id: 7, name: 'منى', phone: '92220000', gender: 'female', category_id: 1, category: 'استقبال',
            starts_at: '2026-10-01 06:00', ends_at: '2026-10-01 19:00', status: 'confirmed', notes: null },
    ];
    const base = {
        operationId: 4, active: true, leadId: null, users: [{ id: 1, name: 'المدير' }], organizers: rows,
        options: [{ id: 7, name: 'منى', gender: 'female', phone: null, categories: [{ id: 1, name: 'استقبال' }] }],
        statusLabels: { assigned: 'معيَّن', confirmed: 'أكّد' }, genderLabels: { female: 'أنثى', male: 'ذكر' },
    };

    it('reads the organizers with their role in this event and shows the tight-gap warning', () => {
        render(<OrganizersTab {...base} conflicts={[{ severity: 'warning', kind: 'organizer_tight_gap', message: 'المنظّم منى بينه وبين الفعاليّة OP-2 :n دقيقةً فقط.' }]} canManage={false} />);

        expect(screen.getByText('منى')).toBeInTheDocument();
        expect(screen.getByText('استقبال')).toBeInTheDocument();
        expect(screen.getByText('أكّد')).toBeInTheDocument();
        expect(screen.getByText(/بينه وبين الفعاليّة OP-2/)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /تعيين منظّم/ })).not.toBeInTheDocument();
    });

    it('lets who manages the crew assign an organizer — but not on a finished event', () => {
        const { rerender } = render(<OrganizersTab {...base} conflicts={[]} canManage />);
        expect(screen.getByRole('button', { name: /تعيين منظّم/ })).toBeInTheDocument();

        rerender(<OrganizersTab {...base} active={false} conflicts={[]} canManage />);
        expect(screen.queryByRole('button', { name: /تعيين منظّم/ })).not.toBeInTheDocument();
    });

    it('offers an assignment only the organizer’s own categories — and keeps a current one removed since', () => {
        const own = [{ id: 1, name: 'استقبال' }, { id: 2, name: 'VIP' }];
        expect(assignmentCategories({ category_id: 1, category: 'استقبال', categories: own })).toEqual(own);
        expect(assignmentCategories({ category_id: 9, category: 'بروتوكول', categories: own }).map((c) => c.name)).toEqual(['استقبال', 'VIP', 'بروتوكول']);
        expect(assignmentCategories({ category_id: null, category: null, categories: [] })).toEqual([]);
    });
});

describe('receipts', () => {
    it('calls a services-only order a completion, keeps goods as they were, and names a mixed one both', () => {
        expect(receiptMode(['product', 'custom'])).toBe('goods');
        expect(receiptMode([null, undefined])).toBe('goods');
        expect(receiptMode(['service', 'service'])).toBe('services');
        expect(receiptMode(['service', 'product'])).toBe('mixed');
        expect(RECEIPT_WORDS.goods.action).toBe('تسجيل استلام');
        expect(RECEIPT_WORDS.services.action).toBe('تأكيد تنفيذ الخدمة');
    });
});

describe('PurchasesTab', () => {
    const data = (money: boolean): ProjectPurchases => ({
        summary: { orders: 2, drafts: 0, total: 1500, received: 1, pending: 1, invoices: 1, invoiced: money ? 1000 : null, paid: money ? 400 : null, outstanding: money ? 600 : null },
        orders: [
            { id: 11, number: 'PO-000011', supplier_id: 5, supplier: 'شركة النور', status: 'مستلم', receipt: 'received', closed: false, ordered_at: '2026-10-01', expected_delivery_at: null, total: 1000, kinds: ['service'], items: 1 },
            { id: 12, number: 'PO-000012', supplier_id: 6, supplier: 'مخازن الكراسي', status: 'مُرسل', receipt: 'pending', closed: false, ordered_at: '2026-10-02', expected_delivery_at: '2026-10-05', total: 500, kinds: ['product'], items: 2 },
        ],
        invoices: [{ id: 21, ref: 'INV-1', supplier: 'شركة النور', purchase_order_id: 11, approval_status: 'معتمد', status: 'جزئي', issued_at: '2026-10-03', total: money ? 1000 : null, paid: money ? 400 : null, outstanding: money ? 600 : null }],
        suppliers: [{ id: 5, name: 'شركة النور', type: 'services', orders: 1, total: 1000 }, { id: 6, name: 'مخازن الكراسي', type: 'products', orders: 1, total: 500 }],
        kinds: ['product', 'service', 'custom'],
        statuses: ['مسودة', 'مُرسل', 'مستلم جزئيًا', 'مستلم'],
        money,
        may: { create: true },
    });

    it('reads the real orders, opens the purchase order screen with the project chosen, and shows money only to who may see it', () => {
        const { rerender } = render(<PurchasesTab projectId={5} data={data(true)} m={(v) => String(v ?? '—')} />);

        expect(screen.getByText('PO-000011')).toHaveAttribute('href', '/admin.purchases.show/11');
        expect(screen.getByText('خدمة')).toBeInTheDocument();
        expect(within(screen.getByText('PO-000012').closest('tr')!).getByText('بانتظار الاستلام')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /إضافة شراء/ })).toHaveAttribute('href', '/admin.purchases.create/5');
        expect(screen.getByText('المدفوع')).toBeInTheDocument();

        rerender(<PurchasesTab projectId={5} data={data(false)} m={(v) => String(v ?? '—')} />);
        expect(screen.queryByText('المدفوع')).not.toBeInTheDocument();
    });

    it('says a services-only order awaits completion, not receipt', () => {
        const d = data(true);
        d.orders = [{ ...d.orders[1], kinds: ['service'] }];
        render(<PurchasesTab projectId={5} data={d} m={(v) => String(v ?? '—')} />);
        expect(within(screen.getByText('PO-000012').closest('tr')!).getByText('بانتظار تأكيد التنفيذ')).toBeInTheDocument();
    });
});
