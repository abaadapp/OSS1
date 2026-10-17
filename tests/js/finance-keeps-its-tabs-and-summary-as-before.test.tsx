import { render, renderHook, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import SectionTabs, { FINANCE_TABS } from '@/Components/SectionTabs';
import Summary from '@/Pages/Admin/Finance/Summary';
import { money } from '@/lib/format';
import { NAV } from '@/lib/nav';
import { useNavSections } from '@/lib/navSections';
import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@/Components/RangeTabs', () => ({ default: () => null }));

/**
 * المالية بشكلها الذي كانت عليه — عشرةُ تبويباتٍ في شريطٍ واحد، والملخّصُ كما كان.
 *
 * وما بقي من التبسيط ليس شكلًا: «المالية» في القائمة الجانبيّة تفتح الملخّص،
 * وإجماليُّ الفواتير يصل باسمه (`period.invoice_total`) لا «sales».
 */

const TABS = [
    'الملخّص المالي', 'الحركة المالية', 'الذمم المدينة', 'الشيكات', 'المبالغ المستحقة',
    'مصاريف شهرية', 'الحسابات البنكية', 'القيود اليومية', 'شجرة الحسابات', 'أصول ثابتة',
];

const grant = (abilities: string[]) => Object.assign(pageProps, { auth: { abilities, mayActions: [] } });

/** الملخّصُ يكتب بمنسّق العملة نفسه — فالاختبارُ يقرأ به */
const cur = (v: number) => money(v, pageProps.context.currency as never);

afterEach(() => grant([]));

describe('شريط المالية', () => {
    it('shows all ten tabs in one bar, in their order, with no folding menu', () => {
        grant(['finance', 'expenses']);
        render(<SectionTabs tabs={FINANCE_TABS} current="admin.finance.summary" />);

        expect(screen.getAllByRole('link').map((a) => a.textContent)).toEqual(TABS);
        expect(screen.queryByRole('button', { name: 'المحاسبة والمزيد' })).not.toBeInTheDocument();
        expect(screen.queryByRole('menu')).not.toBeInTheDocument();
        expect(FINANCE_TABS.some((tb) => 'group' in tb)).toBe(false);
    });

    it('keeps every tab on its own route', () => {
        expect(Object.fromEntries(FINANCE_TABS.map((tb) => [tb.label, tb.routeName]))).toEqual({
            'الملخّص المالي': 'admin.finance.summary',
            'الحركة المالية': 'admin.finance.transactions',
            'الذمم المدينة': 'admin.finance.receivables',
            'الشيكات': 'admin.finance.cheques',
            'المبالغ المستحقة': 'admin.finance.dues',
            'مصاريف شهرية': 'admin.expenses.index',
            'الحسابات البنكية': 'admin.finance.index',
            'القيود اليومية': 'admin.finance.journal',
            'شجرة الحسابات': 'admin.finance.chart',
            'أصول ثابتة': 'admin.finance.assets',
        });
    });

    it('still hides the expense log from whoever does not hold the expenses section', () => {
        grant(['finance']);
        render(<SectionTabs tabs={FINANCE_TABS} current="admin.finance.summary" />);

        expect(screen.getAllByRole('link').map((a) => a.textContent)).toEqual(TABS.filter((l) => l !== 'مصاريف شهرية'));
    });
});

describe('القائمة الجانبية', () => {
    const realRoute = globalThis.route;
    /** `route()` بلا معامل تقول أين المستخدم — والإعدادُ لا يعرف ذلك */
    const at = (current: string) => {
        globalThis.route = ((...args: unknown[]) =>
            args.length === 0 ? { current: () => current } : (realRoute as (...a: unknown[]) => string)(...args)) as never;
    };
    const finance = () => {
        const { result } = renderHook(() => useNavSections());
        return { item: result.current.groups.flatMap((g) => g.items).find((i) => i.label === 'المالية'), active: result.current.activeRoute };
    };

    afterEach(() => {
        globalThis.route = realRoute;
    });

    it('opens the financial summary from «المالية» — not the bank accounts', () => {
        const item = NAV.flatMap((g) => g.items).find((i) => i.label === 'المالية');
        expect(item?.route).toBe('admin.finance.summary');
        expect(item?.section).toBe('finance');

        at('admin.dashboard');
        grant(['finance']);
        expect(finance().item?.route).toBe('admin.finance.summary');
    });

    it('keeps the bank accounts one tab away and «المالية» lit on every finance page', () => {
        expect(FINANCE_TABS.find((tb) => tb.label === 'الحسابات البنكية')?.routeName).toBe('admin.finance.index');

        grant(['finance', 'expenses']);
        for (const page of ['admin.finance.summary', 'admin.finance.index', 'admin.finance.statement', 'admin.finance.journal', 'admin.finance.assets', 'admin.finance.receivables', 'admin.expenses.index']) {
            at(page);
            expect(finance().active, page).toBe('admin.finance.summary');
        }
    });

    it('still hides «المالية» from whoever does not hold the section', () => {
        at('admin.dashboard');
        grant(['expenses']);
        expect(finance().item).toBeUndefined();
    });
});

describe('الملخّص المالي', () => {
    const summary = () => {
        Object.assign(pageProps, {
            range: 'month', cash: 120, bank: 3400,
            accounts: [
                { id: 1, label: 'مسقط', balance: 3000, active: true },
                { id: 2, label: 'ظفار', balance: 400, active: true },
            ],
            period: { invoice_total: 5250, expenses: 70, profit: 1800, tax: 250, in: 900, out: 70, transfers: 40 },
            dues: { expenses: 111, invoices: 222, payroll: 333, total: 666, overdue: 2 },
            receivables: { total: 4100, overdue: 900, due_soon: 777, credit: 55, invoices: 9, customers: 4 },
        });

        return render(<Summary />);
    };

    it('lays out the cards it had before — cash, bank, what you owe, what you are owed', () => {
        summary();

        for (const label of ['الصندوق (نقدًا)', 'البنك', 'عليك الآن', 'لك الآن', 'ما جرى في المدة', 'حركة المال في المدة']) {
            expect(screen.getByText(label)).toBeInTheDocument();
        }
        expect(screen.getByText(/2 حسابات/)).toBeInTheDocument();
        expect(screen.getByText(cur(666))).toBeInTheDocument();
        expect(screen.getByText(cur(4100))).toBeInTheDocument();
        expect(screen.getByText('الصندوق (نقدًا)').closest('.grid')).toHaveClass('lg:grid-cols-3');
        expect(screen.queryByText('وضعك الآن')).not.toBeInTheDocument();
        expect(screen.queryByText('لك عند العملاء')).not.toBeInTheDocument();
    });

    it('reads the invoice total from period.invoice_total — the old «sales» name stays gone', () => {
        summary();

        const card = screen.getByText('إجمالي الفواتير (شامل الضريبة)').closest('div')!.parentElement!;
        expect(card).toHaveTextContent(cur(5250));
    });

    it('never says «المبيعات»', () => {
        const { container } = summary();

        expect(container.textContent).not.toMatch(/مبيع/);
    });
});
