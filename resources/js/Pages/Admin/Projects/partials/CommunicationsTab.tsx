import { useCallback, useEffect, useState } from 'react';
import {
    Activity,
    AlertTriangle,
    Check,
    Eye,
    Mail,
    MessageCircle,
    MessageSquare,
    PauseCircle,
    Pencil,
    PlusCircle,
    RefreshCw,
    Send,
    Settings,
    ShieldCheck,
    Trash2,
    Undo2,
    type LucideIcon,
} from 'lucide-react';
import SmartLink from '@/Components/SmartLink';
import SendMessageDialog from '@/Components/SendMessageDialog';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import TabHead from '@/Components/project/TabHead';
import { useTranslate } from '@/lib/i18n';
import { CHANNEL_LABEL, clock, day, STATUS_LABEL, statusTone, type TimelineRow } from '@/lib/communications';

/** خريطةٌ صريحة لأسماء أيقونات الأثر — الاستيرادُ الشامل يضخّ المكتبةَ كلَّها */
const ICONS: Record<string, LucideIcon> = {
    activity: Activity,
    'alert-triangle': AlertTriangle,
    check: Check,
    eye: Eye,
    mail: Mail,
    'message-circle': MessageCircle,
    'message-square': MessageSquare,
    'pause-circle': PauseCircle,
    pencil: Pencil,
    'plus-circle': PlusCircle,
    'refresh-cw': RefreshCw,
    send: Send,
    settings: Settings,
    'shield-check': ShieldCheck,
    'trash-2': Trash2,
    'undo-2': Undo2,
};

const TONE: Record<string, string> = {
    success: 'bg-[#ecfdf5] text-[#047857]',
    danger: 'bg-[#fef2f2] text-[#b91c1c]',
    warning: 'bg-[#fffbeb] text-[#b45309]',
    info: 'bg-[#eff6ff] text-[#1d4ed8]',
    primary: 'bg-[#f3f4f6] text-[#374151]',
    gray: 'bg-[#f3f4f6] text-[#6b7280]',
};

interface Payload {
    timeline: TimelineRow[];
    may: { send: boolean; retry: boolean; templates: boolean; view: boolean; hub: boolean };
}

/**
 * تبويبُ المراسلات في المشروع — قصّتُه بترتيبها.
 *
 * ما فعله الفريق وما قيل للعميل وما ردّ به المزوّد، في خطٍّ واحدٍ مقسومٍ
 * بالأيّام. تاريخٌ تشغيليّ يُقرأ، لا سجلُّ تقنيّاتٍ يُفكّ.
 */
export default function CommunicationsTab({ projectId, open }: { projectId: number; open: boolean }) {
    const t = useTranslate();
    const [data, setData] = useState<Payload | null>(null);
    const [denied, setDenied] = useState(false);
    const [sending, setSending] = useState(false);

    const load = useCallback(() => {
        fetch(route('admin.projects.timeline', projectId), { headers: { Accept: 'application/json' } })
            .then(async (r) => {
                if (r.status === 403) {
                    setDenied(true);
                    return null;
                }
                return r.ok ? ((await r.json()) as Payload) : null;
            })
            .then((d) => d && setData(d))
            .catch(() => undefined);
    }, [projectId]);

    useEffect(() => {
        load();
    }, [load]);

    if (denied) {
        return (
            <Card className="p-4">
                <p className="text-[13px] text-[#9ca3af]">{t('لم تُمنح رؤية المراسلات')}</p>
            </Card>
        );
    }

    const rows = data?.timeline ?? [];
    const days: { day: string; rows: TimelineRow[] }[] = [];
    for (const r of rows) {
        const d = day(r.at);
        const last = days[days.length - 1];
        if (last && last.day === d) last.rows.push(r);
        else days.push({ day: d, rows: [r] });
    }

    return (
        <Card className="p-4">
            {/* سطرُ خلاصةٍ ثمّ الفعل — وما التبويبُ إلّا ما جرى وما قيل للعميل بترتيب وقوعه */}
            <TabHead
                facts={data === null ? [] : [{ label: 'الوقائع', n: rows.length }]}
                action={
                    data?.may.send && open && (
                        <Button size="sm" onClick={() => setSending(true)}>
                            <Send />
                            {t('إرسال للعميل')}
                        </Button>
                    )
                }
            />

            {data === null ? (
                <p className="text-[13px] text-[#9ca3af]">{t('جارٍ التحميل…')}</p>
            ) : rows.length === 0 ? (
                <p className="text-[13px] text-[#9ca3af]">{t('لا شيء بعد')}</p>
            ) : (
                <div className="space-y-5">
                    {days.map((g) => (
                        <div key={g.day}>
                            <div className="mb-2 text-[12px] font-bold text-[#6b7280]" dir="ltr">
                                {g.day}
                            </div>
                            <ol className="relative ms-3 space-y-3 border-s border-[#e5e7eb] ps-5">
                                {g.rows.map((r, i) => {
                                    const Icon = ICONS[r.icon] ?? (r.kind === 'communication' ? Mail : Activity);
                                    const tone = TONE[r.color] ?? TONE.primary;

                                    return (
                                        <li key={`${r.at}-${i}`} className="relative">
                                            <span className={'absolute -start-[31px] top-0 flex size-6 items-center justify-center rounded-full ' + tone}>
                                                <Icon className="size-3.5" />
                                            </span>
                                            <div className="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 text-[13px]">
                                                <span className="tabular-nums text-[#9ca3af]" dir="ltr">
                                                    {clock(r.at).slice(11)}
                                                </span>
                                                <span className="font-medium text-[#111]">{r.title}</span>
                                                {r.kind === 'communication' && r.status && (
                                                    <span className={'rounded-full px-2 py-0.5 text-[11px] ' + statusTone(r.status)}>
                                                        {t(STATUS_LABEL[r.status] ?? r.status)}
                                                    </span>
                                                )}
                                                {r.internal && <span className="rounded-full bg-[#f3f4f6] px-2 py-0.5 text-[11px] text-[#6b7280]">{t('إشعار داخلي')}</span>}
                                            </div>
                                            {(r.detail || r.by || r.channel) && (
                                                <div className="text-[12px] text-[#6b7280]">
                                                    {r.detail}
                                                    {r.channel ? ` · ${t(CHANNEL_LABEL[r.channel] ?? r.channel)}` : ''}
                                                    {r.by ? ` · ${r.by}` : ''}
                                                    {r.kind === 'communication' && r.communication_id && data.may.hub && (
                                                        <>
                                                            {' · '}
                                                            <SmartLink
                                                                routeName="admin.communications.show"
                                                                href={route('admin.communications.show', r.communication_id)}
                                                                className="text-[#1d4ed8]"
                                                            >
                                                                {t('التفاصيل')}
                                                            </SmartLink>
                                                        </>
                                                    )}
                                                </div>
                                            )}
                                        </li>
                                    );
                                })}
                            </ol>
                        </div>
                    ))}
                </div>
            )}

            {data?.may.send && <SendMessageDialog subjectType="project" subjectId={projectId} open={sending} onOpenChange={setSending} onSent={load} />}
        </Card>
    );
}
