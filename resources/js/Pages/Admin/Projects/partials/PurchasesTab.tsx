import { useState } from 'react';
import { ClipboardList, Plus, Receipt, Truck } from 'lucide-react';
import Field, { Select } from '@/Components/Field';
import SmartLink from '@/Components/SmartLink';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { receiptMode } from '@/lib/receipts';

export interface ProjectPurchases {
    summary: {
        orders: number; drafts: number; total: number; received: number; pending: number;
        invoices: number; invoiced: number | null; paid: number | null; outstanding: number | null;
    };
    orders: {
        id: number; number: string; supplier_id: number | null; supplier: string | null; status: string;
        receipt: 'draft' | 'pending' | 'partial' | 'received'; closed: boolean;
        ordered_at: string | null; expected_delivery_at: string | null; total: number; kinds: string[]; items: number;
    }[];
    invoices: {
        id: number; ref: string; supplier: string | null; purchase_order_id: number | null; approval_status: string; status: string;
        issued_at: string | null; total: number | null; paid: number | null; outstanding: number | null;
    }[];
    suppliers: { id: number | null; name: string | null; service: string | null; orders: number; total: number }[];
    kinds: string[];
    statuses: string[];
    money: boolean;
    may: { create: boolean };
}

export const LINE_KIND_LABEL: Record<string, string> = { product: 'منتج', service: 'خدمة', custom: 'بند مخصص' };

const RECEIPT_LABEL: Record<string, string> = { draft: 'مسودة', pending: 'بانتظار الاستلام', partial: 'مستلم جزئيًا', received: 'مستلم' };
/* وأمرُ الخدمات وحدها يُنفَّذ ولا يُستلم — المستندُ نفسُه (`lib/receipts`) */
const SERVICE_RECEIPT_LABEL: Record<string, string> = { draft: 'مسودة', pending: 'بانتظار تأكيد التنفيذ', partial: 'منفَّذ جزئيًا', received: 'منفَّذ' };
const RECEIPT_TONE: Record<string, 'neutral' | 'warning' | 'info' | 'success'> = { draft: 'neutral', pending: 'warning', partial: 'info', received: 'success' };

/**
 * المشترياتُ على المشروع — أوامرُ الشراء الحقيقيّة نفسُها التي في قسم المشتريات.
 *
 * تبويبٌ مساند لا مرحلةٌ من مراحل المسار. و«+ إضافة شراء» يفتح شاشةَ أمر الشراء
 * الحاليّة والمشروعُ مختارٌ فيها، ويعود إلى هنا بعد الحفظ — مستندٌ واحد لا نسختان.
 */
export default function PurchasesTab({ projectId, data, m }: { projectId: number; data: ProjectPurchases; m: (v: number | null) => string }) {
    const t = useTranslate();
    const [filters, setFilters] = useState({ supplier: '', status: '', kind: '', from: '', until: '' });
    const s = data.summary;

    const orders = data.orders.filter((o) =>
        (filters.supplier === '' || String(o.supplier_id ?? '') === filters.supplier)
        && (filters.status === '' || o.status === filters.status)
        && (filters.kind === '' || o.kinds.includes(filters.kind))
        && (filters.from === '' || (o.ordered_at ?? '') >= filters.from)
        && (filters.until === '' || (o.ordered_at ?? '') <= filters.until));

    const cards = [
        { label: 'أوامر الشراء', value: String(s.orders) + (s.drafts ? ` (${t(':n مسودة', { n: String(s.drafts) })})` : '') },
        { label: 'إجمالي القيمة', value: m(s.total) },
        { label: 'مستلم', value: String(s.received) },
        { label: 'بانتظار الاستلام', value: String(s.pending) },
        { label: 'فواتير الموردين', value: String(s.invoices) },
        ...(data.money ? [{ label: 'المدفوع', value: m(s.paid) }, { label: 'المتبقي', value: m(s.outstanding) }] : []),
    ];

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-[12.5px] text-[#6b7280]">{t('أوامر الشراء المرتبطة بالمشروع — هي نفسها في قسم المشتريات.')}</p>
                {data.may.create && (
                    <Button asChild>
                        <SmartLink routeName="admin.purchases.create" href={route('admin.purchases.create', { project_id: projectId })}>
                            <Plus />{t('إضافة شراء')}
                        </SmartLink>
                    </Button>
                )}
            </div>

            <div className="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-7">
                {cards.map((c) => (
                    <div key={c.label} className="rounded-[12px] border border-[var(--ui-border,#e8e8e8)] bg-white p-3">
                        <span className="block text-[11.5px] text-[#6b7280]">{t(c.label)}</span>
                        <span className="mt-1 block text-[15px] font-bold tabular-nums text-[#111]">{c.value}</span>
                    </div>
                ))}
            </div>

            <Card className="p-4">
                <div className="flex flex-wrap items-end gap-3">
                    <Field label="المورد" className="min-w-[160px]">
                        <Select value={filters.supplier} placeholder="الكلّ" onChange={(e) => setFilters({ ...filters, supplier: e.target.value })}
                            options={data.suppliers.filter((x) => x.id !== null).map((x) => ({ value: String(x.id), label: x.name ?? '—' }))} />
                    </Field>
                    <Field label="الحالة" className="min-w-[140px]">
                        <Select value={filters.status} placeholder="الكلّ" onChange={(e) => setFilters({ ...filters, status: e.target.value })}
                            options={data.statuses.map((x) => ({ value: x, label: x }))} />
                    </Field>
                    <Field label="نوع البند" className="min-w-[130px]">
                        <Select value={filters.kind} placeholder="الكلّ" onChange={(e) => setFilters({ ...filters, kind: e.target.value })}
                            options={data.kinds.map((x) => ({ value: x, label: LINE_KIND_LABEL[x] ?? x }))} />
                    </Field>
                    <Field label="من" className="min-w-[140px]">
                        <Input type="date" value={filters.from} onChange={(e) => setFilters({ ...filters, from: e.target.value })} />
                    </Field>
                    <Field label="إلى" className="min-w-[140px]">
                        <Input type="date" value={filters.until} onChange={(e) => setFilters({ ...filters, until: e.target.value })} />
                    </Field>
                </div>
            </Card>

            <Card className="overflow-hidden">
                <h3 className="flex items-center gap-2 border-b border-[#f4f4f5] px-5 py-3 text-sm font-bold"><ClipboardList className="size-4 text-[#6d28d9]" />{t('أوامر الشراء')}</h3>
                <div className="overflow-x-auto">
                    <table className="w-full text-[13px]">
                        <thead className="bg-[#fafafa] text-[12px] text-[#6b7280]">
                            <tr>
                                <th className="px-4 py-2 text-start">{t('الرقم')}</th>
                                <th className="px-4 py-2 text-start">{t('المورد')}</th>
                                <th className="px-4 py-2 text-start">{t('نوع البند')}</th>
                                <th className="px-4 py-2 text-start">{t('التاريخ')}</th>
                                <th className="px-4 py-2 text-center">{t('الاستلام')}</th>
                                <th className="px-4 py-2 text-end">{t('الإجمالي')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {orders.length === 0 && <tr><td colSpan={6} className="px-4 py-8 text-center text-[#9ca3af]">{t('لا أوامر شراء على هذا المشروع بعد')}</td></tr>}
                            {orders.map((o) => (
                                <tr key={o.id} className="border-t border-[#f4f4f5]">
                                    <td className="px-4 py-2 font-mono text-[12px]">
                                        <SmartLink routeName="admin.purchases.show" href={route('admin.purchases.show', o.id)} className="text-[#1d4ed8]">{o.number}</SmartLink>
                                    </td>
                                    <td className="px-4 py-2">{o.supplier_id
                                        ? <SmartLink routeName="admin.suppliers.show" href={route('admin.suppliers.show', o.supplier_id)} className="hover:underline">{o.supplier}</SmartLink>
                                        : (o.supplier ?? '—')}</td>
                                    <td className="px-4 py-2 text-[12px]">{o.kinds.map((k) => t(LINE_KIND_LABEL[k] ?? k)).join('، ') || '—'}</td>
                                    <td className="px-4 py-2 text-[#6b7280]">{o.ordered_at ?? '—'}{o.expected_delivery_at ? <span className="block text-[11px]">{t('التسليم المتوقع')}: {o.expected_delivery_at}</span> : null}</td>
                                    <td className="px-4 py-2 text-center"><Badge variant={RECEIPT_TONE[o.receipt]}>{t((receiptMode(o.kinds) === 'services' ? SERVICE_RECEIPT_LABEL : RECEIPT_LABEL)[o.receipt])}</Badge>{o.closed && <span className="ms-1 text-[11px] text-[#9ca3af]">{t('مقفل')}</span>}</td>
                                    <td className="px-4 py-2 text-end tabular-nums">{m(o.total)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </Card>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <Card className="overflow-hidden lg:col-span-2">
                    <h3 className="flex items-center gap-2 border-b border-[#f4f4f5] px-5 py-3 text-sm font-bold"><Receipt className="size-4 text-[#6d28d9]" />{t('فواتير الموردين المرتبطة')}</h3>
                    <table className="w-full text-[13px]">
                        <tbody>
                            {data.invoices.length === 0 && <tr><td className="px-4 py-6 text-center text-[#9ca3af]">{t('لا سندات موردين على المشروع')}</td></tr>}
                            {data.invoices.map((i) => (
                                <tr key={i.id} className="border-t border-[#f4f4f5] first:border-t-0">
                                    <td className="px-4 py-2 font-mono text-[12px]">
                                        <SmartLink routeName="admin.purchases.invoices.show" href={route('admin.purchases.invoices.show', i.id)} className="text-[#1d4ed8]">{i.ref}</SmartLink>
                                    </td>
                                    <td className="px-4 py-2">{i.supplier ?? '—'}</td>
                                    <td className="px-4 py-2 text-center"><Badge status={i.approval_status}>{t(i.approval_status)}</Badge></td>
                                    {data.money && <td className="px-4 py-2 text-end tabular-nums">{m(i.total)}</td>}
                                    {data.money && <td className="px-4 py-2 text-end tabular-nums text-[#6b7280]">{t('المتبقي')}: {m(i.outstanding)}</td>}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Card>
                <Card className="overflow-hidden">
                    <h3 className="flex items-center gap-2 border-b border-[#f4f4f5] px-5 py-3 text-sm font-bold"><Truck className="size-4 text-[#6d28d9]" />{t('الموردون في المشروع')}</h3>
                    <ul className="divide-y divide-[#f4f4f5]">
                        {data.suppliers.length === 0 && <li className="px-5 py-6 text-center text-[13px] text-[#9ca3af]">—</li>}
                        {data.suppliers.map((x, i) => (
                            <li key={x.id ?? `x${i}`} className="flex items-center justify-between gap-2 px-5 py-2 text-[13px]">
                                <span className="min-w-0 truncate">
                                    {x.name}
                                    {x.service && <span className="ms-1 text-[12px] text-[#9ca3af]">· {x.service}</span>}
                                </span>
                                <span className="shrink-0 tabular-nums text-[12px] text-[#6b7280]">{x.orders} · {m(x.total)}</span>
                            </li>
                        ))}
                    </ul>
                </Card>
            </div>
        </div>
    );
}
