import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Archive, ArchiveRestore, Download, Pencil } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import BackLink from '@/Components/BackLink';
import PageHeader from '@/Components/PageHeader';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { useTranslate } from '@/lib/i18n';
import { documentTone, type DocumentCatalog, type DocumentRow } from '@/lib/documents';
import DocumentDialog from './partials/DocumentDialog';

interface Props extends DocumentCatalog {
    document: DocumentRow;
    files: { id: number; name: string; size: number | null; uploaded_by: string | null; at: string | null; current: boolean }[];
    may: { manage: boolean };
}

const size = (bytes: number | null) => bytes === null ? '' : bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;

/** مستندٌ واحد — بياناتُه وحالُه ونسخُ ملفّه، والتعديلُ والأرشفةُ لمن يملكهما */
export default function DocumentShow() {
    const props = usePage().props as unknown as Props & { errors?: Record<string, string> };
    const { document: d, files, may, errors } = props;
    const t = useTranslate();
    const [editing, setEditing] = useState(false);

    const rows: [string, string | null][] = [
        ['التصنيف', d.category], ['رقم المستند', d.document_number], ['جهة الإصدار', d.issuer],
        ['تاريخ الإصدار', d.issued_at], ['تاريخ الانتهاء', d.expires_at],
        ['مرتبط بـ', d.link_label ? `${props.link_types[d.link_type ?? ''] ?? ''}: ${d.link_label}` : t('الشركة نفسها')],
        ['المسؤول', d.responsible],
    ];

    return (
        <AdminLayout title={d.title}>
            <BackLink routeName="admin.documents.index" href={route('admin.documents.index')} label="المستندات" />
            <PageHeader
                title={d.title}
                subtitle={t('مستند داخلي')}
                actions={
                    <div className="flex flex-wrap gap-2">
                        {d.file_url && <Button variant="outline" asChild><a href={d.file_url} target="_blank" rel="noreferrer"><Download />{t('فتح الملف')}</a></Button>}
                        {may.manage && <Button variant="outline" onClick={() => setEditing(true)}><Pencil />{t('تعديل أو نسخة جديدة')}</Button>}
                        {may.manage && (d.archived
                            ? <Button variant="outline" onClick={() => router.post(route('admin.documents.restore', d.id), {}, { preserveScroll: true })}><ArchiveRestore />{t('إعادة من الأرشيف')}</Button>
                            : <Button variant="outline" onClick={() => router.post(route('admin.documents.archive', d.id), {}, { preserveScroll: true })}><Archive />{t('أرشفة')}</Button>)}
                    </div>
                }
            />

            {errors?.document && <div className="mb-4 rounded-lg bg-[#fef2f2] px-4 py-3 text-[13px] text-[#b91c1c]">{errors.document}</div>}

            <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_360px]">
                <Card className="p-4">
                    <div className="mb-3 flex items-center gap-2">
                        <span className={'rounded-full px-2 py-1 text-[11.5px] ' + documentTone(d.status)}>{props.statuses[d.status] ?? d.status}</span>
                        {d.days_left !== null && !d.archived && (
                            <span className="text-[12.5px] text-[#6b7280]">{d.days_left < 0 ? t('انتهى منذ :n يومًا', { n: String(-d.days_left) }) : d.days_left === 0 ? t('ينتهي اليوم') : t('ينتهي بعد :n يومًا', { n: String(d.days_left) })}</span>
                        )}
                    </div>
                    <dl className="grid grid-cols-1 gap-x-6 gap-y-2 text-[13px] sm:grid-cols-2">
                        {rows.map(([label, value]) => (
                            <div key={label} className="flex justify-between gap-3 border-b border-[#f4f4f5] py-1.5">
                                <dt className="shrink-0 text-[#6b7280]">{t(label)}</dt>
                                <dd className="text-end text-[#111]">{value || '—'}</dd>
                            </div>
                        ))}
                    </dl>
                    {d.notes && <p className="mt-3 whitespace-pre-wrap rounded-[10px] bg-[#fafafa] p-3 text-[13px] text-[#374151]">{d.notes}</p>}
                </Card>

                <Card className="p-4">
                    <h2 className="mb-2 text-[14px] font-semibold">{t('نسخ الملف')}</h2>
                    <ul className="divide-y divide-[#f4f4f5] text-[13px]">
                        {files.map((f) => (
                            <li key={f.id} className="flex items-center justify-between gap-2 py-2">
                                <span className="min-w-0">
                                    <a href={route('admin.documents.version', [d.id, f.id])} target="_blank" rel="noreferrer" className="block truncate text-[#1d4ed8]">{f.name}</a>
                                    <span className="text-[11.5px] text-[#9ca3af]"><span dir="ltr">{f.at}</span> · {f.uploaded_by ?? '—'} · {size(f.size)}</span>
                                </span>
                                {f.current && <span className="shrink-0 rounded-full bg-[#ecfdf5] px-2 py-0.5 text-[11px] text-[#047857]">{t('الحالية')}</span>}
                            </li>
                        ))}
                    </ul>
                </Card>
            </div>

            <DocumentDialog open={editing} onClose={() => setEditing(false)} document={d} catalog={props} />
        </AdminLayout>
    );
}
