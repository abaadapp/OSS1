import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { router, usePage } from '@inertiajs/react';
import { AlertTriangle, Check, Eye, FilePlus2, FileText, Mail, MessageCircle, RefreshCw, Send, ShieldCheck, Undo2 } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import BackLink from '@/Components/BackLink';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import CopyButton from '@/Components/CopyButton';
import CommunicationHistory from '@/Components/CommunicationHistory';
import SendMessageDialog from '@/Components/SendMessageDialog';
import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input, Textarea } from '@/Components/ui/input';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { SCOPE_STATE_LABEL, scopeTone } from '@/lib/scopes';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

interface SnapshotItem {
    description: string;
    unit: string;
    quantity: string;
    unit_price: string | null;
    line_total: string | null;
}

interface Current {
    id: number;
    number: string;
    revision: number;
    status: string;
    state: string;
    editable: boolean;
    customer_name: string | null;
    contact_person: string | null;
    contact_phone: string | null;
    contact_email: string | null;
    project_name: string;
    event_type: string | null;
    location: string | null;
    starts_at: string | null;
    ends_at: string | null;
    schedule_notes: string | null;
    description: string | null;
    inclusions: string | null;
    exclusions: string | null;
    company_responsibilities: string | null;
    client_responsibilities: string | null;
    terms: string | null;
    show_value: boolean;
    snapshot: {
        quotation: { label: string; hash: string; decided_at: string } | null;
        change_orders: string[];
        items: SnapshotItem[];
        requirements: { title: string; details: string }[];
        starts_at: string;
        ends_at: string;
        value: string | null;
    };
    content_hash: string | null;
    ready_at: string | null;
    sent_at: string | null;
    sent_by: string | null;
    first_viewed_at: string | null;
    last_viewed_at: string | null;
    view_count: number;
    decided_at: string | null;
    decided_by_name: string | null;
    decline_reason: string | null;
    url: string | null;
    stale: boolean;
    approval: {
        decision: string;
        signer_name: string;
        signer_title: string | null;
        signer_email: string | null;
        signer_phone: string | null;
        decided_at: string | null;
        ip: string | null;
        user_agent: string | null;
        consent_text: string | null;
        decline_reason: string | null;
        content_hash: string;
        verified: boolean;
    } | null;
}

interface Props {
    project: { id: number; code: string; name: string; customer: string; status: string; open: boolean; baselined: boolean; required: boolean };
    current: Current | null;
    revisions: { id: number; number: string; revision: number; status: string; state: string; sent_at: string | null; decided_at: string | null; decided_by_name: string | null; superseded_at: string | null; url: string | null }[];
    channels: { email: boolean; whatsapp: boolean; whatsapp_template: { ar: boolean; en: boolean } };
    may: { manage: boolean; send: boolean; communications: boolean; templates: boolean; commercial: boolean };
}

type Form = Record<
    | 'customer_name' | 'contact_person' | 'contact_phone' | 'contact_email' | 'project_name' | 'event_type' | 'location'
    | 'starts_at' | 'ends_at' | 'schedule_notes' | 'description' | 'inclusions' | 'exclusions'
    | 'company_responsibilities' | 'client_responsibilities' | 'terms',
    string
> & { show_value: boolean };

const formOf = (c: Current): Form => ({
    customer_name: c.customer_name ?? '',
    contact_person: c.contact_person ?? '',
    contact_phone: c.contact_phone ?? '',
    contact_email: c.contact_email ?? '',
    project_name: c.project_name ?? '',
    event_type: c.event_type ?? '',
    location: c.location ?? '',
    starts_at: c.starts_at ?? '',
    ends_at: c.ends_at ?? '',
    schedule_notes: c.schedule_notes ?? '',
    description: c.description ?? '',
    inclusions: c.inclusions ?? '',
    exclusions: c.exclusions ?? '',
    company_responsibilities: c.company_responsibilities ?? '',
    client_responsibilities: c.client_responsibilities ?? '',
    terms: c.terms ?? '',
    show_value: c.show_value,
});

/**
 * نطاقُ العمل — يُعدّ ويُراجَع ويُرسَل للتوقيع من داخل المشروع.
 *
 * ═══ ما يُكتب وما يُقرأ ═══
 *
 * البنودُ والكميّاتُ والمتطلّباتُ والقيمةُ من النطاق المعتمد (العرض + أوامر
 * التغيير) — تتحدّث مع كلّ حفظٍ للمسودّة ولا تُكتب هنا. وما يُكتب: بياناتُ
 * العميل والفعاليّة، ووصفُ التنفيذ، وما يشمله وما لا يشمله، ومسؤوليّاتُ
 * الطرفين، والشروط.
 *
 * ═══ ومراحلُه ═══
 *
 * مسودّة ← جاهز للإرسال ← أُرسل (يُبصَم ويُجمَّد ويُبنى رابطُه) ← فُتح ←
 * موقَّع. وما وُقّع لا يُعدَّل: التعديلُ نسخةٌ جديدة تُوقَّع من جديد.
 */
export default function ScopeOfWork({ project, current, revisions, channels, may }: Props) {
    const t = useTranslate();
    const { context } = usePage<PageProps>().props;
    const { errors } = usePage().props as { errors?: Record<string, string> };
    const m = (v: string | number | null | undefined) => (v === null || v === undefined ? '—' : money(Number(v), context!.currency));
    const [form, setForm] = useState<Form | null>(current ? formOf(current) : null);
    const [saving, setSaving] = useState(false);
    const [confirmSend, setConfirmSend] = useState(false);
    const [confirmRevision, setConfirmRevision] = useState(false);
    const [dialog, setDialog] = useState<null | 'whatsapp' | 'email'>(null);

    useEffect(() => setForm(current ? formOf(current) : null), [current]);

    const dirty = useMemo(() => current !== null && form !== null && JSON.stringify(form) !== JSON.stringify(formOf(current)), [current, form]);
    const set = (k: keyof Form) => (e: { target: { value: string } }) => form && setForm({ ...form, [k]: e.target.value });
    const post = (name: string, params: number[], data: Record<string, unknown> = {}) => router.post(route(name, params), data as never, { preserveScroll: true });
    const save = () => {
        if (!current || !form) return;
        setSaving(true);
        router.put(route('admin.projects.scope.update', [project.id, current.id]), { ...form, show_value: form.show_value ? '1' : '0' }, { preserveScroll: true, onFinish: () => setSaving(false) });
    };

    const live = current !== null && (current.state === 'sent' || current.state === 'opened');
    const canRevise = current !== null && ['sent', 'opened', 'signed', 'declined'].includes(current.state) && project.open && may.manage;

    return (
        <AdminLayout title={t('نطاق العمل')}>
            <BackLink routeName="admin.projects.show" href={route('admin.projects.show', project.id)} label={project.name} />
            <PageHeader
                title={t('نطاق العمل')}
                subtitle={`${project.code} · ${project.name} · ${project.customer}`}
                actions={
                    current && (
                        <Button variant="outline" asChild>
                            <a href={route('admin.projects.scope.preview', [project.id, current.id])} target="_blank" rel="noopener noreferrer">
                                <Eye />
                                {t('معاينة')}
                            </a>
                        </Button>
                    )
                }
            />

            {errors?.scope && <Notice tone="danger">{errors.scope}</Notice>}
            {errors?.communication && <Notice tone="danger">{errors.communication}</Notice>}
            {!project.required && <Notice tone="info">{t('مشروعٌ مستورَد من التاريخ — لا يُطلب له نطاقٌ موقَّع، ويجوز إعدادُه للتوثيق.')}</Notice>}

            {current === null ? (
                <Card className="p-8 text-center">
                    <FileText className="mx-auto mb-3 size-8 text-[#a1a1aa]" />
                    <h2 className="text-[16px] font-semibold">{t('لم يُعدّ نطاق العمل بعد')}</h2>
                    <p className="mx-auto mt-1 max-w-lg text-[13px] text-[#71717a]">
                        {t('يُبنى من العرض المقبول وأوامر التغيير: البنود والكميات والمتطلبات والقيمة. وتكتب أنت وصف التنفيذ وما يشمله وما لا يشمله ومسؤوليات الطرفين، ثم يُرسل للعميل ليوقّعه من رابطٍ بلا حساب.')}
                    </p>
                    {project.open && may.manage && (
                        <Button className="mt-4" onClick={() => post('admin.projects.scope.store', [project.id])}>
                            <FilePlus2 />
                            {t('إعداد نطاق العمل')}
                        </Button>
                    )}
                </Card>
            ) : (
                <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_340px]">
                    <div className="space-y-4">
                        {/* ─── الحال والخطوة التالية ─── */}
                        <Card className="p-4">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="font-mono text-[13px]" dir="ltr">{current.number}</span>
                                <span className={'rounded-full px-2 py-0.5 text-[11.5px] font-medium ' + scopeTone(current.state)}>{t(SCOPE_STATE_LABEL[current.state] ?? current.state)}</span>
                                <span className="text-[12px] text-[#71717a]">{t('النسخة :n', { n: String(current.revision) })}</span>
                            </div>
                            <Steps state={current.state} />

                            {current.stale && current.editable && <Notice tone="warning">{t('تغيّر النطاق المعتمد منذ آخر حفظ (أمر تغيير قُبل) — احفظ المسودّة لتُقرأ البنود من جديد.')}</Notice>}
                            {current.stale && current.state === 'ready' && <Notice tone="warning">{t('تغيّر النطاق المعتمد منذ المراجعة — أعدها مسودّةً واحفظها ثمّ راجعها قبل الإرسال.')}</Notice>}
                            {current.state === 'declined' && current.decline_reason && <Notice tone="warning">{t('طلب العميل تعديلًا')}: {current.decline_reason}</Notice>}

                            {live && current.url && (
                                <div className="mt-3 rounded-[12px] bg-[#fafafa] p-3">
                                    <div className="mb-1 text-[12px] text-[#71717a]">{t('رابط التوقيع — يُفتح على الهاتف بلا حساب')}</div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <code className="min-w-0 flex-1 truncate rounded-[8px] bg-white px-2 py-1.5 text-[12px]" dir="ltr">{current.url}</code>
                                        <CopyButton text={current.url} label="نسخ الرابط" />
                                    </div>
                                    <div className="mt-2 text-[12px] text-[#71717a]">
                                        {current.sent_at && t('أُرسل في :at', { at: current.sent_at })}
                                        {current.sent_by && ` · ${current.sent_by}`}
                                        {' · '}
                                        {current.first_viewed_at ? t('فتحه العميل أوّل مرّة في :at — :n مرّة', { at: current.first_viewed_at, n: String(current.view_count) }) : t('لم يفتحه العميل بعد')}
                                    </div>
                                </div>
                            )}

                            {current.approval && <Evidence approval={current.approval} />}

                            <div className="mt-3 flex flex-wrap gap-2">
                                {current.editable && may.manage && (
                                    <>
                                        <Button disabled={saving || !dirty} onClick={save}>
                                            <Check />
                                            {t('حفظ المسودّة')}
                                        </Button>
                                        <Button variant={dirty ? 'outline' : 'primary'} disabled={dirty} title={dirty ? t('احفظ التعديلات أولًا') : undefined} onClick={() => post('admin.projects.scope.ready', [project.id, current.id])}>
                                            <ShieldCheck />
                                            {t('جاهز للإرسال')}
                                        </Button>
                                    </>
                                )}
                                {current.state === 'ready' && may.manage && (
                                    <>
                                        <Button onClick={() => setConfirmSend(true)}>
                                            <Send />
                                            {t('إرسال للعميل للتوقيع')}
                                        </Button>
                                        <Button variant="outline" onClick={() => post('admin.projects.scope.draft', [project.id, current.id])}>
                                            <Undo2 />
                                            {t('إعادة للتعديل')}
                                        </Button>
                                    </>
                                )}
                                {live && may.send && (
                                    <>
                                        <Button onClick={() => setDialog('whatsapp')}>
                                            <MessageCircle />
                                            {t('إرسال عبر واتساب')}
                                        </Button>
                                        <Button variant="outline" onClick={() => setDialog('email')}>
                                            <Mail />
                                            {t('إرسال بالبريد')}
                                        </Button>
                                        <Button variant="ghost" onClick={() => setDialog(channels.whatsapp ? 'whatsapp' : 'email')}>
                                            <RefreshCw />
                                            {t('إعادة إرسال')}
                                        </Button>
                                    </>
                                )}
                                {current.url && (
                                    <Button variant="ghost" asChild>
                                        <a href={current.url} target="_blank" rel="noopener noreferrer">
                                            <Eye />
                                            {t('كما يراه العميل')}
                                        </a>
                                    </Button>
                                )}
                                {canRevise && (
                                    <Button variant="outline" onClick={() => setConfirmRevision(true)}>
                                        <FilePlus2 />
                                        {current.state === 'declined' ? t('نسخة معدّلة') : t('نسخة جديدة')}
                                    </Button>
                                )}
                            </div>
                        </Card>

                        {/* ─── المستند ─── */}
                        {current.editable && may.manage && form ? (
                            <Editor form={form} set={set} setForm={setForm} commercial={may.commercial} />
                        ) : (
                            <ReadOnly c={current} />
                        )}

                        <ScopeLines c={current} m={m} commercial={may.commercial} projectId={project.id} />
                    </div>

                    <aside className="space-y-4">
                        <Card className="p-4">
                            <h3 className="mb-2 text-[13px] font-semibold">{t('قنوات الإرسال')}</h3>
                            <ul className="space-y-1.5 text-[12.5px]">
                                <Channel ok={channels.whatsapp} label={channels.whatsapp ? t('واتساب موصول') : t('واتساب غير موصول على الخادم')} />
                                {channels.whatsapp && <Channel ok={channels.whatsapp_template.ar} label={channels.whatsapp_template.ar ? t('قالب «رابط نطاق العمل» معتمد') : t('لا قالب واتساب معتمَد لنطاق العمل بعد')} />}
                                <Channel ok={channels.email} label={channels.email ? t('البريد يصل') : t('البريد غير مضبوط على الخادم')} />
                            </ul>
                            {(!channels.whatsapp || !channels.whatsapp_template.ar) && <p className="mt-2 text-[12px] text-[#71717a]">{t('لا شيء يتعطّل: انسخ الرابط وأرسله يدويًا، وتبقى صفحة التوقيع تعمل.')}</p>}
                            {may.templates && (
                                <SmartLink routeName="admin.communications.templates" href={route('admin.communications.templates')} className="mt-2 inline-block text-[12px] text-[#1d4ed8] hover:underline">
                                    {t('قوالب المراسلات')}
                                </SmartLink>
                            )}
                        </Card>

                        <Card className="p-4">
                            <h3 className="mb-2 text-[13px] font-semibold">{t('النسخ')}</h3>
                            <ul className="space-y-2 text-[12.5px]">
                                {revisions.map((r) => (
                                    <li key={r.id} className="flex items-center justify-between gap-2">
                                        <div className="min-w-0">
                                            <div className="font-mono" dir="ltr">{r.number}</div>
                                            <div className="text-[11.5px] text-[#a1a1aa]">
                                                {r.decided_at ? `${r.decided_by_name ?? ''} · ${r.decided_at}` : r.sent_at ? t('أُرسل في :at', { at: r.sent_at }) : ''}
                                            </div>
                                        </div>
                                        <div className="flex shrink-0 items-center gap-1.5">
                                            <span className={'rounded-full px-2 py-0.5 text-[11px] ' + scopeTone(r.state)}>{t(SCOPE_STATE_LABEL[r.state] ?? r.state)}</span>
                                            {r.url && (
                                                <a href={r.url} target="_blank" rel="noopener noreferrer" className="text-[#71717a] hover:text-[#111]" aria-label={t('فتح')}>
                                                    <Eye className="size-4" />
                                                </a>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </Card>

                        {may.communications && <CommunicationHistory subjectType="scope_of_work" subjectId={current.id} title="سجل المراسلات" />}
                    </aside>
                </div>
            )}

            {current && dialog && <SendMessageDialog subjectType="scope_of_work" subjectId={current.id} open={dialog !== null} onOpenChange={(v) => !v && setDialog(null)} channel={dialog} onSent={() => router.reload({ only: ['current'] })} />}

            <Dialog open={confirmSend} onOpenChange={setConfirmSend}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('إرسال للعميل للتوقيع')}</DialogTitle>
                        <DialogDescription>
                            {t('يُجمَّد المستند ويُبصَم ويُبنى رابطٌ آمن يفتحه العميل بلا حساب. بعدها لا يُعدَّل — والتعديل نسخةٌ جديدة. وتُثبَّت متطلبات المشروع إن لم تُثبَّت.')}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setConfirmSend(false)}>{t('تراجع')}</Button>
                        <Button onClick={() => { if (current) post('admin.projects.scope.send', [project.id, current.id]); setConfirmSend(false); }}>
                            <Send />
                            {t('إرسال')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={confirmRevision} onOpenChange={setConfirmRevision}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('نسخة جديدة من نطاق العمل')}</DialogTitle>
                        <DialogDescription>
                            {current?.state === 'signed'
                                ? t('تبدأ مسودّةً بما كُتب في هذه النسخة. وتبقى الموقَّعة ساريةً حتى يوقّع العميل الجديدة. وما يغيّر البنود أو القيمة بابه أمر تغيير.')
                                : t('تبدأ مسودّةً بما كُتب في هذه النسخة، ورابطُ النسخة الحالية لا يقبل توقيعًا بعدها.')}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setConfirmRevision(false)}>{t('تراجع')}</Button>
                        <Button onClick={() => { post('admin.projects.scope.store', [project.id]); setConfirmRevision(false); }}>
                            <FilePlus2 />
                            {t('بدء نسخة جديدة')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}

/* ─────────────────────────── القطع ─────────────────────────── */

const FLOW = ['draft', 'ready', 'sent', 'opened', 'signed'] as const;

/** شريطُ حال النسخة — مسودّة ← جاهز ← أُرسل ← فُتح ← موقَّع */
function Steps({ state }: { state: string }) {
    const t = useTranslate();
    const at = state === 'declined' ? 3 : FLOW.indexOf(state as (typeof FLOW)[number]);

    return (
        <ol className="mt-3 flex flex-wrap items-center gap-1.5 text-[12px]">
            {FLOW.map((s, k) => (
                <li key={s} className="flex items-center gap-1.5">
                    <span className={cn('rounded-full px-2 py-0.5', k < at || (k === at && s === 'signed') ? 'bg-[#6d28d9] text-white' : k === at ? 'bg-[#f3e8ff] text-[#6d28d9] ring-1 ring-[#6d28d9]' : 'bg-[#f4f4f5] text-[#a1a1aa]')}>
                        {t(SCOPE_STATE_LABEL[s])}
                    </span>
                    {k < FLOW.length - 1 && <span className="h-px w-3 bg-[#e4e4e7]" />}
                </li>
            ))}
            {state === 'declined' && <li className="rounded-full bg-[#fffbeb] px-2 py-0.5 text-[#b45309]">{t(SCOPE_STATE_LABEL.declined)}</li>}
        </ol>
    );
}

function Editor({ form, set, setForm, commercial }: { form: Form; set: (k: keyof Form) => (e: { target: { value: string } }) => void; setForm: (f: Form) => void; commercial: boolean }) {
    const t = useTranslate();

    return (
        <Card className="space-y-5 p-4">
            <Section title="بيانات العميل">
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="العميل"><Input value={form.customer_name} onChange={set('customer_name')} /></Field>
                    <Field label="جهة الاتصال"><Input value={form.contact_person} onChange={set('contact_person')} /></Field>
                    <Field label="الهاتف"><Input dir="ltr" value={form.contact_phone} onChange={set('contact_phone')} /></Field>
                    <Field label="البريد الإلكتروني"><Input dir="ltr" type="email" value={form.contact_email} onChange={set('contact_email')} /></Field>
                </div>
            </Section>
            <Section title="المشروع والفعالية">
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="اسم المشروع" required className="sm:col-span-2"><Input value={form.project_name} onChange={set('project_name')} /></Field>
                    <Field label="نوع الفعالية"><Input value={form.event_type} onChange={set('event_type')} /></Field>
                    <Field label="الموقع"><Input value={form.location} onChange={set('location')} /></Field>
                    <Field label="تاريخ البدء"><Input type="date" dir="ltr" value={form.starts_at} onChange={set('starts_at')} /></Field>
                    <Field label="تاريخ الانتهاء"><Input type="date" dir="ltr" value={form.ends_at} onChange={set('ends_at')} /></Field>
                    <Field label="الوقت" hint="مثل: 9 صباحًا — 5 مساءً، والتجهيز من الليلة السابقة" className="sm:col-span-2"><Input value={form.schedule_notes} onChange={set('schedule_notes')} /></Field>
                </div>
            </Section>
            <Section title="وصف نطاق التنفيذ">
                <Textarea rows={4} value={form.description} onChange={set('description')} />
            </Section>
            <div className="grid gap-4 sm:grid-cols-2">
                <Section title="ما يشمله العمل" hint="سطرٌ لكل بند">
                    <Textarea rows={5} value={form.inclusions} onChange={set('inclusions')} />
                </Section>
                <Section title="ما لا يشمله العمل" hint="سطرٌ لكل بند">
                    <Textarea rows={5} value={form.exclusions} onChange={set('exclusions')} />
                </Section>
                <Section title="مسؤوليات Event Plus">
                    <Textarea rows={5} value={form.company_responsibilities} onChange={set('company_responsibilities')} />
                </Section>
                <Section title="مسؤوليات العميل">
                    <Textarea rows={5} value={form.client_responsibilities} onChange={set('client_responsibilities')} />
                </Section>
            </div>
            <Section title="ملاحظات وشروط خاصة">
                <Textarea rows={3} value={form.terms} onChange={set('terms')} />
            </Section>
            {commercial && (
                <label className="flex items-center gap-2 text-[13px]">
                    <input type="checkbox" checked={form.show_value} onChange={(e) => setForm({ ...form, show_value: e.target.checked })} />
                    {t('إظهار الأسعار والقيمة المرجعية للعميل في نطاق العمل')}
                </label>
            )}
        </Card>
    );
}

function ReadOnly({ c }: { c: Current }) {
    const t = useTranslate();
    const dates = c.starts_at ? `${c.starts_at}${c.ends_at && c.ends_at !== c.starts_at ? ` → ${c.ends_at}` : ''}` : '—';

    return (
        <Card className="space-y-4 p-4 text-[13px]">
            <dl className="grid gap-x-6 gap-y-2 sm:grid-cols-2">
                <Row label="العميل" value={c.customer_name} />
                <Row label="جهة الاتصال" value={c.contact_person} />
                <Row label="الهاتف" value={c.contact_phone} ltr />
                <Row label="البريد الإلكتروني" value={c.contact_email} ltr />
                <Row label="المشروع" value={c.project_name} />
                <Row label="نوع الفعالية" value={c.event_type} />
                <Row label="التاريخ" value={dates} ltr />
                <Row label="الوقت" value={c.schedule_notes} />
                <Row label="الموقع" value={c.location} />
            </dl>
            <Prose title="وصف نطاق التنفيذ" text={c.description} />
            <div className="grid gap-4 sm:grid-cols-2">
                <Prose title="ما يشمله العمل" text={c.inclusions} />
                <Prose title="ما لا يشمله العمل" text={c.exclusions} />
                <Prose title="مسؤوليات Event Plus" text={c.company_responsibilities} />
                <Prose title="مسؤوليات العميل" text={c.client_responsibilities} />
            </div>
            <Prose title="ملاحظات وشروط خاصة" text={c.terms} />
            {c.content_hash && (
                <div className="text-[11px] text-[#a1a1aa]">
                    {t('بصمة المستند')}: <span className="font-mono" dir="ltr">{c.content_hash}</span>
                </div>
            )}
        </Card>
    );
}

function ScopeLines({ c, m, commercial, projectId }: { c: Current; m: (v: string | number | null | undefined) => string; commercial: boolean; projectId: number }) {
    const t = useTranslate();
    const s = c.snapshot;

    return (
        <Card className="p-4">
            <div className="mb-1 flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-[15px] font-semibold">{t('الخدمات والبنود المتفق عليها')}</h2>
                {s.quotation && <span className="text-[12px] text-[#71717a]">{t('من العرض :q', { q: s.quotation.label })}{s.change_orders.length > 0 ? ` + ${s.change_orders.join('، ')}` : ''}</span>}
            </div>
            <p className="mb-3 text-[12px] text-[#a1a1aa]">
                {t('من النطاق المعتمد — لا تُكتب هنا. وما يغيّرها بعد التوقيع يمرّ بأمر تغيير يقبله العميل.')}{' '}
                <SmartLink routeName="admin.changeOrders.create" href={route('admin.changeOrders.create', projectId)} className="text-[#1d4ed8] hover:underline">{t('أمر تغيير')}</SmartLink>
            </p>
            <div className="overflow-x-auto">
                <table className="w-full text-[13px]">
                    <thead className="text-[12px] text-[#6b7280]">
                        <tr className="border-b border-[#e8e8e8]">
                            <th className="py-2 text-start">{t('البيان')}</th>
                            <th className="py-2 text-center">{t('الكمية')}</th>
                            {commercial && <th className="py-2 text-end">{t('سعر الوحدة')}</th>}
                            {commercial && <th className="py-2 text-end">{t('المبلغ')}</th>}
                        </tr>
                    </thead>
                    <tbody>
                        {s.items.map((i, k) => (
                            <tr key={k} className="border-b border-[#f4f4f5]">
                                <td className="py-2 font-medium">{i.description}</td>
                                <td className="py-2 text-center tabular-nums">{number(Number(i.quantity))} {i.unit}</td>
                                {commercial && <td className="py-2 text-end tabular-nums">{m(i.unit_price)}</td>}
                                {commercial && <td className="py-2 text-end tabular-nums">{m(i.line_total)}</td>}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {commercial && s.value !== null && (
                <div className="mt-3 flex justify-end text-[13px] font-semibold tabular-nums">
                    {t('القيمة المرجعية')}: {m(s.value)}
                    {!c.show_value && <span className="ms-2 text-[11.5px] font-normal text-[#a1a1aa]">({t('لا تظهر للعميل')})</span>}
                </div>
            )}
            {s.requirements.length > 0 && (
                <div className="mt-4">
                    <h3 className="mb-1 text-[13px] font-semibold">{t('المتطلبات')}</h3>
                    <ul className="list-disc space-y-0.5 ps-5 text-[13px] text-[#3f3f46]">
                        {s.requirements.map((r, k) => (
                            <li key={k}>
                                {r.title}
                                {r.details && <span className="text-[#71717a]"> — {r.details}</span>}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </Card>
    );
}

function Evidence({ approval }: { approval: NonNullable<Current['approval']> }) {
    const t = useTranslate();
    const accepted = approval.decision === 'accepted';

    return (
        <div className={cn('mt-3 rounded-[12px] border p-3 text-[12.5px]', accepted ? 'border-[#a7f3d0] bg-[#f0fdf4]' : 'border-[#fde68a] bg-[#fffbeb]')}>
            <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                <span className="font-semibold">{accepted ? t('وقّعه العميل إلكترونيًا') : t('طلب العميل تعديله')}</span>
                <span className={cn('inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px]', approval.verified ? 'bg-white text-[#065f46]' : 'bg-[#fef2f2] text-[#b91c1c]')}>
                    <ShieldCheck className="size-3" />
                    {approval.verified ? t('البصمة تطابق') : t('البصمة لا تطابق — تغيّر المستند بعد القرار')}
                </span>
            </div>
            <dl className="grid gap-x-6 gap-y-1 sm:grid-cols-2">
                <Row label="الموقّع" value={approval.signer_name} />
                <Row label="الصفة" value={approval.signer_title} />
                <Row label="البريد" value={approval.signer_email} ltr />
                <Row label="الهاتف" value={approval.signer_phone} ltr />
                <Row label="الوقت" value={approval.decided_at} ltr />
                <Row label="العنوان" value={approval.ip} ltr />
            </dl>
            <div className="mt-2 truncate font-mono text-[10.5px] text-[#71717a]" dir="ltr" title={approval.content_hash}>{approval.content_hash}</div>
            {approval.consent_text && (
                <details className="mt-1 text-[12px] text-[#52525b]">
                    <summary className="cursor-pointer">{t('نصّ الإقرار')}</summary>
                    <p className="mt-1 leading-6">{approval.consent_text}</p>
                </details>
            )}
        </div>
    );
}

function Section({ title, hint, children }: { title: string; hint?: string; children: ReactNode }) {
    const t = useTranslate();
    return (
        <section>
            <h3 className="mb-1.5 text-[13.5px] font-semibold text-[#18181b]">
                {t(title)}
                {hint && <span className="ms-2 text-[11.5px] font-normal text-[#a1a1aa]">{t(hint)}</span>}
            </h3>
            {children}
        </section>
    );
}

function Prose({ title, text }: { title: string; text: string | null }) {
    const t = useTranslate();
    if (!text) return null;
    return (
        <div>
            <h3 className="mb-1 text-[13px] font-semibold">{t(title)}</h3>
            <p className="whitespace-pre-line text-[#3f3f46]">{text}</p>
        </div>
    );
}

function Row({ label, value, ltr }: { label: string; value: string | null | undefined; ltr?: boolean }) {
    const t = useTranslate();
    if (!value) return null;
    return (
        <div className="flex justify-between gap-3 sm:block">
            <dt className="text-[#9ca3af]">{t(label)}</dt>
            <dd className="font-medium" dir={ltr ? 'ltr' : undefined}>{value}</dd>
        </div>
    );
}

function Channel({ ok, label }: { ok: boolean; label: string }) {
    return (
        <li className="flex items-center gap-2">
            {ok ? <Check className="size-3.5 text-[#047857]" /> : <AlertTriangle className="size-3.5 text-[#b45309]" />}
            <span className={ok ? 'text-[#27272a]' : 'text-[#92400e]'}>{label}</span>
        </li>
    );
}

function Notice({ tone, children }: { tone: 'danger' | 'warning' | 'info'; children: ReactNode }) {
    return (
        <div className={cn('my-3 rounded-lg px-4 py-3 text-[13px]', tone === 'danger' && 'bg-[#fef2f2] text-[#b91c1c]', tone === 'warning' && 'bg-[#fffbeb] text-[#b45309]', tone === 'info' && 'bg-[#eff6ff] text-[#1e3a8a]')}>
            {children}
        </div>
    );
}
