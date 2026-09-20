import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { MANAGEMENT_TABS } from '@/Components/SectionTabs';
import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input, Textarea } from '@/Components/ui/input';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { useTranslate } from '@/lib/i18n';
import { REVIEW_STATUS_LABEL } from '@/lib/management';

interface Row {
    id: number;
    title: string;
    period_start: string;
    period_end: string;
    status: string;
    status_label: string;
    prepared_by: string | null;
    reviewed_at: string | null;
    closed_at: string | null;
    decisions_count: number;
    url: string;
}

interface Props {
    reviews: Row[];
    may: { manage: boolean; financial: boolean };
}

const reviewTone = (s: string) => (s === 'closed' ? 'bg-[#f4f4f5] text-[#71717a]' : s === 'reviewed' ? 'bg-[#ecfdf5] text-[#047857]' : 'bg-[#fffbeb] text-[#b45309]');

/** لقاءُ الإدارة الدوريّ — لقطةٌ تُلتقط وأقسامٌ تُكتب وقراراتٌ تُسجَّل. النظامُ يسجّل ولا يقرّر */
export default function ManagementReviews({ reviews, may }: Props) {
    const t = useTranslate();
    const { errors } = usePage().props as { errors?: Record<string, string> };
    const [creating, setCreating] = useState(false);
    const today = new Date();
    const firstOfMonth = new Date(today.getFullYear(), today.getMonth(), 1);
    const lastOfPrev = new Date(firstOfMonth.getTime() - 86400000);
    const firstOfPrev = new Date(lastOfPrev.getFullYear(), lastOfPrev.getMonth(), 1);
    const iso = (d: Date) => d.toISOString().slice(0, 10);
    const [form, setForm] = useState({ title: '', period_start: iso(firstOfPrev), period_end: iso(lastOfPrev), notes: '' });

    return (
        <AdminLayout title="المراجعات الإداريّة">
            <PageHeader title="المراجعات الإداريّة" subtitle={t('لقطةٌ من الأرقام في لحظتها، وأقسامٌ تُكتب، وقراراتٌ بمالكٍ وموعد — والنظامُ يسجّل ولا يقرّر')} actions={may.manage ? <Button onClick={() => setCreating(true)}><Plus />{t('مراجعة جديدة')}</Button> : undefined} />
            <SectionTabs tabs={MANAGEMENT_TABS} current="admin.management.reviews" className="mb-4" />

            {errors?.review && <div className="mb-4 rounded-lg bg-[#fef2f2] px-4 py-3 text-[13px] text-[#b91c1c]">{errors.review}</div>}

            <Card className="overflow-hidden">
                <table className="w-full text-[13px]">
                    <thead className="bg-[#fafafa] text-[12px] text-[#6b7280]"><tr><th className="px-3 py-2 text-start">{t('المراجعة')}</th><th className="px-3 py-2 text-start">{t('المدّة')}</th><th className="px-3 py-2 text-center">{t('الحالة')}</th><th className="px-3 py-2 text-start">{t('أعدّها')}</th><th className="px-3 py-2 text-end">{t('قرارات')}</th><th className="px-3 py-2 text-start">{t('رُوجعت / أُقفلت')}</th></tr></thead>
                    <tbody>
                        {reviews.length === 0 && <tr><td colSpan={6} className="px-3 py-12 text-center text-[#9ca3af]">{t('لا مراجعاتٍ بعد — أنشئ واحدةً للشهر الماضي')}</td></tr>}
                        {reviews.map((r) => (
                            <tr key={r.id} className="border-t border-[#f4f4f5]">
                                <td className="px-3 py-2"><a href={r.url} className="font-medium hover:text-[#1d4ed8]">{r.title}</a></td>
                                <td className="px-3 py-2 text-[12px]" dir="ltr">{r.period_start} → {r.period_end}</td>
                                <td className="px-3 py-2 text-center"><span className={'rounded-full px-2 py-0.5 text-[11px] ' + reviewTone(r.status)}>{t(REVIEW_STATUS_LABEL[r.status] ?? r.status)}</span></td>
                                <td className="px-3 py-2 text-[12px] text-[#6b7280]">{r.prepared_by ?? '—'}</td>
                                <td className="px-3 py-2 text-end tabular-nums">{r.decisions_count}</td>
                                <td className="px-3 py-2 text-[11px] text-[#9ca3af]" dir="ltr">{r.reviewed_at ?? '—'} / {r.closed_at ?? '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </Card>

            <Dialog open={creating} onOpenChange={setCreating}>
                <DialogContent>
                    <DialogHeader><DialogTitle>{t('مراجعة جديدة')}</DialogTitle><DialogDescription>{t('تُلتقط الأرقامُ للمدّة عند الإنشاء وتُحفظ كما هي')}</DialogDescription></DialogHeader>
                    <div className="space-y-3 px-5 pb-5">
                        <Field label="العنوان" hint="يُشتقّ من المدّة إن تُرك"><Input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} /></Field>
                        <div className="grid grid-cols-2 gap-2">
                            <Field label="من" required><Input type="date" value={form.period_start} onChange={(e) => setForm({ ...form, period_start: e.target.value })} /></Field>
                            <Field label="إلى" required><Input type="date" value={form.period_end} onChange={(e) => setForm({ ...form, period_end: e.target.value })} /></Field>
                        </div>
                        <Field label="ملاحظات"><Textarea rows={2} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} /></Field>
                    </div>
                    <DialogFooter>
                        <Button onClick={() => router.post(route('admin.management.reviews.store'), form, { onSuccess: () => setCreating(false) })}>{t('إنشاء')}</Button>
                        <Button variant="outline" onClick={() => setCreating(false)}>{t('إلغاء')}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
