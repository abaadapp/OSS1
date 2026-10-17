import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { CalendarCheck, History, Mail, MessageCircle, Pencil, Phone, Trash2 } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import BackLink from '@/Components/BackLink';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { CUSTOMER_TABS } from '@/Components/SectionTabs';
import SmartLink from '@/Components/SmartLink';
import { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { useConfirm } from '@/Components/ConfirmDialog';
import { useTranslate } from '@/lib/i18n';
import { assignmentTone, organizerTone, type OrganizerCategory, type OrganizerLabels, type OrganizerRow } from '@/lib/organizers';
import OrganizerDialog from './partials/OrganizerDialog';

interface Participation {
    id: number;
    operation: { id: number; code: string; name: string; status: string; venue: string | null; project: string | null } | null;
    category: string | null;
    starts_at: string | null;
    ends_at: string | null;
    status: string;
    notes: string | null;
}

interface Props {
    organizer: OrganizerRow;
    upcoming: Participation[];
    history: Participation[];
    categories: OrganizerCategory[];
    labels: OrganizerLabels;
    may: { manage: boolean; operations: boolean };
}

/** صفحةُ المنظّم — بياناتُه وتصنيفاتُه وحالُه، وفعاليّاتُه القادمة، وتاريخُ مشاركاته */
export default function OrganizerShow() {
    const { organizer, upcoming, history, categories, labels, may, errors } = usePage().props as unknown as Props & { errors?: Record<string, string> };
    const t = useTranslate();
    const [ask, confirmDialog] = useConfirm();
    const [editing, setEditing] = useState(false);

    const list = (rows: Participation[], empty: string) => rows.length === 0 ? (
        <p className="px-5 py-6 text-center text-[13px] text-[#9ca3af]">{t(empty)}</p>
    ) : (
        <ul className="divide-y divide-[#f4f4f5]">
            {rows.map((r) => (
                <li key={r.id} className="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-[13px]">
                    <div className="min-w-0">
                        {r.operation && (may.operations ? (
                            <SmartLink routeName="admin.operations.show" href={route('admin.operations.show', r.operation.id)} className="font-medium text-[#1d4ed8]">
                                {r.operation.code} — {r.operation.name}
                            </SmartLink>
                        ) : <span className="font-medium">{r.operation.code} — {r.operation.name}</span>)}
                        <p className="text-[12px] text-[#6b7280]">
                            {[r.category, r.operation?.venue, r.starts_at && `${r.starts_at} → ${r.ends_at ?? ''}`].filter(Boolean).join(' · ')}
                        </p>
                    </div>
                    <span className={'rounded-full px-2 py-0.5 text-[11.5px] ' + assignmentTone(r.status)}>{labels.assignment[r.status] ?? r.status}</span>
                </li>
            ))}
        </ul>
    );

    return (
        <AdminLayout title={organizer.name}>
            <BackLink routeName="admin.organizers.index" href={route('admin.organizers.index')} label="المنظّمون" />
            <PageHeader
                title={organizer.name}
                subtitle={t('منظّم ميدانيّ')}
                actions={may.manage && (
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" onClick={() => setEditing(true)}><Pencil />{t('تعديل')}</Button>
                        <Button variant="outline" className="text-[#b91c1c]" onClick={async () => {
                            if (! await ask({ message: 'حذف المنظّم؟ من له مشاركاتٌ يُوقَف ولا يُحذف.', danger: true, action: 'حذف' })) return;
                            router.delete(route('admin.organizers.destroy', organizer.id));
                        }}><Trash2 />{t('حذف')}</Button>
                    </div>
                )}
            />

            <SectionTabs tabs={CUSTOMER_TABS} current="admin.organizers.show" />

            {errors?.organizer && <div className="mb-4 rounded-lg bg-[#fef2f2] px-4 py-3 text-[13px] text-[#b91c1c]">{errors.organizer}</div>}

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <Card className="space-y-4 p-6">
                    <div className="flex items-center justify-between gap-2">
                        <span className={'rounded-full px-2 py-1 text-[12px] ' + organizerTone(organizer.status)}>{labels.status[organizer.status] ?? organizer.status}</span>
                        <span className="text-[12px] text-[#6b7280]">{labels.gender[organizer.gender]}{organizer.source ? ' · ' + (labels.source[organizer.source] ?? organizer.source) : ''}</span>
                    </div>
                    {may.manage && (
                        <Select value={organizer.status} aria-label={t('الحالة')} onChange={(e) => router.post(route('admin.organizers.status', organizer.id), { status: e.target.value }, { preserveScroll: true })}
                            options={Object.entries(labels.status).map(([value, label]) => ({ value, label }))} />
                    )}
                    <ul className="space-y-2 text-[13px] text-[#4b4b4b]">
                        <li className="flex items-center gap-2"><Phone className="size-4 text-[#9ca3af]" /><span dir="ltr">{organizer.phone || '—'}</span></li>
                        {organizer.whatsapp && <li className="flex items-center gap-2"><MessageCircle className="size-4 text-[#9ca3af]" /><span dir="ltr">{organizer.whatsapp}</span></li>}
                        {organizer.email && <li className="flex items-center gap-2"><Mail className="size-4 text-[#9ca3af]" /><span dir="ltr">{organizer.email}</span></li>}
                        {organizer.joined_at && <li className="text-[12px] text-[#6b7280]">{t('انضمّ في :date', { date: organizer.joined_at })}</li>}
                    </ul>
                    <div>
                        <h3 className="mb-2 text-[12px] font-bold text-[#6b7280]">{t('التصنيفات')}</h3>
                        <div className="flex flex-wrap gap-1">
                            {organizer.categories.length === 0 && <span className="text-[12px] text-[#9ca3af]">—</span>}
                            {organizer.categories.map((c) => <span key={c.id} className="rounded-full bg-[#f5f3ff] px-2 py-0.5 text-[12px] text-[#6d28d9]">{c.name}</span>)}
                        </div>
                    </div>
                    {organizer.notes && <p className="whitespace-pre-line text-[12.5px] text-[#4b4b4b]">{organizer.notes}</p>}
                </Card>

                <div className="space-y-6 lg:col-span-2">
                    <Card className="overflow-hidden">
                        <h3 className="flex items-center gap-2 border-b border-[#f4f4f5] px-5 py-3 text-sm font-bold"><CalendarCheck className="size-4 text-[#6d28d9]" />{t('الفعاليّات القادمة')}</h3>
                        {list(upcoming, 'لا فعاليّات قادمة لهذا المنظّم')}
                    </Card>
                    <Card className="overflow-hidden">
                        <h3 className="flex items-center gap-2 border-b border-[#f4f4f5] px-5 py-3 text-sm font-bold"><History className="size-4 text-[#6b7280]" />{t('تاريخ المشاركات')}</h3>
                        {list(history, 'لا مشاركات سابقة')}
                    </Card>
                </div>
            </div>

            <OrganizerDialog open={editing} onClose={() => setEditing(false)} organizer={organizer} categories={categories} labels={labels} />
            {confirmDialog}
        </AdminLayout>
    );
}
