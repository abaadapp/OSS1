import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import {
    Check,
    FileText,
    Mail,
    Package,
    PackageCheck,
    Paperclip,
    Pencil,
    RotateCcw,
    Send,
    ShieldAlert,
    ShieldCheck,
    Slash,
    Trash2,
    X,
} from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import BackLink from '@/Components/BackLink';
import PageHeader from '@/Components/PageHeader';
import CommunicationHistory from '@/Components/CommunicationHistory';
import SmartLink from '@/Components/SmartLink';
import CopyButton from '@/Components/CopyButton';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input, Textarea } from '@/Components/ui/input';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { DELIVERY_STATE_LABEL, deliveryTone, fileSize } from '@/lib/deliveries';

interface Item {
    id: number;
    kind: 'file' | 'item';
    title: string;
    description: string | null;
    quantity: number | null;
    unit: string | null;
    name: string | null;
    size: number | null;
    mime: string | null;
    checksum: string | null;
    project_item_id: number | null;
    project_item: string | null;
    uploaded_by: string | null;
    url: string | null;
}

/** بندُ نطاقٍ من المشروع — وما بقي منه بعد ما سُلِّم وما في الطريق */
interface ScopeItem {
    id: number;
    description: string;
    unit: string | null;
    quantity: number;
    delivered_quantity: number;
    remaining: number;
    status: string;
}

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
    verified: boolean;
}

interface Readiness {
    ready: boolean;
    blockers: string[];
    accepted_count: number;
    awaiting_count: number;
    unresolved_decline_count: number;
    last_accepted_at: string | null;
}

interface PackageData {
    id: number;
    number: string | null;
    label: string;
    title: string;
    description: string | null;
    client_notes: string | null;
    internal_notes: string | null;
    contact_email: string | null;
    status: string;
    frozen: boolean;
    customer: string | null;
    customer_id: number | null;
    quotation_id: number | null;
    quotation: string | null;
    project_id: number | null;
    project: string | null;
    created_by: string | null;
    prepared_by: string | null;
    prepared_at: string | null;
    sent_by: string | null;
    sent_at: string | null;
    sent_to: string | null;
    first_viewed_at: string | null;
    view_count: number;
    accepted_at: string | null;
    declined_at: string | null;
    decided_by: string | null;
    decided_by_name: string | null;
    decline_reason: string | null;
    cancellation_reason: string | null;
    content_hash: string | null;
    public_url: string | null;
    supersedes: { id: number; label: string } | null;
    blockers: string[];
    items: Item[];
    approval: Approval | null;
    readiness: Readiness | null;
}

interface Props {
    package: PackageData;
    scope_items: ScopeItem[];
    customers: { id: number; name: string }[];
    quotations: { id: number; label: string; customer_id: number | null }[];
    projects: { id: number; label: string; customer_id: number | null; quotation_id: number | null }[];
    limits: { extensions: string[]; max_kb: number; max_items: number };
    may: {
        create: boolean;
        send: boolean;
        cancel: boolean;
        record_decision: boolean;
        email: boolean;
        mail_reason: string | null;
    };
}

/**
 * حزمةُ التسليم — بنودُها وملفّاتُها ودورتُها ودليلُ قبولها.
 *
 * ولا يُرسم مقبضٌ يردّه الخادم: ما لا يملكه القارئ لا يُعرض، وما لا تقبله
 * الحالةُ لا يُعرض. والدورةُ نفسُها في `DeliveryPackages` تقرأ الحالةَ
 * ثانيةً تحت قفل.
 */
export default function DeliveryPackageShow({ package: p, scope_items, customers, quotations, projects, limits, may }: Props) {
    const t = useTranslate();
    const { errors } = usePage().props as { errors?: Record<string, string> };

    const [editing, setEditing] = useState(false);
    const [cancelling, setCancelling] = useState(false);
    const [cancelReason, setCancelReason] = useState('');
    const [deciding, setDeciding] = useState<'accepted' | 'declined' | null>(null);
    const [decidedBy, setDecidedBy] = useState('');
    const [declineReason, setDeclineReason] = useState('');
    const [mailing, setMailing] = useState(false);
    const [mailTo, setMailTo] = useState(p.sent_to ?? p.contact_email ?? '');
    const [mailNote, setMailNote] = useState('');

    const post = (name: string) => router.post(route(name, p.id), {}, { preserveScroll: true });

    const editForm = useForm({
        title: p.title,
        description: p.description ?? '',
        client_notes: p.client_notes ?? '',
        internal_notes: p.internal_notes ?? '',
        contact_email: p.contact_email ?? '',
        customer_id: p.customer_id ? String(p.customer_id) : '',
        quotation_id: p.quotation_id ? String(p.quotation_id) : '',
        project_id: p.project_id ? String(p.project_id) : '',
    });

    const itemForm = useForm<{
        kind: 'file' | 'item';
        project_item_id: string;
        title: string;
        description: string;
        quantity: string;
        unit: string;
        file: File | null;
    }>({ kind: 'file', project_item_id: '', title: '', description: '', quantity: '', unit: '', file: null });

    /* واختيارُ بندِ نطاقٍ يملأ الباقي: العنوانُ والوحدةُ وما بقي منه */
    const chooseScope = (id: string) => {
        const s = scope_items.find((x) => String(x.id) === id);
        itemForm.setData((d) => ({
            ...d,
            project_item_id: id,
            title: s ? s.description : d.title,
            unit: s?.unit ?? d.unit,
            quantity: s ? String(Math.max(0, s.remaining)) : d.quantity,
        }));
    };

    const addItem = (e: React.FormEvent) => {
        e.preventDefault();
        itemForm.post(route('admin.deliveries.items.store', p.id), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => itemForm.reset(),
        });
    };

    const files = p.items.filter((i) => i.kind === 'file');
    const lines = p.items.filter((i) => i.kind === 'item');
    const editable = p.status === 'draft' && may.create;

    return (
        <AdminLayout title={p.label}>
            <BackLink routeName="admin.deliveries.index" href={route('admin.deliveries.index')} label="حزم التسليم" />

            <PageHeader
                title={p.number ? p.label : `${t('مسودة')} #${p.id}`}
                subtitle={p.title}
                actions={
                    <div className="flex flex-wrap gap-2">
                        {editable && (
                            <Button variant="outline" onClick={() => setEditing(true)}>
                                <Pencil />
                                {t('تعديل')}
                            </Button>
                        )}
                        {p.status === 'draft' && may.create && (
                            <Button onClick={() => post('admin.deliveries.ready')} disabled={p.blockers.length > 0}>
                                <PackageCheck />
                                {t('جاهزة للإرسال')}
                            </Button>
                        )}
                        {p.status === 'ready' && may.create && (
                            <Button variant="outline" onClick={() => post('admin.deliveries.reopen')}>
                                <RotateCcw />
                                {t('إعادة إلى المسودّة')}
                            </Button>
                        )}
                        {p.status === 'ready' && may.send && (
                            <Button onClick={() => post('admin.deliveries.send')}>
                                <Send />
                                {t('إرسال للعميل')}
                            </Button>
                        )}
                        {p.status === 'sent' && may.email && (
                            <Button variant="outline" onClick={() => setMailing(true)}>
                                <Mail />
                                {p.sent_to ? t('تذكير بالبريد') : t('إرسال بالبريد')}
                            </Button>
                        )}
                        {p.status === 'sent' && may.record_decision && (
                            <>
                                <Button variant="outline" onClick={() => setDeciding('accepted')}>
                                    <Check />
                                    {t('تسجيل القبول')}
                                </Button>
                                <Button variant="outline" onClick={() => setDeciding('declined')}>
                                    <X />
                                    {t('تسجيل الرفض')}
                                </Button>
                            </>
                        )}
                        {p.status === 'declined' && may.create && (
                            <Button onClick={() => post('admin.deliveries.revise')}>
                                <Package />
                                {t('إعداد حزمة معدَّلة')}
                            </Button>
                        )}
                        {['draft', 'ready', 'sent'].includes(p.status) && may.cancel && (
                            <Button variant="outline" onClick={() => setCancelling(true)}>
                                <Slash />
                                {t('إلغاء')}
                            </Button>
                        )}
                    </div>
                }
            />

            {errors?.package && (
                <div className="mb-4 rounded-lg bg-[#fef2f2] px-4 py-3 text-[13px] text-[#b91c1c]">{errors.package}</div>
            )}
            {errors?.email && (
                <div className="mb-4 rounded-lg bg-[#fef2f2] px-4 py-3 text-[13px] text-[#b91c1c]">{errors.email}</div>
            )}
            {errors?.decision && (
                <div className="mb-4 rounded-lg bg-[#fef2f2] px-4 py-3 text-[13px] text-[#b91c1c]">{errors.decision}</div>
            )}

            <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_400px]">
                <div className="space-y-4">
                    {/* ─────────── الحال ─────────── */}
                    <Card className="p-4">
                        <div className="mb-3 flex flex-wrap items-center gap-2">
                            <span className={'rounded-full px-2 py-1 text-[12px] ' + deliveryTone(p.status)}>
                                {t(DELIVERY_STATE_LABEL[p.status] ?? p.status)}
                            </span>
                            {p.supersedes && (
                                <SmartLink
                                    routeName="admin.deliveries.show"
                                    href={route('admin.deliveries.show', p.supersedes.id)}
                                    className="rounded-full bg-[#f4f4f5] px-2 py-1 text-[12px] text-[#52525b]"
                                >
                                    {t('تحلّ محلّ :label', { label: p.supersedes.label })}
                                </SmartLink>
                            )}
                        </div>

                        <dl className="grid gap-x-6 gap-y-2 text-[13px] sm:grid-cols-2">
                            <Row label="العميل" value={p.customer} />
                            <Row label="المشروع" value={p.project} />
                            <Row label="العرض" value={p.quotation} />
                            <Row label="أنشأها" value={p.created_by} />
                            <Row label="جهّزها" value={p.prepared_by ? `${p.prepared_by} · ${p.prepared_at}` : null} />
                            <Row label="أرسلها" value={p.sent_by ? `${p.sent_by} · ${p.sent_at}` : null} />
                            <Row label="أُرسلت بالبريد إلى" value={p.sent_to} ltr />
                            <Row label="فتحها العميل" value={p.first_viewed_at ? `${p.first_viewed_at} · ${t(':n مرة', { n: number(p.view_count) })}` : null} />
                            <Row label="قُبلت في" value={p.accepted_at} ltr />
                            <Row label="رُفضت في" value={p.declined_at} ltr />
                            <Row label="سجّل القرار" value={p.decided_by} />
                            <Row label="باسم" value={p.decided_by_name} />
                            <Row label="سبب الإلغاء" value={p.cancellation_reason} />
                        </dl>

                        {p.decline_reason && (
                            <div className="mt-3 rounded-lg bg-[#fef2f2] px-3 py-2 text-[13px] text-[#b91c1c]">
                                <span className="font-medium">{t('سبب الرفض')}: </span>
                                {p.decline_reason}
                            </div>
                        )}

                        {p.description && <p className="mt-3 whitespace-pre-wrap text-[13px] text-[#374151]">{p.description}</p>}
                    </Card>

                    {/* ─────────── الموانع ─────────── */}
                    {p.status === 'draft' && p.blockers.length > 0 && (
                        <Card className="border-[#fde68a] bg-[#fffbeb] p-4">
                            <div className="mb-1 flex items-center gap-2 text-[13px] font-bold text-[#b45309]">
                                <ShieldAlert className="size-4" />
                                {t('ما ينقص قبل الإرسال')}
                            </div>
                            <ul className="list-disc space-y-1 ps-5 text-[13px] text-[#92400e]">
                                {p.blockers.map((b) => (
                                    <li key={b}>{b}</li>
                                ))}
                            </ul>
                        </Card>
                    )}

                    {/* ─────────── البنود المسلَّمة ─────────── */}
                    <Card className="p-4">
                        <h2 className="mb-3 font-bold text-[#111]">{t('ما سُلِّم')}</h2>
                        {lines.length === 0 ? (
                            <p className="text-[13px] text-[#9ca3af]">{t('لا بنود خدمة بعد')}</p>
                        ) : (
                            <table className="w-full text-[13px]">
                                <thead className="text-[12px] text-[#9ca3af]">
                                    <tr>
                                        <th className="py-1 text-start font-normal">{t('البند')}</th>
                                        <th className="py-1 text-end font-normal">{t('الكمية')}</th>
                                        {editable && <th />}
                                    </tr>
                                </thead>
                                <tbody>
                                    {lines.map((i) => (
                                        <tr key={i.id} className="border-t border-[#f3f4f6]">
                                            <td className="py-2">
                                                {i.title}
                                                {i.project_item && i.project_item !== i.title && (
                                                    <div className="text-[11px] text-[#9ca3af]">{t('من النطاق')}: {i.project_item}</div>
                                                )}
                                                {i.description && <div className="text-[12px] text-[#6b7280]">{i.description}</div>}
                                            </td>
                                            <td className="py-2 text-end tabular-nums">
                                                {i.quantity === null ? '—' : number(i.quantity)} {i.unit ?? ''}
                                            </td>
                                            {editable && (
                                                <td className="py-2 text-end">
                                                    <button
                                                        type="button"
                                                        className="text-[#9ca3af] hover:text-[#b91c1c]"
                                                        onClick={() =>
                                                            router.delete(route('admin.deliveries.items.destroy', [p.id, i.id]), { preserveScroll: true })
                                                        }
                                                        aria-label={t('حذف')}
                                                    >
                                                        <Trash2 className="size-4" />
                                                    </button>
                                                </td>
                                            )}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </Card>

                    {/* ─────────── الملفّات والتقارير ─────────── */}
                    <Card className="p-4">
                        <h2 className="mb-3 font-bold text-[#111]">{t('الملفّات والتقارير')}</h2>
                        {files.length === 0 ? (
                            <p className="text-[13px] text-[#9ca3af]">{t('لا ملفات مرفقة بعد')}</p>
                        ) : (
                            <ul className="space-y-2 text-[13px]">
                                {files.map((i) => (
                                    <li key={i.id} className="flex items-start gap-2">
                                        <Paperclip className="mt-0.5 size-3.5 shrink-0 text-[#9ca3af]" />
                                        <div className="min-w-0 flex-1">
                                            {i.url ? (
                                                <a href={i.url} target="_blank" rel="noreferrer" className="font-medium text-[#1d4ed8] hover:underline">
                                                    {i.title}
                                                </a>
                                            ) : (
                                                <span className="font-medium">{i.title}</span>
                                            )}
                                            <div className="text-[11px] text-[#9ca3af]" dir="ltr">
                                                {i.name} · {fileSize(i.size)}
                                                {i.uploaded_by ? ` · ${i.uploaded_by}` : ''}
                                            </div>
                                            {i.description && <div className="text-[12px] text-[#6b7280]">{i.description}</div>}
                                        </div>
                                        {editable && (
                                            <button
                                                type="button"
                                                className="text-[#9ca3af] hover:text-[#b91c1c]"
                                                onClick={() =>
                                                    router.delete(route('admin.deliveries.items.destroy', [p.id, i.id]), { preserveScroll: true })
                                                }
                                                aria-label={t('حذف')}
                                            >
                                                <Trash2 className="size-4" />
                                            </button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>

                    {/* ─────────── إضافة بند ─────────── */}
                    {editable && p.items.length < limits.max_items && (
                        <Card className="p-4">
                            <h2 className="mb-3 font-bold text-[#111]">{t('إضافة بند')}</h2>
                            <form onSubmit={addItem} className="space-y-3">
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <Field label="النوع" required>
                                        <Select
                                            value={itemForm.data.kind}
                                            onChange={(e) => itemForm.setData('kind', e.target.value as 'file' | 'item')}
                                            options={[
                                                { value: 'file', label: t('ملف — تقرير أو صورة أو مخرَج') },
                                                { value: 'item', label: t('بند خدمة — بكمية') },
                                            ]}
                                        />
                                    </Field>
                                    <Field label="العنوان" required={itemForm.data.kind === 'item'} hint="للملفّ: اسمُه إن تُرك فارغًا" error={itemForm.errors.title}>
                                        <Input value={itemForm.data.title} onChange={(e) => itemForm.setData('title', e.target.value)} />
                                    </Field>
                                    {itemForm.data.kind === 'file' ? (
                                        <Field label="الملف" required error={itemForm.errors.file} className="sm:col-span-2">
                                            <input
                                                type="file"
                                                className="block w-full text-[13px]"
                                                accept={limits.extensions.map((e) => '.' + e).join(',')}
                                                onChange={(e) => itemForm.setData('file', e.target.files?.[0] ?? null)}
                                            />
                                        </Field>
                                    ) : (
                                        <>
                                            {p.project_id && (
                                                <Field label="بند النطاق" hint="من بنود المشروع — وما بقي من كلٍّ" error={itemForm.errors.project_item_id} className="sm:col-span-2">
                                                    <Select
                                                        value={itemForm.data.project_item_id}
                                                        onChange={(e) => chooseScope(e.target.value)}
                                                        placeholder="بند حرّ — خارج النطاق"
                                                        options={scope_items.map((s) => ({
                                                            value: String(s.id),
                                                            label: `${s.description} — ${t('بقي')} ${number(s.remaining)} / ${number(s.quantity)} ${s.unit ?? ''}`,
                                                        }))}
                                                    />
                                                </Field>
                                            )}
                                            <Field label="الكمية" error={itemForm.errors.quantity}>
                                                <Input
                                                    type="number"
                                                    step="0.001"
                                                    min="0"
                                                    dir="ltr"
                                                    value={itemForm.data.quantity}
                                                    onChange={(e) => itemForm.setData('quantity', e.target.value)}
                                                />
                                            </Field>
                                            <Field label="الوحدة" error={itemForm.errors.unit}>
                                                <Input value={itemForm.data.unit} onChange={(e) => itemForm.setData('unit', e.target.value)} placeholder={t('يوم · ساعة · وحدة')} />
                                            </Field>
                                        </>
                                    )}
                                    <Field label="وصف" hint="اختياري — يقرؤه العميل" error={itemForm.errors.description} className="sm:col-span-2">
                                        <Input value={itemForm.data.description} onChange={(e) => itemForm.setData('description', e.target.value)} />
                                    </Field>
                                </div>
                                {errors?.item && <p className="text-[12px] text-[#b91c1c]">{errors.item}</p>}
                                <div className="flex justify-end">
                                    <Button type="submit" disabled={itemForm.processing || (itemForm.data.kind === 'file' && !itemForm.data.file)}>
                                        <FileText />
                                        {t('إضافة البند')}
                                    </Button>
                                </div>
                            </form>
                        </Card>
                    )}
                </div>

                <div className="space-y-4">
                    {/* ─────────── الرابط ─────────── */}
                    {p.public_url && (
                        <Card className="p-4">
                            <h2 className="mb-2 font-bold text-[#111]">{t('رابط العميل')}</h2>
                            <p className="mb-2 text-[12px] text-[#6b7280]">{t('يفتحه العميل بلا حساب، يراجع ما سُلِّم ويقبله إلكترونيًا.')}</p>
                            <div className="flex items-center gap-2">
                                <Input readOnly dir="ltr" value={p.public_url} className="text-[12px]" />
                                <CopyButton text={p.public_url} label={t('نسخ')} variant="outline" size="sm" />
                            </div>
                            {may.mail_reason && <p className="mt-2 text-[11px] text-[#9ca3af]">{may.mail_reason}</p>}
                        </Card>
                    )}

                    {/* ─────────── دليلُ القرار ─────────── */}
                    {p.approval && (
                        <Card className={'p-4 ' + (p.approval.decision === 'accepted' ? 'border-[#a7f3d0] bg-[#ecfdf5]' : 'border-[#fecaca] bg-[#fef2f2]')}>
                            <div className="mb-2 flex items-center gap-2 font-bold text-[#111]">
                                {p.approval.verified ? <ShieldCheck className="size-4 text-[#047857]" /> : <ShieldAlert className="size-4 text-[#b91c1c]" />}
                                {p.approval.decision === 'accepted' ? t('قبول إلكتروني موثَّق') : t('رفض إلكتروني موثَّق')}
                            </div>
                            <dl className="grid gap-y-1 text-[13px]">
                                <Row label="الموقّع" value={p.approval.signer_name + (p.approval.signer_title ? ` — ${p.approval.signer_title}` : '')} />
                                <Row label="البريد" value={p.approval.signer_email} ltr />
                                <Row label="الهاتف" value={p.approval.signer_phone} ltr />
                                <Row label="الوقت" value={p.approval.decided_at} ltr />
                                <Row label="العنوان" value={p.approval.ip} ltr />
                                <Row label="سبب الرفض" value={p.approval.decline_reason} />
                            </dl>
                            <p className="mt-2 break-all font-mono text-[10px] text-[#6b7280]" dir="ltr">{p.approval.content_hash}</p>
                            <p className="mt-1 text-[11px] text-[#6b7280]">
                                {p.approval.verified ? t('البصمة تطابق اللقطة — لم يُمسّ المستند بعد القرار.') : t('البصمة لا تطابق اللقطة — مُسّ المستند بعد القرار!')}
                            </p>
                            {p.approval.consent_text && <p className="mt-2 text-[11px] leading-relaxed text-[#374151]">{p.approval.consent_text}</p>}
                        </Card>
                    )}

                    {/* ─────────── النطاقُ المعتمَد وما سُلِّم منه ─────────── */}
                    {scope_items.length > 0 && (
                        <Card className="p-4">
                            <h2 className="mb-2 font-bold text-[#111]">{t('النطاق المعتمَد')}</h2>
                            <table className="w-full text-[12px]">
                                <thead className="text-[11px] text-[#9ca3af]">
                                    <tr>
                                        <th className="py-1 text-start font-normal">{t('البند')}</th>
                                        <th className="py-1 text-end font-normal">{t('معتمَد')}</th>
                                        <th className="py-1 text-end font-normal">{t('سُلِّم')}</th>
                                        <th className="py-1 text-end font-normal">{t('بقي')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {scope_items.map((s) => (
                                        <tr key={s.id} className="border-t border-[#f3f4f6]">
                                            <td className="py-1">{s.description}</td>
                                            <td className="py-1 text-end tabular-nums">{number(s.quantity)}</td>
                                            <td className="py-1 text-end tabular-nums text-[#047857]">{number(s.delivered_quantity)}</td>
                                            <td className={'py-1 text-end tabular-nums ' + (s.remaining <= 0 ? 'text-[#9ca3af]' : 'text-[#b45309]')}>{number(s.remaining)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </Card>
                    )}

                    {/* ─────────── جاهزيّةُ الإتمام ─────────── */}
                    {p.readiness && (
                        <Card className="p-4">
                            <h2 className="mb-2 font-bold text-[#111]">{t('جاهزية الإتمام')}</h2>
                            {p.readiness.ready ? (
                                <p className="flex items-center gap-2 text-[13px] text-[#047857]">
                                    <Check className="size-4" />
                                    {t('التسليم مكتمل ومقبول — جاهز للفوترة النهائية.')}
                                </p>
                            ) : (
                                <ul className="list-disc space-y-1 ps-5 text-[13px] text-[#92400e]">
                                    {p.readiness.blockers.map((b) => (
                                        <li key={b}>{b}</li>
                                    ))}
                                </ul>
                            )}
                        </Card>
                    )}

                    {/* ─────────── الملاحظات ─────────── */}
                    {(p.client_notes || p.internal_notes) && (
                        <Card className="space-y-3 p-4">
                            {p.client_notes && (
                                <div>
                                    <h3 className="mb-1 text-[12px] text-[#9ca3af]">{t('ملاحظات للعميل')}</h3>
                                    <p className="whitespace-pre-wrap text-[13px]">{p.client_notes}</p>
                                </div>
                            )}
                            {p.internal_notes && (
                                <div>
                                    <h3 className="mb-1 text-[12px] text-[#9ca3af]">{t('ملاحظات داخلية')}</h3>
                                    <p className="whitespace-pre-wrap text-[13px]">{p.internal_notes}</p>
                                </div>
                            )}
                        </Card>
                    )}

                    {/* المراسلات — المرحلة 6 */}
                    {p.status !== 'draft' && p.status !== 'ready' && <CommunicationHistory subjectType="delivery_package" subjectId={p.id} />}

                    {p.content_hash && !p.approval && (
                        <p className="break-all font-mono text-[10px] text-[#9ca3af]" dir="ltr">
                            {t('بصمة المستند')}: {p.content_hash}
                        </p>
                    )}
                </div>
            </div>

            {/* ─────────── تعديل الرأس ─────────── */}
            <Dialog open={editing} onOpenChange={setEditing}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{t('تعديل الحزمة')}</DialogTitle>
                        <DialogDescription>{t('رأسُ الحزمة — والبنودُ تُضاف وتُحذف من صفحتها.')}</DialogDescription>
                    </DialogHeader>
                    <div className="space-y-3 px-5">
                        <Field label="عنوان الحزمة" required error={editForm.errors.title}>
                            <Input value={editForm.data.title} onChange={(e) => editForm.setData('title', e.target.value)} />
                        </Field>
                        <Field label="المشروع" hint="تبديلُه يُسقط وصلاتِ البنود بنطاقه" error={editForm.errors.project_id}>
                            <Select
                                value={editForm.data.project_id}
                                onChange={(e) => editForm.setData('project_id', e.target.value)}
                                placeholder="بلا مشروع"
                                options={projects.map((x) => ({ value: String(x.id), label: x.label }))}
                            />
                        </Field>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="العميل" error={editForm.errors.customer_id}>
                                <Select
                                    value={editForm.data.customer_id}
                                    onChange={(e) => editForm.setData('customer_id', e.target.value)}
                                    placeholder="اختر العميل"
                                    options={customers.map((c) => ({ value: String(c.id), label: c.name }))}
                                />
                            </Field>
                            <Field label="العرض المقبول" error={editForm.errors.quotation_id}>
                                <Select
                                    value={editForm.data.quotation_id}
                                    onChange={(e) => editForm.setData('quotation_id', e.target.value)}
                                    placeholder="بلا عرض"
                                    options={quotations.map((q) => ({ value: String(q.id), label: q.label }))}
                                />
                            </Field>
                        </div>
                        <Field label="بريد جهة الاتصال" error={editForm.errors.contact_email}>
                            <Input type="email" dir="ltr" value={editForm.data.contact_email} onChange={(e) => editForm.setData('contact_email', e.target.value)} />
                        </Field>
                        <Field label="وصف" error={editForm.errors.description}>
                            <Textarea rows={2} value={editForm.data.description} onChange={(e) => editForm.setData('description', e.target.value)} />
                        </Field>
                        <Field label="ملاحظات للعميل" error={editForm.errors.client_notes}>
                            <Textarea rows={2} value={editForm.data.client_notes} onChange={(e) => editForm.setData('client_notes', e.target.value)} />
                        </Field>
                        <Field label="ملاحظات داخلية" error={editForm.errors.internal_notes}>
                            <Textarea rows={2} value={editForm.data.internal_notes} onChange={(e) => editForm.setData('internal_notes', e.target.value)} />
                        </Field>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setEditing(false)}>
                            {t('تراجع')}
                        </Button>
                        <Button
                            disabled={editForm.processing}
                            onClick={() =>
                                editForm.put(route('admin.deliveries.update', p.id), {
                                    preserveScroll: true,
                                    onSuccess: () => setEditing(false),
                                })
                            }
                        >
                            {t('حفظ')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ─────────── الإلغاء ─────────── */}
            <Dialog open={cancelling} onOpenChange={setCancelling}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('إلغاء الحزمة')}</DialogTitle>
                        <DialogDescription>{t('إلغاءٌ داخليّ قبل قرار العميل — يبقى في الأثر بسببه.')}</DialogDescription>
                    </DialogHeader>
                    <div className="px-5">
                        <Field label="سبب الإلغاء" required>
                            <Input value={cancelReason} onChange={(e) => setCancelReason(e.target.value)} />
                        </Field>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setCancelling(false)}>
                            {t('تراجع')}
                        </Button>
                        <Button
                            variant="danger"
                            disabled={cancelReason.trim() === ''}
                            onClick={() => {
                                router.post(route('admin.deliveries.cancel', p.id), { reason: cancelReason }, { preserveScroll: true });
                                setCancelling(false);
                            }}
                        >
                            {t('إلغاء الحزمة')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ─────────── قرارٌ نيابةً ─────────── */}
            <Dialog open={deciding !== null} onOpenChange={(open) => !open && setDeciding(null)}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>{deciding === 'accepted' ? t('تسجيل قبول العميل') : t('تسجيل رفض العميل')}</DialogTitle>
                        <DialogDescription>
                            {t('قرارٌ وصل ببريدٍ أو اتصال — يُسجَّل خبرًا باسمك، لا دليلًا كقبول الرابط.')}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-3 px-5">
                        <Field label="باسم من؟" hint="من قرّر عند العميل">
                            <Input value={decidedBy} onChange={(e) => setDecidedBy(e.target.value)} />
                        </Field>
                        {deciding === 'declined' && (
                            <Field label="سبب الرفض" required>
                                <Textarea rows={3} value={declineReason} onChange={(e) => setDeclineReason(e.target.value)} />
                            </Field>
                        )}
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDeciding(null)}>
                            {t('تراجع')}
                        </Button>
                        <Button
                            disabled={deciding === 'declined' && declineReason.trim() === ''}
                            onClick={() => {
                                router.post(
                                    route('admin.deliveries.decision', p.id),
                                    { decision: deciding, decided_by_name: decidedBy, decline_reason: declineReason },
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

            {/* ─────────── البريد ─────────── */}
            <Dialog open={mailing} onOpenChange={setMailing}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>{p.sent_to ? t('تذكير بالبريد') : t('إرسال الحزمة بالبريد')}</DialogTitle>
                        <DialogDescription>{t('يصل العميلَ رابطُ الحزمة ليراجعها ويقبلها إلكترونيًا — ويُسجَّل الإرسال في الأثر.')}</DialogDescription>
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
                                router.post(route('admin.deliveries.email', p.id), { to: mailTo, note: mailNote }, { preserveScroll: true });
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
