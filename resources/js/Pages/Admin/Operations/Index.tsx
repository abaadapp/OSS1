import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { CalendarCheck, CalendarDays, ClipboardList, List, Plus } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input, Textarea } from '@/Components/ui/input';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { useTranslate } from '@/lib/i18n';
import { label, operationTone, type Labels } from '@/lib/operations';
import SectionTabs, { PROJECT_TABS } from '@/Components/SectionTabs';
import { cn } from '@/lib/utils';
import OperationsCalendar, { type CalendarData } from './partials/OperationsCalendar';

interface OperationRow {
    id: number;
    code: string;
    name: string;
    status: string;
    project: { id: number; code: string; name: string } | null;
    venue_name: string | null;
    event_starts_at: string | null;
    event_ends_at: string | null;
    lead: string | null;
}

interface Props {
    operations: OperationRow[];
    pagination: { current_page: number; last_page: number; total: number };
    filters: { q?: string; status?: string; window?: string; month?: string };
    view: 'calendar' | 'list';
    calendar: CalendarData;
    statuses: string[];
    projects: { id: number; code: string; name: string }[];
    totals: { live: number; today: number; week: number; active: number };
    labels: Labels;
    may: { manage: boolean };
}

/**
 * الفعاليّاتُ التشغيليّة — ما يجري على الأرض، لا ما في العقد.
 *
 * كلُّ صفٍّ فعاليّةٌ بموعدها ومكانها وقائدها وحالها. والإنشاءُ من مشروعٍ
 * قائم: الفعاليّةُ تنفيذٌ لمشروع، لا تُنشأ في فراغ.
 */
export default function OperationsIndex({ operations, pagination, filters, view, calendar, statuses, projects, labels, may }: Props) {
    const t = useTranslate();
    const { errors } = usePage().props as { errors?: Record<string, string> };
    const [creating, setCreating] = useState(false);
    const [form, setForm] = useState({ project_id: '', name: '', venue_name: '', event_starts_at: '', event_ends_at: '', setup_starts_at: '', teardown_ends_at: '', notes: '' });

    /* والعرضُ والشهرُ في الرابط: من ينسخه أو يحدّث الصفحة يعود إلى ما كان يراه */
    const base = { ...filters, view: view === 'list' ? 'list' : undefined, month: view === 'calendar' ? calendar.month : undefined };
    const filter = (patch: Record<string, string | undefined>) =>
        router.get(route('admin.operations.index'), { ...base, ...patch, page: undefined }, { preserveState: true, preserveScroll: true, replace: true });

    const submit = () => router.post(route('admin.operations.store'), form, { onSuccess: () => setCreating(false) });

    const statusLabel = (s: string) => label(labels, 'status', s);

    return (
        <AdminLayout title="العمليات">
            <PageHeader
                title="العمليات"
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        {/* تقويمٌ أو قائمة — والتقويمُ أوّلًا */}
                        <div className="inline-flex rounded-[10px] border border-[var(--ui-border,#e8e8e8)] p-0.5">
                            {(
                                [
                                    ['calendar', CalendarDays, 'تقويم'],
                                    ['list', List, 'قائمة'],
                                ] as const
                            ).map(([key, Icon, text]) => (
                                <button
                                    key={key}
                                    type="button"
                                    aria-pressed={view === key}
                                    onClick={() => filter(key === 'list' ? { view: 'list', month: undefined } : { view: undefined, window: undefined, month: undefined })}
                                    className={cn(
                                        'inline-flex items-center gap-1.5 rounded-[8px] px-3 py-1.5 text-[13px] font-medium transition-colors',
                                        view === key ? 'bg-[#111] text-white' : 'text-[#4b4b4b] hover:bg-[#f5f5f4]',
                                    )}
                                >
                                    <Icon className="size-4" />
                                    {t(text)}
                                </button>
                            ))}
                        </div>
                        <Button variant="outline" asChild>
                            <SmartLink routeName="admin.operations.templates" href={route('admin.operations.templates')}>
                                <ClipboardList />
                                {t('قوالب التحقّق')}
                            </SmartLink>
                        </Button>
                        {/* وفي التقويم زرُّ الإنشاء بجانب فعاليّات الشهر — لا مرّتين */}
                        {may.manage && view === 'list' && (
                            <Button onClick={() => setCreating(true)}>
                                <Plus />
                                {t('فعاليّة جديدة')}
                            </Button>
                        )}
                    </div>
                }
            />

            <SectionTabs tabs={PROJECT_TABS} current="admin.operations.index" />

            {errors?.operation && <div className="mb-4 rounded-lg bg-[#fef2f2] px-4 py-3 text-[13px] text-[#b91c1c]">{errors.operation}</div>}

            {/*
             * والتصفيةُ للقائمة وحدَها: التقويمُ يُصفّي من «الكل» بجانب فعاليّاته.
             * وبطاقاتُ الأرقام نُزعت — التقويمُ نفسُه يقول ما اليومَ وما هذا الأسبوع.
             */}
            {view === 'list' && (
                <div className="mb-4 flex flex-wrap items-end gap-3">
                    <Field label="بحث" className="min-w-[220px] flex-1">
                        <Input type="search" defaultValue={filters.q ?? ''} placeholder="الرمز أو الاسم أو المكان أو المشروع" onKeyDown={(e) => e.key === 'Enter' && filter({ q: (e.target as HTMLInputElement).value })} />
                    </Field>
                    <Field label="الحالة" className="min-w-[160px]">
                        <Select value={filters.status ?? ''} placeholder="كل الحالات" onChange={(e) => filter({ status: e.target.value })} options={statuses.map((s) => ({ value: s, label: statusLabel(s) }))} />
                    </Field>
                    <Field label="النافذة" className="min-w-[160px]">
                        <Select
                            value={filters.window ?? ''}
                            placeholder="كل المواعيد"
                            onChange={(e) => filter({ window: e.target.value })}
                            options={[
                                { value: 'today', label: t('اليوم') },
                                { value: 'week', label: t('خلال أسبوع') },
                                { value: 'upcoming', label: t('القادمة') },
                                { value: 'past', label: t('الماضية') },
                            ]}
                        />
                    </Field>
                </div>
            )}

            {view === 'calendar' && (
                <OperationsCalendar data={calendar} statusLabel={statusLabel} onMonth={(month) => filter({ month })} onCreate={may.manage ? () => setCreating(true) : undefined} />
            )}

            {view === 'list' && (
            <Card className="overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-[13px]">
                        <thead className="bg-[#fafafa] text-[12px] text-[#6b7280]">
                            <tr>
                                <th className="px-4 py-2 text-start">{t('الرمز')}</th>
                                <th className="px-4 py-2 text-start">{t('الفعاليّة')}</th>
                                <th className="px-4 py-2 text-start">{t('المشروع')}</th>
                                <th className="px-4 py-2 text-start">{t('الموعد')}</th>
                                <th className="px-4 py-2 text-start">{t('المكان')}</th>
                                <th className="px-4 py-2 text-start">{t('القائد')}</th>
                                <th className="px-4 py-2 text-center">{t('الحالة')}</th>
                                <th className="px-4 py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            {operations.length === 0 && (
                                <tr>
                                    <td colSpan={8} className="px-4 py-10 text-center text-[#9ca3af]">
                                        <CalendarCheck className="mx-auto mb-2 h-6 w-6" />
                                        {t('لا فعاليّات بعد — أنشئ أوّل فعاليّة من مشروعٍ قائم')}
                                    </td>
                                </tr>
                            )}
                            {operations.map((o) => (
                                <tr key={o.id} className="border-t border-[#f4f4f5]">
                                    <td className="px-4 py-2 font-mono text-[12px]">
                                        <SmartLink routeName="admin.operations.show" href={route('admin.operations.show', o.id)} className="text-[#1d4ed8]">
                                            {o.code}
                                        </SmartLink>
                                    </td>
                                    <td className="px-4 py-2 font-medium">{o.name}</td>
                                    <td className="px-4 py-2 text-[12px] text-[#6b7280]">
                                        {o.project ? (
                                            <SmartLink routeName="admin.projects.show" href={route('admin.projects.show', o.project.id)} className="hover:text-[#1d4ed8]">
                                                {o.project.code}
                                            </SmartLink>
                                        ) : '—'}
                                    </td>
                                    <td className="px-4 py-2 text-[12px] tabular-nums" dir="ltr">{o.event_starts_at ?? '—'}</td>
                                    <td className="px-4 py-2 text-[12px] text-[#6b7280]">{o.venue_name ?? '—'}</td>
                                    <td className="px-4 py-2 text-[12px]">{o.lead ?? '—'}</td>
                                    <td className="px-4 py-2 text-center">
                                        <span className={'rounded-full px-2 py-1 text-[11px] ' + operationTone(o.status)}>{statusLabel(o.status)}</span>
                                    </td>
                                    <td className="px-4 py-2 text-end">
                                        <Button variant="outline" size="sm" asChild>
                                            <SmartLink routeName="admin.operations.onsite" href={route('admin.operations.onsite', o.id)}>{t('شاشة الموقع')}</SmartLink>
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {pagination.last_page > 1 && (
                    <div className="flex items-center justify-between border-t border-[#f4f4f5] px-4 py-2 text-[12px] text-[#6b7280]">
                        <span>{t('صفحة :n من :m', { n: String(pagination.current_page), m: String(pagination.last_page) })}</span>
                        <div className="flex gap-2">
                            <Button variant="outline" size="sm" disabled={pagination.current_page <= 1} onClick={() => router.get(route('admin.operations.index'), { ...base, page: pagination.current_page - 1 })}>{t('السابق')}</Button>
                            <Button variant="outline" size="sm" disabled={pagination.current_page >= pagination.last_page} onClick={() => router.get(route('admin.operations.index'), { ...base, page: pagination.current_page + 1 })}>{t('التالي')}</Button>
                        </div>
                    </div>
                )}
            </Card>
            )}

            <Dialog open={creating} onOpenChange={(v) => !v && setCreating(false)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('فعاليّة جديدة')}</DialogTitle>
                        <DialogDescription>{t('تنفيذٌ لمشروعٍ قائم — يُعرَّف موعدُه ومكانُه ثمّ يُجهَّز فريقُه ومعدّاتُه.')}</DialogDescription>
                    </DialogHeader>
                    <div className="space-y-3 px-5 pb-5">
                        <Field label="المشروع" required error={errors?.project_id}>
                            <Select value={form.project_id} placeholder="اختر مشروعًا" onChange={(e) => setForm({ ...form, project_id: e.target.value })} options={projects.map((p) => ({ value: p.id, label: `${p.code} — ${p.name}` }))} />
                        </Field>
                        <Field label="اسم الفعاليّة" hint="يُترك فارغًا ليأخذ اسم المشروع">
                            <Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
                        </Field>
                        <Field label="المكان">
                            <Input value={form.venue_name} onChange={(e) => setForm({ ...form, venue_name: e.target.value })} />
                        </Field>
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="بداية الفعاليّة" error={errors?.event_starts_at}>
                                <Input type="datetime-local" value={form.event_starts_at} onChange={(e) => setForm({ ...form, event_starts_at: e.target.value })} />
                            </Field>
                            <Field label="نهاية الفعاليّة" error={errors?.event_ends_at}>
                                <Input type="datetime-local" value={form.event_ends_at} onChange={(e) => setForm({ ...form, event_ends_at: e.target.value })} />
                            </Field>
                            <Field label="بداية التجهيز">
                                <Input type="datetime-local" value={form.setup_starts_at} onChange={(e) => setForm({ ...form, setup_starts_at: e.target.value })} />
                            </Field>
                            <Field label="نهاية الفكّ">
                                <Input type="datetime-local" value={form.teardown_ends_at} onChange={(e) => setForm({ ...form, teardown_ends_at: e.target.value })} />
                            </Field>
                        </div>
                        <Field label="ملاحظات">
                            <Textarea rows={2} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
                        </Field>
                    </div>
                    <DialogFooter>
                        <Button disabled={form.project_id === ''} onClick={submit}>{t('إنشاء')}</Button>
                        <Button variant="outline" onClick={() => setCreating(false)}>{t('إلغاء')}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
