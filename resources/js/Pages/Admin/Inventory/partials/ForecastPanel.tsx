import { useState } from 'react';
import { ChevronDown, ChevronUp } from 'lucide-react';
import SmartLink from '@/Components/SmartLink';
import { Card } from '@/Components/ui/card';
import { ForecastStatus, ForecastTimeline, type ProductForecast } from '@/Components/InventoryForecast';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';

export interface InventoryForecastPanel {
    days: number;
    from: string;
    to: string;
    products: ProductForecast[];
    may_settings: boolean;
}

/**
 * توقّعُ المخزون للمشاريع القادمة — المنتجاتُ التي يحتاجها مشروعٌ في النافذة،
 * والناقصُ أوّلًا، ولكلٍّ خطُّه الزمنيّ. قراءةٌ لا خصم (`InventoryForecast`).
 */
export default function ForecastPanel({ data }: { data: InventoryForecastPanel }) {
    const t = useTranslate();
    const [open, setOpen] = useState<number | null>(null);
    const short = data.products.filter((p) => p.shortage > 0).length;

    return (
        <Card className="mb-4 p-4">
            <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 className="text-[15px] font-semibold text-[#111]">{t('توقّع المخزون للمشاريع القادمة')}</h2>
                    <p className="text-[12px] text-[#9ca3af]">
                        {t(':n يومًا (:from ← :to) — توقّع لا خصم', { n: String(data.days), from: data.from, to: data.to })}
                        {data.may_settings && <> · <SmartLink routeName="admin.settings.index" href={route('admin.settings.index')} className="underline">{t('تغيير النافذة')}</SmartLink></>}
                    </p>
                </div>
                {short > 0 && <span className="rounded-full bg-[#fef2f2] px-2.5 py-1 text-[12px] font-medium text-[#b91c1c]">{t('نقص في :n منتجات', { n: String(short) })}</span>}
            </div>

            {data.products.length === 0 ? (
                <p className="text-[13px] text-[#9ca3af]">{t('لا مشاريع قادمة تحتاج منتجات مخزّنة في النافذة.')}</p>
            ) : (
                <ul className="divide-y divide-[#f4f4f5] text-[13px]">
                    {data.products.map((p) => (
                        <li key={p.id} className="py-2">
                            <button type="button" className="flex w-full flex-wrap items-center justify-between gap-2 text-start" onClick={() => setOpen(open === p.id ? null : p.id)}>
                                <span className="font-medium text-[#111]">{p.name}</span>
                                <span className="flex items-center gap-3 text-[12px] text-[#6b7280]">
                                    <span>{t('المتوفر')}: <b className="tabular-nums text-[#111]">{number(p.available)}</b></span>
                                    <span>{t(':n مشاريع', { n: String(p.timeline.length) })}</span>
                                    <ForecastStatus shortage={p.shortage} />
                                    {open === p.id ? <ChevronUp className="size-3.5" /> : <ChevronDown className="size-3.5" />}
                                </span>
                            </button>
                            {open === p.id && <div className="mt-2 rounded-[10px] bg-[#fafafa] px-3 py-2"><ForecastTimeline rows={p.timeline} available={p.available} /></div>}
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}
