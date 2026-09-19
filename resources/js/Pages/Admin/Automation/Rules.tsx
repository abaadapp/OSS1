import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { History, Pause, Pencil, Play, Plus, RefreshCw, Trash2, Zap } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { useTranslate } from '@/lib/i18n';
import { CHANNEL_LABEL, DAY_LABEL, KIND_LABEL, MODE_LABEL, RULE_MODE_LABEL, modeTone, offsetLabel } from '@/lib/automation';

interface Step {
    offset: number;
    audience: string;
}

interface RuleRow {
    id: number;
    key: string;
    name: string;
    kind: string;
    trigger: string;
    trigger_label: string;
    subject_type: string | null;
    audiences: string[];
    enabled: boolean;
    mode: string;
    effective_mode: string;
    channel: string;
    template_key: string | null;
    template_name: string | null;
    steps: Step[];
    send_time: string | null;
    cooldown_hours: number;
    business_hours: boolean;
    language: string;
    recipients: number[];
    retry_once: boolean;
    last_run_at: string | null;
    last_action_at: string | null;
    failed: number;
    next_at: string | null;
    templates: { key: string; name: string; channels: string[] }[];
}

interface Settings {
    mode: string;
    paused: boolean;
    timezone: string;
    days: number[];
    start: string;
    end: string;
    now: string;
    open_now: boolean;
}

interface Props {
    rules: RuleRow[];
    settings: Settings;
    channels: { email: boolean; whatsapp: boolean };
    audiences: Record<string, string>;
    users: { id: number; name: string }[];
    timezones: string[];
    may: { manage: boolean; pause: boolean };
}

/**
 * قواعدُ الأتمتة — ما يُتابَع وحده، وكيف، وإلى من.
 *
 * ═══ الأمانُ أوّلًا في الشاشة ═══
 *
 * مفتاحُ الإيقاف في الأعلى، والوضعُ بجانبه، وما يبلغ العميلَ في مجموعةٍ
 * وحدَه موسومًا. ولا يُحرَّر JSON: الخطواتُ صفوفٌ من «بعد كم يومًا» و«إلى
 * من»، والقالبُ من قائمة، والجمهورُ من قائمة.
 */
export default function AutomationRules({ rules, settings, channels, audiences, users, timezones, may }: Props) {
    const t = useTranslate();
    const { errors } = usePage().props as { errors?: Record<string, string> };
    const [editing, setEditing] = useState<RuleRow | null>(null);
    const [hours, setHours] = useState(false);

    const internal = rules.filter((r) => r.kind === 'internal');
    const external = rules.filter((r) => r.kind === 'external');

    const setMode = (mode: string) => router.post(route('admin.automation.settings'), { mode }, { preserveScroll: true });

    return (
        <AdminLayout title={t('الأتمتة')}>
            <PageHeader
                title={t('الأتمتة')}
                subtitle={t('ما يتابعه النظام وحده — ويذكّر ولا يقرّر.')}
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        <Button variant="outline" asChild>
                            <SmartLink routeName="admin.automation.history" href={route('admin.automation.history')}>
                                <History />
                                {t('السجلّ')}
                            </SmartLink>
                        </Button>
                        {may.manage && (
                            <Button variant="outline" onClick={() => router.post(route('admin.automation.evaluate'), {}, { preserveScroll: true })}>
                                <RefreshCw />
                                {t('قيّم الآن')}
                            </Button>
                        )}
                    </div>
                }
            />

            {errors?.settings && <div className="mb-4 rounded-lg bg-[#fef2f2] px-4 py-3 text-[13px] text-[#b91c1c]">{errors.settings}</div>}
            {errors?.rule && <div className="mb-4 rounded-lg bg-[#fef2f2] px-4 py-3 text-[13px] text-[#b91c1c]">{errors.rule}</div>}

            {/* ─────────── مفتاحُ الأمان والوضع ─────────── */}
            <Card className={'mb-4 p-4 ' + (settings.paused ? 'border-[#fecaca] bg-[#fef2f2]' : '')}>
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex flex-wrap items-center gap-3">
                        <span className={'flex size-10 items-center justify-center rounded-lg text-white ' + (settings.paused ? 'bg-[#dc2626]' : settings.mode === 'live' ? 'bg-[#059669]' : 'bg-[#6b7280]')}>
                            {settings.paused ? <Pause className="size-5" /> : <Zap className="size-5" />}
                        </span>
                        <div>
                            <div className="font-bold text-[#111]">
                                {settings.paused ? t('الأتمتة موقوفة — لا يخرج شيء') : t(MODE_LABEL[settings.mode] ?? settings.mode)}
                            </div>
                            <div className="text-[12px] text-[#6b7280]">
                                {t('توقيت الشركة')}: <span dir="ltr">{settings.timezone}</span> · {settings.now} ·{' '}
                                {t('الدوام')}: {settings.days.map((d) => t(DAY_LABEL[d])).join('، ')} <span dir="ltr">{settings.start}–{settings.end}</span>{' '}
                                {settings.open_now ? <span className="text-[#047857]">({t('مفتوح الآن')})</span> : <span className="text-[#9ca3af]">({t('خارج الدوام')})</span>}
                            </div>
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        {may.manage && (
                            <Select
                                value={settings.mode}
                                onChange={(e) => setMode(e.target.value)}
                                options={['off', 'observe', 'live'].map((m) => ({ label: t(MODE_LABEL[m]), value: m }))}
                                className="min-w-[220px]"
                            />
                        )}
                        {may.manage && (
                            <Button variant="outline" onClick={() => setHours(true)}>
                                {t('ساعات العمل')}
                            </Button>
                        )}
                        {may.pause && (
                            <Button
                                variant={settings.paused ? 'success' : 'danger'}
                                onClick={() => router.post(route('admin.automation.pause'), { paused: !settings.paused }, { preserveScroll: true })}
                            >
                                {settings.paused ? <Play /> : <Pause />}
                                {settings.paused ? t('استئناف الأتمتة') : t('إيقاف الأتمتة')}
                            </Button>
                        )}
                    </div>
                </div>
                {settings.mode === 'observe' && !settings.paused && (
                    <p className="mt-3 text-[12px] text-[#1d4ed8]">{t('وضع المراقبة: يُسجَّل ما كان سيحدث في السجلّ ولا يُرسَل شيء — راجعه ثمّ شغّل.')}</p>
                )}
                {settings.mode === 'off' && (
                    <p className="mt-3 text-[12px] text-[#6b7280]">{t('الأتمتة مطفأة: لا تقييم ولا تنفيذ. ابدأ بالمراقبة.')}</p>
                )}
            </Card>

            <RuleGroup title="قواعد داخلية" hint="تنبيهات للموظّفين — لا تخرج من النظام" rows={internal} onEdit={setEditing} may={may} />
            <RuleGroup title="قواعد تبلغ العميل" hint="موقوفة افتراضًا — فعّلها بيدك بعد ضبط القالب والقناة" rows={external} onEdit={setEditing} may={may} external />

            {editing && (
                <Editor rule={editing} channels={channels} audiences={audiences} users={users} manage={may.manage} onClose={() => setEditing(null)} />
            )}
            {hours && <HoursDialog settings={settings} timezones={timezones} onClose={() => setHours(false)} />}
        </AdminLayout>
    );
}

function RuleGroup({ title, hint, rows, onEdit, may, external }: { title: string; hint: string; rows: RuleRow[]; onEdit: (r: RuleRow) => void; may: Props['may']; external?: boolean }) {
    const t = useTranslate();

    return (
        <Card className={'mb-4 p-4 ' + (external ? 'border-[#fde68a]' : '')}>
            <div className="mb-2">
                <h2 className="font-bold text-[#111]">{t(title)}</h2>
                <p className="text-[12px] text-[#9ca3af]">{t(hint)}</p>
            </div>
            <ul className="divide-y divide-[#f3f4f6]">
                {rows.map((r) => (
                    <li key={r.id} className="flex items-start justify-between gap-3 py-2.5">
                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <button
                                    type="button"
                                    onClick={() => may.manage && router.put(route('admin.automation.rules.update', r.id), { enabled: !r.enabled }, { preserveScroll: true })}
                                    className={'relative h-5 w-9 shrink-0 rounded-full transition ' + (r.enabled ? 'bg-[#059669]' : 'bg-[#d1d5db]') + (may.manage ? '' : ' cursor-default')}
                                    aria-label={r.enabled ? t('إيقاف القاعدة') : t('تفعيل القاعدة')}
                                >
                                    <span className={'absolute top-0.5 size-4 rounded-full bg-white transition ' + (r.enabled ? 'start-4' : 'start-0.5')} />
                                </button>
                                <span className={'font-medium ' + (r.enabled ? 'text-[#111]' : 'text-[#9ca3af]')}>{r.name}</span>
                                <span className={'rounded-full px-2 py-0.5 text-[11px] ' + modeTone(r.enabled ? r.effective_mode : 'off')}>
                                    {t(r.enabled ? (RULE_MODE_LABEL[r.effective_mode] ?? MODE_LABEL[r.effective_mode] ?? r.effective_mode) : 'موقوفة')}
                                </span>
                                <span className="rounded-full bg-[#f3f4f6] px-2 py-0.5 text-[11px] text-[#6b7280]">{t(KIND_LABEL[r.kind] ?? r.kind)}</span>
                                {r.failed > 0 && <span className="rounded-full bg-[#fef2f2] px-2 py-0.5 text-[11px] text-[#b91c1c]">{t(':n فشل', { n: r.failed })}</span>}
                            </div>
                            <div className="text-[12px] text-[#6b7280]">
                                {r.trigger_label} · {t(CHANNEL_LABEL[r.channel] ?? r.channel)}
                                {r.template_name ? ` · ${r.template_name}` : ''}
                                {' · '}
                                {r.steps.map((s) => `${t(offsetLabel(s.offset))} → ${t(audienceLabelOf(s.audience))}`).join(' · ')}
                            </div>
                            <div className="text-[11px] text-[#9ca3af]" dir="ltr">
                                {r.last_action_at ? `${t('آخر تشغيل')}: ${r.last_action_at}` : t('لم تُشغَّل بعد')}
                                {r.next_at ? ` · ${t('القادم')}: ${r.next_at}` : ''}
                            </div>
                        </div>
                        <Button size="sm" variant="outline" onClick={() => onEdit(r)}>
                            <Pencil />
                            {may.manage ? t('ضبط') : t('عرض')}
                        </Button>
                    </li>
                ))}
            </ul>
        </Card>
    );
}

const AUDIENCE_FALLBACK: Record<string, string> = {
    customer: 'العميل',
    assignee: 'المسؤول عن المهمّة',
    owner: 'صاحب المشروع',
    creator: 'من كتب المستند',
    initiator: 'من أرسل الرسالة',
    finance: 'المالية',
    management: 'الإدارة',
    custom: 'قائمة محدَّدة',
};

function audienceLabelOf(a: string): string {
    return AUDIENCE_FALLBACK[a] ?? a;
}

function Editor({ rule: r, channels, audiences, users, manage, onClose }: {
    rule: RuleRow;
    channels: Props['channels'];
    audiences: Record<string, string>;
    users: { id: number; name: string }[];
    manage: boolean;
    onClose: () => void;
}) {
    const t = useTranslate();
    const [name, setName] = useState(r.name);
    const [mode, setMode] = useState(r.mode);
    const [channel, setChannel] = useState(r.channel);
    const [template, setTemplate] = useState(r.template_key ?? '');
    const [steps, setSteps] = useState<Step[]>(r.steps);
    const [sendTime, setSendTime] = useState(r.send_time ?? '');
    const [cooldown, setCooldown] = useState(String(r.cooldown_hours));
    const [businessHours, setBusinessHours] = useState(r.business_hours);
    const [language, setLanguage] = useState(r.language);
    const [recipients, setRecipients] = useState<number[]>(r.recipients);
    const [retryOnce, setRetryOnce] = useState(r.retry_once);
    const [saving, setSaving] = useState(false);

    const external = steps.some((s) => s.audience === 'customer');
    const usesCustom = steps.some((s) => s.audience === 'custom');
    const channelOptions = (r.subject_type ? ['internal', 'email', 'whatsapp'] : ['internal', 'email']).map((c) => ({
        label: t(CHANNEL_LABEL[c]) + (c !== 'internal' && !channels[c as 'email' | 'whatsapp'] ? ` — ${t('غير موصول')}` : ''),
        value: c,
    }));

    const save = () => {
        setSaving(true);
        router.put(
            route('admin.automation.rules.update', r.id),
            {
                name,
                mode,
                channel,
                template_key: template || null,
                steps: steps.map((s) => ({ offset: s.offset, audience: s.audience })),
                send_time: sendTime || null,
                cooldown_hours: Number(cooldown),
                business_hours: businessHours,
                language,
                recipients,
                retry_once: retryOnce,
            },
            { preserveScroll: true, onSuccess: onClose, onFinish: () => setSaving(false) },
        );
    };

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{r.name}</DialogTitle>
                    <DialogDescription>
                        {r.trigger_label} · <span dir="ltr">{r.key}</span>
                        {external ? ` · ${t('تبلغ العميل')}` : ''}
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-4 px-5 md:grid-cols-2">
                    <div className="space-y-3">
                        <Field label="الاسم">
                            <Input value={name} onChange={(e) => setName(e.target.value)} disabled={!manage} />
                        </Field>
                        <Field label="وضع القاعدة" hint="الفعليّ هو الأضيق بين وضع الشركة ووضع القاعدة">
                            <Select value={mode} onChange={(e) => setMode(e.target.value)} disabled={!manage} options={['observe', 'live'].map((m) => ({ label: t(RULE_MODE_LABEL[m]), value: m }))} />
                        </Field>
                        <Field label="القناة" hint={external ? 'قناة الرسالة إلى العميل' : 'داخل النظام دائمًا — والبريد يُرسل نسخةً للموظّف'}>
                            <Select value={channel} onChange={(e) => setChannel(e.target.value)} disabled={!manage} options={channelOptions} />
                        </Field>
                        {external && (
                            <Field label="القالب" hint="من كتالوج المراسلات — ولواتساب يلزم اسم معتمَد في «المراسلات ‹ القوالب»" required>
                                <Select
                                    value={template}
                                    onChange={(e) => setTemplate(e.target.value)}
                                    disabled={!manage}
                                    placeholder="اختر قالبًا"
                                    options={r.templates.filter((x) => channel === 'internal' || x.channels.includes(channel)).map((x) => ({ label: x.name, value: x.key }))}
                                />
                            </Field>
                        )}
                        <Field label="اللغة">
                            <Select value={language} onChange={(e) => setLanguage(e.target.value)} disabled={!manage} options={[{ label: t('العربية'), value: 'ar' }, { label: t('الإنجليزية'), value: 'en' }]} />
                        </Field>
                        <Field label="ساعة الإرسال" hint="فارغة = بداية الدوام">
                            <Input dir="ltr" placeholder="09:00" value={sendTime} onChange={(e) => setSendTime(e.target.value)} disabled={!manage} />
                        </Field>
                        <Field label="فترة الهدوء (ساعات)" hint="لا يُكرَّر المثل إلى الجمهور نفسه عن المستند نفسه خلالها">
                            <Input dir="ltr" type="number" min={1} max={720} value={cooldown} onChange={(e) => setCooldown(e.target.value)} disabled={!manage} />
                        </Field>
                        <label className="flex items-center gap-2 text-[13px]">
                            <input type="checkbox" checked={businessHours} onChange={(e) => setBusinessHours(e.target.checked)} disabled={!manage} />
                            {t('رسائل العميل داخل ساعات العمل فقط')}
                        </label>
                        {r.trigger === 'communication_failed' && (
                            <label className="flex items-center gap-2 text-[13px]">
                                <input type="checkbox" checked={retryOnce} onChange={(e) => setRetryOnce(e.target.checked)} disabled={!manage} />
                                {t('إعادة إرسال مرّة واحدة تلقائيًّا (وإلّا تنبيه فقط)')}
                            </label>
                        )}
                    </div>

                    <div className="space-y-3">
                        <Field label="الخطوات" hint="بعد كم يومًا من الموعد (سالب = قبله) وإلى من">
                            <ol className="space-y-2">
                                {steps.map((s, i) => (
                                    <li key={i} className="flex items-center gap-2">
                                        <Input
                                            dir="ltr"
                                            type="number"
                                            min={-60}
                                            max={60}
                                            className="w-20"
                                            value={s.offset}
                                            disabled={!manage}
                                            onChange={(e) => setSteps(steps.map((x, j) => (j === i ? { ...x, offset: Number(e.target.value) } : x)))}
                                        />
                                        <span className="text-[12px] text-[#9ca3af]">{t('يوم')}</span>
                                        <Select
                                            value={s.audience}
                                            disabled={!manage}
                                            onChange={(e) => setSteps(steps.map((x, j) => (j === i ? { ...x, audience: e.target.value } : x)))}
                                            options={r.audiences.map((a) => ({ label: audiences[a] ?? audienceLabelOf(a), value: a }))}
                                            className="flex-1"
                                        />
                                        {manage && (
                                            <button type="button" className="text-[#9ca3af] hover:text-[#b91c1c]" onClick={() => setSteps(steps.filter((_, j) => j !== i))} aria-label={t('حذف')}>
                                                <Trash2 className="size-4" />
                                            </button>
                                        )}
                                    </li>
                                ))}
                            </ol>
                            {manage && steps.length < 8 && (
                                <Button size="sm" variant="ghost" className="mt-2" onClick={() => setSteps([...steps, { offset: 0, audience: r.audiences[0] }])}>
                                    <Plus />
                                    {t('خطوة')}
                                </Button>
                            )}
                        </Field>

                        {usesCustom && (
                            <Field label="القائمة المحدَّدة" hint="الموظّفون الذين تبلغهم خطوات «قائمة محدَّدة»">
                                <div className="max-h-40 space-y-1 overflow-auto rounded-lg border border-[#e5e7eb] p-2 text-[13px]">
                                    {users.map((u) => (
                                        <label key={u.id} className="flex items-center gap-2">
                                            <input
                                                type="checkbox"
                                                checked={recipients.includes(u.id)}
                                                disabled={!manage}
                                                onChange={(e) => setRecipients(e.target.checked ? [...recipients, u.id] : recipients.filter((x) => x !== u.id))}
                                            />
                                            {u.name}
                                        </label>
                                    ))}
                                </div>
                            </Field>
                        )}

                        {external && (
                            <div className="rounded-lg bg-[#fffbeb] px-3 py-2 text-[12px] text-[#92400e]">
                                {t('هذه القاعدة تبلغ العميل: لا تخرج رسالة إلّا والشركة على «تشغيل» والقاعدة مفعَّلة وعلى «تشغيل» والقالب صالح والمستلم صالح — وتُقيَّد كلّها في المراسلات.')}
                            </div>
                        )}
                    </div>
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        {manage ? t('تراجع') : t('إغلاق')}
                    </Button>
                    {manage && (
                        <Button onClick={save} loading={saving} disabled={saving || steps.length === 0 || (external && template === '')}>
                            {t('حفظ')}
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function HoursDialog({ settings, timezones, onClose }: { settings: Settings; timezones: string[]; onClose: () => void }) {
    const t = useTranslate();
    const [timezone, setTimezone] = useState(settings.timezone);
    const [days, setDays] = useState<number[]>(settings.days);
    const [start, setStart] = useState(settings.start);
    const [end, setEnd] = useState(settings.end);
    const [saving, setSaving] = useState(false);

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>{t('ساعات العمل')}</DialogTitle>
                    <DialogDescription>{t('رسائل العميل لا تخرج خارجها — تُؤجَّل إلى أوّل ساعة مفتوحة. التنبيهات الداخلية لا تنتظر.')}</DialogDescription>
                </DialogHeader>
                <div className="space-y-3 px-5">
                    <Field label="المنطقة الزمنية">
                        <Select value={timezone} onChange={(e) => setTimezone(e.target.value)} options={timezones.map((z) => ({ label: z, value: z }))} />
                    </Field>
                    <Field label="أيّام العمل">
                        <div className="flex flex-wrap gap-2">
                            {[0, 1, 2, 3, 4, 5, 6].map((d) => (
                                <label key={d} className="flex items-center gap-1 text-[13px]">
                                    <input type="checkbox" checked={days.includes(d)} onChange={(e) => setDays(e.target.checked ? [...days, d].sort() : days.filter((x) => x !== d))} />
                                    {t(DAY_LABEL[d])}
                                </label>
                            ))}
                        </div>
                    </Field>
                    <div className="grid grid-cols-2 gap-3">
                        <Field label="من">
                            <Input dir="ltr" value={start} onChange={(e) => setStart(e.target.value)} placeholder="09:00" />
                        </Field>
                        <Field label="إلى">
                            <Input dir="ltr" value={end} onChange={(e) => setEnd(e.target.value)} placeholder="17:00" />
                        </Field>
                    </div>
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        {t('تراجع')}
                    </Button>
                    <Button
                        loading={saving}
                        disabled={saving || days.length === 0}
                        onClick={() => {
                            setSaving(true);
                            router.post(route('admin.automation.settings'), { timezone, days, start, end }, { preserveScroll: true, onSuccess: onClose, onFinish: () => setSaving(false) });
                        }}
                    >
                        {t('حفظ')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
