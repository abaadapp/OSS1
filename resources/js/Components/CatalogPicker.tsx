import { useEffect, useMemo, useRef, useState } from 'react';
import { Check, ChevronDown, X } from 'lucide-react';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { fold } from '@/lib/pages';
import { cn } from '@/lib/utils';

export interface CatalogCategory {
    id: number;
    name: string;
    color: string | null;
}

export interface PickerRow {
    id: number;
    name: string;
    sku: string | null;
    kind: 'item' | 'service' | 'package';
    list_price: number;
    category: CatalogCategory | null;
}

/*
 * لونٌ لكلّ تصنيف — لونُه إن كُتب، وإلّا لونٌ ثابتٌ من معرّفه.
 *
 * تصنيفٌ بلا لونٍ مختار (وهو حالُ كلّ تصنيفٍ أُنشئ قبل هذا) يأخذ من اللوحة
 * بمعرّفه لا بترتيبه: فيبقى لونُه هو في كلّ شاشةٍ وكلّ فتحة، ولا يتبدّل حين
 * يُضاف تصنيفٌ قبله.
 */
const PALETTE = ['#1e3a5f', '#2563eb', '#0f766e', '#7c3aed', '#b45309', '#be123c', '#0e7490', '#4d7c0f', '#9333ea', '#c2410c'];

export function categoryColor(category: CatalogCategory | null): string {
    if (!category) return '#6b7280';
    if (category.color && /^#[0-9a-f]{6}$/i.test(category.color)) return category.color;

    return PALETTE[Math.abs(category.id) % PALETTE.length];
}

/** وسمُ التصنيف — ملوّنٌ بلونه: الخلفيّةُ فاتحةٌ منه، والنصُّ هو */
export function CategoryTag({ category, className }: { category: CatalogCategory | null; className?: string }) {
    const t = useTranslate();
    const color = categoryColor(category);

    return (
        <span
            className={cn('inline-flex max-w-full items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-medium leading-4', className)}
            style={{
                color,
                backgroundColor: `color-mix(in srgb, ${color} 10%, white)`,
                borderColor: `color-mix(in srgb, ${color} 28%, white)`,
            }}
        >
            <span className="size-1.5 shrink-0 rounded-full" style={{ backgroundColor: color }} />
            <span className="truncate">{category ? category.name : t('بلا تصنيف')}</span>
        </span>
    );
}

/**
 * اختيارُ بندٍ من الكتالوج — بوسم تصنيفه ولونه.
 *
 * كانت قائمةً أصليّة يرسمها المتصفّح: أسماءٌ متتالية بلا لون ولا تجميع،
 * وأربعون بندًا فيها تُقرأ واحدًا واحدًا. وصارت قائمةً مرسومة: بحثٌ بالاسم
 * أو الرمز، ورقائقُ تصنيفٍ تضيّق، ونتائجُ مجموعةٌ تحت تصنيفها بلونه —
 * فالعينُ تجد «الأجهزة» بلونها قبل أن تقرأ.
 */
export default function CatalogPicker({
    value,
    rows,
    onPick,
    placeholder,
    kindLabel,
    money,
}: {
    value: number | null;
    rows: PickerRow[];
    /** معرّفُ البند، أو فارغٌ لبندٍ حرّ */
    onPick: (value: string) => void;
    placeholder: string;
    kindLabel: (kind: PickerRow['kind']) => string;
    money: (n: number) => string;
}) {
    const t = useTranslate();
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');
    const [only, setOnly] = useState<number | 'none' | null>(null);
    const box = useRef<HTMLDivElement>(null);
    const search = useRef<HTMLInputElement>(null);

    useEffect(() => {
        const away = (e: MouseEvent) => {
            if (box.current && !box.current.contains(e.target as Node)) setOpen(false);
        };
        document.addEventListener('mousedown', away);

        return () => document.removeEventListener('mousedown', away);
    }, []);

    const selected = value ? rows.find((r) => r.id === value) ?? null : null;

    /* التصنيفاتُ الحاضرة في الكتالوج — بترتيب اسمها، فرمزُ «01» قبل «02» */
    const categories = useMemo(() => {
        const seen = new Map<number, CatalogCategory>();
        rows.forEach((r) => r.category && seen.set(r.category.id, r.category));

        return [...seen.values()].sort((a, b) => a.name.localeCompare(b.name, 'ar'));
    }, [rows]);
    const hasUncategorized = rows.some((r) => !r.category);

    const groups = useMemo(() => {
        const needle = fold(q.trim());
        const hits = rows.filter((r) => {
            if (only === 'none' && r.category) return false;
            if (typeof only === 'number' && r.category?.id !== only) return false;

            return needle === '' || fold(r.name).includes(needle) || fold(r.sku ?? '').includes(needle);
        });

        const byCategory = new Map<string, { category: CatalogCategory | null; rows: PickerRow[] }>();
        for (const r of hits) {
            const key = r.category ? String(r.category.id) : 'none';
            if (!byCategory.has(key)) byCategory.set(key, { category: r.category, rows: [] });
            byCategory.get(key)!.rows.push(r);
        }

        return [...byCategory.values()].sort((a, b) =>
            !a.category ? 1 : !b.category ? -1 : a.category.name.localeCompare(b.category.name, 'ar'),
        );
    }, [rows, q, only]);

    const choose = (v: string) => {
        onPick(v);
        setOpen(false);
        setQ('');
    };

    const chip = (key: number | 'none' | null, label: string, color: string | null) => {
        const active = only === key;

        return (
            <button
                key={String(key)}
                type="button"
                onClick={() => setOnly(key)}
                className="shrink-0 rounded-full border px-2.5 py-1 text-[11px] font-medium transition-colors"
                style={
                    color
                        ? active
                            ? { backgroundColor: color, borderColor: color, color: '#fff' }
                            : { color, borderColor: `color-mix(in srgb, ${color} 30%, white)`, backgroundColor: `color-mix(in srgb, ${color} 8%, white)` }
                        : active
                          ? { backgroundColor: '#111', borderColor: '#111', color: '#fff' }
                          : { color: '#4b4b4b', borderColor: '#e8e8e8', backgroundColor: '#fff' }
                }
            >
                {label}
            </button>
        );
    };

    return (
        <div ref={box} className="relative">
            <button
                type="button"
                aria-expanded={open}
                aria-label={t('بند الكتالوج')}
                onClick={() => {
                    setOpen((v) => !v);
                    window.setTimeout(() => search.current?.focus(), 0);
                }}
                className={cn(
                    'flex min-h-10 w-full items-center justify-between gap-2 rounded-[10px] pointer-coarse:min-h-11',
                    'border border-[var(--ui-border,#e8e8e8)] bg-white px-3 py-1.5 text-start text-sm text-[#111]',
                    'outline-none transition-[border-color,box-shadow] focus:border-[#d1d5db] focus:shadow-[0_0_0_3px_rgba(0,0,0,0.05)]',
                )}
            >
                {selected ? (
                    <span className="flex min-w-0 flex-col items-start gap-1">
                        <span className="w-full truncate">{selected.name}</span>
                        <CategoryTag category={selected.category} />
                    </span>
                ) : (
                    <span className="truncate text-[#9ca3af]">{t(placeholder)}</span>
                )}
                <ChevronDown className="size-4 shrink-0 text-[#6b7280]" />
            </button>

            {open && (
                <div className="absolute start-0 z-30 mt-1 w-[min(30rem,calc(100vw-2rem))] rounded-[12px] border border-[var(--ui-border,#e8e8e8)] bg-white p-2 shadow-lg">
                    <Input
                        ref={search}
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Escape') {
                                e.preventDefault();
                                setOpen(false);
                            }
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                const first = groups[0]?.rows[0];
                                if (first) choose(String(first.id));
                            }
                        }}
                        placeholder={t('ابحث بالاسم أو الرمز')}
                        aria-label={t('ابحث بالاسم أو الرمز')}
                        className="h-9 text-[13px]"
                    />

                    {/* رقائقُ التصنيف — بلونها، والمختارةُ ممتلئةٌ به */}
                    <div className="mt-2 flex flex-wrap gap-1.5 pb-1">
                        {chip(null, t('الكل'), null)}
                        {categories.map((c) => chip(c.id, c.name, categoryColor(c)))}
                        {hasUncategorized && chip('none', t('بلا تصنيف'), '#6b7280')}
                    </div>

                    <div className="mt-1 max-h-72 overflow-y-auto">
                        <button
                            type="button"
                            onClick={() => choose('')}
                            className="flex w-full items-center justify-between rounded-[8px] px-2 py-1.5 text-start text-[13px] text-[#6b7280] hover:bg-[#f7f7f5]"
                        >
                            {t('بند حر (بلا كتالوج)')}
                            {!selected && <Check className="size-4 text-[#5b21b6]" />}
                        </button>

                        {groups.length === 0 && (
                            <p className="px-2 py-6 text-center text-[13px] text-[#9ca3af]">{t('لا بند يطابق البحث.')}</p>
                        )}

                        {groups.map(({ category, rows: items }) => (
                            <div key={category ? category.id : 'none'} className="mt-2">
                                <div className="sticky top-0 z-[1] bg-white px-1 pb-1">
                                    <CategoryTag category={category} />
                                </div>
                                {items.map((r) => (
                                    <button
                                        key={r.id}
                                        type="button"
                                        onClick={() => choose(String(r.id))}
                                        className="flex w-full items-center gap-2 rounded-[8px] py-1.5 pe-2 ps-3 text-start hover:bg-[#f7f7f5]"
                                        style={{ borderInlineStart: `3px solid ${categoryColor(category)}` }}
                                    >
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-[13px] text-[#111]">{r.name}</span>
                                            <span className="block truncate text-[11px] text-[#9ca3af]">
                                                {kindLabel(r.kind)}
                                                {r.sku ? ` · ${r.sku}` : ''}
                                            </span>
                                        </span>
                                        <span className="shrink-0 text-[12px] tabular-nums text-[#4b4b4b]">
                                            {r.list_price > 0 ? money(r.list_price) : t('حسب النطاق')}
                                        </span>
                                        {r.id === value && <Check className="size-4 shrink-0 text-[#5b21b6]" />}
                                    </button>
                                ))}
                            </div>
                        ))}
                    </div>

                    {only !== null && (
                        <button
                            type="button"
                            onClick={() => setOnly(null)}
                            className="mt-1 flex items-center gap-1 px-2 text-[11px] text-[#6b7280] hover:text-[#111]"
                        >
                            <X className="size-3" />
                            {t('كل التصنيفات')}
                        </button>
                    )}
                </div>
            )}
        </div>
    );
}
