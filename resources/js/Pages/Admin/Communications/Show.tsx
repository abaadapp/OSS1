import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { AlertTriangle, Check, Eye, Mail, MessageCircle, RotateCcw, Send } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import BackLink from '@/Components/BackLink';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { useTranslate } from '@/lib/i18n';
import { CHANNEL_LABEL, STATUS_LABEL, SUBJECT_LABEL, statusTone, type CommunicationRow } from '@/lib/communications';

interface Detail extends CommunicationRow {
    language: string | null;
    provider: string | null;
    provider_message_id: string | null;
    failure_code: string | null;
    queued_at: string | null;
    url: string | null;
    variables: Record<string, string> | null;
    subject_route: string | null;
    retryable: boolean;
    retry_of: { id: number; status: string; created_at: string | null } | null;
    retries: { id: number; status: string; recipient: string; created_at: string | null; by: string | null }[];
}

interface Event {
    id: number;
    kind: string;
    status: string | null;
    occurred_at: string | null;
    code: string | null;
    description: string | null;
    provider: string | null;
}

interface Props {
    communication: Detail;
    events: Event[];
    may: { send: boolean; retry: boolean; templates: boolean; view: boolean };
}

const KIND_LABEL: Record<string, string> = {
    sent: 'قبلها المزوّد',
    accepted: 'قبلها المزوّد',
    delivered: 'وصلت',
    read: 'قُرئت',
    opened: 'فُتحت',
    clicked: 'ضُغط رابطها',
    failed: 'فشلت',
    bounced: 'ارتدّت',
    complaint: 'أُبلغ عنها كبريد مزعج',
};

/**
 * رسالةٌ واحدة — إلى من، وعبر ماذا، وبأيّ قالب، وماذا قال المزوّد ومتى.
 *
 * ولا سرَّ هنا: معرّفُ المزوّد للرسالة يُعرض (هو ما يُبحث به عنده)، أمّا
 * رموزُ الوصول فلا تبلغ الشاشةَ أصلًا — انظر `Communications::sanitize`.
 */
export default function CommunicationShow({ communication: c, events, may }: Props) {
    const t = useTranslate();
    const { errors } = usePage().props as { errors?: Record<string, string> };
    const [retrying, setRetrying] = useState(false);
    const [to, setTo] = useState(c.recipient);

    const stamps: { label: string; at: string | null; icon: typeof Check }[] = [
        { label: 'أُرسلت', at: c.sent_at, icon: Send },
        { label: 'وصلت', at: c.delivered_at, icon: Check },
        { label: 'قُرئت', at: c.read_at, icon: Eye },
        { label: c.status === 'bounced' ? 'ارتدّت' : 'فشلت', at: c.failed_at, icon: AlertTriangle },
    ];

    return (
        <AdminLayout title={t('المراسلات')}>
            <BackLink routeName="admin.communications.index" href={route('admin.communications.index')} label="المراسلات" />

            <PageHeader
                title={c.template_name}
                subtitle={c.title}
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        <span className={'rounded-full px-3 py-1 text-[12px] ' + statusTone(c.status)}>{t(STATUS_LABEL[c.status] ?? c.status)}</span>
                        {may.retry && c.retryable && (
                            <Button variant="outline" onClick={() => setRetrying(true)}>
                                <RotateCcw />
                                {t('إعادة الإرسال')}
                            </Button>
                        )}
                    </div>
                }
            />

            {errors?.retry && (
                <div className="mb-4 rounded-lg bg-[#fef2f2] px-4 py-3 text-[13px] text-[#b91c1c]">{errors.retry}</div>
            )}

            <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_360px]">
                <div className="space-y-4">
                    <Card className="p-4">
                        <h2 className="mb-3 font-bold text-[#111]">{t('الرسالة')}</h2>
                        <dl className="grid gap-x-6 gap-y-2 text-[13px] sm:grid-cols-2">
                            <Row label="القناة" value={t(CHANNEL_LABEL[c.channel] ?? c.channel)} icon={c.channel === 'whatsapp' ? MessageCircle : Mail} />
                            <Row label="إلى" value={c.recipient_name ? `${c.recipient_name} — ${c.recipient}` : c.recipient} ltr={!c.recipient_name} />
                            <Row label="القالب" value={`${c.template_name} (${c.template_key})`} />
                            <Row label="اللغة" value={c.language === 'en' ? t('الإنجليزية') : t('العربية')} />
                            <Row label="العميل" value={c.customer} />
                            <Row label="أرسلها" value={c.initiated_by} />
                            <Row label="المشروع" value={c.project} />
                            <Row label="النوع" value={c.internal ? t('إشعار داخلي إلى الشركة') : t('رسالة إلى العميل')} />
                        </dl>

                        {(c.subject_label || c.subject_route) && (
                            <div className="mt-3 rounded-lg bg-[#f9fafb] px-3 py-2 text-[13px]">
                                <span className="text-[#9ca3af]">{t('عن')}: </span>
                                {c.subject_route ? (
                                    <a href={c.subject_route} className="text-[#1d4ed8]">
                                        {c.subject_label}
                                    </a>
                                ) : (
                                    <span>{c.subject_label ?? (c.subject_type ? t(SUBJECT_LABEL[c.subject_type] ?? c.subject_type) : '—')}</span>
                                )}
                            </div>
                        )}

                        {c.url && (
                            <div className="mt-2 text-[12px] text-[#6b7280]">
                                <span className="text-[#9ca3af]">{t('الرابط')}: </span>
                                <a href={c.url} dir="ltr" className="break-all text-[#1d4ed8]" target="_blank" rel="noreferrer">
                                    {c.url}
                                </a>
                            </div>
                        )}
                    </Card>

                    {(c.status === 'failed' || c.status === 'bounced') && (
                        <Card className="border-[#fecaca] bg-[#fef2f2] p-4">
                            <h2 className="mb-1 flex items-center gap-2 font-bold text-[#b91c1c]">
                                <AlertTriangle className="size-4" />
                                {t('لماذا لم تصل')}
                            </h2>
                            <p className="text-[13px] text-[#7f1d1d]">{c.failure_reason ?? t('لم يذكر المزوّد سببًا')}</p>
                            {c.failure_code && (
                                <p className="mt-1 text-[11px] text-[#9ca3af]" dir="ltr">
                                    {c.failure_code}
                                </p>
                            )}
                        </Card>
                    )}

                    <Card className="p-4">
                        <h2 className="mb-3 font-bold text-[#111]">{t('ما قاله المزوّد')}</h2>
                        {events.length === 0 ? (
                            <p className="text-[13px] text-[#9ca3af]">{t('لم يصل خبرٌ من المزوّد بعد')}</p>
                        ) : (
                            <ul className="divide-y divide-[#f3f4f6] text-[13px]">
                                {events.map((e) => (
                                    <li key={e.id} className="flex items-start justify-between gap-3 py-2">
                                        <div>
                                            <div className="font-medium text-[#111]">{t(KIND_LABEL[e.kind] ?? e.kind)}</div>
                                            {(e.description || e.code) && (
                                                <div className="text-[12px] text-[#6b7280]">
                                                    {e.description}
                                                    {e.code ? <span dir="ltr" className="ms-1 text-[#9ca3af]">({e.code})</span> : null}
                                                </div>
                                            )}
                                        </div>
                                        <div className="shrink-0 text-[11px] text-[#9ca3af]" dir="ltr">
                                            {e.occurred_at}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>

                    {c.variables && Object.keys(c.variables).length > 0 && (
                        <Card className="p-4">
                            <h2 className="mb-3 font-bold text-[#111]">{t('ما مُلئ في القالب')}</h2>
                            <dl className="grid gap-x-6 gap-y-1 text-[12px] sm:grid-cols-2">
                                {Object.entries(c.variables)
                                    .filter(([, v]) => v !== '' && v !== null)
                                    .map(([k, v]) => (
                                        <div key={k} className="flex justify-between gap-3">
                                            <dt className="text-[#9ca3af]" dir="ltr">
                                                {k}
                                            </dt>
                                            <dd className="truncate text-[#374151]">{v}</dd>
                                        </div>
                                    ))}
                            </dl>
                        </Card>
                    )}
                </div>

                <div className="space-y-4">
                    <Card className="p-4">
                        <h2 className="mb-3 font-bold text-[#111]">{t('المسار')}</h2>
                        <ol className="space-y-2 text-[13px]">
                            {stamps.map((s) =>
                                s.at ? (
                                    <li key={s.label} className="flex items-center gap-2">
                                        <s.icon className={'size-4 ' + (s.label === 'فشلت' || s.label === 'ارتدّت' ? 'text-[#b91c1c]' : 'text-[#047857]')} />
                                        <span className="flex-1">{t(s.label)}</span>
                                        <span className="text-[11px] text-[#9ca3af]" dir="ltr">
                                            {s.at}
                                        </span>
                                    </li>
                                ) : null,
                            )}
                        </ol>
                    </Card>

                    <Card className="p-4">
                        <h2 className="mb-3 font-bold text-[#111]">{t('عند المزوّد')}</h2>
                        <dl className="space-y-1 text-[12px]">
                            <div className="flex justify-between gap-3">
                                <dt className="text-[#9ca3af]">{t('المزوّد')}</dt>
                                <dd dir="ltr">{c.provider ?? '—'}</dd>
                            </div>
                            <div className="flex justify-between gap-3">
                                <dt className="shrink-0 text-[#9ca3af]">{t('معرّف الرسالة')}</dt>
                                <dd dir="ltr" className="break-all text-end">
                                    {c.provider_message_id ?? '—'}
                                </dd>
                            </div>
                        </dl>
                    </Card>

                    {(c.retry_of || c.retries.length > 0) && (
                        <Card className="p-4">
                            <h2 className="mb-3 font-bold text-[#111]">{t('سلسلة الإعادة')}</h2>
                            <ul className="space-y-2 text-[13px]">
                                {c.retry_of && (
                                    <li>
                                        <span className="text-[#9ca3af]">{t('إعادةٌ لـ')} </span>
                                        <SmartLink routeName="admin.communications.show" href={route('admin.communications.show', c.retry_of.id)} className="text-[#1d4ed8]">
                                            #{c.retry_of.id}
                                        </SmartLink>
                                        <span className="ms-2 text-[11px] text-[#9ca3af]" dir="ltr">
                                            {c.retry_of.created_at}
                                        </span>
                                    </li>
                                )}
                                {c.retries.map((r) => (
                                    <li key={r.id} className="flex items-center justify-between gap-2">
                                        <span>
                                            <SmartLink routeName="admin.communications.show" href={route('admin.communications.show', r.id)} className="text-[#1d4ed8]">
                                                #{r.id}
                                            </SmartLink>
                                            <span className="ms-2 text-[#6b7280]" dir="ltr">
                                                {r.recipient}
                                            </span>
                                        </span>
                                        <span className={'rounded-full px-2 py-0.5 text-[11px] ' + statusTone(r.status)}>{t(STATUS_LABEL[r.status] ?? r.status)}</span>
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    )}
                </div>
            </div>

            <Dialog open={retrying} onOpenChange={setRetrying}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('إعادة الإرسال')}</DialogTitle>
                        <DialogDescription>
                            {t('تُرسَل الرسالةُ من جديد بقالبها نفسِه، ويُقيَّد صفٌّ جديد يشير إلى هذا. صحّح العنوان إن كان الخطأ فيه.')}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-3 px-5">
                        <Field label={c.channel === 'whatsapp' ? 'رقم المُرسَل إليه' : 'بريد المُرسَل إليه'} required>
                            <Input dir="ltr" value={to} onChange={(e) => setTo(e.target.value)} />
                        </Field>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setRetrying(false)}>
                            {t('تراجع')}
                        </Button>
                        <Button
                            disabled={to.trim() === ''}
                            onClick={() => {
                                router.post(route('admin.communications.retry', c.id), { to }, { preserveScroll: true });
                                setRetrying(false);
                            }}
                        >
                            <RotateCcw />
                            {t('أعد الإرسال')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}

function Row({ label, value, ltr, icon: Icon }: { label: string; value: string | null | undefined; ltr?: boolean; icon?: typeof Mail }) {
    const t = useTranslate();

    if (!value) return null;

    return (
        <div className="flex justify-between gap-3 sm:block">
            <dt className="text-[#9ca3af]">{t(label)}</dt>
            <dd className="flex items-center gap-1 text-[#111]" dir={ltr ? 'ltr' : undefined}>
                {Icon && <Icon className="size-4 text-[#6b7280]" />}
                {value}
            </dd>
        </div>
    );
}
