import { useState, type ReactNode } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { AlertTriangle, ArrowRight, Check, CheckCircle2, LogIn, LogOut, MapPin, Megaphone, Phone, Play, Radio, RefreshCw, XCircle } from 'lucide-react';
import SmartLink from '@/Components/SmartLink';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Input, Textarea } from '@/Components/ui/input';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { useTranslate } from '@/lib/i18n';
import { CHECKLIST_STATUS_LABEL, RESERVATION_LABEL, clock, crewTone, label, operationTone, reservationTone, severityTone, type Labels } from '@/lib/operations';
import type { PageProps } from '@/types';

interface Operation {
    id: number;
    code: string;
    name: string;
    status: string;
    venue_name: string | null;
    venue_address: string | null;
    venue_notes: string | null;
    event_starts_at: string | null;
    event_ends_at: string | null;
    setup_starts_at: string | null;
    doors_open_at: string | null;
    teardown_starts_at: string | null;
    lead: string | null;
    active: boolean;
    project: { id: number; code: string; name: string } | null;
}

interface ScheduleItem {
    id: number;
    title: string;
    type: string;
    status: string;
    starts_at: string | null;
    ends_at: string | null;
    responsible: string | null;
}

interface CrewMember {
    id: number;
    name: string;
    role: string;
    status: string;
    phone: string | null;
    checked_in_at: string | null;
    checked_out_at: string | null;
}

interface Reservation {
    id: number;
    asset_code: string | null;
    asset_name: string | null;
    asset_status: string | null;
    status: string;
    custodian: string | null;
}

interface CriticalItem {
    id: number;
    title: string;
    status: string;
    checklist: string;
    phase: string;
}

interface Incident {
    id: number;
    title: string;
    severity: string;
    status: string;
    occurred_at: string | null;
}

interface Props {
    operation: Operation;
    relative: { phase: string; minutes: number | null };
    readiness: { ready: boolean; blockers: { key: string; message: string }[]; warnings: number };
    completion_blockers: string[];
    transitions: string[];
    now: { current: ScheduleItem[]; next: ScheduleItem[]; overdue: ScheduleItem[] };
    schedule: ScheduleItem[];
    crew: CrewMember[];
    attendance: { expected: number; checked_in: number; missing: number };
    equipment: { rows: Reservation[]; expected: number; checked_out: number; returned: number; problems: number; gaps: { description: string; shortfall: number; critical: boolean }[] };
    checklist: { progress: { id: number; name: string; phase: string; total: number; done: number; waived: number; required_pending: number }[]; critical: CriticalItem[] };
    incidents: Incident[];
    contacts: { label: string; name: string; phone: string | null }[];
    labels: Labels;
    may: Record<string, boolean>;
}

/**
 * شاشةُ الموقع — للهاتف واللوح، أثناء الفعاليّة.
 *
 * لا شريطَ جانبيًّا ولا قوائمَ محاسبة: رأسٌ يقول أين نحن من الفعاليّة، ثمّ
 * «ماذا الآن»، ثمّ الفريقُ والمعدّاتُ والقائمةُ والحوادث — أزرارٌ كبيرة وفعلٌ
 * واحد لكلّ ضغطة. وكلُّ فعلٍ يمرّ بخدمته على الخادم: الشاشةُ تطلب ولا تقرّر.
 */
export default function OnSite(p: Props) {
    const t = useTranslate();
    const { errors } = usePage().props as { errors?: Record<string, string> };
    const { auth } = usePage<PageProps>().props;
    const o = p.operation;
    const L = p.labels;
    const [reporting, setReporting] = useState(false);
    const [form, setForm] = useState({ title: '', category: 'equipment', severity: 'medium', description: '' });
    const [returning, setReturning] = useState<Reservation | null>(null);
    const [returnForm, setReturnForm] = useState({ condition: 'good', missing_accessories: '', damage_notes: '' });
    const [section, setSection] = useState<'now' | 'crew' | 'equipment' | 'checklist' | 'incidents'>('now');
    const [alerting, setAlerting] = useState(false);
    const [alertText, setAlertText] = useState('');

    const post = (name: string, params: (number | string)[], data: Record<string, string | number | boolean | null | undefined> = {}) => router.post(route(name, [o.id, ...params]), data, { preserveScroll: true });

    const relative = p.relative.phase === 'unscheduled'
        ? t('بلا موعد')
        : p.relative.phase === 'before'
          ? t('تبدأ بعد :time', { time: duration(p.relative.minutes ?? 0, t) })
          : p.relative.phase === 'after'
            ? t('انتهت منذ :time', { time: duration(p.relative.minutes ?? 0, t) })
            : t('جارية منذ :time', { time: duration(p.relative.minutes ?? 0, t) });

    const errorText = errors ? Object.values(errors)[0] : null;

    return (
        <div className="min-h-screen bg-[#f6f6f4] pb-24 text-[#111]" dir={(usePage().props as { dir?: string }).dir ?? 'rtl'}>
            <Head title={`${o.code} — ${t('شاشة الموقع')}`} />

            {/* ─ الرأس ─ */}
            <header className="sticky top-0 z-10 border-b border-[#e5e7eb] bg-white px-4 py-3">
                <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                        <div className="flex items-center gap-2">
                            <span className={'rounded-full px-2 py-0.5 text-[11px] ' + operationTone(o.status)}>{label(L, 'status', o.status)}</span>
                            <span className="text-[11px] text-[#6b7280]">{relative}</span>
                        </div>
                        <h1 className="mt-1 truncate text-[18px] font-bold">{o.name}</h1>
                        <div className="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[12px] text-[#6b7280]">
                            {o.venue_name && <span className="inline-flex items-center gap-1"><MapPin className="size-3" />{o.venue_name}</span>}
                            {o.lead && <span>{t('القائد')}: {o.lead}</span>}
                            {o.event_starts_at && <span dir="ltr" className="tabular-nums">{clock(o.event_starts_at)}{o.event_ends_at ? `–${clock(o.event_ends_at)}` : ''}</span>}
                        </div>
                    </div>
                    <div className="flex shrink-0 flex-col items-end gap-1">
                        <SmartLink routeName="admin.operations.show" href={route('admin.operations.show', o.id)} className="text-[12px] text-[#1d4ed8]">{t('الشاشة الكاملة')}</SmartLink>
                        <button type="button" onClick={() => router.reload()} className="inline-flex items-center gap-1 text-[12px] text-[#6b7280]" aria-label={t('تحديث')}><RefreshCw className="size-3.5" />{t('تحديث')}</button>
                    </div>
                </div>

                {errorText && <div className="mt-2 rounded-lg bg-[#fef2f2] px-3 py-2 text-[13px] text-[#b91c1c]">{errorText}</div>}

                {o.active && (
                    <div className="mt-2 flex flex-wrap items-center gap-2">
                        {!p.readiness.ready ? (
                            <div className="flex-1 rounded-lg bg-[#fef2f2] px-3 py-2 text-[12px] text-[#b91c1c]">
                                <AlertTriangle className="me-1 inline size-3.5" />
                                {p.readiness.blockers.slice(0, 3).map((b) => b.message).join(' · ')}
                                {p.readiness.blockers.length > 3 && ` +${p.readiness.blockers.length - 3}`}
                            </div>
                        ) : (
                            <div className="flex-1 rounded-lg bg-[#ecfdf5] px-3 py-2 text-[12px] text-[#047857]"><CheckCircle2 className="me-1 inline size-3.5" />{t('الجاهزيّة سليمة')}{p.readiness.warnings > 0 && ` · ${t(':n تحذيرات', { n: String(p.readiness.warnings) })}`}</div>
                        )}
                    </div>
                )}

                {p.may.status && o.active && (
                    <div className="mt-2 flex gap-2 overflow-x-auto">
                        {p.transitions.filter((s) => !['cancelled', 'planning', 'preparing'].includes(s)).map((s) => (
                            <Button
                                key={s}
                                size="lg"
                                variant={s === 'live' ? 'success' : s === 'completed' ? 'primary' : 'outline'}
                                className="shrink-0"
                                onClick={() => (s === 'completed' ? post('admin.operations.complete', []) : post('admin.operations.transition', [], { status: s }))}
                            >
                                {s === 'live' ? <Radio /> : s === 'completed' ? <Check /> : <Play />}
                                {label(L, 'status', s)}
                            </Button>
                        ))}
                    </div>
                )}
                {p.completion_blockers.length > 0 && <div className="mt-2 text-[12px] text-[#b45309]">{t('موانع الإتمام')}: {p.completion_blockers.join(' · ')}</div>}
            </header>

            {/* ─ الأقسام ─ */}
            <nav className="grid grid-cols-5 gap-1 px-2 py-2 text-[11px]">
                {([
                    ['now', t('الآن'), p.now.overdue.length],
                    ['crew', t('الفريق'), p.attendance.missing],
                    ['equipment', t('المعدّات'), p.equipment.gaps.length + p.equipment.problems],
                    ['checklist', t('القائمة'), p.checklist.critical.length],
                    ['incidents', t('الحوادث'), p.incidents.length],
                ] as const).map(([key, text, badge]) => (
                    <button key={key} type="button" onClick={() => setSection(key)} className={'relative rounded-lg py-2 ' + (section === key ? 'bg-[#111] text-white' : 'bg-white text-[#374151]')}>
                        {text}
                        {badge > 0 && <span className={'absolute -top-1 -end-1 rounded-full px-1.5 text-[10px] ' + (section === key ? 'bg-white text-[#111]' : 'bg-[#dc2626] text-white')}>{badge}</span>}
                    </button>
                ))}
            </nav>

            <main className="space-y-3 px-3">
                {section === 'now' && (
                    <>
                        <Block title={t('ماذا الآن')}>
                            {p.now.current.length === 0 && p.now.overdue.length === 0 && <Empty>{t('لا شيء جارٍ الآن')}</Empty>}
                            {p.now.overdue.map((i) => <ScheduleRow key={i.id} item={i} tone="overdue" onStatus={(s) => post('admin.operations.schedule.status', ['schedule', i.id], { status: s })} L={L} />)}
                            {p.now.current.map((i) => <ScheduleRow key={i.id} item={i} tone="current" onStatus={(s) => post('admin.operations.schedule.status', ['schedule', i.id], { status: s })} L={L} />)}
                        </Block>
                        <Block title={t('التالي')}>
                            {p.now.next.length === 0 && <Empty>{t('لا بنود قادمة')}</Empty>}
                            {p.now.next.map((i) => <ScheduleRow key={i.id} item={i} tone="next" onStatus={(s) => post('admin.operations.schedule.status', ['schedule', i.id], { status: s })} L={L} />)}
                        </Block>
                        <Block title={t('الجدول كاملًا')}>
                            {p.schedule.length === 0 && <Empty>{t('لا جدول')}</Empty>}
                            {p.schedule.map((i) => <ScheduleRow key={i.id} item={i} tone="plain" onStatus={(s) => post('admin.operations.schedule.status', ['schedule', i.id], { status: s })} L={L} />)}
                        </Block>
                        {p.contacts.length > 0 && (
                            <Block title={t('جهات الاتصال')}>
                                {p.contacts.map((c, i) => (
                                    <div key={i} className="flex items-center justify-between gap-3 py-2 text-[14px]">
                                        <span><span className="text-[12px] text-[#6b7280]">{c.label}</span><br />{c.name}</span>
                                        {c.phone && <a href={`tel:${c.phone}`} className="inline-flex h-11 items-center gap-1 rounded-lg bg-[#111] px-4 text-white" dir="ltr"><Phone className="size-4" />{c.phone}</a>}
                                    </div>
                                ))}
                            </Block>
                        )}
                    </>
                )}

                {section === 'crew' && (
                    <Block
                        title={t('الفريق — متوقَّع :e · حضر :i · غائب :m', { e: String(p.attendance.expected), i: String(p.attendance.checked_in), m: String(p.attendance.missing) })}
                        action={(p.may.crew || p.may.status) && o.active ? <Button size="lg" variant="outline" onClick={() => setAlerting(true)}><Megaphone />{t('تنبيه الفريق')}</Button> : null}
                    >
                        {p.crew.length === 0 && <Empty>{t('لا فريق')}</Empty>}
                        {p.crew.map((c) => (
                            <div key={c.id} className="flex items-center gap-3 py-2">
                                <span className={'rounded-full px-2 py-0.5 text-[11px] ' + crewTone(c.status)}>{label(L, 'crew_status', c.status)}</span>
                                <span className="min-w-0 flex-1 text-[14px]">
                                    <span className="font-medium">{c.name}</span>
                                    <span className="block text-[11px] text-[#6b7280]">{label(L, 'role', c.role)}{c.checked_in_at ? ` · ${c.checked_in_at}` : ''}{c.checked_out_at ? ` → ${c.checked_out_at}` : ''}</span>
                                </span>
                                {c.phone && <a href={`tel:${c.phone}`} className="inline-flex size-11 items-center justify-center rounded-lg bg-[#f4f4f5]" aria-label={c.phone}><Phone className="size-4" /></a>}
                                {(c.status === 'planned' || c.status === 'confirmed' || c.status === 'no_show') && o.active && (
                                    <Button size="lg" variant="success" onClick={() => post('admin.operations.crew.checkIn', ['crew', c.id])}><LogIn />{t('حضر')}</Button>
                                )}
                                {c.status === 'checked_in' && o.active && (
                                    <Button size="lg" variant="outline" onClick={() => post('admin.operations.crew.checkOut', ['crew', c.id])}><LogOut />{t('انصرف')}</Button>
                                )}
                            </div>
                        ))}
                    </Block>
                )}

                {section === 'equipment' && (
                    <>
                        {p.equipment.gaps.length > 0 && (
                            <Block title={t('ينقص')}>
                                {p.equipment.gaps.map((g, i) => (
                                    <div key={i} className={'py-1.5 text-[14px] ' + (g.critical ? 'text-[#b91c1c]' : 'text-[#b45309]')}><AlertTriangle className="me-1 inline size-4" />{g.shortfall} × {g.description}</div>
                                ))}
                            </Block>
                        )}
                        <Block title={t('المعدّات — :e متوقَّعة · :o في الميدان · :r عادت', { e: String(p.equipment.expected), o: String(p.equipment.checked_out), r: String(p.equipment.returned) })}>
                            {p.equipment.rows.length === 0 && <Empty>{t('لا قطع محجوزة')}</Empty>}
                            {p.equipment.rows.map((r) => (
                                <div key={r.id} className="flex items-center gap-3 py-2">
                                    <span className={'rounded-full px-2 py-0.5 text-[11px] ' + reservationTone(r.status)}>{t(RESERVATION_LABEL[r.status] ?? r.status)}</span>
                                    <span className="min-w-0 flex-1 text-[14px]">
                                        <span className="font-mono text-[12px] text-[#6b7280]">{r.asset_code}</span> <span className="font-medium">{r.asset_name}</span>
                                        {r.asset_status && ['damaged', 'lost', 'maintenance', 'retired'].includes(r.asset_status) && <span className="ms-1 rounded-full bg-[#fef2f2] px-1.5 text-[10px] text-[#b91c1c]">{label(L, 'asset_status', r.asset_status)}</span>}
                                        {r.custodian && <span className="block text-[11px] text-[#6b7280]">{r.custodian}</span>}
                                    </span>
                                    {r.status === 'reserved' && p.may.checkout && o.active && <Button size="lg" variant="outline" onClick={() => post('admin.operations.reservations.checkout', ['reservations', r.id])}><ArrowRight />{t('تسليم')}</Button>}
                                    {r.status === 'checked_out' && p.may.return && <Button size="lg" onClick={() => { setReturning(r); setReturnForm({ condition: 'good', missing_accessories: '', damage_notes: '' }); }}><Check />{t('ردّ')}</Button>}
                                </div>
                            ))}
                        </Block>
                    </>
                )}

                {section === 'checklist' && (
                    <>
                        <Block title={t('التقدّم')}>
                            {p.checklist.progress.map((c) => (
                                <div key={c.id} className="py-2">
                                    <div className="flex justify-between text-[13px]"><span>{c.name} <span className="text-[11px] text-[#6b7280]">({label(L, 'phase', c.phase)})</span></span><span className="tabular-nums">{c.done + c.waived}/{c.total}</span></div>
                                    <div className="mt-1 h-2 overflow-hidden rounded-full bg-[#f4f4f5]"><div className={'h-full ' + (c.required_pending > 0 ? 'bg-[#d97706]' : 'bg-[#059669]')} style={{ width: `${c.total ? ((c.done + c.waived) / c.total) * 100 : 0}%` }} /></div>
                                </div>
                            ))}
                        </Block>
                        <Block title={t('الإلزاميّ الناقص')}>
                            {p.checklist.critical.length === 0 && <Empty>{t('كلّ الإلزاميّ أُنجز')}</Empty>}
                            {p.checklist.critical.map((i) => (
                                <div key={i.id} className="flex items-center gap-3 py-2">
                                    <span className="min-w-0 flex-1 text-[14px]">
                                        {i.title}
                                        <span className="block text-[11px] text-[#6b7280]">{i.checklist}{i.status === 'blocked' ? ` · ${t(CHECKLIST_STATUS_LABEL.blocked)}` : ''}</span>
                                    </span>
                                    {o.active && <Button size="lg" variant="success" onClick={() => post('admin.operations.checklistItems.complete', ['checklist-items', i.id])}><Check />{t('تمّ')}</Button>}
                                </div>
                            ))}
                        </Block>
                    </>
                )}

                {section === 'incidents' && (
                    <Block title={t('الحوادث المفتوحة')} action={p.may.incidents ? <Button size="lg" variant="danger" onClick={() => setReporting(true)}><AlertTriangle />{t('حادث')}</Button> : null}>
                        {p.incidents.length === 0 && <Empty>{t('لا حوادث مفتوحة')}</Empty>}
                        {p.incidents.map((i) => (
                            <div key={i.id} className="flex items-center gap-3 py-2">
                                <span className={'rounded-full px-2 py-0.5 text-[11px] ' + severityTone(i.severity)}>{label(L, 'severity', i.severity)}</span>
                                <span className="min-w-0 flex-1 text-[14px]">{i.title}<span className="block text-[11px] text-[#6b7280]">{i.occurred_at}</span></span>
                            </div>
                        ))}
                    </Block>
                )}
            </main>

            {/* ─ إضافةُ حادثٍ سريعة — دائمًا في المتناول ─ */}
            {p.may.incidents && section !== 'incidents' && (
                <button type="button" onClick={() => setReporting(true)} className="fixed bottom-4 end-4 z-10 inline-flex h-14 items-center gap-2 rounded-full bg-[#dc2626] px-5 text-[15px] font-semibold text-white shadow-lg">
                    <AlertTriangle className="size-5" />
                    {t('حادث')}
                </button>
            )}

            <Dialog open={reporting} onOpenChange={setReporting}>
                <DialogContent>
                    <DialogHeader><DialogTitle>{t('تسجيل حادث')}</DialogTitle></DialogHeader>
                    <div className="space-y-3 px-5 pb-5">
                        <Field label="ماذا حدث" required><Input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} autoFocus /></Field>
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="التصنيف"><Select value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} options={Object.entries(L.incident_category ?? {}).map(([v, l]) => ({ value: v, label: l }))} /></Field>
                            <Field label="الشدّة"><Select value={form.severity} onChange={(e) => setForm({ ...form, severity: e.target.value })} options={Object.entries(L.severity ?? {}).map(([v, l]) => ({ value: v, label: l }))} /></Field>
                        </div>
                        <Field label="تفاصيل"><Textarea rows={2} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} /></Field>
                    </div>
                    <DialogFooter>
                        <Button size="lg" disabled={form.title.trim() === ''} onClick={() => router.post(route('admin.operations.incidents.store', o.id), form, { preserveScroll: true, onSuccess: () => { setReporting(false); setForm({ title: '', category: 'equipment', severity: 'medium', description: '' }); setSection('incidents'); } })}>{t('تسجيل')}</Button>
                        <Button size="lg" variant="outline" onClick={() => setReporting(false)}>{t('إلغاء')}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={alerting} onOpenChange={setAlerting}>
                <DialogContent className="max-w-md">
                    <DialogHeader><DialogTitle>{t('تنبيه الفريق')}</DialogTitle></DialogHeader>
                    <div className="space-y-3 px-5 pb-5">
                        <Field label="النصّ" required hint="يصل جرسًا لكلّ موظّفٍ معيَّن بحساب — وبريدًا إن كانت القناة موصولة"><Textarea rows={3} value={alertText} onChange={(e) => setAlertText(e.target.value)} autoFocus /></Field>
                    </div>
                    <DialogFooter>
                        <Button size="lg" disabled={alertText.trim() === ''} onClick={() => router.post(route('admin.operations.crew.alert', o.id), { message: alertText }, { preserveScroll: true, onSuccess: () => { setAlerting(false); setAlertText(''); } })}>{t('إرسال')}</Button>
                        <Button size="lg" variant="outline" onClick={() => setAlerting(false)}>{t('إلغاء')}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={returning !== null} onOpenChange={(v) => !v && setReturning(null)}>
                <DialogContent className="max-w-md">
                    <DialogHeader><DialogTitle>{t('ردّ :code', { code: returning?.asset_code ?? '' })}</DialogTitle></DialogHeader>
                    <div className="space-y-3 px-5 pb-5">
                        <Field label="الحال عند العودة" required>
                            <Select value={returnForm.condition} onChange={(e) => setReturnForm({ ...returnForm, condition: e.target.value })} options={[...Object.entries(L.condition ?? {}).map(([v, l]) => ({ value: v, label: l })), { value: 'lost', label: t('مفقودة') }]} />
                        </Field>
                        <Field label="ملحقات ناقصة"><Input value={returnForm.missing_accessories} onChange={(e) => setReturnForm({ ...returnForm, missing_accessories: e.target.value })} /></Field>
                        <Field label="وصف التلف"><Textarea rows={2} value={returnForm.damage_notes} onChange={(e) => setReturnForm({ ...returnForm, damage_notes: e.target.value })} /></Field>
                    </div>
                    <DialogFooter>
                        <Button size="lg" onClick={() => returning && router.post(route('admin.operations.reservations.return', [o.id, returning.id]), returnForm, { preserveScroll: true, onSuccess: () => setReturning(null) })}>{t('تسجيل الردّ')}</Button>
                        <Button size="lg" variant="outline" onClick={() => setReturning(null)}>{t('إلغاء')}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <footer className="mt-6 px-4 text-center text-[11px] text-[#9ca3af]">
                {auth?.user?.name} · {o.code}{o.project ? ` · ${o.project.code}` : ''}
            </footer>
        </div>
    );
}

function Block({ title, action, children }: { title: string; action?: ReactNode; children: ReactNode }) {
    return (
        <section className="rounded-xl bg-white p-3 shadow-sm">
            <div className="mb-1 flex items-center justify-between gap-2">
                <h2 className="text-[13px] font-semibold text-[#374151]">{title}</h2>
                {action}
            </div>
            <div className="divide-y divide-[#f4f4f5]">{children}</div>
        </section>
    );
}

function Empty({ children }: { children: ReactNode }) {
    return <p className="py-3 text-center text-[13px] text-[#9ca3af]">{children}</p>;
}

function ScheduleRow({ item: i, tone, onStatus, L }: { item: ScheduleItem; tone: 'overdue' | 'current' | 'next' | 'plain'; onStatus: (s: string) => void; L: Labels }) {
    const t = useTranslate();
    const settled = i.status === 'completed' || i.status === 'skipped';

    return (
        <div className={'flex items-center gap-3 py-2 ' + (tone === 'overdue' ? 'text-[#b91c1c]' : '')}>
            <span className="w-[44px] shrink-0 text-[13px] tabular-nums text-[#6b7280]" dir="ltr">{clock(i.starts_at)}</span>
            <span className={'min-w-0 flex-1 text-[14px] ' + (settled ? 'text-[#9ca3af] line-through' : '')}>
                {i.title}
                <span className="block text-[11px] text-[#6b7280] no-underline">{label(L, 'schedule_type', i.type)}{i.responsible ? ` · ${i.responsible}` : ''}{tone === 'overdue' ? ` · ${t('فات موعده')}` : ''}</span>
            </span>
            {!settled && (
                <div className="flex gap-1">
                    {i.status !== 'in_progress' && <Button size="icon" variant="outline" onClick={() => onStatus('in_progress')} aria-label={t('بدء')}><Play /></Button>}
                    <Button size="icon" variant="success" onClick={() => onStatus('completed')} aria-label={t('تمّ')}><Check /></Button>
                    <Button size="icon" variant="ghost" onClick={() => onStatus('blocked')} aria-label={t('معطَّل')}><XCircle /></Button>
                </div>
            )}
        </div>
    );
}

/** «٤٥ د» أو «٣ س ١٠ د» أو «٣ أيام» */
function duration(minutes: number, t: (key: string, values?: Record<string, string>) => string): string {
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    if (h >= 48) return t(':n يوم', { n: String(Math.floor(h / 24)) });

    return h > 0 ? t(':h س :m د', { h: String(h), m: String(m) }) : t(':m د', { m: String(m) });
}
