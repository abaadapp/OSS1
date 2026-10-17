import { render, renderHook, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import SectionTabs, { FINANCE_TABS, type SectionTab } from '@/Components/SectionTabs';
import Summary from '@/Pages/Admin/Finance/Summary';
import MonthlyExpensesCard, { type MonthlyExpenses } from '@/Pages/Admin/Finance/partials/MonthlyExpensesCard';
import { money } from '@/lib/format';
import { NAV } from '@/lib/nav';
import { useNavSections } from '@/lib/navSections';
import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@/Components/RangeTabs', () => ({ default: () => null }));

/**
 * المالية تجيب أوّلًا ما يُسأل كلَّ يوم — والمحاسبةُ باقيةٌ خلف زرّ.
 *
 * ستّةُ تبويباتٍ في الشريط، وأدواتُ المحاسب الأربع في «المحاسبة والمزيد» بصلاحياتها
 * كما هي. والملخّصُ أربعُ بطاقاتٍ في «وضعك الآن»، ومصروفاتُ الشهر مختصرةٌ حتى تُفتح.
 */

const PRIMARY = ['الملخّص المالي', 'الحركة المالية', 'لك عند العملاء', 'عليك الدفع', 'الحسابات البنكية', 'الشيكات'];
const ACCOUNTING = ['مصاريف شهرية', 'القيود اليومية', 'شجرة الحسابات', 'الأصول الثابتة'];

const grant = (abilities: string[]) => Object.assign(pageProps, { auth: { abilities, mayActions: [] } });

/** القائمةُ تُفتح بلوحة المفاتيح — Radix لا يفتحها بنقرة jsdom */
const openAccounting = async () => {
    const user = userEvent.setup();
    const trigger = screen.getByRole('button', { name: 'المحاسبة والمزيد' });
    trigger.focus();
    await user.keyboard('{Enter}');

    return screen.findByRole('menu');
};

const month = (over: Partial<MonthlyExpenses> = {}): MonthlyExpenses => ({
    month: '2026-10', label: 'أكتوبر 2026', from: '2026-10-01', to: '2026-10-31', total: 1200,
    groups: [
        { key: 'operating', label: 'مصروفات تشغيلية', total: 300, accounts: [{ account_id: 1, code: '5300', name: 'الإيجار', total: 300, entries: 1 }] },
        { key: 'payroll', label: 'رواتب ومستحقات موظفين', total: 900, accounts: [{ account_id: 3, code: '5200', name: 'الرواتب والأجور', total: 900, entries: 2 }] },
    ],
    previous: { month: '2026-09', label: 'سبتمبر 2026', total: 800 },
    change: { amount: 400, percent: 50 },
    entries: 3,
    inventory_purchases: 0,
    obligations: { paid: 700, due: 400, overdue: 150, documents: { expenses: 1, supplier_invoices: 1, payroll: 1 } },
    months: [{ value: '2026-10', label: 'أكتوبر 2026' }],
    ...over,
});

const m = (v: number) => `${v.toFixed(3)} ر.ع`;
/** الملخّصُ يكتب بمنسّق العملة نفسه — فالاختبارُ يقرأ به */
const cur = (v: number) => money(v, pageProps.context.currency as never);

const summary = (over: Record<string, unknown> = {}) => {
    Object.assign(pageProps, {
        range: 'month', cash: 120, bank: 3400,
        accounts: [
            { id: 1, label: 'مسقط', balance: 3000, active: true },
            { id: 2, label: 'ظفار', balance: 300, active: true },
            { id: 3, label: 'صحار', balance: 100, active: true },
        ],
        period: { invoice_total: 5250, expenses: 70, profit: 1800, tax: 250, in: 900, out: 70, transfers: 40 },
        dues: { expenses: 111, invoices: 222, payroll: 333, total: 666, overdue: 2 },
        receivables: { total: 4100, overdue: 900, due_soon: 777, credit: 55, invoices: 9, customers: 4 },
        monthly: month(), monthly_links: { expenses: true, supplier_invoices: true, payroll: true },
        ...over,
    });

    return render(<Summary />);
};

afterEach(() => grant([]));

describe('شريط المالية', () => {
    it('shows the six daily tabs in the bar and folds the accounting tools behind one button', () => {
        grant(['finance', 'expenses']);
        render(<SectionTabs tabs={FINANCE_TABS} current="admin.finance.summary" />);

        expect(screen.getAllByRole('link').map((a) => a.textContent)).toEqual(PRIMARY);
        expect(screen.getByRole('link', { name: 'لك عند العملاء' })).toHaveAttribute('href', '/admin.finance.receivables');
        expect(screen.getByRole('link', { name: 'عليك الدفع' })).toHaveAttribute('href', '/admin.finance.dues');
        for (const name of ACCOUNTING) {
            expect(screen.queryByRole('link', { name })).not.toBeInTheDocument();
        }
        expect(screen.queryByText('الذمم المدينة')).not.toBeInTheDocument();
        expect(screen.queryByText('المبالغ المستحقة')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'المحاسبة والمزيد' })).toBeInTheDocument();
    });

    it('opens the journal, the chart, the assets and the monthly expenses from «المحاسبة والمزيد»', async () => {
        grant(['finance', 'expenses']);
        render(<SectionTabs tabs={FINANCE_TABS} current="admin.finance.journal" />);

        const menu = await openAccounting();
        const items = within(menu).getAllByRole('menuitem');
        expect(items.map((el) => el.textContent)).toEqual(ACCOUNTING);
        expect(within(menu).getByRole('menuitem', { name: 'القيود اليومية' })).toHaveAttribute('href', '/admin.finance.journal');
        expect(within(menu).getByRole('menuitem', { name: 'شجرة الحسابات' })).toHaveAttribute('href', '/admin.finance.chart');
        expect(within(menu).getByRole('menuitem', { name: 'الأصول الثابتة' })).toHaveAttribute('href', '/admin.finance.assets');
        // والشاشةُ المفتوحة معلَّمةٌ داخل القائمة
        expect(within(menu).getByRole('menuitem', { name: 'القيود اليومية' })).toHaveAttribute('aria-current', 'page');
    });

    it('keeps each folded tab behind its own permission — no expenses section, no monthly expenses', async () => {
        grant(['finance']);
        render(<SectionTabs tabs={FINANCE_TABS} current="admin.finance.summary" />);

        const menu = await openAccounting();
        expect(within(menu).getAllByRole('menuitem').map((el) => el.textContent)).toEqual(['القيود اليومية', 'شجرة الحسابات', 'الأصول الثابتة']);
    });

    it('shows a lone folded tab as a plain tab — a menu of one is a wasted click', () => {
        grant(['a', 'b']);
        const tabs: SectionTab[] = [
            { label: 'الأوّل', routeName: 'x.first', section: 'a' },
            { label: 'الثاني', routeName: 'x.second', section: 'b', group: 'المزيد' },
            { label: 'الثالث', routeName: 'x.third', section: 'c', group: 'المزيد' },
        ];
        render(<SectionTabs tabs={tabs} current="x.first" />);

        expect(screen.getAllByRole('link').map((a) => a.textContent)).toEqual(['الأوّل', 'الثاني']);
        expect(screen.queryByRole('button', { name: 'المزيد' })).not.toBeInTheDocument();
    });

    it('scrolls inside itself on a narrow screen instead of widening the page', () => {
        grant(['finance', 'expenses']);
        render(<SectionTabs tabs={FINANCE_TABS} current="admin.finance.summary" />);

        expect(screen.getByRole('link', { name: 'الملخّص المالي' }).parentElement).toHaveClass('overflow-x-auto');
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
    it('opens on four balanced cards — cash, bank, owed to you, you owe', () => {
        summary();
        const now = screen.getByTestId('finance-now');

        expect(screen.getByRole('heading', { name: 'وضعك الآن' })).toBeInTheDocument();
        expect(now.children).toHaveLength(4);
        expect([...now.children].map((c) => c.querySelector('p')?.textContent)).toEqual(['الصندوق', 'البنك', 'لك عند العملاء', 'عليك الدفع']);
        // عمودٌ على الهاتف، ٢×٢ على اللوح، أربعةٌ على الشاشة العريضة — لا رابعةَ تسقط وحدها تحت ثلاث
        expect(now).toHaveClass('grid-cols-1', 'md:grid-cols-2', 'xl:grid-cols-4');
        expect(now.className).not.toMatch(/lg:grid-cols-3/);
    });

    it('shows the bank total and how many accounts — not each account', () => {
        summary();
        const bank = screen.getByTestId('finance-now').children[1] as HTMLElement;

        expect(within(bank).getByText(cur(3400))).toBeInTheDocument();
        expect(within(bank).getByText(/3 حسابات/)).toBeInTheDocument();
        expect(within(bank).getByRole('link', { name: 'عرض الحسابات' })).toHaveAttribute('href', '/admin.finance.index');
        expect(screen.queryByText('مسقط')).not.toBeInTheDocument();
    });

    it('says what is owed to you and what you owe — total and the late part, the rest behind «التفاصيل»', () => {
        summary();
        const [, , owed, owe] = [...screen.getByTestId('finance-now').children] as HTMLElement[];

        expect(within(owed).getByText(cur(4100))).toBeInTheDocument();
        expect(within(owed).getByText(`${cur(900)} متأخّر عن موعده`)).toBeInTheDocument();
        expect(within(owed).getByRole('link', { name: 'التفاصيل' })).toHaveAttribute('href', '/admin.finance.receivables');
        // العملاءُ والفواتيرُ وما يستحقّ قريبًا والرصيدُ الدائن في شاشة الذمم
        expect(within(owed).queryByText(/777/)).not.toBeInTheDocument();
        expect(within(owed).queryByText(/عملاء مدينون/)).not.toBeInTheDocument();
        expect(within(owed).queryByText(/^[49]$/)).not.toBeInTheDocument();

        expect(within(owe).getByText(cur(666))).toBeInTheDocument();
        expect(within(owe).getByText(/2 متأخّرة عن موعدها/)).toBeInTheDocument();
        expect(within(owe).getByRole('link', { name: 'التفاصيل' })).toHaveAttribute('href', '/admin.finance.dues');
        // والمصروفاتُ والسنداتُ والرواتبُ مفصّلةً في «عليك الدفع» — لا تُعاد هنا
        for (const part of [cur(111), cur(222), cur(333)]) {
            expect(screen.queryByText(part)).not.toBeInTheDocument();
        }
    });

    it('hides the late line when nothing is late', () => {
        summary({ receivables: { total: 50, overdue: 0, due_soon: 0, credit: 0, invoices: 1, customers: 1 }, dues: { expenses: 0, invoices: 0, payroll: 0, total: 0, overdue: 0 } });

        expect(screen.queryByText(/متأخّر عن موعده/)).not.toBeInTheDocument();
        expect(screen.queryByText(/متأخّرة عن موعدها/)).not.toBeInTheDocument();
    });

    it('reads the period from the same numbers, the invoice total under its own name', () => {
        summary();

        const card = screen.getByText('إجمالي الفواتير (شامل الضريبة)').closest('div')!.parentElement!;
        expect(within(card).getByText(cur(5250))).toBeInTheDocument();
        for (const label of ['المصروفات المدفوعة في المدة', 'صافي الربح', 'ضريبة محصّلة', 'دخل', 'خرج', 'تحويلات بين الصندوق والبنك']) {
            expect(screen.getByText(label)).toBeInTheDocument();
        }
        expect(screen.getByText('التحويل بين الصندوق والبنك لا يُقرأ دخلًا ولا مصروفًا')).toBeInTheDocument();
    });

    it('never says «المبيعات»', () => {
        grant(['finance', 'expenses']);
        const { container } = summary();

        expect(container.textContent).not.toMatch(/مبيع/);
    });
});

describe('مصروفات الشهر — مختصرةٌ حتى تُفتح', () => {
    it('shows only the total, the comparison and paid / due / late before it is opened', () => {
        render(<MonthlyExpensesCard data={month()} links={{ expenses: true, supplier_invoices: true, payroll: true }} m={m} />);

        expect(screen.getByText('1200.000 ر.ع')).toBeInTheDocument();
        expect(screen.getByText(/50٪ عن سبتمبر 2026/)).toBeInTheDocument();
        for (const label of ['المدفوع', 'المستحق', 'المتأخر']) {
            expect(screen.getByText(label)).toBeInTheDocument();
        }
        // ولا مجموعاتٍ ولا عددَ قيودٍ ولا مستنداتٍ ولا روابطَ يوميّة
        expect(screen.queryByText('مصروفات تشغيلية')).not.toBeInTheDocument();
        expect(screen.queryByText(/قيدًا · /)).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'قيود الشهر في اليومية' })).not.toBeInTheDocument();
        expect(screen.queryByText('5200')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: /عرض التفاصيل/ })).toHaveAttribute('aria-expanded', 'false');
    });

    it('opens every detail it had before — groups with totals, accounts, counts and links', async () => {
        const user = userEvent.setup();
        render(<MonthlyExpensesCard data={month()} links={{ expenses: true, supplier_invoices: false, payroll: true }} m={m} />);

        await user.click(screen.getByRole('button', { name: /عرض التفاصيل/ }));

        expect(screen.getByRole('button', { name: /عرض التفاصيل/ })).toHaveAttribute('aria-expanded', 'true');
        expect(screen.getByText('مصروفات تشغيلية')).toBeInTheDocument();
        expect(screen.getAllByText('رواتب ومستحقات موظفين')).toHaveLength(1);
        expect(screen.getByText('5200')).toBeInTheDocument();
        expect(screen.getByText('3 قيدًا · 3 مستندًا')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'قيود الشهر في اليومية' })).toHaveAttribute('href', expect.stringContaining('admin.finance.journal'));
        expect(screen.getByRole('link', { name: 'سجل مصروفات الشهر' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'مسيرات الرواتب' })).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'سندات الموردين' })).not.toBeInTheDocument();
    });

    it('tells the books figure apart from the expense log', () => {
        render(<MonthlyExpensesCard data={month()} links={{ expenses: true, supplier_invoices: true, payroll: true }} m={m} />);

        expect(screen.getByText('مصروفات الشهر من الدفتر')).toBeInTheDocument();
        expect(screen.getByText(/يشمل الرواتب وسندات الموردين والتكلفة — لا سجل المصروفات وحده/)).toBeInTheDocument();
    });
});
