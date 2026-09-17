import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Check, Copy, Download, Eye, Pencil, Send, ShieldCheck, Slash, X } from 'lucide-react';
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
import { Input } from '@/Components/ui/input';
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
    items: Item[];
}

interface Props {
    quotation: Quotation;
    paper: string;
    chain: { id: number; label: string; revision: number; status: string; total: number; sent_at: string | null; current: boolean }[];
    may: {
        create: boolean;
        send: boolean;
        approve: boolean;
        cancel: boolean;
        record_decision: boolean;
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

                        {awaitingApproval && may.approve && (
                            <Button onClick={() => post('admin.quotations.approve')}>
                                <ShieldCheck />
                                {t('اعتماد الخصم')}
                            </Button>
                        )}

                        {q.status === 'draft' && may.send && (
                            <Button onClick={() => post('admin.quotations.send')}>
                                <Send />
                                {t('إرسال للعميل')}
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
                    {t('هذا العرض ينتظر اعتماد الخصم — لا يخرج إلى العميل قبله.')}
                </div>
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
                            <Row label="قرار العميل" value={q.decided_at} ltr />
                            <Row label="سجّله" value={q.decided_by_name} />
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
                            {t('يُسجَّل القرار نيابةً عن العميل — والقبول الإلكتروني الكامل يأتي لاحقًا.')}
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
