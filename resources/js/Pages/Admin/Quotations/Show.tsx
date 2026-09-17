import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Check, Copy, Download, Eye, Mail, Pencil, Send, ShieldAlert, ShieldCheck, Slash, X } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import BackLink from '@/Components/BackLink';
import PageHeader from '@/Components/PageHeader';
import PaperFrame from '@/Components/PaperFrame';
import SmartLink from '@/Components/SmartLink';
import CopyButton from '@/Components/CopyButton';
import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Card } from '@/Components/ui/card';
import { Input, Textarea } from '@/Components/ui/input';
import { usePage } from '@inertiajs/react';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { STATE_LABEL, stateTone } from '@/lib/quotations';
import type { PageProps } from '@/types';

interface Item {
    id: number;
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
    tax_amount: number;
    line_total: number;
    notes: string | null;
}

interface Exception {
    id: number;
    key: string;
    label: string;
    detected: Record<string, unknown> | null;
    reason: string;
    surcharge_amount: number | null;
    requested_by: string | null;
    approved_by: string | null;
    approved_at: string | null;
}

/** دليلُ قرار العميل من رابطه — يُقرأ ويُعاد فحصُ بصمته عند كلّ فتح */
interface Approval {
    decision: 'accepted' | 'declined';
    signer_name: string;
    signer_title: string | null;
    signer_email: string | null;
    signer_phone: string | null;
    consent_text: string | null;
    decline_reason: string | null;
    decided_at: string | null;
    ip: string | null;
    user_agent: string | null;
    content_hash: string;
    provider: string;
    verified: boolean;
}

interface Quotation {
    id: number;
    number: string | null;
    label: string;
    revision: number;
    title: string;
    customer: string;
    status: string;
    state: string;
    issued_at: string | null;
    valid_until: string | null;
    days_left: number | null;
    total: number;
    subtotal: number;
    discount_total: number;
    tax_total: number;
    view_count: number;
    frozen: boolean;
    public_url: string | null;
    content_hash: string | null;
    created_by: string | null;
    sent_at: string | null;
    first_viewed_at: string | null;
    last_viewed_at: string | null;
    approved_at: string | null;
    decided_at: string | null;
    decided_by_name: string | null;
    rejection_reason: string | null;
    cancellation_reason: string | null;
    scope: string | null;
    terms: string | null;
    internal_notes: string | null;
    contact_email: string | null;
    sent_to: string | null;
    items: Item[];
    policies: { cap: string; text: string }[];
    exceptions: Exception[];
    unsettled: { key: string; label: string; message: string }[];
    approval: Approval | null;
}

interface Props {
    quotation: Quotation;
    paper: string;
    chain: { id: number; label: string; revision: number; status: string; total: number; sent_at: string | null; current: boolean }[];
    may: {
        create: boolean;
        send: boolean;
        approve: boolean;
        override_policy: boolean;
        cancel: boolean;
        record_decision: boolean;
        email: boolean;
        mail_reason: string | null;
    };
}

/**
 * عرضُ سعرٍ واحد — ورقتُه وأثرُه وسلسلةُ تنقيحاته.
 *
 * ═══ ولمَ الأثرُ إلى جانب الورقة ═══
 *
 * الورقةُ تقول **ما عُرض**، والأثرُ يقول **ما جرى**: من كتب، ومن اعتمد،
 * ومتى خرج، وهل فُتح. والثاني هو ما يُسأل عنه بعد شهرٍ حين لا يردّ العميل
 * — «هل وصله أصلًا؟» — ولا تجيبه ورقةٌ مهما طُبعت.
 */
export default function QuotationShow({ quotation: q, paper, chain, may }: Props) {
    const t = useTranslate();
    const { context } = usePage<PageProps>().props;
    const m = (v: number) => money(v, context!.currency);

    const [cancelling, setCancelling] = useState(false);
    const [reason, setReason] = useState('');
    const [deciding, setDeciding] = useState<null | 'accepted' | 'rejected'>(null);
    const [decidedBy, setDecidedBy] = useState('');
    const [rejectReason, setRejectReason] = useState('');
    const [mailing, setMailing] = useState(false);
    const [mailTo, setMailTo] = useState(q.sent_to ?? q.contact_email ?? '');
    const [mailNote, setMailNote] = useState('');
    const { errors } = usePage().props as { errors?: Record<string, string> };

    const post = (name: string) => router.post(route(name, q.id), {}, { preserveScroll: true });

    const awaitingApproval = q.status === 'pending_approval';

    return (
        <AdminLayout title={q.label}>
            <BackLink routeName="admin.quotations.index" href={route('admin.quotations.index')} label="عروض الأسعار" />

            <PageHeader
                title={q.number ? q.label : `${t('مسودة')} #${q.id}`}
                subtitle={q.title}
                actions={
                    <div className="flex flex-wrap gap-2">
                        {/* ولا يُرسم مقبضٌ يردّه الخادم: بابٌ معروضٌ لا يُفتح يُقرأ عطبًا */}
                        {!q.frozen && may.create && (
                            <Button variant="outline" asChild>
                                <SmartLink
                                    routeName="admin.quotations.create"
                                    href={route('admin.quotations.create', { id: q.id })}
                                >
                                    <Pencil />
                                    {t('تعديل')}
                                </SmartLink>
                            </Button>
                        )}

                        {awaitingApproval && (may.approve || may.override_policy) && (
                            <Button onClick={() => post('admin.quotations.approve')}>
                                <ShieldCheck />
                                {t('اعتماد')}
                            </Button>
                        )}

                        {q.status === 'draft' && may.send && (
                            <Button onClick={() => post('admin.quotations.send')}>
                                <Send />
                                {t('إرسال للعميل')}
                            </Button>
                        )}

                        {/*
                            والبريدُ بابٌ يُخفى حين لا يفتح: `may.email` تقول إنّ
                            الخادمَ يُوصِل فعلًا. ومن لا يملكه ينسخ الرابط.
                        */}
                        {q.state !== 'expired' && q.status === 'sent' && may.email && (
                            <Button variant="outline" onClick={() => setMailing(true)}>
                                <Mail />
                                {q.sent_to ? t('تذكير بالبريد') : t('إرسال بالبريد')}
                            </Button>
                        )}

                        {(q.status === 'sent' || q.status === 'rejected') && may.create && (
                            <Button variant="outline" onClick={() => post('admin.quotations.revise')}>
                                <Pencil />
                                {t('تنقيح جديد')}
                            </Button>
                        )}

                        {may.create && (
                            <Button variant="outline" onClick={() => post('admin.quotations.duplicate')}>
                                <Copy />
                                {t('نسخة جديدة')}
                            </Button>
                        )}

                        {q.status === 'sent' && may.record_decision && (
                            <>
                                <Button variant="outline" onClick={() => setDeciding('accepted')}>
                                    <Check />
                                    {t('سجّل القبول')}
                                </Button>
                                <Button variant="outline" onClick={() => setDeciding('rejected')}>
                                    <X />
                                    {t('سجّل الرفض')}
                                </Button>
                            </>
                        )}

                        {q.status !== 'accepted' && q.status !== 'cancelled' && q.status !== 'superseded' && may.cancel && (
                            <Button variant="outline" onClick={() => setCancelling(true)}>
                                <Slash />
                                {t('إلغاء')}
                            </Button>
                        )}

                        <Button variant="outline" asChild>
                            <a href={route('admin.quotations.pdf', q.id)} target="_blank" rel="noreferrer">
                                <Download />
                                {t('PDF')}
                            </a>
                        </Button>
                    </div>
                }
            />

            {awaitingApproval && (
                <div className="mb-4 rounded-lg bg-[#fffbeb] px-4 py-3 text-[13px] text-[#b45309]">
                    {t('هذا العرض ينتظر اعتمادًا — لا يخرج إلى العميل قبله.')}
                </div>
            )}

            {/*
                وما يخالفه الآن بلا استثناءٍ مسجَّل: سياسةٌ أُضيفت بعد الحفظ،
                أو تنقيحٌ ورث تاريخًا صار عاجلًا. يُقال هنا قبل أن يردّه «إرسال».
            */}
            {q.unsettled.length > 0 && (
                <div className="mb-4 rounded-lg bg-[#fef2f2] px-4 py-3 text-[13px] text-[#b91c1c]">
                    {q.unsettled.map((f) => (
                        <div key={f.key}>
                            {t(f.label)} — {f.message} · {t('احفظ العرض مرة أخرى مع سبب الاستثناء.')}
                        </div>
                    ))}
                </div>
            )}

            {errors?.email && (
                <div className="mb-4 rounded-lg bg-[#fef2f2] px-4 py-3 text-[13px] text-[#b91c1c]">{errors.email}</div>
            )}
            {errors?.quotation && (
                <div className="mb-4 rounded-lg bg-[#fef2f2] px-4 py-3 text-[13px] text-[#b91c1c]">{errors.quotation}</div>
            )}

            <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_420px]">
                <div className="space-y-4">
                    {/* ─────────── الحال والأثر ─────────── */}
                    <Card className="p-4">
                        <div className="mb-3 flex flex-wrap items-center gap-2">
                            <span className={'rounded-full px-2 py-1 text-[12px] ' + stateTone(q.state)}>
                                {t(STATE_LABEL[q.state] ?? q.state)}
                            </span>
                            {q.revision > 1 && (
                                <span className="rounded-full bg-[#f4f4f5] px-2 py-1 text-[12px] text-[#52525b]">
                                    {t('تنقيح :n', { n: number(q.revision) })}
                                </span>
                            )}
                            <span className="text-[15px] font-semibold tabular-nums">{m(q.total)}</span>
                        </div>

                        <dl className="grid gap-x-6 gap-y-2 text-[13px] sm:grid-cols-2">
                            <Row label="العميل" value={q.customer} />
                            <Row label="التاريخ" value={q.issued_at} ltr />
                            <Row label="صالح حتى" value={q.valid_until} ltr />
                            <Row label="كتبه" value={q.created_by} />
                            <Row label="أُرسل في" value={q.sent_at} ltr />
                            <Row label="اعتُمد في" value={q.approved_at} ltr />
                            {/*
                                و«لم يُفتح بعد» خبرٌ لا فراغ: من أرسل عرضًا ولم
                                يُفتح منذ أسبوعٍ يعرف أنّ المشكلة في الوصول لا
                                في السعر.
                            */}
                            <Row
                                label="فتحه العميل"
                                value={
                                    q.sent_at
                                        ? q.first_viewed_at
                                            ? `${q.first_viewed_at} — ${t(':n مرة', { n: number(q.view_count) })}`
                                            : t('لم يُفتح بعد')
                                        : null
                                }
                            />
                            <Row label="أُرسل بالبريد إلى" value={q.sent_to} ltr />
                            <Row label="قرار العميل" value={q.decided_at} ltr />
                            <Row label={q.approval ? 'قرّره' : 'سجّله'} value={q.decided_by_name} />
                            <Row label="سبب الرفض" value={q.rejection_reason} />
                            <Row label="سبب الإلغاء" value={q.cancellation_reason} />
                        </dl>

                        {q.public_url && (
                            <div className="mt-3 flex items-center gap-2 border-t border-[#e8e8e8] pt-3">
                                <Eye className="size-4 text-[#9ca3af]" />
                                <span className="truncate text-[12px] text-[#6b7280]" dir="ltr">
                                    {q.public_url}
                                </span>
                                <CopyButton text={q.public_url} name="نسخ رابط العميل" />
                            </div>
                        )}

                        {/*
                            والبصمةُ تُعرض للقارئ لا للآلة: هي ما يُثبت بعد سنةٍ
                            أنّ ما قُبل هو هذا بعينه. وثمانيةُ أحرفٍ تكفي للعين،
                            والكاملةُ تُنسخ.
                        */}
                        {q.content_hash && (
                            <div className="mt-2 text-[11px] text-[#9ca3af]" dir="ltr">
                                {t('بصمة المحتوى')}: {q.content_hash.slice(0, 12)}…
                            </div>
                        )}
                    </Card>

                    {/* ─────────── دليلُ قرار العميل ─────────── */}
                    {/*
                        ما يجعل القبولَ دليلًا لا زرًّا: من قَبِل، وبأيّ إقرار،
                        ومن أين، ومتى، وبأيّ بصمة — وهل ما زالت البصمةُ تطابق
                        ما في القاعدة. و«لا يطابق» يُقال بالأحمر: مستندٌ مُسّ
                        بعد قبوله أخطرُ من مستندٍ لم يُقبل.
                    */}
                    {q.approval && (
                        <Card className={q.approval.decision === 'accepted' ? 'border-[#a7f3d0] p-4' : 'p-4'}>
                            <div className="mb-2 flex flex-wrap items-center gap-2">
                                <h2 className="text-[15px] font-semibold">
                                    {q.approval.decision === 'accepted'
                                        ? t('قبول إلكتروني من العميل')
                                        : t('اعتذار إلكتروني من العميل')}
                                </h2>
                                <span
                                    className={
                                        'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] ' +
                                        (q.approval.verified
                                            ? 'bg-[#ecfdf5] text-[#065f46]'
                                            : 'bg-[#fef2f2] text-[#b91c1c]')
                                    }
                                >
                                    <ShieldCheck className="size-3" />
                                    {q.approval.verified ? t('البصمة تطابق') : t('البصمة لا تطابق — تغيّر المستند بعد القرار')}
                                </span>
                            </div>
                            <dl className="grid gap-x-6 gap-y-2 text-[13px] sm:grid-cols-2">
                                <Row label="الاسم" value={q.approval.signer_name} />
                                <Row label="الصفة" value={q.approval.signer_title} />
                                <Row label="البريد" value={q.approval.signer_email} ltr />
                                <Row label="الهاتف" value={q.approval.signer_phone} ltr />
                                <Row label="في" value={q.approval.decided_at} ltr />
                                <Row label="من العنوان" value={q.approval.ip} ltr />
                                <Row label="سبب الاعتذار" value={q.approval.decline_reason} />
                            </dl>
                            {q.approval.consent_text && (
                                <p className="mt-3 rounded-md bg-[#fafafa] px-3 py-2 text-[12px] leading-relaxed text-[#52525b]">
                                    {q.approval.consent_text}
                                </p>
                            )}
                            <div className="mt-2 text-[11px] text-[#9ca3af]" dir="ltr">
                                {q.approval.content_hash}
                            </div>
                        </Card>
                    )}

                    {/* ─────────── استثناءات السياسات ─────────── */}
                    {q.exceptions.length > 0 && (
                        <Card className="p-4">
                            <h2 className="mb-3 flex items-center gap-2 text-[15px] font-semibold">
                                <ShieldAlert className="size-4 text-[#b45309]" />
                                {t('استثناءات من سياسة الشركة')}
                            </h2>
                            <ul className="space-y-3 text-[13px]">
                                {q.exceptions.map((e) => (
                                    <li key={e.id} className="rounded-lg border border-[#f4f4f5] p-3">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-semibold">{t(e.label)}</span>
                                            <span
                                                className={
                                                    'rounded-full px-2 py-0.5 text-[11px] ' +
                                                    (e.approved_at
                                                        ? 'bg-[#ecfdf5] text-[#065f46]'
                                                        : 'bg-[#fffbeb] text-[#b45309]')
                                                }
                                            >
                                                {e.approved_at
                                                    ? `${t('اعتمده')} ${e.approved_by ?? ''} · ${e.approved_at}`
                                                    : t('ينتظر اعتمادًا')}
                                            </span>
                                        </div>
                                        <div className="mt-1 text-[#52525b]">{e.reason}</div>
                                        <div className="mt-1 text-[12px] text-[#9ca3af]">
                                            {e.requested_by ? `${t('طلبه')} ${e.requested_by}` : ''}
                                            {e.surcharge_amount !== null && e.surcharge_amount > 0
                                                ? ` · ${t('رسوم')}: ${m(e.surcharge_amount)}`
                                                : ''}
                                            {e.detected && typeof e.detected.required_days === 'number'
                                                ? ` · ${t('المهلة :required والمتاح :available', {
                                                      required: String(e.detected.required_days),
                                                      available: String(e.detected.available_days ?? ''),
                                                  })}`
                                                : ''}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    )}

                    {/* ─────────── البنود وأسعارها ─────────── */}
                    <Card className="p-4">
                        <h2 className="mb-3 text-[15px] font-semibold">{t('البنود')}</h2>
                        <div className="overflow-x-auto">
                            <table className="w-full text-[13px]">
                                <thead className="text-[12px] text-[#6b7280]">
                                    <tr className="border-b border-[#e8e8e8]">
                                        <th className="py-2 text-start">{t('البيان')}</th>
                                        <th className="py-2 text-center">{t('الكمية')}</th>
                                        <th className="py-2 text-end">{t('السعر المعتمد')}</th>
                                        <th className="py-2 text-end">{t('السعر المعروض')}</th>
                                        <th className="py-2 text-end">{t('المبلغ')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {q.items.map((i) => (
                                        <tr key={i.id} className="border-b border-[#f4f4f5] align-top">
                                            <td className="py-2">
                                                <div className="font-medium">{i.description}</div>
                                                {i.notes && <div className="text-[11px] text-[#9ca3af]">{i.notes}</div>}
                                                {/*
                                                    وسببُ الخصم تحت البند لا في عمودٍ سادس:
                                                    هو جملةٌ تُقرأ مع ما تشرحه.
                                                */}
                                                {i.discount_reason && (
                                                    <div className="mt-1 text-[11px] text-[#b45309]">
                                                        {i.overridden ? t('فوق السعر') : t('خصم')} — {i.discount_reason}
                                                        {i.approved_by
                                                            ? ` · ${t('اعتمده')} ${i.approved_by}`
                                                            : i.awaiting_approval
                                                              ? ` · ${t('ينتظر اعتمادًا')}`
                                                              : ''}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="py-2 text-center tabular-nums">
                                                {number(i.quantity)}
                                                {i.unit ? ` ${i.unit}` : ''}
                                            </td>
                                            <td className="py-2 text-end tabular-nums text-[#9ca3af]">
                                                {m(i.list_price)}
                                            </td>
                                            <td className="py-2 text-end tabular-nums">
                                                {m(i.unit_price)}
                                                {i.discount_percent > 0 && (
                                                    <div className="text-[11px] text-[#b91c1c]">
                                                        − {i.discount_percent.toFixed(1)}٪
                                                    </div>
                                                )}
                                            </td>
                                            <td className="py-2 text-end font-medium tabular-nums">{m(i.line_total)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <div className="mt-3 space-y-1 border-t border-[#e8e8e8] pt-3 text-[13px]">
                            <div className="flex justify-between">
                                <span className="text-[#6b7280]">{t('المجموع الفرعي')}</span>
                                <span className="tabular-nums">{m(q.subtotal)}</span>
                            </div>
                            {q.discount_total > 0 && (
                                <div className="flex justify-between">
                                    <span className="text-[#6b7280]">{t('الخصم')}</span>
                                    <span className="tabular-nums text-[#b91c1c]">− {m(q.discount_total)}</span>
                                </div>
                            )}
                            <div className="flex justify-between">
                                <span className="text-[#6b7280]">{t('ضريبة القيمة المضافة')}</span>
                                <span className="tabular-nums">{m(q.tax_total)}</span>
                            </div>
                            <div className="flex justify-between border-t border-[#e8e8e8] pt-2 font-semibold">
                                <span>{t('الإجمالي')}</span>
                                <span className="tabular-nums">{m(q.total)}</span>
                            </div>
                        </div>
                    </Card>

                    {/* ─────────── سلسلة التنقيحات ─────────── */}
                    {chain.length > 1 && (
                        <Card className="p-4">
                            <h2 className="mb-1 text-[15px] font-semibold">{t('سلسلة التنقيحات')}</h2>
                            <p className="mb-3 text-[12px] text-[#9ca3af]">
                                {t('ما خرج إلى العميل يبقى كما خرج — والتنقيح نسخة جديدة لا تعديل على القديمة.')}
                            </p>
                            <ul className="space-y-2 text-[13px]">
                                {chain.map((c) => (
                                    <li key={c.id} className="flex items-center justify-between gap-3">
                                        <SmartLink
                                            routeName="admin.quotations.show"
                                            href={route('admin.quotations.show', c.id)}
                                            className={c.current ? 'font-semibold' : 'text-[#1d4ed8]'}
                                        >
                                            {t('تنقيح :n', { n: number(c.revision) })}
                                            {c.current ? ` — ${t('الحالي')}` : ''}
                                        </SmartLink>
                                        <span className={'rounded-full px-2 py-0.5 text-[11px] ' + stateTone(c.status)}>
                                            {t(STATE_LABEL[c.status] ?? c.status)}
                                        </span>
                                        <span className="tabular-nums text-[#6b7280]">{m(c.total)}</span>
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    )}

                    {/*
                        وسياساتُ الشركة كما تُقرأ على ورقته — من الختم إن خرج:
                        ورقةٌ أُرسلت في مارس تحمل نصَّ مارس ولو غُيّرت السياسةُ بعده.
                    */}
                    {q.policies.length > 0 && (
                        <Card className="p-4">
                            <h2 className="mb-1 text-[15px] font-semibold">{t('سياسات الشركة على هذا العرض')}</h2>
                            <p className="mb-3 text-[12px] text-[#9ca3af]">
                                {q.frozen
                                    ? t('كما خُتمت لحظة الإرسال — وتعديل السياسة بعده لا يمسّها.')
                                    : t('تُختم في العرض لحظة إرساله بنسختها.')}
                            </p>
                            <dl className="space-y-2 text-[13px]">
                                {q.policies.map((p) => (
                                    <div key={p.cap}>
                                        <dt className="font-medium">{t(p.cap)}</dt>
                                        <dd className="whitespace-pre-line text-[#52525b]">{p.text}</dd>
                                    </div>
                                ))}
                            </dl>
                        </Card>
                    )}

                    {q.internal_notes && (
                        <Card className="p-4">
                            <h2 className="mb-2 text-[15px] font-semibold">{t('ملاحظات داخلية')}</h2>
                            <p className="whitespace-pre-line text-[13px] text-[#52525b]">{q.internal_notes}</p>
                            <p className="mt-2 text-[12px] text-[#9ca3af]">{t('لا تظهر في الورقة المطبوعة.')}</p>
                        </Card>
                    )}
                </div>

                <div className="lg:sticky lg:top-4 lg:self-start">
                    <PaperFrame html={paper} title={t('ورقة عرض السعر')} viewport="min(72svh, 1000px)" />
                </div>
            </div>

            {/*
                ونافذةُ الإلغاء تسأل عن السبب لا عن التأكيد وحده.

                «هل أنت متأكد؟» لا تُبقي شيئًا يُقرأ بعد شهر. والسببُ يبقى في
                الصفّ ويُعرض على الورقة — انظر `Quotations::cancel`.
            */}
            <Dialog open={cancelling} onOpenChange={setCancelling}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle>{t('إلغاء عرض السعر')}</DialogTitle>
                        <DialogDescription>{t('العرض الملغى يبقى في السجل — ولا يُمحى.')}</DialogDescription>
                    </DialogHeader>
                    <div className="px-5">
                        <Field label="سبب الإلغاء" required>
                            <Input value={reason} onChange={(e) => setReason(e.target.value)} />
                        </Field>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setCancelling(false)}>
                            {t('تراجع')}
                        </Button>
                        <Button
                            variant="danger"
                            disabled={reason.trim() === ''}
                            onClick={() => {
                                router.post(
                                    route('admin.quotations.cancel', q.id),
                                    { reason },
                                    { preserveScroll: true },
                                );
                                setCancelling(false);
                                setReason('');
                            }}
                        >
                            {t('إلغاء العرض')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={deciding !== null} onOpenChange={(open) => !open && setDeciding(null)}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle>
                            {deciding === 'rejected' ? t('تسجيل رفض العميل') : t('تسجيل قبول العميل')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('يُسجَّل القرار نيابةً عن العميل — بعد مكالمة أو بريد. والقبول من رابطه يُسجَّل بنفسه.')}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-3 px-5">
                        <Field label="اسم من قرّر لدى العميل">
                            <Input value={decidedBy} onChange={(e) => setDecidedBy(e.target.value)} />
                        </Field>
                        {deciding === 'rejected' && (
                            <Field label="سبب الرفض" required hint="عرضٌ يُخسر بلا سبب مكتوب لا يُتعلَّم منه">
                                <Input value={rejectReason} onChange={(e) => setRejectReason(e.target.value)} />
                            </Field>
                        )}
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDeciding(null)}>
                            {t('تراجع')}
                        </Button>
                        <Button
                            disabled={deciding === 'rejected' && rejectReason.trim() === ''}
                            onClick={() => {
                                router.post(
                                    route('admin.quotations.decision', q.id),
                                    {
                                        decision: deciding,
                                        decided_by_name: decidedBy,
                                        rejection_reason: rejectReason,
                                    },
                                    { preserveScroll: true },
                                );
                                setDeciding(null);
                            }}
                        >
                            {t('تسجيل')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/*
                ونافذةُ البريد تسأل إلى من، وتقبل سطرًا مرافقًا. والعنوانُ
                يُملأ بما سبق: من أُرسل إليه أوّلًا، وإلّا بريدُ المُرسَل إليه
                في العرض.
            */}
            <Dialog open={mailing} onOpenChange={setMailing}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>{q.sent_to ? t('تذكير بالبريد') : t('إرسال العرض بالبريد')}</DialogTitle>
                        <DialogDescription>
                            {t('يصل العميلَ رابطُ العرض ليقرأه ويقبله إلكترونيًا — ويُسجَّل الإرسال في الأثر.')}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-3 px-5">
                        <Field label="بريد المُرسَل إليه" required>
                            <Input type="email" dir="ltr" value={mailTo} onChange={(e) => setMailTo(e.target.value)} />
                        </Field>
                        <Field label="رسالة مرافقة" hint="اختياري — تُكتب فوق الرابط">
                            <Textarea rows={3} value={mailNote} onChange={(e) => setMailNote(e.target.value)} />
                        </Field>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setMailing(false)}>
                            {t('تراجع')}
                        </Button>
                        <Button
                            disabled={mailTo.trim() === ''}
                            onClick={() => {
                                router.post(
                                    route('admin.quotations.email', q.id),
                                    { to: mailTo, note: mailNote },
                                    { preserveScroll: true },
                                );
                                setMailing(false);
                                setMailNote('');
                            }}
                        >
                            <Send />
                            {t('إرسال')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}

/** صفٌّ في قائمة الأثر — ولا يُرسم إن لم تكن له قيمة */
function Row({ label, value, ltr }: { label: string; value: string | null | undefined; ltr?: boolean }) {
    const t = useTranslate();

    if (!value) return null;

    return (
        <div className="flex justify-between gap-3 sm:block">
            <dt className="text-[#9ca3af]">{t(label)}</dt>
            <dd className="font-medium" dir={ltr ? 'ltr' : undefined}>
                {value}
            </dd>
        </div>
    );
}
