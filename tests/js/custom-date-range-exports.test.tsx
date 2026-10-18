import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { router } from '@inertiajs/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import RangeTabs, { periodQuery, type PeriodInfo } from '@/Components/RangeTabs';
import CustomDateRangePicker, { rangeProblem } from '@/Components/CustomDateRangePicker';
import ListDateRange from '@/Components/ListDateRange';
import ExportMenu from '@/Components/ExportMenu';
import ReportScreen from '@/Components/ReportScreen';
import { exportLinks } from '@/lib/exportLink';
import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@/Components/BackToReports', () => ({ default: () => null }));

/**
 * الفترةُ المخصَّصةُ والتصدير — في الواجهة.
 *
 * «فترة مخصّصة» تظهر حيث يقرؤها الخادم وحده، ومن يومٍ إلى يوم: لا تُطبَّق
 * ونهايتُها قبل بدايتها، وتُكتب في الرابط مع بقيّة المرشّحات، والعودةُ إلى
 * فترةٍ جاهزةٍ تُسقط حدّيها. وزرُّ «تصدير» بصيغه الثلاث يحمل ما على الشاشة.
 */

const custom: PeriodInfo = { range: 'custom', from: '2026-01-01', to: '2026-03-31', label: 'فترة مخصّصة (2026-01-01 — 2026-03-31)', error: null };
const month: PeriodInfo = { range: 'month', from: null, to: null, label: 'هذا الشهر', error: null };

afterEach(() => {
    vi.clearAllMocks();
    delete (pageProps as Record<string, unknown>).period;
});

const lastVisit = () => vi.mocked(router.get).mock.calls.at(-1);

describe('RangeTabs', () => {
    it('offers «فترة مخصّصة» only where the server reads both bounds', () => {
        const { rerender } = render(<RangeTabs current="month" />);
        expect(screen.queryByText('فترة مخصّصة')).not.toBeInTheDocument();
        expect(screen.getAllByRole('tab').map((x) => x.textContent)).toEqual(['اليوم', 'الأسبوع', 'الشهر', 'السنة', 'الكل']);

        rerender(<RangeTabs current="month" period={month} />);
        expect(screen.getByRole('tab', { name: 'فترة مخصّصة' })).toBeInTheDocument();
    });

    it('opens the two dates, refuses an end before the start, and applies keeping the other filters', async () => {
        const user = userEvent.setup();
        render(<RangeTabs current="month" period={month} params={{ range: 'month', status: 'صادرة', branch_id: '2', from: 'old', to: 'old' }} />);

        await user.click(screen.getByRole('tab', { name: 'فترة مخصّصة' }));
        const group = screen.getByRole('group', { name: 'فترة مخصّصة' });
        const apply = within(group).getByRole('button', { name: 'تطبيق الفترة' });
        expect(apply).toBeDisabled();

        fireEvent.change(within(group).getByLabelText('من تاريخ'), { target: { value: '2026-03-31' } });
        fireEvent.change(within(group).getByLabelText('إلى تاريخ'), { target: { value: '2026-01-01' } });
        expect(within(group).getByRole('alert')).toHaveTextContent('تاريخ النهاية قبل تاريخ البداية.');
        expect(apply).toBeDisabled();
        expect(router.get).not.toHaveBeenCalled();

        fireEvent.change(within(group).getByLabelText('إلى تاريخ'), { target: { value: '2026-04-30' } });
        expect(apply).toBeEnabled();
        await user.click(apply);

        expect(lastVisit()?.[1]).toEqual({ status: 'صادرة', branch_id: '2', range: 'custom', from: '2026-03-31', to: '2026-04-30' });
    });

    it('drops the custom bounds when going back to a preset, and resets to the month', async () => {
        const user = userEvent.setup();
        render(<RangeTabs current="custom" period={custom} params={{ range: 'custom', from: '2026-01-01', to: '2026-03-31', type: 'إيجار' }} />);

        await user.click(screen.getByRole('tab', { name: 'السنة' }));
        expect(lastVisit()?.[1]).toEqual({ type: 'إيجار', range: 'year' });

        await user.click(screen.getByRole('button', { name: 'إعادة تعيين' }));
        expect(lastVisit()?.[1]).toEqual({ type: 'إيجار', range: 'month' });
    });

    it('says why the server refused a custom period instead of hiding it', () => {
        render(<RangeTabs current="month" period={{ ...month, error: 'تاريخ النهاية قبل تاريخ البداية.' }} />);

        expect(screen.getByRole('alert')).toHaveTextContent('تاريخ النهاية قبل تاريخ البداية.');
    });

    it('writes the period into a link exactly as the server reads it', () => {
        expect(periodQuery(custom)).toEqual({ range: 'custom', from: '2026-01-01', to: '2026-03-31' });
        expect(periodQuery(month)).toEqual({ range: 'month' });
        expect(periodQuery(null)).toEqual({});
    });
});

describe('CustomDateRangePicker', () => {
    it('checks the dates the way the server does', () => {
        expect(rangeProblem('', '2026-01-01')).toBe('اختر تاريخ البداية وتاريخ النهاية.');
        expect(rangeProblem('2026-1-1', '2026-01-02')).toBe('تاريخٌ غير صحيح — الصيغة سنة-شهر-يوم.');
        expect(rangeProblem('2026-02-01', '2026-01-31')).toBe('تاريخ النهاية قبل تاريخ البداية.');
        expect(rangeProblem('2026-01-31', '2026-01-31')).toBeNull();
    });

    it('stacks on a phone and lines up on a wide screen — and bounds each field by the other', () => {
        render(<CustomDateRangePicker from="2026-01-01" to="2026-01-31" onApply={() => undefined} onReset={() => undefined} />);

        const group = screen.getByRole('group', { name: 'فترة مخصّصة' });
        expect(group).toHaveClass('flex-wrap');
        expect(screen.getByLabelText('من تاريخ')).toHaveAttribute('max', '2026-01-31');
        expect(screen.getByLabelText('إلى تاريخ')).toHaveAttribute('min', '2026-01-01');
        /* الحقلان والزرّان بعرض الشاشة على الجوّال، وبعرضهم على الواسعة */
        expect(group.querySelectorAll('.w-full').length).toBeGreaterThanOrEqual(3);
        expect(group.querySelectorAll('.sm\\:w-auto').length).toBeGreaterThanOrEqual(3);
    });
});

describe('ExportMenu and the export links', () => {
    it('offers Excel, PDF and CSV under one «تصدير» button', async () => {
        const user = userEvent.setup();
        render(<ExportMenu {...exportLinks('admin.finance.transactions.export')} />);

        const trigger = screen.getByRole('button', { name: /تصدير/ });
        trigger.focus();
        await user.keyboard('{Enter}');
        const menu = await screen.findByRole('menu');
        expect(within(menu).getAllByRole('menuitem').map((x) => x.textContent)).toEqual(['تصدير كملف إكسل', 'تصدير كملف PDF', 'تصدير كملف CSV']);
        expect(within(menu).getByRole('menuitem', { name: 'تصدير كملف CSV' })).toHaveAttribute('href', '/admin.finance.transactions.export/csv');
    });

    it('builds one route per list with the format as its parameter and drops empty values', () => {
        expect(exportLinks('admin.finance.statement.export', { account: 3, status: '', q: null })).toEqual({
            xlsx: '/admin.finance.statement.export/xlsx/3',
            pdf: '/admin.finance.statement.export/pdf/3',
            csv: '/admin.finance.statement.export/csv/3',
            checkPdf: true,
        });
    });

    it('lets a report screen export the period it shows, not a stale one in the address bar', async () => {
        const user = userEvent.setup();
        Object.assign(pageProps, { period: custom });
        render(
            <ReportScreen title="المصروفات" reportKey="expenses" subtitle="" range="custom" rangeLabel={custom.label} filters={{ range: 'custom', from: '2026-01-01', to: '2026-03-31' }} stats={[]}>
                <div />
            </ReportScreen>,
        );

        expect(screen.getByText(custom.label)).toBeInTheDocument();
        const trigger = screen.getByRole('button', { name: /تصدير/ });
        trigger.focus();
        await user.keyboard('{Enter}');
        const menu = await screen.findByRole('menu');
        for (const item of within(menu).getAllByRole('menuitem')) {
            expect(item.getAttribute('href')).toContain('/expenses/custom/2026-01-01/2026-03-31');
        }
    });
});

describe('ExportMenu before a PDF', () => {
    const answer = (rows: number) => vi.fn().mockResolvedValue({ ok: true, json: async () => ({ rows, limit: 1000, fits: rows <= 1000 }) });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    const openMenu = async (user: ReturnType<typeof userEvent.setup>) => {
        const trigger = screen.getByRole('button', { name: /تصدير/ });
        trigger.focus();
        await user.keyboard('{Enter}');
        return screen.findByRole('menu');
    };

    it('asks the server how long the PDF would be as soon as the menu opens', async () => {
        const user = userEvent.setup();
        const fetch = answer(20500);
        vi.stubGlobal('fetch', fetch);
        render(<ExportMenu {...exportLinks('admin.finance.transactions.export')} />);

        await openMenu(user);
        await waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));
        expect(fetch.mock.calls[0][0]).toBe('/admin.finance.transactions.export/pdf?probe=1');
    });

    it('warns before a PDF that cannot hold every result, and offers the full Excel and CSV', async () => {
        const user = userEvent.setup();
        vi.stubGlobal('fetch', answer(20500));
        const open = vi.spyOn(window, 'open').mockReturnValue(null);
        render(<ExportMenu {...exportLinks('admin.finance.transactions.export')} />);

        const menu = await openMenu(user);
        await waitFor(() => expect(fetch).toHaveBeenCalled());
        await user.click(within(menu).getByRole('menuitem', { name: 'تصدير كملف PDF' }));

        const dialog = await screen.findByRole('dialog');
        expect(screen.queryByRole('menu')).not.toBeInTheDocument();
        expect(within(dialog).getByText('ملفّ PDF لا يتّسع لكلّ النتائج')).toBeInTheDocument();
        expect(dialog).toHaveTextContent('النتائج 20,500 صفًّا، وملفّ PDF يتّسع لـ1,000 صفّ على الأكثر. لتحصل على كلّ النتائج صدّر Excel أو CSV.');
        expect(within(dialog).getByRole('link', { name: 'تصدير Excel كاملًا' })).toHaveAttribute('href', '/admin.finance.transactions.export/xlsx');
        expect(within(dialog).getByRole('link', { name: 'تصدير CSV كاملًا' })).toHaveAttribute('href', '/admin.finance.transactions.export/csv');
        // والجزءُ لمن أراده — يقول اسمُه إنّه جزء، ويطلبه صراحةً
        expect(within(dialog).getByRole('link', { name: 'PDF لأوّل 1,000 صفّ فقط' })).toHaveAttribute('href', '/admin.finance.transactions.export/pdf?partial=1');
        expect(open).not.toHaveBeenCalled();
        open.mockRestore();
    });

    it('lets a PDF that fits open as it always did', async () => {
        const user = userEvent.setup();
        vi.stubGlobal('fetch', answer(40));
        render(<ExportMenu {...exportLinks('admin.finance.transactions.export')} />);

        const menu = await openMenu(user);
        await waitFor(() => expect(fetch).toHaveBeenCalled());
        const pdf = within(menu).getByRole('menuitem', { name: 'تصدير كملف PDF' });
        const click = new MouseEvent('click', { bubbles: true, cancelable: true });
        pdf.dispatchEvent(click);

        expect(click.defaultPrevented).toBe(false);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('leaves other PDF links alone — only the unified exports answer the question', async () => {
        const user = userEvent.setup();
        const fetch = answer(20500);
        vi.stubGlobal('fetch', fetch);
        render(<ExportMenu pdf="/admin/documents/7/pdf" />);

        await openMenu(user);
        expect(fetch).not.toHaveBeenCalled();
    });
});

describe('ListDateRange', () => {
    it('writes the two dates into the address with the other filters, and clears both at once', async () => {
        const user = userEvent.setup();
        const { rerender } = render(<ListDateRange routeName="admin.finance.cheques" params={{ status: 'محصَّل' }} from={null} to={null} label="تاريخ الاستلام" />);

        fireEvent.change(screen.getByLabelText('من تاريخ'), { target: { value: '2026-01-01' } });
        expect(lastVisit()?.[1]).toEqual({ status: 'محصَّل', from: '2026-01-01' });

        rerender(<ListDateRange routeName="admin.finance.cheques" params={{ status: 'محصَّل' }} from="2026-01-01" to="2026-01-31" label="تاريخ الاستلام" />);
        expect(screen.getByLabelText('إلى تاريخ')).toHaveAttribute('min', '2026-01-01');
        await user.click(screen.getByRole('button', { name: 'إعادة تعيين' }));
        expect(lastVisit()?.[1]).toEqual({ status: 'محصَّل' });
    });
});
