import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { router } from '@inertiajs/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    SupplierInvoicePaymentsCard,
    SupplierPaymentDialog,
    type SupplierPayment,
} from '@/Pages/Admin/Purchases/partials/SupplierInvoicePayments';

/**
 * دفعاتُ سند المورّد — كلُّ دفعةٍ سطرٌ بإيصالها، والنموذجُ يرسل الإيصالَ ملفًّا.
 *
 * الإيصالُ يُرسل نموذجًا بملفّ (`forceFormData`) ومعه مفتاحُ النموذج، والملفُّ الممنوع
 * يُقال بالعربيّة قبل الإرسال. و«عرض الإيصال» لما له إيصالٌ يُفتح وحده — لا زرٌّ وهميّ.
 */

const m = (v: number) => `${v.toFixed(3)} ر.ع`;
const invoice = { id: 9, reference: 'INV-1000', paid: 300, outstanding: 700 };

const payment = (over: Partial<SupplierPayment>): SupplierPayment => ({
    id: 1, number: 'JE-0001', at: '2026-10-16', amount: 300, from: 'bank', by: 'المالك',
    has_receipt: true, receipt_name: 'إيصال تحويل 1.pdf', receipt: '/receipt/1', legacy: false, ...over,
});

describe('SupplierPaymentDialog', () => {
    beforeEach(() => vi.mocked(router.post).mockClear());

    it('offers an optional receipt and sends it as form data with the request key', async () => {
        const user = userEvent.setup();
        render(<SupplierPaymentDialog open onClose={() => {}} invoice={invoice} today="2026-10-16" m={m} defaultAmount="100" />);

        expect(screen.getByRole('heading', { name: /تسجيل دفعة — INV-1000/ })).toBeInTheDocument();
        expect(screen.getByText('إيصال السداد (اختياري)')).toBeInTheDocument();
        expect(screen.getByText('المصدر')).toBeInTheDocument();
        const file = new File(['%PDF'], 'إيصال تحويل 1.pdf', { type: 'application/pdf' });
        await user.upload(screen.getByLabelText('إيصال السداد'), file);
        expect(screen.getByTestId('receipt-name')).toHaveTextContent('إيصال تحويل 1.pdf');

        await user.click(screen.getByRole('button', { name: /تسجيل الدفعة/ }));
        expect(router.post).toHaveBeenCalledTimes(1);
        const [url, data, options] = vi.mocked(router.post).mock.calls[0] as [string, Record<string, unknown>, Record<string, unknown>];
        expect(url).toBe('/admin.purchases.invoices.pay/9');
        expect(data).toMatchObject({ amount: '100', paid_at: '2026-10-16', from: 'cash' });
        expect(data.receipt).toBe(file);
        expect(typeof data.request_key).toBe('string');
        expect((data.request_key as string).length).toBeGreaterThan(8);
        expect(options.forceFormData).toBe(true);
    });

    it('sends no file and no form data when there is no receipt', async () => {
        const user = userEvent.setup();
        render(<SupplierPaymentDialog open onClose={() => {}} invoice={invoice} today="2026-10-16" m={m} defaultAmount="50" />);

        await user.click(screen.getByRole('button', { name: /تسجيل الدفعة/ }));
        const [, data, options] = vi.mocked(router.post).mock.calls[0] as [string, Record<string, unknown>, Record<string, unknown>];
        expect(data).not.toHaveProperty('receipt');
        expect(options.forceFormData).toBe(false);
    });

    it('says in Arabic why a receipt is refused and does not send it', async () => {
        const user = userEvent.setup({ applyAccept: false });
        render(<SupplierPaymentDialog open onClose={() => {}} invoice={invoice} today="2026-10-16" m={m} defaultAmount="50" />);

        await user.upload(screen.getByLabelText('إيصال السداد'), new File(['x'], 'برنامج.exe', { type: 'application/octet-stream' }));
        expect(screen.getByText('صيغة الإيصال غير مدعومة — المقبول: PDF أو صورة (JPG، PNG، WEBP، HEIC).')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /تسجيل الدفعة/ })).toBeDisabled();

        const big = new File(['x'], 'كبير.pdf', { type: 'application/pdf' });
        Object.defineProperty(big, 'size', { value: 11 * 1024 * 1024 });
        await user.upload(screen.getByLabelText('إيصال السداد'), big);
        expect(screen.getByText('حجم الإيصال أكبر من 10 ميجابايت.')).toBeInTheDocument();
        expect(router.post).not.toHaveBeenCalled();
    });
});

describe('SupplierInvoicePaymentsCard', () => {
    it('lists each payment on its own line — a receipt button only where a receipt opens', () => {
        render(
            <SupplierInvoicePaymentsCard
                total={1000}
                paid={700}
                outstanding={300}
                m={m}
                payments={[
                    payment({ id: 1, number: 'JE-0001' }),
                    payment({ id: 2, number: 'JE-0002', at: '2026-10-20', amount: 400, receipt_name: 'تحويل 2.jpg', receipt: '/receipt/2' }),
                    payment({ id: 3, number: 'JE-0003', at: '2026-10-25', amount: 300, from: 'cash', has_receipt: false, receipt: null, receipt_name: null }),
                ]}
            />,
        );

        expect(screen.getByText('الدفعات')).toBeInTheDocument();
        const rows = screen.getAllByTestId('supplier-payment');
        expect(rows).toHaveLength(3);
        expect(within(rows[0]).getByRole('link', { name: /عرض الإيصال/ })).toHaveAttribute('href', '/receipt/1');
        expect(within(rows[1]).getByRole('link', { name: /عرض الإيصال/ })).toHaveAttribute('href', '/receipt/2');
        expect(within(rows[2]).queryByRole('link', { name: /عرض الإيصال/ })).not.toBeInTheDocument();
        expect(within(rows[2]).getByText('لا يوجد إيصال')).toBeInTheDocument();
        expect(within(rows[0]).getByText('بنك')).toBeInTheDocument();
        expect(within(rows[2]).getByText('نقد')).toBeInTheDocument();
        expect(within(rows[1]).getByText('JE-0002')).toBeInTheDocument();
        expect(within(rows[1]).getByText('400.000 ر.ع')).toBeInTheDocument();
        expect(screen.getByText('سداد جزئي — المتبقّي 300.000 ر.ع')).toBeInTheDocument();
        expect(screen.getByText('المدفوع 700.000 ر.ع من 1000.000 ر.ع')).toBeInTheDocument();
    });

    it('tells a reader without attachment access that a receipt exists, and shows a legacy line plainly', () => {
        render(
            <SupplierInvoicePaymentsCard total={500} paid={500} outstanding={0} m={m}
                payments={[
                    payment({ id: null, number: 'JE-OLD', legacy: true, has_receipt: false, receipt: null, receipt_name: null, from: 'cash' }),
                    payment({ id: 5, number: 'JE-0005', receipt: null }),
                ]} />,
        );

        const [old, locked] = screen.getAllByTestId('supplier-payment');
        expect(within(old).getByText('لا يوجد إيصال')).toBeInTheDocument();
        expect(within(locked).getByText('إيصال مرفق')).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: /عرض الإيصال/ })).not.toBeInTheDocument();
        expect(screen.getByText('مسدَّد بالكامل')).toBeInTheDocument();
    });
});
