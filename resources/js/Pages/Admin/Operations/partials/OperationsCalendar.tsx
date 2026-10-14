import { useEffect, useMemo, useState, type CSSProperties } from 'react';
import { usePage } from '@inertiajs/react';
import { CalendarDays, ChevronDown, ChevronLeft, ChevronRight, MapPin, Plus } from 'lucide-react';
import SmartLink from '@/Components/SmartLink';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

export interface CalendarEvent {
    id: number;
    code: string;
    name: string;
    status: string;
    venue_name: string | null;
    event_starts_at: string | null;
    event_ends_at: string | null;
    window_starts_at: string | null;
    window_ends_at: string | null;
    lead: string | null;
    project: { id: number; code: string; name: string } | null;
}

export interface CalendarData {
    month: string;
    from: string;
    to: string;
    today: string;
    events: CalendarEvent[];
    upcoming: CalendarEvent[];
    undated: number;
}

/* لونُ الحال في شارة البطاقة — الكلمةُ بجانب الاسم بلونها */
const TONE: Record<string, string> = {
    planning: '#6366f1',
    preparing: '#d97706',
    ready: '#2563eb',
    setup: '#0891b2',
    live: '#059669',
    teardown: '#0891b2',
    completed: '#16a34a',
    cancelled: '#dc2626',
};

export const toneOf = (status: string) => TONE[status] ?? '#6b7280';

/*
 * لكلّ فعاليّةٍ لونُها — من لوحةٍ هادئة، بمعرّفها لا بترتيبها.
 *
 * فيبقى «معرضُ السياحة» بلونه في كلّ شهرٍ وكلّ فتحة، ويُفرَّق بين
 * فعاليّتين متجاورتين بلا قراءة. والقطعُ الثلاث من اللون نفسه بدرجاتٍ:
 * التجهيزُ مخطَّطٌ فاتح، ويومُ الفعاليّة ممتلئ، والفكُّ أفتحُ منهما —
 * خطٌّ واحدٌ يُقرأ من أوّله إلى آخره. والملغاةُ رماديّةٌ مشطوبة.
 */
const HUES = ['#4f46e5', '#0d9488', '#db2777', '#2563eb', '#7c3aed', '#ea580c', '#0891b2', '#65a30d'];
const hueOf = (e: CalendarEvent) => (e.status === 'cancelled' ? '#a1a1aa' : HUES[Math.abs(e.id) % HUES.length]);
const mix = (c: string, p: number, base = 'white') => `color-mix(in srgb, ${c} ${p}%, ${base})`;

function barStyle(kind: Kind, c: string, cancelled = false): CSSProperties {
    if (cancelled) return { background: '#f4f4f5', color: '#a1a1aa', boxShadow: 'inset 0 0 0 1px #e4e4e7', textDecoration: 'line-through' };
    if (kind === 'event') return { background: c, color: '#fff' };
    if (kind === 'setup')
        return {
            background: `repeating-linear-gradient(-45deg, ${mix(c, 20)} 0 5px, ${mix(c, 9)} 5px 10px)`,
            color: mix(c, 85, 'black'),
            boxShadow: `inset 0 0 0 1px ${mix(c, 28)}`,
        };
    return { background: mix(c, 9), color: mix(c, 60, '#52525b'), boxShadow: `inset 0 0 0 1px ${mix(c, 18)}` };
}

const pad = (n: number) => String(n).padStart(2, '0');
const isoOf = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const dayOf = (stamp: string | null) => (stamp ? stamp.slice(0, 10) : null);
const dateOf = (iso: string) => new Date(iso + 'T00:00:00');
const shiftIso = (iso: string, n: number) => {
    const d = dateOf(iso);
    d.setDate(d.getDate() + n);
    return isoOf(d);
};
const DAY = 86_400_000;
const daysBetween = (a: string, b: string) => Math.round((dateOf(b).getTime() - dateOf(a).getTime()) / DAY);

/** حدودُ الفعاليّة بالأيّام: نافذتُها (تجهيزٌ إلى فكّ) ويومُها */
function spanOf(e: CalendarEvent) {
    const from = dayOf(e.event_starts_at);
    if (!from) return null;
    const to = dayOf(e.event_ends_at) ?? from;
    return { winFrom: dayOf(e.window_starts_at) ?? from, from, to, winTo: dayOf(e.window_ends_at) ?? to };
}

type Kind = 'setup' | 'event' | 'teardown';
type Segment = { event: CalendarEvent; kind: Kind; start: number; end: number; joinPrev: boolean; joinNext: boolean; openStart: boolean; openEnd: boolean };

const LANES = 3;

/**
 * تقويمُ الفعاليّات — الشهرُ في ثلثَيه، والقادمُ في ثلثه.
 *
 * كلُّ فعاليّةٍ خطٌّ واحدٌ متّصلٌ عبر المربّعات بلونها: تجهيزٌ مخطَّطٌ ثمّ
 * يومُها ممتلئًا ثمّ فكٌّ فاتح — فيُرى المعرضُ ذو الأيّام الثلاثة وتجهيزُه قبله
 * قطعةً واحدة لا نقاطًا متفرّقة. والضغطُ على يومٍ يضيّق القائمةَ إليه.
 */
export default function OperationsCalendar({
    data,
    statusLabel,
    onMonth,
    onCreate,
}: {
    data: CalendarData;
    statusLabel: (s: string) => string;
    onMonth: (month: string) => void;
    onCreate?: () => void;
}) {
    const t = useTranslate();
    const { locale } = usePage<PageProps>().props;
    const intl = locale === 'en' ? 'en-GB' : 'ar-u-nu-latn';

    const [picked, setPicked] = useState<string | null>(null);
    const [only, setOnly] = useState('');
    /* شهرٌ آخر: اليومُ المختارُ من الشهر السابق لا معنى له هنا */
    useEffect(() => setPicked(null), [data.month]);

    const monthStart = dateOf(data.month + '-01');
    const shift = (n: number) => {
        const d = new Date(monthStart);
        d.setMonth(d.getMonth() + n);
        onMonth(`${d.getFullYear()}-${pad(d.getMonth() + 1)}`);
    };

    const weeks = useMemo(() => {
        const out: string[][] = [];
        for (let iso = data.from; iso <= data.to; iso = shiftIso(iso, 1)) {
            if (out.length === 0 || out[out.length - 1].length === 7) out.push([]);
            out[out.length - 1].push(iso);
        }
        return out;
    }, [data.from, data.to]);

    const pass = (e: CalendarEvent) => !only || e.status === only;
    const events = useMemo(() => data.events.filter(pass), [data.events, only]); // eslint-disable-line react-hooks/exhaustive-deps

    /* أيّامُ الفعاليّات بلون أوّلها — حافّةٌ رفيعةٌ ولمسةٌ خفيفةٌ منه، لا أكثر */
    const eventDays = useMemo(() => {
        const map = new Map<string, string>();
        for (const e of events) {
            const s = spanOf(e);
            if (!s || e.status === 'cancelled') continue;
            for (let d = s.from; d <= s.to; d = shiftIso(d, 1)) if (!map.has(d)) map.set(d, hueOf(e));
        }
        return map;
    }, [events]);

    /*
     * الخطوطُ أسبوعًا أسبوعًا — لكلّ فعاليّةٍ حارةٌ في أسبوعها، وقطعُها
     * الثلاث (تجهيزٌ ويومٌ وفكّ) متلاصقةٌ فيها. وما زاد عن الحارات يُعدّ.
     */
    const layout = useMemo(
        () =>
            weeks.map((week) => {
                const first = week[0];
                const last = week[week.length - 1];
                const col = (iso: string) => daysBetween(first, iso);
                const inWeek = events
                    .map((e) => ({ e, s: spanOf(e) }))
                    .filter((x): x is { e: CalendarEvent; s: NonNullable<ReturnType<typeof spanOf>> } => !!x.s && x.s.winFrom <= last && x.s.winTo >= first)
                    .sort((a, b) => a.s.winFrom.localeCompare(b.s.winFrom) || b.s.winTo.localeCompare(a.s.winTo));

                const laneEnds: number[] = [];
                const bars: { lane: number; segments: Segment[] }[] = [];
                const hidden = new Map<string, number>();

                for (const { e, s } of inWeek) {
                    const a = Math.max(0, col(s.winFrom));
                    const b = Math.min(6, col(s.winTo));
                    let lane = laneEnds.findIndex((end) => end < a);
                    if (lane === -1) lane = laneEnds.length;
                    if (lane >= LANES) {
                        for (let c = a; c <= b; c++) hidden.set(week[c], (hidden.get(week[c]) ?? 0) + 1);
                        continue;
                    }
                    laneEnds[lane] = b;

                    const parts: [Kind, string, string][] = (
                        [
                            ['setup', s.winFrom, shiftIso(s.from, -1)],
                            ['event', s.from, s.to],
                            ['teardown', shiftIso(s.to, 1), s.winTo],
                        ] as [Kind, string, string][]
                    ).filter(([, f, to]) => f <= to && to >= first && f <= last);

                    const segments = parts.map(([kind, f, to], k) => ({
                        event: e,
                        kind,
                        start: Math.max(0, col(f)),
                        end: Math.min(6, col(to)),
                        joinPrev: k > 0,
                        joinNext: k < parts.length - 1,
                        openStart: f < first,
                        openEnd: to > last,
                    }));
                    bars.push({ lane, segments });
                }

                return { week, bars, hidden };
            }),
        [weeks, events],
    );

    const inMonth = (iso: string) => dateOf(iso).getMonth() === monthStart.getMonth();

    /* القائمة: القادمةُ من اليوم، أو ما يقع في اليوم المختار */
    const listed = useMemo(() => {
        if (!picked) return data.upcoming.filter(pass);
        return events.filter((e) => {
            const s = spanOf(e);
            return s && s.winFrom <= picked && s.winTo >= picked;
        });
    }, [picked, events, data.upcoming, only]); // eslint-disable-line react-hooks/exhaustive-deps

    const fmt = (opts: Intl.DateTimeFormatOptions, d: Date) => new Intl.DateTimeFormat(intl, opts).format(d);
    const weekdays = weeks[0].map((iso) => fmt({ weekday: 'short' }, dateOf(iso)));
    const todayWeekday = dateOf(data.today).getDay();
    const time = (stamp: string | null) => (stamp ? stamp.slice(11, 16) : '');
    const statusesHere = [...new Set([...data.events, ...data.upcoming].map((e) => e.status))];

    /* العددُ بالعربيّة كما يُقال: يومٌ، يومان، ثلاثةُ أيّام، أحدَ عشرَ يومًا */
    const count = (n: number, one: string, two: string, few: string, many: string) =>
        t(n === 1 ? one : n === 2 ? two : n <= 10 ? few : many, { n: String(n) });

    /* «اليوم» و«غدًا» و«بعد ٥ أيّام» — المسافةُ أوضحُ من التاريخ */
    const when = (e: CalendarEvent) => {
        const s = spanOf(e);
        if (!s) return null;
        if (s.from <= data.today && s.to >= data.today) return t('جارية الآن');
        const d = daysBetween(data.today, s.from);
        if (d === 0) return t('اليوم');
        if (d === 1) return t('غدًا');
        return d > 0 ? count(d, 'غدًا', 'بعد يومين', 'بعد :n أيام', 'بعد :n يومًا') : null;
    };

    /* موضعُ القطعة في صفّ الأسبوع — بالأعمدة والفجوات، والاتّجاهُ يتبع الصفحة */
    const place = (seg: Segment, lane: number): CSSProperties => {
        const W = '((100% - 6 * var(--g)) / 7)';
        const extStart = seg.openStart ? '0px' : seg.joinPrev ? 'calc(var(--g) / 2)' : '-5px';
        const extEnd = seg.openEnd ? '0px' : seg.joinNext ? 'calc(var(--g) / 2)' : '-5px';
        const n = seg.end - seg.start + 1;
        const r = 'var(--r)';

        return {
            insetInlineStart: `calc(${seg.start} * (${W} + var(--g)) - ${extStart})`,
            width: `calc(${n} * ${W} + ${n - 1} * var(--g) + ${extStart} + ${extEnd})`,
            top: `calc(var(--lt) + ${lane} * var(--lh))`,
            borderStartStartRadius: seg.joinPrev || seg.openStart ? 0 : r,
            borderEndStartRadius: seg.joinPrev || seg.openStart ? 0 : r,
            borderStartEndRadius: seg.joinNext || seg.openEnd ? 0 : r,
            borderEndEndRadius: seg.joinNext || seg.openEnd ? 0 : r,
        };
    };

    const styleOf = (seg: Segment): CSSProperties => barStyle(seg.kind, hueOf(seg.event), seg.event.status === 'cancelled');

    const labelOf = (seg: Segment) => (seg.kind === 'event' ? seg.event.name : seg.kind === 'setup' ? t('تجهيز') : t('فكّ'));

    return (
        <div className="overflow-hidden rounded-[24px] border border-[var(--ui-border,#e8e8e8)] bg-white">
            <div className="grid lg:grid-cols-[minmax(0,65fr)_minmax(0,35fr)]">
                {/* ─────────── الشهر ─────────── */}
                <section className="p-4 sm:p-6 lg:border-e lg:border-[var(--ui-border,#e8e8e8)]">
                    <div className="mb-5 flex items-center justify-between gap-2">
                        <h2 className="text-[22px] font-bold tracking-tight text-[#111]">
                            {fmt({ month: 'long' }, monthStart)} <span className="font-semibold text-[#a1a1aa] tabular-nums">{monthStart.getFullYear()}</span>
                        </h2>
                        <div className="flex items-center gap-1.5">
                            <button
                                type="button"
                                onClick={() => onMonth(data.today.slice(0, 7))}
                                className="h-9 rounded-full border border-[var(--ui-border,#e8e8e8)] px-4 text-[13px] font-medium text-[#111] transition-colors hover:bg-[#f4f4f5]"
                            >
                                {t('اليوم')}
                            </button>
                            <button
                                type="button"
                                onClick={() => shift(-1)}
                                aria-label={t('الشهر السابق')}
                                className="flex size-9 items-center justify-center rounded-full border border-[var(--ui-border,#e8e8e8)] text-[#111] transition-colors hover:bg-[#f4f4f5]"
                            >
                                <ChevronRight className="size-4 ltr:rotate-180" />
                            </button>
                            <button
                                type="button"
                                onClick={() => shift(1)}
                                aria-label={t('الشهر التالي')}
                                className="flex size-9 items-center justify-center rounded-full border border-[var(--ui-border,#e8e8e8)] text-[#111] transition-colors hover:bg-[#f4f4f5]"
                            >
                                <ChevronLeft className="size-4 ltr:rotate-180" />
                            </button>
                        </div>
                    </div>

                    <div className="flex flex-col gap-[var(--g)] [--g:5px] [--lh:8px] [--lt:24px] [--r:3px] sm:[--g:10px] sm:[--lh:22px] sm:[--lt:34px] sm:[--r:7px]">
                        <div className="grid grid-cols-7 gap-[var(--g)]">
                            {weekdays.map((w, i) => (
                                <div
                                    key={w}
                                    className={cn(
                                        'rounded-full py-1.5 text-center text-[11px] font-medium sm:text-[12.5px]',
                                        dateOf(weeks[0][i]).getDay() === todayWeekday ? 'bg-[#e4e4e7] font-bold text-[#111]' : 'bg-[#f4f4f5] text-[#71717a]',
                                    )}
                                >
                                    {w}
                                </div>
                            ))}
                        </div>

                        {layout.map(({ week, bars, hidden }) => (
                            <div key={week[0]} className="relative grid grid-cols-7 gap-[var(--g)]">
                                {week.map((iso) => {
                                    const own = inMonth(iso);
                                    const hue = eventDays.get(iso);
                                    const isToday = iso === data.today;
                                    const more = hidden.get(iso) ?? 0;

                                    return (
                                        <button
                                            key={iso}
                                            type="button"
                                            onClick={() => setPicked(picked === iso ? null : iso)}
                                            aria-pressed={picked === iso}
                                            aria-label={fmt({ weekday: 'long', day: 'numeric', month: 'long' }, dateOf(iso))}
                                            className={cn(
                                                'relative flex min-h-[58px] flex-col items-center rounded-[12px] pt-1 transition-[background-color,box-shadow,opacity] sm:min-h-[106px] sm:rounded-[14px] sm:pt-2',
                                                !hue && (own ? 'bg-[#f7f7f8] text-[#27272a] hover:bg-[#efeff1]' : 'bg-[#fbfbfb] text-[#d4d4d8] hover:bg-[#f4f4f5]'),
                                                hue && 'text-[#27272a] hover:brightness-[0.98]',
                                                hue && !own && 'opacity-55',
                                                picked === iso && 'outline outline-2 outline-offset-2 outline-[#111]',
                                            )}
                                            style={hue ? { background: mix(hue, 5), boxShadow: `inset 0 0 0 1.5px ${mix(hue, 38)}` } : undefined}
                                        >
                                            <span
                                                className={cn(
                                                    'flex size-6 items-center justify-center rounded-full text-[12px] font-medium tabular-nums sm:size-7 sm:text-[14px]',
                                                    isToday && 'bg-[#111] font-semibold text-white',
                                                )}
                                                style={hue && !isToday ? { color: mix(hue, 80, 'black'), fontWeight: 600 } : undefined}
                                            >
                                                {dateOf(iso).getDate()}
                                            </span>
                                            {more > 0 && (
                                                <span className="absolute bottom-1 text-[10px] font-medium text-[#71717a] sm:bottom-1.5 sm:text-[11px]">
                                                    {t('+ :n أخرى', { n: String(more) })}
                                                </span>
                                            )}
                                        </button>
                                    );
                                })}

                                {/* الخطوطُ فوق المربّعات — لا تلتقط الضغط فيبقى لليوم تحتها */}
                                {bars.map(({ lane, segments }) =>
                                    segments.map((seg) => (
                                        <span
                                            key={`${seg.event.id}-${seg.kind}`}
                                            className="pointer-events-none absolute flex h-[6px] items-center overflow-hidden px-1.5 text-[11px] font-semibold leading-none sm:h-[19px]"
                                            style={{ ...place(seg, lane), ...styleOf(seg) }}
                                        >
                                            <span className="hidden truncate sm:block">{seg.openStart && seg.kind !== 'event' ? '' : labelOf(seg)}</span>
                                        </span>
                                    )),
                                )}
                            </div>
                        ))}
                    </div>

                    {/* مفتاحُ الخطوط — بلونٍ واحدٍ للمثال، ولكلّ فعاليّةٍ لونُها */}
                    <div className="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-[11.5px] text-[#71717a]">
                        {(
                            [
                                ['setup', 'تجهيز'],
                                ['event', 'يوم الفعاليّة'],
                                ['teardown', 'فكّ'],
                            ] as const
                        ).map(([kind, text]) => (
                            <span key={kind} className="inline-flex items-center gap-1.5">
                                <span className="h-2.5 w-6 rounded-[3px]" style={barStyle(kind, '#4f46e5')} />
                                {t(text)}
                            </span>
                        ))}
                        <span className="text-[#a1a1aa]">· {t('لكلّ فعاليّة لونها')}</span>
                    </div>
                </section>

                {/* ─────────── الفعاليّات القادمة ─────────── */}
                <section className="flex min-h-0 flex-col border-t border-[var(--ui-border,#e8e8e8)] p-4 sm:p-5 lg:border-t-0">
                    <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
                        <div className="min-w-0">
                            <h2 className="text-[19px] font-bold tracking-tight text-[#111]">
                                {picked ? fmt({ weekday: 'long', day: 'numeric', month: 'long' }, dateOf(picked)) : t('الفعاليّات القادمة')}
                            </h2>
                            {picked ? (
                                <button type="button" onClick={() => setPicked(null)} className="text-[12px] text-[#71717a] underline-offset-2 hover:text-[#111] hover:underline">
                                    {t('عرض القادمة')}
                                </button>
                            ) : (
                                data.undated > 0 && <p className="text-[12px] text-[#a1a1aa]">{t(':n بلا موعد — في القائمة', { n: String(data.undated) })}</p>
                            )}
                        </div>
                        <div className="flex items-center gap-2">
                            <label className="relative">
                                <span className="sr-only">{t('الحالة')}</span>
                                <select
                                    value={only}
                                    onChange={(e) => setOnly(e.target.value)}
                                    className="h-9 appearance-none rounded-full border border-[var(--ui-border,#e8e8e8)] bg-white pe-8 ps-3.5 text-[13px] font-medium text-[#111] outline-none hover:bg-[#fafafa]"
                                >
                                    <option value="">{t('الكل')}</option>
                                    {statusesHere.map((s) => (
                                        <option key={s} value={s}>
                                            {statusLabel(s)}
                                        </option>
                                    ))}
                                </select>
                                <ChevronDown className="pointer-events-none absolute end-2.5 top-1/2 size-4 -translate-y-1/2 text-[#71717a]" />
                            </label>
                            {onCreate && (
                                <button
                                    type="button"
                                    onClick={onCreate}
                                    aria-label={t('فعاليّة جديدة')}
                                    title={t('فعاليّة جديدة')}
                                    className="flex size-9 items-center justify-center rounded-full bg-[#111] text-white shadow-[0_6px_16px_rgba(0,0,0,0.18)] transition-opacity hover:opacity-90"
                                >
                                    <Plus className="size-4" />
                                </button>
                            )}
                        </div>
                    </div>

                    <div className="-me-2 space-y-2.5 overflow-y-auto pe-2 lg:max-h-[640px]">
                        {listed.length === 0 && (
                            <div className="rounded-[18px] border border-dashed border-[var(--ui-border,#e8e8e8)] py-14 text-center text-[13px] text-[#a1a1aa]">
                                {picked ? t('لا فعاليّات في هذا اليوم.') : t('لا فعاليّات قادمة.')}
                            </div>
                        )}

                        {listed.map((event) => {
                            const color = toneOf(event.status);
                            const hue = hueOf(event);
                            const s = spanOf(event);
                            const start = s ? dateOf(s.from) : null;
                            const length = s ? daysBetween(s.from, s.to) + 1 : 1;
                            const prep = s ? daysBetween(s.winFrom, s.from) : 0;
                            const soon = when(event);
                            const inPrep = picked && s && picked < s.from;

                            return (
                                <SmartLink
                                    key={event.id}
                                    routeName="admin.operations.show"
                                    href={route('admin.operations.show', event.id)}
                                    className={cn(
                                        'flex items-stretch gap-3 rounded-[16px] border border-[var(--ui-border,#e8e8e8)] bg-white p-3 transition-[box-shadow,border-color] hover:border-[#d4d4d8] hover:shadow-[0_6px_20px_rgba(0,0,0,0.06)]',
                                        inPrep && 'border-dashed',
                                    )}
                                >
                                    <div
                                        className="flex w-12 shrink-0 flex-col items-center justify-center rounded-[12px] py-1.5"
                                        style={{ background: mix(hue, 8), boxShadow: `inset 0 0 0 1px ${mix(hue, 22)}` }}
                                    >
                                        <span className="text-[20px] font-bold leading-none tabular-nums" style={{ color: mix(hue, 90, 'black') }}>
                                            {start ? pad(start.getDate()) : '—'}
                                        </span>
                                        <span className="mt-1 text-[11px]" style={{ color: mix(hue, 60, '#71717a') }}>
                                            {start ? fmt({ month: 'short' }, start) : ''}
                                        </span>
                                    </div>

                                    <div className="min-w-0 flex-1">
                                        <div className={cn('truncate text-[14.5px] font-semibold text-[#27272a]', event.status === 'cancelled' && 'line-through decoration-[#dc2626]/50')}>
                                            {event.name}
                                        </div>
                                        <div className="mt-1.5 flex flex-wrap items-center gap-1 text-[11.5px]">
                                            <span
                                                className="rounded-[6px] px-1.5 py-0.5 font-medium"
                                                style={{ color: `color-mix(in srgb, ${color} 85%, black)`, backgroundColor: `color-mix(in srgb, ${color} 14%, white)` }}
                                            >
                                                {inPrep ? t('تجهيز') : statusLabel(event.status)}
                                            </span>
                                            {soon && <span className="rounded-[6px] bg-[#111] px-1.5 py-0.5 font-medium text-white">{soon}</span>}
                                            <span className="inline-flex items-center gap-1 rounded-[6px] bg-[#f4f4f5] px-1.5 py-0.5 text-[#52525b]">
                                                <CalendarDays className="size-3" />
                                                {length > 1 ? count(length, 'يوم واحد', 'يومان', ':n أيام', ':n يومًا') : <span className="tabular-nums" dir="ltr">{time(event.event_starts_at)}</span>}
                                            </span>
                                            {prep > 0 && <span className="rounded-[6px] px-1.5 py-0.5" style={{ background: `repeating-linear-gradient(-45deg, ${mix(hue, 14)} 0 4px, ${mix(hue, 7)} 4px 8px)`, color: mix(hue, 85, 'black') }}>{count(prep, 'تجهيز يوم قبلها', 'تجهيز يومين قبلها', 'تجهيز :n أيام قبلها', 'تجهيز :n يومًا قبلها')}</span>}
                                        </div>
                                        {event.venue_name && (
                                            <div className="mt-1 flex min-w-0 items-center gap-1 text-[11.5px] text-[#71717a]">
                                                <MapPin className="size-3 shrink-0" />
                                                <span className="truncate">{event.venue_name}</span>
                                            </div>
                                        )}
                                    </div>
                                </SmartLink>
                            );
                        })}
                    </div>
                </section>
            </div>
        </div>
    );
}
