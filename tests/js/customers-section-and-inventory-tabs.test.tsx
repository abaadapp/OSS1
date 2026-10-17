import { renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { CUSTOMER_TABS, INVENTORY_TABS } from '@/Components/SectionTabs';
import { NAV } from '@/lib/nav';
import { useNavSections } from '@/lib/navSections';
import { pageProps } from './setup';

/**
 * المنظّمون تبويبٌ في قسم «العملاء» لا قسمٌ في القائمة، والمخزونُ بابٌ واحد.
 *
 * والقائمةُ لا تُسقط من مُنح المنظّمين وحدهم: «العملاء» يقود إلى أوّلِ ما
 * يملكه من تبويباته. ومن لا يملك شيئًا منها لا يرى البابَ أصلًا.
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

const customersItem = () => {
    const { result } = renderHook(() => useNavSections());
    return { item: result.current.groups.flatMap((g) => g.items).find((i) => i.label === 'العملاء'), active: result.current.activeRoute };
};

describe('قسم العملاء', () => {
    beforeEach(() => at('admin.dashboard'));
    afterEach(() => {
        globalThis.route = realRoute;
        grant([]);
    });

    it('carries the organizers as a fourth tab with their own section', () => {
        expect(CUSTOMER_TABS.map((t) => t.routeName)).toEqual([
            'admin.customers.organizations',
            'admin.customers.index',
            'admin.suppliers.index',
            'admin.organizers.index',
        ]);
        expect(CUSTOMER_TABS.find((t) => t.routeName === 'admin.organizers.index')?.section).toBe('organizers');
    });

    it('has no separate organizers entry in the sidebar, and the customers entry covers their pages', () => {
        const items = NAV.flatMap((g) => g.items);
        expect(items.some((i) => i.route === 'admin.organizers.index')).toBe(false);
        expect(items.some((i) => i.label === 'المنظّمون')).toBe(false);

        const customers = items.find((i) => i.label === 'العملاء');
        expect(customers?.covers).toEqual(expect.arrayContaining(['admin.organizers.index', 'admin.organizers.show', 'admin.suppliers.show']));
    });

    it('opens the customers entry onto the organizers for whoever holds them alone', () => {
        grant(['organizers']);
        expect(customersItem().item?.route).toBe('admin.organizers.index');

        grant(['suppliers', 'organizers']);
        expect(customersItem().item?.route).toBe('admin.suppliers.index');

        grant(['customers', 'organizers']);
        expect(customersItem().item?.route).toBe('admin.customers.index');

        grant(['projects']);
        expect(customersItem().item).toBeUndefined();
    });

    it('lights the customers entry on an organizer page', () => {
        grant(['customers', 'organizers']);
        at('admin.organizers.show');
        expect(customersItem().active).toBe('admin.customers.index');

        grant(['organizers']);
        at('admin.organizers.index');
        expect(customersItem().active).toBe('admin.organizers.index');
    });
});

describe('قسم المخزون', () => {
    it('keeps only the stock balances — no stocktake, no stock log, no goods receipt list', () => {
        expect(INVENTORY_TABS.map((t) => t.routeName)).toEqual(['admin.inventory.index']);
        const covered = NAV.flatMap((g) => g.items).find((i) => i.route === 'admin.inventory.index')?.covers ?? [];
        for (const gone of ['admin.inventory.stocktake', 'admin.inventory.adjustments', 'admin.inventory.receipts']) {
            expect(covered).not.toContain(gone);
        }
    });
});
