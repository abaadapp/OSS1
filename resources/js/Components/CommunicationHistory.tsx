import { useCallback, useEffect, useState } from 'react';
import { AlertTriangle, Mail, MessageCircle, Send } from 'lucide-react';
import SmartLink from '@/Components/SmartLink';
import SendMessageDialog, { SUBJECT_ROUTES } from '@/Components/SendMessageDialog';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { useTranslate } from '@/lib/i18n';
import { CHANNEL_LABEL, STATUS_LABEL, statusTone, type CommunicationRow } from '@/lib/communications';

interface Props {
    subjectType: string;
    subjectId: number;
    /** عنوانُ البطاقة — «المراسلات» افتراضًا */
    title?: string;
    className?: string;
}

interface History {
    communications: CommunicationRow[];
    may: { send: boolean; retry: boolean; templates: boolean; view: boolean; hub: boolean };
}

/**
 * بطاقةُ مراسلات مستندٍ — ما أُرسل عنه، وزرُّ الإرسال.
 *
 * تُقرأ من بابها في قسم المستند نفسِه (`{section}.communications`)، فيراها
 * من يعمل على المستند ولو لم يُمنح سجلَّ المراسلات كلَّه. وما لا يُؤذَن به
 * (٤٠٣) تُطوى البطاقةُ عنه صامتةً: لا خطأَ يُعرض لمن لم يُمنح.
 */
export default function CommunicationHistory({ subjectType, subjectId, title = 'المراسلات', className }: Props) {
    const t = useTranslate();
    const [history, setHistory] = useState<History | null>(null);
    const [denied, setDenied] = useState(false);
    const [sending, setSending] = useState(false);

    const base = SUBJECT_ROUTES[subjectType];

    const load = useCallback(() => {
        if (!base) return;
        fetch(route(`${base}.communications`, subjectId), { headers: { Accept: 'application/json' } })
            .then(async (r) => {
                if (r.status === 403) {
                    setDenied(true);
                    return null;
                }
                if (!r.ok) return null;
                return (await r.json()) as History;
            })
            .then((data) => data && setHistory(data))
            .catch(() => undefined);
    }, [base, subjectId]);

    useEffect(() => {
        load();
    }, [load]);

    if (denied || !base) return null;

    const rows = history?.communications ?? [];

    return (
        <Card className={'p-4 ' + (className ?? '')}>
            <div className="mb-3 flex items-center justify-between gap-2">
                <h2 className="font-bold text-[#111]">{t(title)}</h2>
                {history?.may.send && (
                    <Button size="sm" variant="outline" onClick={() => setSending(true)}>
                        <Send />
                        {t('إرسال للعميل')}
                    </Button>
                )}
            </div>

            {history === null ? (
                <p className="text-[13px] text-[#9ca3af]">{t('جارٍ التحميل…')}</p>
            ) : rows.length === 0 ? (
                <p className="text-[13px] text-[#9ca3af]">{t('لم يُرسَل شيءٌ عن هذا المستند بعد')}</p>
            ) : (
                <ul className="divide-y divide-[#f3f4f6] text-[13px]">
                    {rows.map((c) => (
                        <li key={c.id} className="flex items-start gap-3 py-2">
                            <span className="mt-0.5 shrink-0 text-[#6b7280]">
                                {c.channel === 'whatsapp' ? <MessageCircle className="size-4" /> : <Mail className="size-4" />}
                            </span>
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="font-medium text-[#111]">{c.template_name}</span>
                                    {c.internal && (
                                        <span className="rounded-full bg-[#f3f4f6] px-2 py-0.5 text-[11px] text-[#6b7280]">{t('إشعار داخلي')}</span>
                                    )}
                                    <span className={'rounded-full px-2 py-0.5 text-[11px] ' + statusTone(c.status)}>
                                        {t(STATUS_LABEL[c.status] ?? c.status)}
                                    </span>
                                </div>
                                <div className="truncate text-[12px] text-[#6b7280]">
                                    {t(CHANNEL_LABEL[c.channel] ?? c.channel)} · <span dir="ltr">{c.recipient}</span>
                                    {c.initiated_by ? ` · ${c.initiated_by}` : ''}
                                </div>
                                {(c.status === 'failed' || c.status === 'bounced') && c.failure_reason && (
                                    <div className="mt-1 flex items-start gap-1 text-[12px] text-[#b91c1c]">
                                        <AlertTriangle className="mt-0.5 size-3 shrink-0" />
                                        <span>{c.failure_reason}</span>
                                    </div>
                                )}
                            </div>
                            <div className="shrink-0 text-end text-[11px] text-[#9ca3af]" dir="ltr">
                                {history.may.hub ? (
                                    <SmartLink routeName="admin.communications.show" href={route('admin.communications.show', c.id)} className="text-[#1d4ed8]">
                                        {c.created_at}
                                    </SmartLink>
                                ) : (
                                    c.created_at
                                )}
                                {c.read_at ? <div>{t('قُرئت')} {c.read_at}</div> : c.delivered_at ? <div>{t('وصلت')} {c.delivered_at}</div> : null}
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            {history?.may.send && (
                <SendMessageDialog subjectType={subjectType} subjectId={subjectId} open={sending} onOpenChange={setSending} onSent={load} />
            )}
        </Card>
    );
}
