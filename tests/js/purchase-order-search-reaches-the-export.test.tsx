import { act, fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { router } from '@inertiajs/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import PurchasesIndex from '@/Pages/Admin/Purchases/Index';
import type { PurchaseOrder } from '@/types/models';
import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));

/**
 * البحثُ في أوامر الشراء يُكتب في الرابط — فيقرؤه الخادمُ وزرُّ «تصدير».
 *
 * كان يرشّح الجدولَ في المتصفّح وحده: يرى التاجرُ أمرين ويصدّر فيخرج الملفُّ بالكلّ.
 * الجدولُ ما زال يرشّح فورًا، وبعد توقّفٍ قصير يصير النصُّ `q` في الرابط مع الحال
 * والمدّة والمورّد — ولا يُسقط تغييرُ الحال البحثَ.
 */

const order = (id: number, number: string, supplier: string, branch: string): PurchaseOrder =>
    ({
        id, number, supplier, branch, status: 'مُرسل', total: 100, items_count: 1, items: [],
        receipt: null, receipt_name: null, has_receipt: false, ordered: '2026-01-10', received: null,
    }) as PurchaseOrder;

const FILTERS = { status: 'مُرسل', from: '2026-01-01', to: '2026-01-31', supplier_id: '4' };

beforeEach(() => {
    Object.assign(pageProps, {
        orders: [order(1, 'PO-2026-0101', 'مؤسسة الخليج للتجهيزات', 'مسقط'), order(2, 'PO-2026-0103', 'شركة النور', 'صلالة')],
        stats: [],
        reorder: [],
        projects: [],
        q: null,
        filters: FILTERS,
    });
});

afterEach(() => {
    vi.useRealTimers();
    vi.clearAllMocks();
    vi.unstubAllGlobals();
    window.history.replaceState(null, '', '/');
    for (const key of ['orders', 'stats', 'reorder', 'projects', 'q', 'filters']) delete (pageProps as Record<string, unknown>)[key];
});

const lastVisit = () => vi.mocked(router.get).mock.calls.at(-1);

describe('أوامر الشراء — البحثُ في الرابط', () => {
    it('filters at once, then writes q into the address with the status, dates and supplier', () => {
        vi.useFakeTimers();
        render(<PurchasesIndex />);

        fireEvent.change(screen.getByPlaceholderText('ابحث بالرقم أو المورّد…'), { target: { value: 'الخليج' } });
        expect(screen.getByText('PO-2026-0101')).toBeInTheDocument();
        expect(screen.queryByText('PO-2026-0103')).not.toBeInTheDocument();
        expect(router.get).not.toHaveBeenCalled(); // لا طلبَ لكلّ حرف

        act(() => vi.advanceTimersByTime(400));
        expect(router.get).toHaveBeenCalledTimes(1);
        expect(lastVisit()?.[0]).toBe('/admin.purchases.orders');
        expect(lastVisit()?.[1]).toEqual({ q: 'الخليج', ...FILTERS });
        expect(lastVisit()?.[2]).toMatchObject({ preserveState: true, replace: true });
    });

    it('keeps the search when the status changes — and drops it when the field is emptied', () => {
        vi.useFakeTimers();
        render(<PurchasesIndex />);
        const field = screen.getByPlaceholderText('ابحث بالرقم أو المورّد…');

        fireEvent.change(field, { target: { value: 'PO-2026' } });
        fireEvent.click(screen.getByRole('tab', { name: 'مسودة' }));
        expect(lastVisit()?.[1]).toEqual({ ...FILTERS, q: 'PO-2026', status: 'مسودة' });

        act(() => vi.advanceTimersByTime(400));
        expect(router.get).toHaveBeenCalledTimes(1); // البحثُ المنتظرُ ذهب مع الحال — لا طلبٌ ثانٍ

        fireEvent.change(field, { target: { value: '' } });
        act(() => vi.advanceTimersByTime(400));
        expect(lastVisit()?.[1]).toEqual(FILTERS);
    });

    /* الرابطُ يحمل بحثًا قديمًا، والحقلُ يحمل ما كُتب للتوّ — والرابطُ لم يُحدَّث بعد */
    const STALE = '/admin/purchases?q=%D9%82%D8%AF%D9%8A%D9%85&status=%D9%85%D9%8F%D8%B1%D8%B3%D9%84&supplier_id=4';

    const exportNow = async (rows = 1) => {
        const fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ rows, limit: 1000, fits: rows <= 1000 }) });
        vi.stubGlobal('fetch', fetch);
        const user = userEvent.setup();
        const trigger = screen.getByRole('button', { name: /^تصدير$/ });
        trigger.focus();
        await user.keyboard('{Enter}');
        const menu = await screen.findByRole('menu');
        const hrefs = Object.fromEntries(
            within(menu).getAllByRole('menuitem').map((item) => {
                const href = new URL(item.getAttribute('href') ?? '', 'http://x');
                return [href.pathname.split('/').pop(), href.searchParams];
            }),
        ) as Record<'xlsx' | 'pdf' | 'csv', URLSearchParams>;
        await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
        const probe = new URL(String(fetch.mock.calls[0][0]), 'http://x').searchParams;

        return { user, menu, hrefs, probe };
    };

    it('exports the words just typed — Excel, CSV and the PDF question — before they reach the address', async () => {
        window.history.replaceState(null, '', STALE);
        Object.assign(pageProps, { q: 'قديم' });
        render(<PurchasesIndex />);

        fireEvent.change(screen.getByPlaceholderText('ابحث بالرقم أو المورّد…'), { target: { value: 'الخليج' } });
        expect(new URLSearchParams(window.location.search).get('q')).toBe('قديم'); // الرابطُ ما زال قديمًا

        const { hrefs, probe } = await exportNow();
        for (const format of ['xlsx', 'csv', 'pdf'] as const) {
            expect(hrefs[format].get('q'), format).toBe('الخليج');
            expect(hrefs[format].get('status'), format).toBe('مُرسل');
            expect(hrefs[format].get('supplier_id'), format).toBe('4');
        }
        expect(probe.get('q')).toBe('الخليج');
        expect(probe.get('probe')).toBe('1');
    });

    it('carries the words just typed into the full Excel and CSV offered by the PDF warning', async () => {
        window.history.replaceState(null, '', STALE);
        Object.assign(pageProps, { q: 'قديم' });
        render(<PurchasesIndex />);

        fireEvent.change(screen.getByPlaceholderText('ابحث بالرقم أو المورّد…'), { target: { value: 'النور' } });
        const { user, menu, probe } = await exportNow(5000);
        expect(probe.get('q')).toBe('النور');

        await user.click(within(menu).getByRole('menuitem', { name: 'تصدير كملف PDF' }));
        const dialog = await screen.findByRole('dialog');
        for (const name of ['تصدير Excel كاملًا', 'تصدير CSV كاملًا', /PDF لأوّل/]) {
            const href = new URL(within(dialog).getByRole('link', { name }).getAttribute('href') ?? '', 'http://x');
            expect(href.searchParams.get('q'), String(name)).toBe('النور');
        }
    });

    it('drops the old search at once when the field is emptied and «تصدير» is pressed', async () => {
        window.history.replaceState(null, '', STALE);
        Object.assign(pageProps, { q: 'قديم' });
        render(<PurchasesIndex />);

        fireEvent.change(screen.getByPlaceholderText('ابحث بالرقم أو المورّد…'), { target: { value: '' } });
        expect(new URLSearchParams(window.location.search).get('q')).toBe('قديم');

        const { hrefs, probe } = await exportNow();
        for (const format of ['xlsx', 'csv', 'pdf'] as const) {
            expect(hrefs[format].has('q'), format).toBe(false);
            expect(hrefs[format].get('status'), format).toBe('مُرسل'); // وبقيّةُ المرشّحات باقية
            expect(hrefs[format].get('supplier_id'), format).toBe('4');
        }
        expect(probe.has('q')).toBe(false);
    });

    it('lets «تصدير» carry the search that is in the address', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, json: async () => ({ rows: 1, limit: 1000, fits: true }) }));
        window.history.replaceState(null, '', '/admin/purchases?q=%D8%A7%D9%84%D8%AE%D9%84%D9%8A%D8%AC&status=%D9%85%D9%8F%D8%B1%D8%B3%D9%84');
        Object.assign(pageProps, { q: 'الخليج' });
        const user = userEvent.setup();
        render(<PurchasesIndex />);

        const trigger = screen.getByRole('button', { name: /^تصدير$/ });
        trigger.focus();
        await user.keyboard('{Enter}');
        const menu = await screen.findByRole('menu');
        for (const item of within(menu).getAllByRole('menuitem')) {
            const href = new URL(item.getAttribute('href') ?? '', 'http://x');
            expect(href.pathname).toMatch(/^\/admin\.purchases\.orders\.export\/(xlsx|pdf|csv)$/);
            expect(href.searchParams.get('q')).toBe('الخليج');
            expect(href.searchParams.get('status')).toBe('مُرسل');
        }
    });
});
