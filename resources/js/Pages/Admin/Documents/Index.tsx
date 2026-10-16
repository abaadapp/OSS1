import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { FolderOpen, Plus, Tags } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import Field, { Select } from '@/Components/Field';
import CatalogDialog from '@/Components/CatalogDialog';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { documentTone, type DocumentCatalog, type DocumentRow } from '@/lib/documents';
import DocumentDialog from './partials/DocumentDialog';

interface Props extends DocumentCatalog {
    documents: DocumentRow[];
    stats: { total: number; archived: number; needs_action: number; expiring: number; expired: number; nearest: { id: number; title: string; expires_at: string } | null };
    filters: { q: string | null; category: number | null; status: string | null; link_type: string | null; link_id: number | null };
    may: { manage: boolean };
}

/**
 * «المستندات» — سجلُّ مستندات الشركة الداخليّة: ما ينتهي أوّلًا أوّلُ ما يُرى.
 *
 * غيرُ أوراق العملاء: سجلٌّ تجاريّ وتأمينٌ وعقودٌ وتصاريحُ ومستنداتُ موظّفين وموردين
 * ومشاريع. والحالُ من تاريخ الانتهاء، والمؤرشفُ خارج القائمة ما لم يُطلب.
 */
export default function DocumentsIndex() {
    const props = usePage().props as unknown as Props & { errors?: Record<string, string> };
    const { documents, stats, filters, may, errors } = props;
    const t = useTranslate();
    const [creating, setCreating] = useState(false);
    const [managing, setManaging] = useState(false);

    const filter = (patch: Record<string, string>) =>
        router.get(route('admin.documents.index'), { ...filters, ...patch }, { preserveState: true, replace: true });

    return (
        <AdminLayout title="المستندات">
            <PageHeader
                title="المستندات"
                subtitle={t('مستندات الشركة الداخلية وتواريخ انتهائها — غير أوراق العملاء')}
                actions={
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" onClick={() => setManaging(true)}><Tags />{t('التصنيفات')}</Button>
                        {may.manage && <Button onClick={() => setCreating(true)}><Plus />{t('مستند جديد')}</Button>}
                    </div>
                }
            />

            {(errors?.document || errors?.category) && <div className="mb-4 rounded-lg bg-[#fef2f2] px-4 py-3 text-[13px] text-[#b91c1c]">{errors.document ?? errors.category}</div>}

            <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4">
                {[
                    { label: 'غير المؤرشفة', value: stats.total, patch: { status: '' } },
                    { label: 'قريبة الانتهاء', value: stats.expiring, patch: { status: 'expiring' }, tone: 'text-[#b45309]' },
                    { label: 'منتهية', value: stats.expired, patch: { status: 'expired' }, tone: 'text-[#b91c1c]' },
                    { label: 'مؤرشفة', value: stats.archived, patch: { status: 'archived' } },
                ].map((s) => (
                    <button key={s.label} type="button" onClick={() => filter(s.patch)}
                        className="rounded-[12px] border border-[var(--ui-border,#e8e8e8)] bg-white p-4 text-start hover:border-[#c4b5fd]">
                        <span className="block text-[12px] text-[#6b7280]">{t(s.label)}</span>
                        <span className={'mt-1 block text-[22px] font-bold tabular-nums ' + (s.tone ?? 'text-[#111]')}>{s.value}</span>
                    </button>
                ))}
            </div>

            <Card className="mb-4 p-4">
                <div className="flex flex-wrap items-end gap-3">
                    <Field label="بحث" className="w-full sm:min-w-[200px] sm:flex-1">
                        <Input defaultValue={filters.q ?? ''} placeholder="العنوان أو الرقم أو جهة الإصدار"
                            onKeyDown={(e) => e.key === 'Enter' && filter({ q: (e.target as HTMLInputElement).value })} />
                    </Field>
                    <Field label="التصنيف" className="w-full sm:w-auto sm:min-w-[150px]">
                        <Select value={filters.category ? String(filters.category) : ''} placeholder="الكلّ" onChange={(e) => filter({ category: e.target.value })}
                            options={props.categories.map((c) => ({ value: String(c.id), label: c.name }))} />
                    </Field>
                    <Field label="الحالة" className="w-full sm:w-auto sm:min-w-[140px]">
                        <Select value={filters.status ?? ''} placeholder="غير المؤرشفة" onChange={(e) => filter({ status: e.target.value })}
                            options={Object.entries(props.statuses).map(([value, label]) => ({ value, label }))} />
                    </Field>
                    <Field label="مرتبط بـ" className="w-full sm:w-auto sm:min-w-[140px]">
                        <Select value={filters.link_type ?? ''} placeholder="الكلّ" onChange={(e) => filter({ link_type: e.target.value, link_id: '' })}
                            options={Object.entries(props.link_types).map(([value, label]) => ({ value, label }))} />
                    </Field>
                </div>
            </Card>

            <Card className="overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[720px] text-[13px]">
                        <thead className="bg-[#fafafa] text-[12px] text-[#6b7280]">
                            <tr>
                                <th className="px-4 py-2 text-start">{t('المستند')}</th>
                                <th className="px-4 py-2 text-start">{t('التصنيف')}</th>
                                <th className="px-4 py-2 text-start">{t('مرتبط بـ')}</th>
                                <th className="px-4 py-2 text-start">{t('المسؤول')}</th>
                                <th className="px-4 py-2 text-start">{t('ينتهي')}</th>
                                <th className="px-4 py-2 text-center">{t('الحالة')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {documents.length === 0 && (
                                <tr><td colSpan={6} className="px-4 py-10 text-center text-[#9ca3af]"><FolderOpen className="mx-auto mb-2 size-6" />{t('لا مستندات تطابق — أضف أول مستند')}</td></tr>
                            )}
                            {documents.map((d) => (
                                <tr key={d.id} className="border-t border-[#f4f4f5]">
                                    <td className="px-4 py-2">
                                        <SmartLink routeName="admin.documents.show" href={route('admin.documents.show', d.id)} className="font-medium text-[#1d4ed8]">{d.title}</SmartLink>
                                        {d.document_number && <span className="block font-mono text-[11.5px] text-[#9ca3af]" dir="ltr">{d.document_number}</span>}
                                    </td>
                                    <td className="px-4 py-2 text-[12.5px]">{d.category ?? '—'}</td>
                                    <td className="px-4 py-2 text-[12.5px]">{d.link_label ? <>{props.link_types[d.link_type ?? ''] ?? ''}: {d.link_label}</> : t('الشركة')}</td>
                                    <td className="px-4 py-2 text-[12.5px]">{d.responsible ?? '—'}</td>
                                    <td className="px-4 py-2 text-[12.5px] tabular-nums">
                                        <span dir="ltr">{d.expires_at ?? '—'}</span>
                                        {d.days_left !== null && !d.archived && <span className="ms-1 text-[11px] text-[#9ca3af]">({d.days_left < 0 ? t('منذ :n يومًا', { n: String(-d.days_left) }) : t('بعد :n يومًا', { n: String(d.days_left) })})</span>}
                                    </td>
                                    <td className="px-4 py-2 text-center"><span className={'rounded-full px-2 py-1 text-[11px] ' + documentTone(d.status)}>{props.statuses[d.status] ?? d.status}</span></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </Card>

            <DocumentDialog open={creating} onClose={() => setCreating(false)} document={null} catalog={props} />
            <CatalogDialog open={managing} onClose={() => setManaging(false)} title={t('تصنيفات المستندات')} canEdit={may.manage}
                items={props.categories} routes={{ store: 'admin.documents.categories.store', update: 'admin.documents.categories.update', destroy: 'admin.documents.categories.destroy' }} />
        </AdminLayout>
    );
}
