import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Check, Eye, Mail, Pencil, Send, ShieldAlert, ShieldCheck, Slash, X } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import BackLink from '@/Components/BackLink';
import PageHeader from '@/Components/PageHeader';
import PaperFrame from '@/Components/PaperFrame';
import SmartLink from '@/Components/SmartLink';
import CopyButton from '@/Components/CopyButton';
import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input, Textarea } from '@/Components/ui/input';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { CO_STATE_LABEL, coTone, PARTY_LABEL } from '@/lib/projects';
import type { PageProps } from '@/types';

interface Item {
    id: number;
    project_item_id: number | null;
    description: string;
    unit: string | null;
    quantity: number;
    list_price: number;
    unit_price: number;
    discount: number;
    discount_percent: number;
    discount_reason: string | null;
    approved_by: string | null;
    awaiting_approval: boolean;
    overridden: boolean;
    reduction: boolean;
    tax_amount: number;
    line_total: number;
    notes: string | null;
}

interface Order {
    id: number;
    label: string;
    number: string | null;
    sequence: number;
    project: { id: number; code: string; name: string; customer: string | null };
    title: string;
    reason: string | null;
    requested_by_party: string;
    requested_by_name: string | null;
    status: string;
    state: string;
    frozen: boolean;
    new_starts_at: string | null;
    new_ends_at: string | null;
    valid_until: string | null;
    subtotal_delta: number;
    discount_delta: number;
    tax_delta: number;
    total_delta: number;
    content_hash: string | null;
    created_by: string | null;
    sent_at: string | null;
    sent_to: string | null;
    first_viewed_at: string | null;
    view_count: number;
    approved_at: string | null;
    decided_at: string | null;
    decided_by_name: string | null;
    decline_reason: string | null;
    cancellation_reason: string | null;
    public_url: string | null;
    items: Item[];
    added: { id: number; title: string; details: string | null }[];
    removed: { id: number; title: string }[];
    exceptions: { id: number; label: string; reason: string; approved_by: string | null; approved_at: string | null; requested_by: string | null }[];
    unsettled: { key: string; label: string; message: string }[];
    approval: { decision: string; signer_name: string; signer_title: string | null; signer_email: string | null; decided_at: string | null; ip: string | null; content_hash: string; verified: boolean } | null;
}

interface Props {
    order: Order;
    paper: string;
    may: { create: boolean; send: boolean; approve: boolean; override_policy: boolean; cancel: boolean; record_decision: boolean; email: boolean; mail_reason: string | null };
}

/**
 * أمرُ تغييرٍ واحد — ورقتُه وأثرُه ودليلُ قبوله.
 *
 * والقبولُ يُطبَّق في الخادم لحظةَ يقع: ما تراه هنا بعد «مقبول» قد وقع
 * فعلًا على بنود المشروع — انظر `ChangeOrders::apply`.
 */
export default function ChangeOrderShow({ order: o, paper, may }: Props) {
    const t = useTranslate();
    const { context } = usePage<PageProps>().props;
    const { errors } = usePage().props as { errors?: Record<string, string> };
    const m = (v: number) => money(v, context!.currency);
    const signed = (v: number) => `${v >= 0 ? '+' : ''}${m(v)}`;

    const [cancelling, setCancelling] = useState(false);
    const [reason, setReason] = useState('');
    const [deciding, setDeciding] = useState<null | 'accepted' | 'declined'>(null);
    const [decidedBy, setDecidedBy] = useState('');
    const [declineReason, setDeclineReason] = useState('');
    const [mailing, setMailing] = useState(false);
    const [mailTo, setMailTo] = useState(o.sent_to ?? '');
    const [mailNote, setMailNote] = useState('');

    const post = (name: string) => router.post(route(name, o.id), {}, { preserveScroll: true });
    const awaitingApproval = o.status === 'pending_approval';

    return (
        <AdminLayout title={o.label}>
            <BackLink routeName="admin.projects.show" href={route('admin.projects.show', o.project.id)} label={o.project.code} />

            <PageHeader
                title={o.label}
                subtitle={`${o.title} — ${o.project.name}`}
                actions={
                    <div className="flex flex-wrap gap-2">
                        {!o.frozen && may.create && (
                            <Button variant="outline" asChild>
                                <SmartLink routeName="admin.changeOrders.edit" href={route('admin.changeOrders.edit', o.id)}><Pencil />{t('تعديل')}</SmartLink>
                            </Button>
                        )}
                        {awaitingApproval && (may.approve || may.override_policy) && (
                            <Button onClick={() => post('admin.changeOrders.approve')}><ShieldCheck />{t('اعتماد')}</Button>
                        )}
                        {o.status === 'draft' && may.send && (
                            <Button onClick={() => post('admin.changeOrders.send')}><Send />{t('إرسال للعميل')}</Button>
                        )}
                        {o.state !== 'expired' && o.status === 'sent' && may.email && (
                            <Button variant="outline" onClick={() => setMailing(true)}><Mail />{o.sent_to ? t('تذكير بالبريد') : t('إرسال بالبريد')}</Button>
                        )}
                        {o.status === 'sent' && may.record_decision && (
                            <>
                                <Button variant="outline" onClick={() => setDeciding('accepted')}><Check />{t('سجّل القبول')}</Button>
                                <Button variant="outline" onClick={() => setDeciding('declined')}><X />{t('سجّل الرفض')}</Button>
                            </>
                        )}
                        {o.status !== 'accepted' && o.status !== 'cancelled' && may.cancel && (
                            <Button variant="outline" onClick={() => setCancelling(true)}><Slash />{t('إلغاء')}</Button>
                        )}
                    </div>
                }
            />

            {awaitingApproval && <div className="mb-4 rounded-lg bg-[#fffbeb] px-4 py-3 text-[13px] text-[#b45309]">{t('هذا الأمر ينتظر اعتمادًا — لا يخرج إلى العميل قبله.')}</div>}
            {o.unsettled.length > 0 && (
                <div className="mb-4 rounded-lg bg-[#fef2f2] px-4 py-3 text-[13px] text-[#b91c1c]">
                    {o.unsettled.map((f) => <div key={f.key}>{t(f.label)} — {f.message} · {t('احفظ الأمر مرة أخرى مع سبب الاستثناء.')}</div>)}
                </div>
            )}
            {errors?.order && <div className="mb-4 rounded-lg bg-[#fef2f2] px-4 py-3 text-[13px] text-[#b91c1c]">{errors.order}</div>}
            {errors?.decision && <div className="mb-4 rounded-lg bg-[#fef2f2] px-4 py-3 text-[13px] text-[#b91c1c]">{errors.decision}</div>}
            {errors?.email && <div className="mb-4 rounded-lg bg-[#fef2f2] px-4 py-3 text-[13px] text-[#b91c1c]">{errors.email}</div>}
            {o.status === 'accepted' && <div className="mb-4 rounded-lg bg-[#ecfdf5] px-4 py-3 text-[13px] text-[#065f46]">{t('قُبل وطُبّق على نطاق المشروع — الكميّات والمتطلّبات والموعد كما في هذا الأمر.')}</div>}

            <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_420px]">
                <div className="space-y-4">
                    <Card className="p-4">
                        <div className="mb-3 flex flex-wrap items-center gap-2">
                            <span className={'rounded-full px-2 py-1 text-[12px] ' + coTone(o.state)}>{t(CO_STATE_LABEL[o.state] ?? o.state)}</span>
                            <span className={'text-[15px] font-semibold tabular-nums ' + (o.total_delta < 0 ? 'text-[#b91c1c]' : o.total_delta > 0 ? 'text-[#047857]' : '')}>{signed(o.total_delta)}</span>
                        </div>
                        <dl className="grid gap-x-6 gap-y-2 text-[13px] sm:grid-cols-2">
                            <Row label="المشروع" value={`${o.project.code} — ${o.project.name}`} />
                            <Row label="العميل" value={o.project.customer} />
                            <Row label="طلبه" value={`${t(PARTY_LABEL[o.requested_by_party] ?? o.requested_by_party)}${o.requested_by_name ? ` — ${o.requested_by_name}` : ''}`} />
                            <Row label="كتبه" value={o.created_by} />
                            <Row label="صالح حتى" value={o.valid_until} ltr />
                            <Row label="اعتُمد في" value={o.approved_at} ltr />
                            <Row label="أُرسل في" value={o.sent_at} ltr />
                            <Row label="فتحه العميل" value={o.sent_at ? (o.first_viewed_at ? `${o.first_viewed_at} — ${t(':n مرة', { n: number(o.view_count) })}` : t('لم يُفتح بعد')) : null} />
                            <Row label="أُرسل بالبريد إلى" value={o.sent_to} ltr />
                            <Row label="قرار العميل" value={o.decided_at} ltr />
                            <Row label={o.approval ? 'قرّره' : 'سجّله'} value={o.decided_by_name} />
                            <Row label="سبب الرفض" value={o.decline_reason} />
                            <Row label="سبب الإلغاء" value={o.cancellation_reason} />
                            <Row label="الموعد الجديد" value={o.new_starts_at ? `${o.new_starts_at}${o.new_ends_at ? ` → ${o.new_ends_at}` : ''}` : null} ltr />
                        </dl>
                        {o.reason && <p className="mt-3 whitespace-pre-line text-[13px] text-[#52525b]">{o.reason}</p>}
                        {o.public_url && (
                            <div className="mt-3 flex items-center gap-2 border-t border-[#e8e8e8] pt-3">
                                <Eye className="size-4 text-[#9ca3af]" />
                                <span className="truncate text-[12px] text-[#6b7280]" dir="ltr">{o.public_url}</span>
                                <CopyButton text={o.public_url} name="نسخ رابط العميل" />
                            </div>
                        )}
                        {o.content_hash && <div className="mt-2 text-[11px] text-[#9ca3af]" dir="ltr">{t('بصمة المحتوى')}: {o.content_hash.slice(0, 12)}…</div>}
                    </Card>

                    {o.approval && (
                        <Card className={o.approval.decision === 'accepted' ? 'border-[#a7f3d0] p-4' : 'p-4'}>
                            <div className="mb-2 flex flex-wrap items-center gap-2">
                                <h2 className="text-[15px] font-semibold">{o.approval.decision === 'accepted' ? t('قبول إلكتروني من العميل') : t('اعتذار إلكتروني من العميل')}</h2>
                                <span className={'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] ' + (o.approval.verified ? 'bg-[#ecfdf5] text-[#065f46]' : 'bg-[#fef2f2] text-[#b91c1c]')}>
                                    <ShieldCheck className="size-3" />
                                    {o.approval.verified ? t('البصمة تطابق') : t('البصمة لا تطابق — تغيّر المستند بعد القرار')}
                                </span>
                            </div>
                            <dl className="grid gap-x-6 gap-y-2 text-[13px] sm:grid-cols-2">
                                <Row label="الاسم" value={o.approval.signer_name} />
                                <Row label="الصفة" value={o.approval.signer_title} />
                                <Row label="البريد" value={o.approval.signer_email} ltr />
                                <Row label="في" value={o.approval.decided_at} ltr />
                                <Row label="من العنوان" value={o.approval.ip} ltr />
                            </dl>
                            <div className="mt-2 text-[11px] text-[#9ca3af]" dir="ltr">{o.approval.content_hash}</div>
                        </Card>
                    )}

                    {o.exceptions.length > 0 && (
                        <Card className="p-4">
                            <h2 className="mb-3 flex items-center gap-2 text-[15px] font-semibold"><ShieldAlert className="size-4 text-[#b45309]" />{t('استثناءات من سياسة الشركة')}</h2>
                            <ul className="space-y-2 text-[13px]">
                                {o.exceptions.map((e) => (
                                    <li key={e.id} className="rounded-lg border border-[#f4f4f5] p-3">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-semibold">{t(e.label)}</span>
                                            <span className={'rounded-full px-2 py-0.5 text-[11px] ' + (e.approved_at ? 'bg-[#ecfdf5] text-[#065f46]' : 'bg-[#fffbeb] text-[#b45309]')}>
                                                {e.approved_at ? `${t('اعتمده')} ${e.approved_by ?? ''} · ${e.approved_at}` : t('ينتظر اعتمادًا')}
                                            </span>
                                        </div>
                                        <div className="mt-1 text-[#52525b]">{e.reason}</div>
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    )}

                    <Card className="p-4">
                        <h2 className="mb-3 text-[15px] font-semibold">{t('ما يتغيّر')}</h2>
                        {o.items.length > 0 && (
                            <div className="overflow-x-auto">
                                <table className="w-full text-[13px]">
                                    <thead className="text-[12px] text-[#6b7280]">
                                        <tr className="border-b border-[#e8e8e8]">
                                            <th className="py-2 text-start">{t('البيان')}</th>
                                            <th className="py-2 text-center">{t('التغيير')}</th>
                                            <th className="py-2 text-end">{t('سعر الوحدة')}</th>
                                            <th className="py-2 text-end">{t('المبلغ')}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {o.items.map((i) => (
                                            <tr key={i.id} className="border-b border-[#f4f4f5] align-top">
                                                <td className="py-2">
                                                    <div className="font-medium">{i.description}</div>
                                                    <div className="text-[11px] text-[#9ca3af]">{i.project_item_id === null ? t('بند جديد') : i.reduction ? t('نقص على بند قائم') : t('زيادة على بند قائم')}{i.notes ? ` — ${i.notes}` : ''}</div>
                                                    {i.discount_reason && (
                                                        <div className="mt-1 text-[11px] text-[#b45309]">
                                                            {i.overridden ? t('فوق السعر') : t('خصم')} — {i.discount_reason}
                                                            {i.approved_by ? ` · ${t('اعتمده')} ${i.approved_by}` : i.awaiting_approval ? ` · ${t('ينتظر اعتمادًا')}` : ''}
                                                        </div>
                                                    )}
                                                </td>
                                                <td className={'py-2 text-center tabular-nums ' + (i.reduction ? 'text-[#b91c1c]' : 'text-[#047857]')} dir="ltr">{i.quantity >= 0 ? '+' : ''}{number(i.quantity)} {i.unit ?? ''}</td>
                                                <td className="py-2 text-end tabular-nums">{m(i.unit_price)}</td>
                                                <td className={'py-2 text-end tabular-nums ' + (i.line_total < 0 ? 'text-[#b91c1c]' : '')}>{signed(i.line_total)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                        {(o.added.length > 0 || o.removed.length > 0) && (
                            <ul className="mt-3 space-y-1 text-[13px]">
                                {o.added.map((r) => <li key={'a' + r.id} className="text-[#047857]">+ {r.title}{r.details ? <span className="text-[#6b7280]"> — {r.details}</span> : null}</li>)}
                                {o.removed.map((r) => <li key={'r' + r.id} className="text-[#b91c1c]">− {r.title}</li>)}
                            </ul>
                        )}
                        <div className="mt-3 space-y-1 border-t border-[#e8e8e8] pt-3 text-[13px]">
                            <div className="flex justify-between"><span className="text-[#6b7280]">{t('الفرق قبل الضريبة')}</span><span className="tabular-nums">{signed(o.subtotal_delta - o.discount_delta)}</span></div>
                            <div className="flex justify-between"><span className="text-[#6b7280]">{t('فرق الضريبة')}</span><span className="tabular-nums">{signed(o.tax_delta)}</span></div>
                            <div className="flex justify-between font-semibold"><span>{t('فرق القيمة')}</span><span className="tabular-nums">{signed(o.total_delta)}</span></div>
                        </div>
                    </Card>
                </div>

                <PaperFrame html={paper} title={t('الورقة كما يراها العميل')} />
            </div>

            <Dialog open={cancelling} onOpenChange={setCancelling}>
                <DialogContent className="max-w-md">
                    <DialogHeader><DialogTitle>{t('إلغاء أمر التغيير')}</DialogTitle></DialogHeader>
                    <div className="px-5"><Field label="سبب الإلغاء" required><Input value={reason} onChange={(e) => setReason(e.target.value)} /></Field></div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setCancelling(false)}>{t('تراجع')}</Button>
                        <Button disabled={reason.trim() === ''} onClick={() => { router.post(route('admin.changeOrders.cancel', o.id), { reason }, { preserveScroll: true }); setCancelling(false); }}>{t('إلغاء')}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={deciding !== null} onOpenChange={(v) => !v && setDeciding(null)}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>{deciding === 'accepted' ? t('تسجيل قبول العميل') : t('تسجيل رفض العميل')}</DialogTitle>
                        <DialogDescription>{deciding === 'accepted' ? t('يُطبَّق الأمر على نطاق المشروع فور التسجيل — ولا يُلغى بعدها.') : t('يُفرَج عمّا حجزه الأمر من متطلّبات.')}</DialogDescription>
                    </DialogHeader>
                    <div className="space-y-3 px-5">
                        <Field label="باسم من"><Input value={decidedBy} onChange={(e) => setDecidedBy(e.target.value)} /></Field>
                        {deciding === 'declined' && <Field label="سبب الرفض" required><Textarea rows={2} value={declineReason} onChange={(e) => setDeclineReason(e.target.value)} /></Field>}
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDeciding(null)}>{t('تراجع')}</Button>
                        <Button disabled={deciding === 'declined' && declineReason.trim() === ''} onClick={() => { router.post(route('admin.changeOrders.decision', o.id), { decision: deciding, decided_by_name: decidedBy, decline_reason: declineReason }, { preserveScroll: true }); setDeciding(null); }}>{t('تسجيل')}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={mailing} onOpenChange={setMailing}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>{o.sent_to ? t('تذكير بالبريد') : t('إرسال الأمر بالبريد')}</DialogTitle>
                        <DialogDescription>{t('يصل العميلَ رابطُ الأمر ليقرأه ويقبله إلكترونيًا — ويُسجَّل الإرسال في الأثر.')}</DialogDescription>
                    </DialogHeader>
                    <div className="space-y-3 px-5">
                        <Field label="بريد المُرسَل إليه" required><Input type="email" dir="ltr" value={mailTo} onChange={(e) => setMailTo(e.target.value)} /></Field>
                        <Field label="رسالة مرافقة" hint="اختياري — تُكتب فوق الرابط"><Textarea rows={3} value={mailNote} onChange={(e) => setMailNote(e.target.value)} /></Field>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setMailing(false)}>{t('تراجع')}</Button>
                        <Button disabled={mailTo.trim() === ''} onClick={() => { router.post(route('admin.changeOrders.email', o.id), { to: mailTo, note: mailNote }, { preserveScroll: true }); setMailing(false); }}><Send />{t('إرسال')}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AdminLayout>
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
