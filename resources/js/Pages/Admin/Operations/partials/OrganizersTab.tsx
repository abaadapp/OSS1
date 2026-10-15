import { useState } from 'react';
import { router } from '@inertiajs/react';
import { AlertTriangle, Plus, UsersRound } from 'lucide-react';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input, Textarea } from '@/Components/ui/input';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { useTranslate } from '@/lib/i18n';
import { assignmentTone } from '@/lib/organizers';

export interface OperationOrganizerRow {
    id: number;
    organizer_id: number;
    name: string | null;
    phone: string | null;
    gender: string | null;
    category_id: number | null;
    category: string | null;
    starts_at: string | null;
    ends_at: string | null;
    status: string;
    notes: string | null;
}

export interface OrganizerOption {
    id: number;
    name: string;
    gender: string;
    phone: string | null;
    categories: { id: number; name: string }[];
}

/**
 * المنظّمون على الفعاليّة — مسؤولُهم من موظّفي الشركة، وقائمتُهم بتصنيفاتهم ووقتهم وحالهم.
 *
 * والتعارضُ بسياسة الفريق نفسِها: تعيينٌ يتداخل مع فعاليّةٍ أخرى يردّه الخادمُ
 * برسالةٍ تظهر في رأس الصفحة، والفجوةُ الضيّقة تحذيرٌ يظهر هنا.
 */
export default function OrganizersTab({
    operationId,
    active,
    leadId,
    users,
    organizers,
    options,
    categories,
    statusLabels,
    genderLabels,
    conflicts,
    canManage,
}: {
    operationId: number;
    active: boolean;
    leadId: number | null;
    users: { id: number; name: string }[];
    organizers: OperationOrganizerRow[];
    options: OrganizerOption[];
    categories: { id: number; name: string }[];
    statusLabels: Record<string, string>;
    genderLabels: Record<string, string>;
    conflicts: { severity: string; kind: string; message: string }[];
    canManage: boolean;
}) {
    const t = useTranslate();
    const [adding, setAdding] = useState(false);
    const [form, setForm] = useState({ organizer_id: '', organizer_category_id: '', starts_at: '', ends_at: '', notes: '' });
    const manage = canManage && active;
    const assigned = new Set(organizers.filter((o) => o.status !== 'cancelled' && o.status !== 'absent').map((o) => o.organizer_id));
    const picked = options.find((o) => String(o.id) === form.organizer_id);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        router.post(route('admin.operations.organizers.store', operationId), form, {
            preserveScroll: true,
            onSuccess: () => { setAdding(false); setForm({ organizer_id: '', organizer_category_id: '', starts_at: '', ends_at: '', notes: '' }); },
        });
    };
    const update = (row: OperationOrganizerRow, data: Record<string, string>) =>
        router.put(route('admin.operations.organizers.update', [operationId, row.id]), data, { preserveScroll: true });

    return (
        <div className="space-y-4">
            <Card className="p-4">
                <div className="flex flex-wrap items-end gap-3">
                    <Field label="مسؤول المنظّمين" hint="موظّفٌ من الشركة يقود المنظّمين في الميدان" className="min-w-[240px] flex-1">
                        <Select
                            value={leadId ? String(leadId) : ''}
                            placeholder="بلا مسؤول"
                            disabled={!manage}
                            onChange={(e) => router.post(route('admin.operations.organizersLead', operationId), { user_id: e.target.value || null }, { preserveScroll: true })}
                            options={users.map((u) => ({ value: String(u.id), label: u.name }))}
                        />
                    </Field>
                    {manage && <Button onClick={() => setAdding(true)}><Plus />{t('تعيين منظّم')}</Button>}
                </div>
            </Card>

            {conflicts.length > 0 && (
                <Card className="space-y-1 border-[#fde68a] bg-[#fffbeb] p-4 text-[13px] text-[#92400e]">
                    {conflicts.map((c, i) => <p key={i} className="flex items-center gap-2"><AlertTriangle className="size-4" />{c.message}</p>)}
                </Card>
            )}

            <Card className="overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-[13px]">
                        <thead className="bg-[#fafafa] text-[12px] text-[#6b7280]">
                            <tr>
                                <th className="px-4 py-2 text-start">{t('المنظّم')}</th>
                                <th className="px-4 py-2 text-start">{t('التصنيف في الفعاليّة')}</th>
                                <th className="px-4 py-2 text-start">{t('وقت العمل')}</th>
                                <th className="px-4 py-2 text-center">{t('الحالة')}</th>
                                <th className="px-4 py-2 text-start">{t('ملاحظات')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {organizers.length === 0 && (
                                <tr><td colSpan={5} className="px-4 py-10 text-center text-[#9ca3af]"><UsersRound className="mx-auto mb-2 size-6" />{t('لا منظّمين على هذه الفعاليّة بعد')}</td></tr>
                            )}
                            {organizers.map((o) => (
                                <tr key={o.id} className="border-t border-[#f4f4f5] align-top">
                                    <td className="px-4 py-2">
                                        <span className="font-medium">{o.name}</span>
                                        <span className="block text-[11.5px] text-[#9ca3af]">{[o.gender && genderLabels[o.gender], o.phone].filter(Boolean).join(' · ')}</span>
                                    </td>
                                    <td className="px-4 py-2">
                                        {manage ? (
                                            <Select value={o.category_id ? String(o.category_id) : ''} placeholder="بلا تصنيف" aria-label={t('التصنيف في الفعاليّة')}
                                                onChange={(e) => update(o, { organizer_category_id: e.target.value })}
                                                options={categories.map((c) => ({ value: String(c.id), label: c.name }))} />
                                        ) : (o.category ?? '—')}
                                    </td>
                                    <td className="px-4 py-2 text-[12px] tabular-nums text-[#6b7280]" dir="ltr">{o.starts_at ?? '—'}{o.ends_at ? ` → ${o.ends_at}` : ''}</td>
                                    <td className="px-4 py-2 text-center">
                                        {manage ? (
                                            <Select value={o.status} aria-label={t('الحالة')} onChange={(e) => update(o, { status: e.target.value })}
                                                options={Object.entries(statusLabels).map(([value, label]) => ({ value, label }))} />
                                        ) : <span className={'rounded-full px-2 py-1 text-[11px] ' + assignmentTone(o.status)}>{statusLabels[o.status] ?? o.status}</span>}
                                    </td>
                                    <td className="px-4 py-2 text-[12px] text-[#4b4b4b]">{o.notes ?? '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </Card>

            <Dialog open={adding} onOpenChange={setAdding}>
                <DialogContent className="max-w-lg">
                    <DialogHeader><DialogTitle>{t('تعيين منظّم على الفعاليّة')}</DialogTitle></DialogHeader>
                    <form onSubmit={submit} className="space-y-4 px-5 pb-2">
                        <Field label="المنظّم" required hint="المتاحون وحدهم — الموقوفُ وغيرُ المتاح لا يُعيَّن">
                            <Select value={form.organizer_id} placeholder="اختر منظّمًا" onChange={(e) => setForm({ ...form, organizer_id: e.target.value, organizer_category_id: '' })}
                                options={options.map((o) => ({
                                    value: String(o.id),
                                    label: `${o.name} · ${genderLabels[o.gender] ?? ''}${o.categories.length ? ' · ' + o.categories.map((c) => c.name).join('، ') : ''}`,
                                    disabled: assigned.has(o.id),
                                }))} />
                        </Field>
                        <Field label="التصنيف في الفعاليّة">
                            <Select value={form.organizer_category_id} placeholder="بلا تصنيف" onChange={(e) => setForm({ ...form, organizer_category_id: e.target.value })}
                                options={(picked && picked.categories.length ? picked.categories : categories).map((c) => ({ value: String(c.id), label: c.name }))} />
                        </Field>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label="من" hint="فارغًا = نافذة الفعاليّة">
                                <Input type="datetime-local" value={form.starts_at} onChange={(e) => setForm({ ...form, starts_at: e.target.value })} />
                            </Field>
                            <Field label="إلى">
                                <Input type="datetime-local" value={form.ends_at} onChange={(e) => setForm({ ...form, ends_at: e.target.value })} />
                            </Field>
                        </div>
                        <Field label="ملاحظات">
                            <Textarea rows={2} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
                        </Field>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setAdding(false)}>{t('إلغاء')}</Button>
                            <Button type="submit" disabled={!form.organizer_id}>{t('تعيين')}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
    );
}
