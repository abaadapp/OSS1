import { Fragment, useState } from 'react';
import { AlertTriangle, CheckCircle2, ChevronDown, ChevronUp } from 'lucide-react';
import { Badge } from '@/Components/ui/badge';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';

export interface ForecastTimelineRow {
    project_id: number;
    code: string;
    name: string;
    date: string;
    required: number;
    source: 'operation' | 'scope';
    remaining: number;
    shortage: number;
}

export interface ForecastLine {
    product_id: number;
    name: string;
    sku: string | null;
    available: number;
    committed: number;
    required: number;
    projected: number;
    shortage: number;
    source: 'operation' | 'scope';
}

export interface ProjectForecast {
    days: number;
    from: string;
    to: string;
    in_window: boolean;
    date: string | null;
    lines: ForecastLine[];
    shortages: number;
    timelines: Record<number, ForecastTimelineRow[]>;
}

export interface ProductForecast {
    id: number;
    name: string;
    sku: string | null;
    available: number;
    alert_qty: number;
    shortage: number;
    timeline: ForecastTimelineRow[];
}

const SOURCE_LABEL: Record<string, string> = { operation: 'مؤكد تشغيليًّا', scope: 'من نطاق المشروع — غير مؤكد تشغيليًّا' };

/** الكميّةُ كما تُقرأ — بلا كسورٍ لا معنى لها */
const qty = (v: number) => number(v, Number.isInteger(v) ? 0 : 2);

/** «نقص 3» أو «كافٍ» — الحالُ بلون نظام التصميم */
export function ForecastStatus({ shortage }: { shortage: number }) {
    const t = useTranslate();

    return shortage > 0
        ? <Badge variant="danger"><AlertTriangle className="size-3" />{t('نقص :n', { n: qty(shortage) })}</Badge>
        : <Badge variant="success"><CheckCircle2 className="size-3" />{t('كافٍ')}</Badge>;
}

/** الخطُّ الزمنيّ لمنتج — توقّعٌ لا خصم */
export function ForecastTimeline({ rows, available, highlight }: { rows: ForecastTimelineRow[]; available: number; highlight?: number }) {
    const t = useTranslate();

    return (
        <ol className="space-y-1 text-[12.5px]">
            <li className="text-[#6b7280]">{t('المتوفر الآن: :n', { n: qty(available) })}</li>
            {rows.map((r) => (
                <li key={r.project_id} className={'flex flex-wrap items-center gap-x-2 ' + (r.project_id === highlight ? 'font-semibold text-[#111]' : 'text-[#4b4b4b]')}>
                    <span className="tabular-nums text-[#9ca3af]" dir="ltr">{r.date}</span>
                    <span>— {r.code} {r.name}</span>
                    <span>— {t('يحتاج :n', { n: qty(r.required) })}</span>
                    <span>— {r.shortage > 0 ? <span className="text-[#b91c1c]">{t('نقص :n', { n: qty(r.shortage) })}</span> : t('المتبقي :n', { n: qty(r.remaining) })}</span>
                    {r.source === 'scope' && <span className="text-[11px] text-[#b45309]">({t('غير مؤكد تشغيليًّا')})</span>}
                </li>
            ))}
        </ol>
    );
}

/**
 * توقّعُ مخزون مشروعٍ — لكلّ منتجٍ مخزون: المتوفّرُ الآن، وما تحتاجه مشاريعُ قبله
 * في الجدول، واحتياجُه، والمتوقَّعُ بعده. قراءةٌ لا خصم (`InventoryForecast`).
 */
export default function ProjectInventoryForecast({ data, projectId }: { data: ProjectForecast; projectId: number }) {
    const t = useTranslate();
    const [open, setOpen] = useState<number | null>(null);

    if (!data.in_window || data.lines.length === 0) {
        return (
            <p className="text-[13px] text-[#9ca3af]">
                {data.in_window
                    ? t('لا يحتاج هذا المشروع منتجات مخزّنة في التوقّع.')
                    : t('المشروع خارج نافذة التوقّع (:n يومًا) — أو لا تاريخ له.', { n: String(data.days) })}
            </p>
        );
    }

    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-[640px] text-[13px]">
                <thead className="text-[12px] text-[#6b7280]">
                    <tr>
                        <th className="p-2 text-start">{t('المنتج')}</th>
                        <th className="p-2 text-end">{t('المتوفر الآن')}</th>
                        <th className="p-2 text-end">{t('لمشاريع قبله')}</th>
                        <th className="p-2 text-end">{t('احتياج هذا المشروع')}</th>
                        <th className="p-2 text-end">{t('المتوقع بعده')}</th>
                        <th className="p-2 text-center">{t('الحال')}</th>
                    </tr>
                </thead>
                <tbody>
                    {data.lines.map((l) => (
                        <Fragment key={l.product_id}>
                            <tr className="cursor-pointer border-t border-[#f4f4f5] hover:bg-[#fafafa]" onClick={() => setOpen(open === l.product_id ? null : l.product_id)}>
                                <td className="p-2">
                                    <span className="font-medium text-[#111]">{l.name}</span>
                                    <span className={'ms-2 text-[11px] ' + (l.source === 'scope' ? 'text-[#b45309]' : 'text-[#6b7280]')}>{t(SOURCE_LABEL[l.source])}</span>
                                </td>
                                <td className="p-2 text-end tabular-nums">{qty(l.available)}</td>
                                <td className="p-2 text-end tabular-nums text-[#6b7280]">{qty(l.committed)}</td>
                                <td className="p-2 text-end tabular-nums">{qty(l.required)}</td>
                                <td className={'p-2 text-end font-semibold tabular-nums ' + (l.projected < 0 ? 'text-[#b91c1c]' : 'text-[#111]')}>{qty(l.projected)}</td>
                                <td className="p-2 text-center">
                                    <span className="inline-flex items-center gap-1"><ForecastStatus shortage={l.shortage} />{open === l.product_id ? <ChevronUp className="size-3.5 text-[#9ca3af]" /> : <ChevronDown className="size-3.5 text-[#9ca3af]" />}</span>
                                </td>
                            </tr>
                            {open === l.product_id && (
                                <tr className="bg-[#fafafa]">
                                    <td colSpan={6} className="px-4 py-3">
                                        <ForecastTimeline rows={data.timelines[l.product_id] ?? []} available={l.available} highlight={projectId} />
                                    </td>
                                </tr>
                            )}
                        </Fragment>
                    ))}
                </tbody>
            </table>
            <p className="mt-2 text-[11.5px] text-[#9ca3af]">
                {t('توقّع لا خصم: لا تتحرك كمية حتى تُصرف فعلًا. النافذة :n يومًا (:from ← :to).', { n: String(data.days), from: data.from, to: data.to })}
            </p>
        </div>
    );
}
