import { renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { NAV } from '@/lib/nav';
import { useNavSections } from '@/lib/navSections';
import { pageProps } from './setup';

/**
 * «المبيعات» خرجت من القائمة — ولا يحلّ اسمٌ آخر محلَّها.
 *
 * وفواتيرُ العملاء تبقى تحت «المالية» وقسمُها «المالية» نفسُها: من يملك
 * القسمَ يراها، ومن كان يملك «المبيعات» وحدها لا يراها.
 */

const realRoute = globalThis.route;

/** `route()` بلا معامل تقول أين المستخدم — والإعدادُ لا يعرف ذلك */
const at = (current: string) => {
    globalThis.route = ((...args: unknown[]) =>
        args.length === 0 ? { current: () => current } : (realRoute as (...a: unknown[]) => string)(...args)) as never;
};

const grant = (abilities: string[]) => {
    Object.assign(pageProps, { auth: { abilities, mayActions: [] } });
};

const visibleLabels = () => {
    const { result } = renderHook(() => useNavSections());
    return result.current.groups.flatMap((g) => g.items).map((i) => i.label);
};

describe('القائمة بلا «المبيعات»', () => {
    beforeEach(() => at('admin.dashboard'));
    afterEach(() => {
        globalThis.route = realRoute;
        grant([]);
    });

    it('has no sales entry and no orders route anywhere in the sidebar', () => {
        const items = NAV.flatMap((g) => g.items);

        expect(items.some((i) => i.label === 'المبيعات')).toBe(false);
        expect(items.some((i) => i.route === 'admin.orders.index')).toBe(false);
        expect(items.some((i) => i.section === 'orders')).toBe(false);
        expect(items.flatMap((i) => i.covers ?? []).some((r) => r.startsWith('admin.orders.'))).toBe(false);
    });

    it('keeps customer invoices under finance, gated by the finance section', () => {
        const finance = NAV.find((g) => g.key === 'finance');
        const invoices = finance?.items.find((i) => i.label === 'فواتير العملاء');

        expect(invoices?.route).toBe('admin.customerInvoices.index');
        expect(invoices?.section).toBe('finance');
    });

    it('shows customer invoices to whoever holds finance and hides them from an old sales holder', () => {
        grant(['finance']);
        expect(visibleLabels()).toContain('فواتير العملاء');

        grant(['orders', 'customers']);
        const labels = visibleLabels();
        expect(labels).not.toContain('فواتير العملاء');
        expect(labels).not.toContain('المبيعات');
        expect(labels).toContain('العملاء');
    });
});
