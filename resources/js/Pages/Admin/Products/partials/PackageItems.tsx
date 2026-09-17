import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Boxes, Package, Plus, Trash2, Wrench } from 'lucide-react';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { Currency } from '@/types';

export interface PackageMember {
    id: number;
    item_id: number;
    name: string;
    kind: 'item' | 'service' | 'package';
    quantity: number;
    unit: string | null;
    /** السعرُ المعتمد للعضو — وهو ما يقرؤه عرضُ السعر عند الفكّ */
    unit_price: number;
    line_total: number;
}

export interface PackageOption {
    value: number;
    label: string;
    kind: 'item' | 'service' | 'package';
    price: number;
}

interface Props {
    /** معرّفُ الباقة — و`null` تعني أنّها لم تُحفظ بعد */
    packageId: number | null;
    items: PackageMember[];
    options: PackageOption[];
    currency: Currency;
}

const KIND_ICON = { item: Boxes, service: Wrench, package: Package } as const;

const KIND_LABEL = { item: 'صنف', service: 'خدمة', package: 'باقة' } as const;

/**
 * بنودُ الباقة — ما تشمله، لا ما يُخصم من الرفّ.
 *
 * ═══ ولمَ ليست «الوصفة» فوقها ═══
 *
 * الوصفةُ قائمةُ موادّ تُخصم من المخزون عند البيع: كميّاتُها تُرفع إلى
 * أعدادٍ صحيحة، ولها نسبةُ فاقد، ولا يُقبل فيها مكوّنٌ له وصفته. والباقةُ
 * حزمةٌ تجاريّةٌ تُسعَّر وتُعرَض: تحوي خدماتٍ وباقاتٍ أصغر، وكميّاتُها
 * كسريّة («يومان ونصف من فريق»).
 *
 * ═══ وما تفعله في عرض السعر ═══
 *
 * تُضاف بندًا واحدًا بسعر الباقة، أو تُفَكّ إلى بنودها فيُسعَّر كلٌّ منها
 * **بسعره المعتمد** — لا بسعر الباقة موزَّعًا عليها. ولذلك يُعرض المجموعُ
 * هنا: من يبني الباقة يرى كم تساوي بنودُها مفرّقةً، فيقرّر سعرَها على بيّنة.
 */
export default function PackageItems({ packageId, items, options, currency }: Props) {
    const t = useTranslate();
    const m = (v: number) => money(v, currency);

    const [adding, setAdding] = useState(false);
    const [draft, setDraft] = useState({ item_id: '', quantity: '1', unit: '' });

    const sum = items.reduce((total, row) => total + row.line_total, 0);

    if (packageId === null) {
        return (
            <Card className="p-6">
                <h3 className="mb-2 font-bold text-[#111]">{t('بنود الباقة')}</h3>
                <p className="text-[13px] leading-relaxed text-[#6b7280]">
                    {t('احفظ الباقة أوّلًا، ثم أضف ما تشمله.')}
                </p>
            </Card>
        );
    }

    const add = () => {
        router.post(
            route('admin.products.package.store', packageId),
            { item_id: draft.item_id, quantity: draft.quantity, unit: draft.unit || null },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setDraft({ item_id: '', quantity: '1', unit: '' });
                    setAdding(false);
                },
            },
        );
    };

    return (
        <Card className="p-6">
            <div className="mb-1 flex items-center justify-between gap-3">
                <h3 className="font-bold text-[#111]">{t('بنود الباقة')}</h3>
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    aria-label={t('إضافة بند')}
                    title={t('إضافة بند')}
                    onClick={() => setAdding((v) => !v)}
                >
                    <Plus />
                </Button>
            </div>
            <p className="mb-4 text-[13px] leading-relaxed text-[#6b7280]">
                {t('ما تشمله الباقة. وفي عرض السعر تُضاف بندًا واحدًا بسعرها، أو تُفَكّ إلى بنودها بأسعارها المعتمدة.')}
            </p>

            {adding && (
                <div className="mb-4 grid grid-cols-1 gap-3 rounded-[12px] bg-[#fafafa] p-4 sm:grid-cols-4">
                    <div className="sm:col-span-2">
                        <Field label="البند" required>
                            <Select
                                value={draft.item_id}
                                onChange={(e) => setDraft({ ...draft, item_id: e.target.value })}
                                placeholder="اختر البند"
                                options={options.map((o) => ({
                                    value: String(o.value),
                                    label: `${t(KIND_LABEL[o.kind])} — ${o.label}`,
                                }))}
                            />
                        </Field>
                    </div>
                    <Field label="الكمية" required>
                        {/* والكميّةُ كسريّة: «يومان ونصف من فريق» — لا رفَّ يُقرّب إليه */}
                        <Input
                            type="number"
                            step="0.001"
                            min="0"
                            dir="ltr"
                            value={draft.quantity}
                            onChange={(e) => setDraft({ ...draft, quantity: e.target.value })}
                        />
                    </Field>
                    <Field label="الوحدة" hint="يوم · شخص · جهاز">
                        <Input
                            value={draft.unit}
                            onChange={(e) => setDraft({ ...draft, unit: e.target.value })}
                        />
                    </Field>
                    <div className="sm:col-span-4">
                        <Button type="button" onClick={add} disabled={!draft.item_id}>
                            {t('إضافة')}
                        </Button>
                    </div>
                </div>
            )}

            {items.length === 0 ? (
                <p className="rounded-[12px] bg-[#fafafa] px-4 py-6 text-center text-[13px] text-[#9ca3af]">
                    {t('لا بنود في هذه الباقة بعد.')}
                </p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full text-[13px]">
                        <thead className="text-[12px] text-[#6b7280]">
                            <tr className="border-b border-[#e8e8e8]">
                                <th className="py-2 text-start">{t('البند')}</th>
                                <th className="py-2 text-center">{t('الكمية')}</th>
                                <th className="py-2 text-end">{t('السعر المعتمد')}</th>
                                <th className="py-2 text-end">{t('المجموع')}</th>
                                <th className="py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            {items.map((row) => {
                                const Icon = KIND_ICON[row.kind];

                                return (
                                    <tr key={row.id} className="border-b border-[#f4f4f5]">
                                        <td className="py-2">
                                            <span className="inline-flex items-center gap-2">
                                                <Icon className="size-3.5 text-[#9ca3af]" />
                                                {row.name}
                                            </span>
                                        </td>
                                        <td className="py-2 text-center tabular-nums">
                                            {row.quantity}
                                            {row.unit ? ` ${row.unit}` : ''}
                                        </td>
                                        <td className="py-2 text-end tabular-nums text-[#6b7280]">
                                            {m(row.unit_price)}
                                        </td>
                                        <td className="py-2 text-end font-medium tabular-nums">
                                            {m(row.line_total)}
                                        </td>
                                        <td className="py-2 text-end">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                aria-label={t('حذف البند')}
                                                onClick={() =>
                                                    router.delete(
                                                        route('admin.products.package.destroy', [packageId, row.id]),
                                                        { preserveScroll: true },
                                                    )
                                                }
                                            >
                                                <Trash2 />
                                            </Button>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>

                    {/*
                        ومجموعُ البنود مفرّقةً يُعرض إلى جانب سعر الباقة: من
                        يبني الباقة يرى كم يعطي من خصمٍ ضمنيّ قبل أن يثبّت
                        سعرَها — وهو رقمٌ لا يقوله شيءٌ آخر في الشاشة.
                    */}
                    <div className="mt-3 flex justify-between border-t border-[#e8e8e8] pt-3 text-[13px] font-semibold">
                        <span>{t('مجموع البنود مفرّقة')}</span>
                        <span className="tabular-nums">{m(sum)}</span>
                    </div>
                </div>
            )}
        </Card>
    );
}
