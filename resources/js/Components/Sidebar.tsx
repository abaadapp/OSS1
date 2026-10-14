import { useEffect, useState } from 'react';
import { AnimatePresence, motion } from 'framer-motion';
import { ChevronDown } from 'lucide-react';
import Logo from '@/Components/Logo';
import SmartLink from '@/Components/SmartLink';
import { useTranslate } from '@/lib/i18n';
import { NAV, type NavGroup, type NavItem } from '@/lib/nav';
import { useNavSections } from '@/lib/navSections';
import { cn } from '@/lib/utils';

const OPEN_KEY = 'eventplus.sidebar.open';

function readOpenGroups(): string[] | null {
    try {
        const raw = window.localStorage.getItem(OPEN_KEY);
        const parsed = raw ? JSON.parse(raw) : null;
        return Array.isArray(parsed) ? parsed.filter((k): k is string => typeof k === 'string') : null;
    } catch {
        return null;
    }
}

function writeOpenGroups(keys: string[]) {
    try {
        window.localStorage.setItem(OPEN_KEY, JSON.stringify(keys));
    } catch {
        // تخزينٌ محجوب: تُفتح مجموعةُ الصفحة وحدَها في كلّ زيارة
    }
}

interface SidebarProps {
    open: boolean;
    onClose: () => void;
    /** قائمة التنقّل — لوحة التاجر افتراضًا، ولوحة المنصة تمرّر قائمتها */
    nav?: NavGroup[];
    /** سطر اختياري تحت الشعار — لوحة المنصة وحدها تمرّره */
    subtitle?: string;
}

export default function Sidebar({ open, onClose, nav = NAV, subtitle }: SidebarProps) {
    const t = useTranslate();
    const current = route().current();
    const { groups, activeRoute } = useNavSections(nav);

    const matches = (name: string) =>
        current === name || (current?.startsWith(name.replace(/\.index$/, '.')) ?? false);

    const flat = groups.flatMap((g) => g.items);

    /*
     * القائمة المنسدلة تُفتح على الأداة المفتوحة.
     *
     * تركُها مطويّةً يُخفي أين المستخدم: يفتح «إشعارات واتساب» فيرى «أدوات
     * التسويق» مطويّةً كما تركها، ولا شيء يقول إنّ ما يقرؤه من تحتها.
     */
    const openBranch = flat.find((i) => i.children?.some((c) => matches(c.route)))?.route ?? null;
    const [expanded, setExpanded] = useState<string | null>(openBranch);

    useEffect(() => {
        if (openBranch) setExpanded(openBranch);
    }, [openBranch]);

    /*
     * المجموعاتُ المفتوحة — تُحفظ في المتصفّح وتعود كما تُركت.
     *
     * ومجموعةُ الصفحة المفتوحة تُفتح دائمًا: مطويّةً تُخفي أين المستخدم،
     * فلا يرى العنصرَ المضيء ولا يعرف تحت أيّ قسمٍ هو.
     */
    const holds = (g: NavGroup) =>
        g.items.some((i) => i.route === activeRoute || i.children?.some((c) => c.route === activeRoute));
    const activeIndex = groups.findIndex(holds);
    const activeGroupKey = activeIndex < 0 ? null : (groups[activeIndex].key ?? String(activeIndex));

    const [openGroups, setOpenGroups] = useState<string[]>(() => {
        const saved = readOpenGroups();
        const base = saved ?? [];
        return activeGroupKey && !base.includes(activeGroupKey) ? [...base, activeGroupKey] : base;
    });

    useEffect(() => {
        if (activeGroupKey) setOpenGroups((o) => (o.includes(activeGroupKey) ? o : [...o, activeGroupKey]));
    }, [activeGroupKey]);

    const toggleGroup = (key: string) =>
        setOpenGroups((o) => {
            const next = o.includes(key) ? o.filter((k) => k !== key) : [...o, key];
            writeOpenGroups(next);
            return next;
        });

    /* المجموعاتُ ذواتُ العناوين — ما يُطوى ويُفتح */
    const foldable = groups.flatMap((g, gi) => (g.heading ? [g.key ?? String(gi)] : []));
    const allOpen = foldable.length > 0 && foldable.every((k) => openGroups.includes(k));

    const toggleAll = () => {
        const next = allOpen ? [] : foldable;
        writeOpenGroups(next);
        setOpenGroups(next);
    };

    const link = (item: NavItem, depth = 0) => {
        const Icon = item.icon;

        return (
            <SmartLink
                key={item.route}
                routeName={item.route}
                href={route(item.route)}
                onClick={onClose}
                className={cn('ui-nav-link', item.route === activeRoute && 'is-active')}
                // الإزاحة منطقية لا يمينية: تنقلب مع اتجاه المستند
                style={depth ? { paddingInlineStart: `${12 + depth * 22}px` } : undefined}
            >
                <Icon className={cn('shrink-0', depth ? 'size-4' : 'size-[18px]')} />
                <span className="truncate">{t(item.label)}</span>
            </SmartLink>
        );
    };

    return (
        <>
            {/* خلفية معتمة على الجوال فقط */}
            <AnimatePresence>
                {open && (
                    <motion.div
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        exit={{ opacity: 0 }}
                        transition={{ duration: 0.2 }}
                        onClick={onClose}
                        className="fixed inset-0 z-30 bg-black/20 backdrop-blur-sm lg:hidden"
                    />
                )}
            </AnimatePresence>

            <aside
                className={cn(
                    // start = يمين في العربية، يسار في الإنجليزية — يتبع اتجاه المستند
                    'fixed inset-y-0 start-0 z-40 flex w-64 flex-col border-e border-[var(--ui-border,#e8e8e8)] bg-white',
                    'transition-transform duration-300 lg:translate-x-0',
                    // الإزاحة على الجوال فقط (max-lg): متغيّرات dir محدِّدها أقوى من lg،
                    // فلو تُركت عامة لبقي الشريط مدفوعًا خارج الشاشة على سطح المكتب.
                    open
                        ? 'translate-x-0'
                        : 'max-lg:rtl:translate-x-full max-lg:ltr:-translate-x-full',
                )}
            >
                {/*
                 * الشعارُ مقبضُ القائمة كلِّها: ضغطةٌ تفتح الأقسامَ جميعًا،
                 * والثانيةُ تطويها جميعًا. ويُقرأ حالُه من المجموعات نفسِها —
                 * فمن فتحها واحدةً واحدة حتّى اكتملت يطويها الشعارُ بضغطة.
                 */}
                <button
                    type="button"
                    onClick={toggleAll}
                    aria-expanded={allOpen}
                    aria-label={t(allOpen ? 'إخفاء الأقسام الفرعية' : 'إظهار الأقسام الفرعية')}
                    title={t(allOpen ? 'إخفاء الأقسام الفرعية' : 'إظهار الأقسام الفرعية')}
                    className="mx-3 flex h-16 shrink-0 items-center justify-center rounded-[12px] px-2 transition-colors hover:bg-[rgba(17,17,17,0.045)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#111]/20"
                >
                    <Logo className="pointer-events-none h-5 w-auto text-[#111]" />
                </button>
                {/* لوحة المنصة وحدها تسمّي نفسها — القائمتان بالعلامة نفسها */}
                {subtitle && (
                    <p className="truncate px-5 pt-3 text-center text-[12px] font-medium text-[#6b7280]">{t(subtitle)}</p>
                )}

                <nav className="flex flex-1 flex-col overflow-y-auto px-3 pb-4">
                    {groups.map((group, gi) => {
                        const items = group.items.filter((i) => !i.hidden);
                        const gkey = group.key ?? String(gi);
                        const holdsActiveGroup = holds(group);
                        /* مجموعةٌ بلا عنوانٍ لا سهمَ لها يفتحها — فتبقى مفتوحة */
                        const groupOpen = !group.heading || openGroups.includes(gkey);

                        return (
                            <div
                                key={group.key ?? gi}
                                className={cn(
                                    'mb-1',
                                    // تُدفع «الإعدادات» إلى الأسفل، وخطُّها يفصلها عمّا فوقها
                                    group.footer && 'mt-auto',
                                    !group.heading && gi > 0 && 'border-t border-[var(--ui-border,#e8e8e8)] pt-2',
                                )}
                            >
                                {group.heading && (
                                    <button
                                        type="button"
                                        aria-expanded={groupOpen}
                                        onClick={() => toggleGroup(gkey)}
                                        className={cn(
                                            'flex w-full items-center gap-2 rounded-[10px] px-3 py-2 text-start text-[13px] font-semibold transition-colors hover:bg-[rgba(17,17,17,0.045)]',
                                            holdsActiveGroup ? 'text-[#111]' : 'text-[#4b4b4b]',
                                        )}
                                    >
                                        <span className="truncate">{t(group.heading)}</span>
                                        <ChevronDown
                                            className={cn('ms-auto size-4 shrink-0 text-[#9ca3af] transition-transform', groupOpen && 'rotate-180')}
                                        />
                                    </button>
                                )}

                                <AnimatePresence initial={false}>
                                {groupOpen && (
                                <motion.div
                                    initial={{ height: 0, opacity: 0 }}
                                    animate={{ height: 'auto', opacity: 1 }}
                                    exit={{ height: 0, opacity: 0 }}
                                    transition={{ duration: 0.18, ease: [0.22, 1, 0.36, 1] }}
                                    className="overflow-hidden"
                                >
                                {items.map((item) => {
                                    if (! item.children) {
                                        return link(item);
                                    }

                                    const Icon = item.icon;
                                    const isOpen = expanded === item.route;
                                    const holdsActive = item.children.some((c) => c.route === activeRoute);

                                    return (
                                        <div key={item.route}>
                                            <button
                                                type="button"
                                                aria-expanded={isOpen}
                                                onClick={() => setExpanded(isOpen ? null : item.route)}
                                                className={cn(
                                                    'ui-nav-link w-full',
                                                    // الأب يُعلَّم حين تكون إحدى أدواته مفتوحة —
                                                    // ولا يُضاء كالوجهة: هو لا ينقل إلى صفحة
                                                    holdsActive && 'font-semibold text-[#111]',
                                                )}
                                            >
                                                <Icon className="size-[18px] shrink-0" />
                                                <span className="truncate">{t(item.label)}</span>
                                                <ChevronDown
                                                    className={cn(
                                                        'ms-auto size-4 shrink-0 text-[#9ca3af] transition-transform',
                                                        isOpen && 'rotate-180',
                                                    )}
                                                />
                                            </button>

                                            <AnimatePresence initial={false}>
                                                {isOpen && (
                                                    <motion.div
                                                        initial={{ height: 0, opacity: 0 }}
                                                        animate={{ height: 'auto', opacity: 1 }}
                                                        exit={{ height: 0, opacity: 0 }}
                                                        transition={{ duration: 0.18, ease: [0.22, 1, 0.36, 1] }}
                                                        className="overflow-hidden"
                                                    >
                                                        {item.children.map((child) => link(child, 1))}
                                                    </motion.div>
                                                )}
                                            </AnimatePresence>
                                        </div>
                                    );
                                })}
                                </motion.div>
                                )}
                                </AnimatePresence>
                            </div>
                        );
                    })}
                </nav>
            </aside>
        </>
    );
}
