import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { CalendarDays, Check, Flag, ListChecks, Plus, Search } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { useTranslate } from '@/lib/i18n';
import { PROJECT_STATUS_LABEL, projectTone } from '@/lib/projects';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

interface Task {
    id: number;
    title: string;
    description: string | null;
    due_at: string | null;
    priority: 'low' | 'normal' | 'high';
    status: 'open' | 'done';
    assignee: { id: number; name: string } | null;
}

interface ProjectGroup {
    id: number;
    code: string;
    name: string;
    customer: string | null;
    status: string;
    starts_at: string | null;
    ends_at: string | null;
    total: number;
    done: number;
    late: number;
    next_due: string | null;
    tasks: Task[];
}

interface Props {
    projects: ProjectGroup[];
    filters: { show: 'open' | 'done' | 'all'; when: '' | 'late' | 'week'; mine: boolean; q: string };
    today: string;
    totals: { open: number; late: number; week: number; mine: number };
    choices: { projects: { id: number; code: string; name: string }[]; staff: { id: number; name: string }[] };
    may: { manage: boolean };
}

const DAY = 86_400_000;
const daysBetween = (from: string, to: string) => Math.round((new Date(to + 'T00:00:00').getTime() - new Date(from + 'T00:00:00').getTime()) / DAY);
const initials = (name: string) =>
    name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0])
        .join('');

/**
 * المهامّ — كلُّ مشروعٍ بطاقةٌ، وفيها مهامُّه بمواعيدها.
 *
 * الموعدُ هو ما يُقرأ أوّلًا: المتأخّرُ أحمر، وما يحلّ اليومَ أو غدًا
 * برتقاليّ، وما بعده رماديٌّ هادئ. والدائرةُ بجانب المهمّة تُنجزها بضغطة.
 */
export default function TasksIndex({ projects, filters, today, totals, choices, may }: Props) {
    const t = useTranslate();
    const { locale, errors } = usePage<PageProps & { errors?: Record<string, string> }>().props;
    const intl = locale === 'en' ? 'en-GB' : 'ar-u-nu-latn';
    const blank = { project_id: '', title: '', due_at: '', assignee_id: '', priority: 'normal' };
    const [adding, setAdding] = useState(false);
    const [form, setForm] = useState(blank);

    const filter = (patch: Partial<Props['filters']>) =>
        router.get(
            route('admin.tasks.index'),
            Object.fromEntries(
                Object.entries({ ...filters, ...patch }).filter(([k, v]) => v !== '' && v !== false && !(k === 'show' && v === 'open')),
            ),
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const toggle = (p: ProjectGroup, task: Task) =>
        router.put(
            route('admin.projects.tasks.update', [p.id, task.id]),
            { title: task.title, status: task.status === 'done' ? 'open' : 'done' },
            { preserveScroll: true },
        );

    const submit = () =>
        router.post(route('admin.projects.tasks.store', form.project_id), form, {
            preserveScroll: true,
            onSuccess: () => {
                setAdding(false);
                setForm(blank);
            },
        });

    const fmtDate = (iso: string) => new Intl.DateTimeFormat(intl, { day: 'numeric', month: 'short' }).format(new Date(iso + 'T00:00:00'));

    /* الموعدُ بلغة الناس: «متأخّرة ٣ أيّام»، «اليوم»، «غدًا»، ثمّ التاريخ */
    const due = (task: Task) => {
        if (!task.due_at) return null;
        if (task.status === 'done') return { text: fmtDate(task.due_at), tone: 'muted' as const };
        const d = daysBetween(today, task.due_at);
        if (d < 0) {
            const n = -d;
            /* العددُ بالعربيّة كما يُقال: يومًا، يومين، ثلاثةَ أيّام، أحدَ عشرَ يومًا */
            const key = n === 1 ? 'متأخّرة يومًا' : n === 2 ? 'متأخّرة يومين' : n <= 10 ? 'متأخّرة :n أيام' : 'متأخّرة :n يومًا';
            return { text: t(key, { n: String(n) }), tone: 'late' as const };
        }
        if (d === 0) return { text: t('اليوم'), tone: 'soon' as const };
        if (d === 1) return { text: t('غدًا'), tone: 'soon' as const };
        return { text: fmtDate(task.due_at), tone: d <= 7 ? ('week' as const) : ('muted' as const) };
    };

    const chips: { key: string; label: string; n: number; on: boolean; go: () => void; danger?: boolean }[] = [
        { key: 'open', label: 'كل المفتوحة', n: totals.open, on: filters.show === 'open' && !filters.when && !filters.mine, go: () => filter({ show: 'open', when: '', mine: false }) },
        { key: 'late', label: 'متأخّرة', n: totals.late, on: filters.when === 'late', go: () => filter({ show: 'open', when: 'late', mine: false }), danger: true },
        { key: 'week', label: 'خلال أسبوع', n: totals.week, on: filters.when === 'week', go: () => filter({ show: 'open', when: 'week', mine: false }) },
        { key: 'mine', label: 'مهامّي', n: totals.mine, on: filters.mine, go: () => filter({ mine: !filters.mine, when: '' }) },
    ];

    return (
        <AdminLayout title="المهام">
            <PageHeader
                title="المهام"
                subtitle={t('مهامّ كلّ مشروع ومواعيدها — المتأخّر أوّلًا')}
                actions={
                    may.manage && (
                        <Button onClick={() => setAdding(true)}>
                            <Plus />
                            {t('مهمّة جديدة')}
                        </Button>
                    )
                }
            />

            {/* ─────────── التصفية ─────────── */}
            <div className="mb-5 flex flex-wrap items-center gap-2">
                {chips.map((c) => (
                    <button
                        key={c.key}
                        type="button"
                        onClick={c.go}
                        aria-pressed={c.on}
                        className={cn(
                            'inline-flex h-9 items-center gap-2 rounded-full border px-4 text-[13px] font-medium transition-colors',
                            c.on ? 'border-[#111] bg-[#111] text-white' : 'border-[var(--ui-border,#e8e8e8)] bg-white text-[#3f3f46] hover:bg-[#f4f4f5]',
                        )}
                    >
                        {t(c.label)}
                        <span
                            className={cn(
                                'rounded-full px-1.5 text-[11px] tabular-nums',
                                c.on ? 'bg-white/20' : c.danger && c.n > 0 ? 'bg-[#fee2e2] text-[#b91c1c]' : 'bg-[#f4f4f5] text-[#71717a]',
                            )}
                        >
                            {c.n}
                        </span>
                    </button>
                ))}

                <div className="ms-auto flex items-center gap-2">
                    <select
                        value={filters.show}
                        onChange={(e) => filter({ show: e.target.value as Props['filters']['show'], when: '' })}
                        aria-label={t('الحالة')}
                        className="h-9 rounded-full border border-[var(--ui-border,#e8e8e8)] bg-white px-3 text-[13px] text-[#3f3f46] outline-none"
                    >
                        <option value="open">{t('المفتوحة')}</option>
                        <option value="done">{t('المنجزة')}</option>
                        <option value="all">{t('الكل')}</option>
                    </select>
                    <label className="relative">
                        <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-[#a1a1aa]" />
                        <input
                            type="search"
                            defaultValue={filters.q}
                            placeholder={t('ابحث في المهام أو المشاريع')}
                            aria-label={t('ابحث في المهام أو المشاريع')}
                            onKeyDown={(e) => e.key === 'Enter' && filter({ q: (e.target as HTMLInputElement).value })}
                            className="h-9 w-56 rounded-full border border-[var(--ui-border,#e8e8e8)] bg-white pe-3 ps-9 text-[13px] outline-none focus:border-[#d4d4d8]"
                        />
                    </label>
                </div>
            </div>

            {errors?.task && <div className="mb-4 rounded-lg bg-[#fef2f2] px-4 py-3 text-[13px] text-[#b91c1c]">{errors.task}</div>}

            {projects.length === 0 && (
                <div className="rounded-[20px] border border-dashed border-[var(--ui-border,#e8e8e8)] py-16 text-center text-[#a1a1aa]">
                    <ListChecks className="mx-auto mb-2 size-7" />
                    <p className="text-[14px]">{filters.when === 'late' ? t('لا مهامّ متأخّرة — أحسنتم.') : t('لا مهامّ هنا.')}</p>
                </div>
            )}

            {/* ─────────── المشاريع ─────────── */}
            <div className="space-y-4">
                {projects.map((p) => {
                    const pct = p.total > 0 ? Math.round((p.done / p.total) * 100) : 0;

                    return (
                        <section key={p.id} className="overflow-hidden rounded-[20px] border border-[var(--ui-border,#e8e8e8)] bg-white">
                            <header className="flex flex-wrap items-center gap-x-5 gap-y-3 border-b border-[var(--ui-border,#e8e8e8)] bg-[#fafafa] px-5 py-4">
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <SmartLink
                                            routeName="admin.projects.show"
                                            href={route('admin.projects.show', { id: p.id, tab: 'tasks' })}
                                            className="truncate text-[16px] font-semibold text-[#111] hover:underline"
                                        >
                                            {p.name}
                                        </SmartLink>
                                        <span className={cn('rounded-full px-2 py-0.5 text-[11px]', projectTone(p.status))}>{t(PROJECT_STATUS_LABEL[p.status] ?? p.status)}</span>
                                        {p.late > 0 && (
                                            <span className="rounded-full bg-[#fee2e2] px-2 py-0.5 text-[11px] font-medium text-[#b91c1c]">
                                                {t(':n متأخّرة', { n: String(p.late) })}
                                            </span>
                                        )}
                                    </div>
                                    <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[12px] text-[#71717a]">
                                        <span className="tabular-nums" dir="ltr">{p.code}</span>
                                        {p.customer && <span>{p.customer}</span>}
                                        {p.starts_at && (
                                            <span className="inline-flex items-center gap-1">
                                                <CalendarDays className="size-3.5" />
                                                {t('الفعاليّة')} {fmtDate(p.starts_at)}
                                            </span>
                                        )}
                                    </div>
                                </div>

                                <div className="flex w-full items-center gap-3 sm:w-56">
                                    <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-[#e4e4e7]">
                                        <div className="h-full rounded-full bg-[#16a34a] transition-[width]" style={{ width: `${pct}%` }} />
                                    </div>
                                    <span className="shrink-0 text-[12px] tabular-nums text-[#52525b]">
                                        {p.done}/{p.total}
                                    </span>
                                </div>
                            </header>

                            <ul className="divide-y divide-[var(--ui-border,#f0f0f0)]">
                                {p.tasks.map((task) => {
                                    const d = due(task);
                                    const done = task.status === 'done';

                                    return (
                                        <li key={task.id} className="flex items-center gap-3 px-5 py-3">
                                            <button
                                                type="button"
                                                disabled={!may.manage}
                                                onClick={() => toggle(p, task)}
                                                aria-label={done ? t('إعادة فتح المهمّة') : t('إنجاز المهمّة')}
                                                className={cn(
                                                    'flex size-5 shrink-0 items-center justify-center rounded-full border-2 transition-colors disabled:cursor-default',
                                                    done ? 'border-[#16a34a] bg-[#16a34a] text-white' : 'border-[#d4d4d8] hover:border-[#16a34a]',
                                                )}
                                            >
                                                {done && <Check className="size-3" strokeWidth={3} />}
                                            </button>

                                            <div className="min-w-0 flex-1">
                                                <div className={cn('flex items-center gap-1.5 text-[14px]', done ? 'text-[#a1a1aa] line-through' : 'text-[#18181b]')}>
                                                    {task.priority === 'high' && !done && <Flag className="size-3.5 shrink-0 fill-[#dc2626] text-[#dc2626]" aria-label={t('أولوية عالية')} />}
                                                    <span className="truncate">{task.title}</span>
                                                </div>
                                                {task.description && <div className="truncate text-[12px] text-[#a1a1aa]">{task.description}</div>}
                                            </div>

                                            {task.assignee ? (
                                                <span className="hidden items-center gap-1.5 text-[12px] text-[#52525b] sm:inline-flex">
                                                    <span className="flex size-6 items-center justify-center rounded-full bg-[#f4f4f5] text-[10px] font-semibold text-[#3f3f46]">
                                                        {initials(task.assignee.name)}
                                                    </span>
                                                    <span className="max-w-[10rem] truncate">{task.assignee.name}</span>
                                                </span>
                                            ) : (
                                                <span className="hidden text-[12px] text-[#d4d4d8] sm:inline">{t('بلا مسؤول')}</span>
                                            )}

                                            {d ? (
                                                <span
                                                    className={cn(
                                                        'inline-flex shrink-0 items-center gap-1 rounded-[7px] px-2 py-0.5 text-[12px] font-medium tabular-nums',
                                                        d.tone === 'late' && 'bg-[#fee2e2] text-[#b91c1c]',
                                                        d.tone === 'soon' && 'bg-[#ffedd5] text-[#c2410c]',
                                                        d.tone === 'week' && 'bg-[#eff6ff] text-[#1d4ed8]',
                                                        d.tone === 'muted' && 'bg-[#f4f4f5] text-[#71717a]',
                                                    )}
                                                >
                                                    <CalendarDays className="size-3.5" />
                                                    {d.text}
                                                </span>
                                            ) : (
                                                <span className="shrink-0 text-[12px] text-[#d4d4d8]">{t('بلا موعد')}</span>
                                            )}
                                        </li>
                                    );
                                })}
                            </ul>
                        </section>
                    );
                })}
            </div>

            <Dialog open={adding} onOpenChange={(v) => !v && setAdding(false)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('مهمّة جديدة')}</DialogTitle>
                        <DialogDescription>{t('عملٌ داخليّ في مشروع — لشخصٍ وبموعد.')}</DialogDescription>
                    </DialogHeader>
                    <div className="space-y-3 px-5 pb-5">
                        <Field label="المشروع" required>
                            <Select
                                value={form.project_id}
                                placeholder="اختر مشروعًا"
                                onChange={(e) => setForm({ ...form, project_id: e.target.value })}
                                options={choices.projects.map((p) => ({ value: p.id, label: `${p.code} — ${p.name}` }))}
                            />
                        </Field>
                        <Field label="المهمّة" required error={errors?.title}>
                            <Input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} />
                        </Field>
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="الموعد النهائي" error={errors?.due_at}>
                                <Input type="date" value={form.due_at} onChange={(e) => setForm({ ...form, due_at: e.target.value })} />
                            </Field>
                            <Field label="الأولوية">
                                <Select
                                    value={form.priority}
                                    onChange={(e) => setForm({ ...form, priority: e.target.value })}
                                    options={[
                                        { value: 'low', label: t('منخفضة') },
                                        { value: 'normal', label: t('عادية') },
                                        { value: 'high', label: t('عالية') },
                                    ]}
                                />
                            </Field>
                        </div>
                        <Field label="المسؤول">
                            <Select
                                value={form.assignee_id}
                                placeholder="بلا مسؤول"
                                onChange={(e) => setForm({ ...form, assignee_id: e.target.value })}
                                options={choices.staff.map((u) => ({ value: u.id, label: u.name }))}
                            />
                        </Field>
                    </div>
                    <DialogFooter>
                        <Button disabled={form.project_id === '' || form.title.trim() === ''} onClick={submit}>
                            {t('إضافة')}
                        </Button>
                        <Button variant="outline" onClick={() => setAdding(false)}>
                            {t('إلغاء')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
