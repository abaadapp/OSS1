import { usePage } from '@inertiajs/react';
import { Briefcase, ClipboardList, Mail, MessageCircle, Phone, Receipt, User } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import BackLink from '@/Components/BackLink';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { PROJECT_STATUS_LABEL, projectTone } from '@/lib/projects';
import type { PageProps } from '@/types';

interface Props {
    supplier: {
        id: number; name: string; name_en: string | null; phone: string | null; whatsapp: string | null; email: string | null;
        contact_person: string | null; notes: string | null; supplier_type: string; status: string;
        services: { id: number; name: string }[]; outstanding: number | null;
    };
    orders: { id: number; number: string; status: string; ordered_at: string | null; total: number; project: { id: number; code: string } | null }[];
    invoices: { id: number; ref: string; approval_status: string; status: string; issued_at: string | null; total: number | null; outstanding: number | null; project: { id: number; code: string } | null }[];
    projects: { id: number; code: string; name: string; status: string; orders: number }[];
    types: Record<string, string>;
    statuses: Record<string, string>;
    may: { purchases: boolean; money: boolean; projects: boolean };
}

/**
 * صفحةُ المورّد — مورّدَ منتجاتٍ كان أو مقدّمَ خدمة: بياناتُه وخدماتُه وأوامرُ
 * شرائه وسنداتُه والمشاريعُ التي عمل فيها. والمالُ لمن يفتح السندات.
 */
export default function SupplierShow() {
    const { supplier, orders, invoices, projects, types, statuses, may, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number | null) => (v === null ? '—' : money(v, context!.currency));
    const projectLink = (p: { id: number; code: string } | null) => !p ? '—' : may.projects
        ? <SmartLink routeName="admin.projects.show" href={route('admin.projects.show', p.id)} className="font-mono text-[12px] text-[#1d4ed8]">{p.code}</SmartLink>
        : <span className="font-mono text-[12px]">{p.code}</span>;

    return (
        <AdminLayout title={supplier.name}>
            <BackLink routeName="admin.suppliers.index" href={route('admin.suppliers.index')} label="المورّدون" />
            <PageHeader
                title={supplier.name}
                subtitle={types[supplier.supplier_type] ?? supplier.supplier_type}
                actions={may.purchases && (
                    <Button variant="outline" asChild>
                        <SmartLink routeName="admin.purchases.index" href={route('admin.purchases.index', { supplier: supplier.id, month: 'all' })}>
                            <ClipboardList />{t('سجل المشتريات')}
                        </SmartLink>
                    </Button>
                )}
            />

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="space-y-6">
                    <Card className="space-y-3 p-6 text-[13px]">
                        <div className="flex flex-wrap gap-2">
                            <Badge variant={supplier.supplier_type === 'products' ? 'neutral' : 'info'}>{types[supplier.supplier_type] ?? supplier.supplier_type}</Badge>
                            <Badge variant={supplier.status === 'active' ? 'success' : 'neutral'}>{statuses[supplier.status] ?? supplier.status}</Badge>
                        </div>
                        <ul className="space-y-2 text-[#4b4b4b]">
                            <li className="flex items-center gap-2"><Phone className="size-4 text-[#9ca3af]" /><span dir="ltr">{supplier.phone || '—'}</span></li>
                            {supplier.whatsapp && <li className="flex items-center gap-2"><MessageCircle className="size-4 text-[#9ca3af]" /><span dir="ltr">{supplier.whatsapp}</span></li>}
                            {supplier.email && <li className="flex items-center gap-2"><Mail className="size-4 text-[#9ca3af]" /><span dir="ltr">{supplier.email}</span></li>}
                            {supplier.contact_person && <li className="flex items-center gap-2"><User className="size-4 text-[#9ca3af]" />{supplier.contact_person}</li>}
                        </ul>
                        {supplier.notes && <p className="whitespace-pre-line text-[12.5px] text-[#6b7280]">{supplier.notes}</p>}
                        {may.money && supplier.outstanding !== null && (
                            <p className="rounded-lg bg-[#fafafa] px-3 py-2">{t('المستحقّ له الآن')}: <strong className="tabular-nums">{m(supplier.outstanding)}</strong></p>
                        )}
                    </Card>

                    <Card className="p-6">
                        <h3 className="mb-3 text-sm font-bold text-[#111]">{t('الخدمات التي يقدّمها')}</h3>
                        <div className="flex flex-wrap gap-1">
                            {supplier.services.length === 0 && <span className="text-[12px] text-[#9ca3af]">{t('لا خدمات مسجّلة')}</span>}
                            {supplier.services.map((x) => <span key={x.id} className="rounded-full bg-[#f5f3ff] px-2.5 py-1 text-[12px] text-[#6d28d9]">{x.name}</span>)}
                        </div>
                    </Card>

                    <Card className="overflow-hidden">
                        <h3 className="flex items-center gap-2 border-b border-[#f4f4f5] px-5 py-3 text-sm font-bold"><Briefcase className="size-4 text-[#6d28d9]" />{t('المشاريع التي عمل فيها')}</h3>
                        {projects.length === 0 ? <p className="px-5 py-5 text-center text-[13px] text-[#9ca3af]">{t('لا مشاريع بعد')}</p> : (
                            <ul className="divide-y divide-[#f4f4f5]">
                                {projects.map((p) => (
                                    <li key={p.id} className="flex items-center justify-between gap-2 px-5 py-2 text-[13px]">
                                        <span className="min-w-0 truncate">{projectLink(p)} {p.name}</span>
                                        <span className={`shrink-0 rounded-full px-2 py-0.5 text-[11px] ${projectTone(p.status)}`}>{t(PROJECT_STATUS_LABEL[p.status] ?? p.status)}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                </div>

                <div className="space-y-6 lg:col-span-2">
                    <Card className="overflow-hidden">
                        <h3 className="flex items-center gap-2 border-b border-[#f4f4f5] px-5 py-3 text-sm font-bold"><ClipboardList className="size-4 text-[#6d28d9]" />{t('أوامر الشراء')}</h3>
                        <table className="w-full text-[13px]">
                            <thead className="bg-[#fafafa] text-[12px] text-[#6b7280]">
                                <tr>
                                    <th className="px-4 py-2 text-start">{t('الرقم')}</th><th className="px-4 py-2 text-start">{t('التاريخ')}</th>
                                    <th className="px-4 py-2 text-start">{t('المشروع')}</th><th className="px-4 py-2 text-center">{t('الحالة')}</th>
                                    <th className="px-4 py-2 text-end">{t('الإجمالي')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {orders.length === 0 && <tr><td colSpan={5} className="px-4 py-6 text-center text-[#9ca3af]">{t('لا أوامر شراء بعد')}</td></tr>}
                                {orders.map((o) => (
                                    <tr key={o.id} className="border-t border-[#f4f4f5]">
                                        <td className="px-4 py-2 font-mono text-[12px]">{may.purchases
                                            ? <SmartLink routeName="admin.purchases.show" href={route('admin.purchases.show', o.id)} className="text-[#1d4ed8]">{o.number}</SmartLink> : o.number}</td>
                                        <td className="px-4 py-2 text-[#6b7280]">{o.ordered_at ?? '—'}</td>
                                        <td className="px-4 py-2">{projectLink(o.project)}</td>
                                        <td className="px-4 py-2 text-center"><Badge status={o.status}>{t(o.status)}</Badge></td>
                                        <td className="px-4 py-2 text-end tabular-nums">{m(o.total)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </Card>

                    <Card className="overflow-hidden">
                        <h3 className="flex items-center gap-2 border-b border-[#f4f4f5] px-5 py-3 text-sm font-bold"><Receipt className="size-4 text-[#6d28d9]" />{t('فواتير المورّد')}</h3>
                        <table className="w-full text-[13px]">
                            <thead className="bg-[#fafafa] text-[12px] text-[#6b7280]">
                                <tr>
                                    <th className="px-4 py-2 text-start">{t('رقم السند')}</th><th className="px-4 py-2 text-start">{t('التاريخ')}</th>
                                    <th className="px-4 py-2 text-start">{t('المشروع')}</th><th className="px-4 py-2 text-center">{t('الاعتماد')}</th>
                                    <th className="px-4 py-2 text-end">{t('الإجمالي')}</th><th className="px-4 py-2 text-end">{t('المتبقّي')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {invoices.length === 0 && <tr><td colSpan={6} className="px-4 py-6 text-center text-[#9ca3af]">{t('لا سندات بعد')}</td></tr>}
                                {invoices.map((i) => (
                                    <tr key={i.id} className="border-t border-[#f4f4f5]">
                                        <td className="px-4 py-2 font-mono text-[12px]">{may.purchases
                                            ? <SmartLink routeName="admin.purchases.invoices.show" href={route('admin.purchases.invoices.show', i.id)} className="text-[#1d4ed8]">{i.ref}</SmartLink> : i.ref}</td>
                                        <td className="px-4 py-2 text-[#6b7280]">{i.issued_at ?? '—'}</td>
                                        <td className="px-4 py-2">{projectLink(i.project)}</td>
                                        <td className="px-4 py-2 text-center"><Badge status={i.approval_status}>{t(i.approval_status)}</Badge></td>
                                        <td className="px-4 py-2 text-end tabular-nums">{m(i.total)}</td>
                                        <td className="px-4 py-2 text-end tabular-nums">{m(i.outstanding)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </Card>
                </div>
            </div>
        </AdminLayout>
    );
}
