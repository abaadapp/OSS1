import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import Summary from '@/Pages/Admin/Finance/Summary';
import MonthlyExpensesCard, { type MonthlyExpenses } from '@/Pages/Admin/Finance/partials/MonthlyExpensesCard';
import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@/Components/SectionTabs', () => ({ default: () => null, FINANCE_TABS: [] }));
vi.mock('@/Components/RangeTabs', () => ({ default: () => null }));

/**
 * «مصروفات الشهر» — بطاقةٌ شهريّة تعرض ما يقوله الدفتر ولا تحسب شيئًا.
 *
 * الإجماليُّ والتفصيلُ والمقارنةُ من الخادم، والروابطُ إلى مصادرها لمن يفتح
 * أقسامَها وحدهم، والتفاصيلُ تُفتح بيد.
 */

const data = (over: Partial<MonthlyExpenses> = {}): MonthlyExpenses => ({
    month: '2026-10', label: 'أكتوبر 2026', from: '2026-10-01', to: '2026-10-31', total: 1200,
    groups: [
        { key: 'operating', label: 'مصروفات تشغيلية', total: 300, accounts: [{ account_id: 1, code: '5300', name: 'الإيجار', total: 300, entries: 1 }] },
        { key: 'suppliers', label: 'فواتير موردين ومشتريات مباشرة', total: 400, accounts: [{ account_id: 2, code: '5150', name: 'مشتريات مباشرة', total: 400, entries: 1 }] },
        { key: 'payroll', label: 'رواتب ومستحقات موظفين', total: 500, accounts: [{ account_id: 3, code: '5200', name: 'الرواتب والأجور', total: 500, entries: 1 }] },
    ],
    previous: { month: '2026-09', label: 'سبتمبر 2026', total: 800 },
    change: { amount: 400, percent: 50 },
    entries: 3,
    inventory_purchases: 600,
    obligations: { paid: 700, due: 400, overdue: 150, documents: { expenses: 1, supplier_invoices: 1, payroll: 1 } },
    months: [{ value: '2026-10', label: 'أكتوبر 2026' }, { value: '2026-09', label: 'سبتمبر 2026' }],
    ...over,
});

const m = (v: number) => `${v.toFixed(3)} ر.ع`;

describe('MonthlyExpensesCard', () => {
    it('reads the month total, its groups, the comparison and the obligations', () => {
        render(<MonthlyExpensesCard data={data()} links={{ expenses: true, supplier_invoices: true, payroll: true }} m={m} />);

        expect(screen.getByText('مصروفات الشهر من الدفتر')).toBeInTheDocument();
        expect(screen.getByText(/بما فيها الرواتب وسندات الموردين والتكلفة/)).toBeInTheDocument();
        expect(screen.getByText('1200.000 ر.ع')).toBeInTheDocument();
        expect(screen.getByText('رواتب ومستحقات موظفين')).toBeInTheDocument();
        expect(screen.getByText(/50٪ عن سبتمبر 2026/)).toBeInTheDocument();
        expect(screen.getByText('150.000 ر.ع')).toBeInTheDocument();
        expect(screen.getByText(/مشتريات للمخزون 600.000/)).toBeInTheDocument();
        expect(screen.getByText(/مستقلة عن تبويبات المدة/)).toBeInTheDocument();
    });

    it('opens the details by account and links only to the sections the reader may open', async () => {
        const user = userEvent.setup();
        const { rerender } = render(<MonthlyExpensesCard data={data()} links={{ expenses: true, supplier_invoices: false, payroll: false }} m={m} />);

        expect(screen.queryByText('5150')).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: /عرض تفاصيل المصروفات/ }));
        expect(screen.getByText('5150')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'قيود الشهر في اليومية' })).toHaveAttribute('href', expect.stringContaining('admin.finance.journal'));
        expect(screen.getAllByRole('link', { name: 'سجل مصروفات الشهر' })).toHaveLength(1);
        expect(screen.queryByRole('link', { name: 'سندات الموردين' })).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'مسيرات الرواتب' })).not.toBeInTheDocument();

        rerender(<MonthlyExpensesCard data={data({ groups: [], total: 0, change: { amount: 0, percent: null } })} links={{ expenses: false, supplier_invoices: false, payroll: false }} m={m} />);
        expect(screen.getByText('لا مصروفات مقيّدة في هذا الشهر')).toBeInTheDocument();
        expect(screen.getByText(/لا مصروفات في سبتمبر 2026 للمقارنة/)).toBeInTheDocument();
    });
});

describe('Finance summary', () => {
    it('names the two expense figures apart — paid in the period, and the whole month from the books', () => {
        Object.assign(pageProps, {
            range: 'month', cash: 100, bank: 200, accounts: [],
            period: { invoice_total: 900, expenses: 70, profit: 500, tax: 10, in: 900, out: 70, transfers: 0 },
            dues: { expenses: 0, invoices: 0, payroll: 0, total: 0, overdue: 0 },
            receivables: { total: 0, overdue: 0, due_soon: 0, credit: 0, invoices: 0, customers: 0 },
            monthly: data(), monthly_links: { expenses: true, supplier_invoices: true, payroll: true },
        });
        render(<Summary />);

        expect(screen.getByText('المصروفات المدفوعة في المدة')).toBeInTheDocument();
        expect(screen.getByText('المسدَّدة من سجل المصروفات فقط')).toBeInTheDocument();
        expect(screen.getByText('مصروفات الشهر من الدفتر')).toBeInTheDocument();
        expect(screen.queryByText('المصروفات')).not.toBeInTheDocument();
    });
});
