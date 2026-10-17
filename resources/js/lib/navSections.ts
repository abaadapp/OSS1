import { usePage } from '@inertiajs/react';
import type { SectionTab } from '@/Components/SectionTabs';
import { NAV, type NavGroup, type NavItem } from '@/lib/nav';
import type { PageProps } from '@/types';

export interface NavSections {
    /** المجموعاتُ بما يراه المستخدم منها — وما حُجب كلُّه يسقط */
    groups: NavGroup[];
    /** مسارُ العنصر المضيء — واحدٌ لا أكثر */
    activeRoute: string | undefined;
}

/**
 * ما يراه المستخدم من القائمة، وأين هو منها.
 *
 * الصلاحيةُ تُسقط ما لا يُفتح، والعنصرُ المضيء واحدٌ يُحسب من الصفحة المفتوحة.
 */
export function useNavSections(nav: NavGroup[] = NAV): NavSections {
    const { auth } = usePage<PageProps>().props;
    const current = route().current();

    // بلا قسم صلاحية يظهر العنصر دائمًا — انظر NavItem.section
    const can = (section?: string) => !section || (auth?.abilities.includes(section) ?? false);
    const mayTab = (tab: SectionTab) =>
        can(tab.section) && (!tab.action || (auth?.mayActions?.includes(tab.action) ?? false));

    /*
     * وبابُ شريطِ تبويبات يُفتح لمن يملك أيَّ تبويبٍ فيه — انظر NavItem.tabs.
     *
     * من يملك قسمَ العنصر يدخل من بابه كما كان، ومن لا يملكه يدخل من أوّلِ
     * تبويبٍ يملكه: من مُنح المنظّمين وحدهم يرى «العملاء» يقود إليهم.
     */
    const reach = (i: NavItem): NavItem | null => {
        if (can(i.section)) return i;
        const first = i.tabs?.find(mayTab);
        return first ? { ...i, route: first.routeName } : null;
    };

    /*
     * والأب يقود إلى أوّل ما بقي من بنيه.
     *
     * أبٌ لا وجهةَ له يفتح ما تحته؛ فإن حُجب كلُّ بنيه بالصلاحية سقط هو
     * معهم بدل أن يبقى مقبضًا لا يفتح شيئًا.
     */
    const visible = (items: NavItem[]): NavItem[] =>
        items
            .map(reach)
            .filter((i): i is NavItem => i !== null)
            .map((i) => {
                if (!i.children) return i;
                const kids = i.children.filter((c) => can(c.section));
                return kids.length === 0 ? null : { ...i, children: kids, route: kids[0].route };
            })
            .filter((i): i is NavItem => i !== null);

    const groups = nav
        .map((g) => ({ ...g, items: visible(g.items) }))
        /* ومجموعةٌ لا يُرسم منها شيء لا عنوانَ لها: عنوانٌ فوق فراغ */
        .filter((g) => g.items.some((i) => !i.hidden));

    // المطابقة بالعائلة تُبقي العنصر مضيئًا في الصفحات الفرعية
    // (admin.products.create مثلًا)
    const matches = (name: string) =>
        current === name || (current?.startsWith(name.replace(/\.index$/, '.')) ?? false);

    /*
     * مسارُ العنصر نفسه يُقدَّم على ما يغطّيه، وأبناءُ القائمة المنسدلة
     * تُقدَّم على آبائها — فلا يضيء الأبُ وحده ولا يُعرف أيّ ابنٍ مفتوح.
     */
    const flat = groups.flatMap((g) => g.items);
    const leaves = flat.flatMap((i) => i.children ?? [i]);
    const activeRoute =
        leaves.find((i) => matches(i.route))?.route ?? flat.find((i) => i.covers?.some(matches))?.route;

    return { groups, activeRoute };
}
