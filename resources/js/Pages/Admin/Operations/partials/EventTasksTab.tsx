import { router } from '@inertiajs/react';
import { CheckCircle2, Copy, MessageCircle, RefreshCw, Send } from 'lucide-react';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import SmartLink from '@/Components/SmartLink';
import { useCopy } from '@/lib/copy';
import { useTranslate } from '@/lib/i18n';

export interface EventTaskPerson {
    user_id: number | null;
    name: string | null;
    has_phone: boolean;
    open: number;
    done: number;
    tasks: { id: number; title: string; status: string; priority: string; due_at: string | null; completed_at: string | null }[];
    last_dispatch: { trigger: string; status: string; channel: string; open_count: number; failure: string | null; at: string | null } | null;
    link: string | null;
}

export interface EventTasks {
    published_at: string | null;
    people: EventTaskPerson[];
    rules: { trigger: string; enabled: boolean; mode: string; channel: string }[];
    automation_mode: string;
}

const DISPATCH_LABEL: Record<string, string> = { sent: 'أُرسلت على واتساب', notified: 'نُبّه داخل النظام', failed: 'تعذّر الإرسال', nothing_open: 'لا مفتوح وقت الإرسال' };
const DISPATCH_TONE: Record<string, 'success' | 'info' | 'danger' | 'neutral'> = { sent: 'success', notified: 'info', failed: 'danger', nothing_open: 'neutral' };
const MODE_LABEL: Record<string, string> = { off: 'مطفأة', observe: 'مراقبة', live: 'تنفيذ' };

/**
 * مهامُّ الفعاليّة لكلّ موظّف — `ProjectTask` نفسُها، تُكتب في تبويب المهام بالمشروع.
 *
 * هنا تُقرأ مجموعةً بأصحابها، و«نشر المهام» مرساةُ رسالة كلّ موظّف في الأتمتة
 * (لا إرسالٌ من الشاشة). وما أُرسل إلى كلٍّ منهم يُقرأ بحاله، ورابطُه يُنسخ ليُرسل
 * بيدٍ حين لا تخرج الرسالة.
 */
export default function EventTasksTab({ operationId, projectId, active, data, canManage }: {
    operationId: number; projectId: number | null; active: boolean; data: EventTasks; canManage: boolean;
}) {
    const t = useTranslate();
    const assigned = data.rules.find((r) => r.trigger === 'event_tasks_assigned');
    const people = data.people.filter((p) => p.user_id !== null);
    const unassigned = data.people.find((p) => p.user_id === null);

    return (
        <div className="space-y-4">
            <Card className="flex flex-wrap items-center justify-between gap-3 p-4">
                <div className="text-[13px] text-[#4b4b4b]">
                    <div className="font-semibold text-[#111]">{t('رسالة واحدة لكل موظف بمهامه في هذه الفعالية')}</div>
                    <div className="mt-0.5 text-[12px] text-[#6b7280]">
                        {data.published_at ? `${t('نُشرت')}: ${data.published_at}` : t('لم تُنشر بعد')}
                        {' · '}{t('الأتمتة')}: {t(MODE_LABEL[data.automation_mode] ?? data.automation_mode)}
                        {assigned && <> · {t('قاعدة الإرسال')}: {t(MODE_LABEL[assigned.mode] ?? assigned.mode)}{!assigned.enabled && ` (${t('موقوفة')})`}</>}
                    </div>
                    {data.published_at && (
                        <div className="mt-1 text-[11.5px] text-[#9ca3af]">{t('إعادة النشر لا تُرسل لمن وصلته رسالته، وتصل من أُضيف في يوم تالٍ — ومن أُضيف اليوم انسخ رابطه أو يصله التذكير.')}</div>
                    )}
                </div>
                <div className="flex flex-wrap gap-2">
                    {projectId !== null && (
                        <Button variant="outline" size="sm" asChild>
                            <SmartLink routeName="admin.projects.show" href={route('admin.projects.show', { id: projectId, tab: 'tasks' })}>{t('إدارة المهام في المشروع')}</SmartLink>
                        </Button>
                    )}
                    {canManage && active && (
                        <Button size="sm" disabled={people.every((p) => p.open === 0)} onClick={() => router.post(route('admin.operations.tasks.publish', operationId), {}, { preserveScroll: true })}>
                            <Send />{data.published_at ? t('إعادة النشر') : t('نشر المهام على الفريق')}
                        </Button>
                    )}
                </div>
            </Card>

            {people.length === 0 && (
                <Card className="p-8 text-center text-[13px] text-[#9ca3af]">{t('لا مهام معيّنة لموظفين على هذه الفعالية — أضفها من تبويب المهام في المشروع واختر الفعالية.')}</Card>
            )}

            <div className="grid gap-4 lg:grid-cols-2">
                {people.map((person) => (
                    <Card key={person.user_id} className="p-4">
                        <div className="mb-2 flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <div className="font-semibold text-[#111]">{person.name}</div>
                                <div className="text-[12px] text-[#6b7280]">
                                    {t(':open مفتوحة · :done منجزة', { open: String(person.open), done: String(person.done) })}
                                    {!person.has_phone && <span className="ms-2 text-[#b45309]">{t('بلا رقم هاتف')}</span>}
                                </div>
                            </div>
                            {person.open === 0 && person.done > 0 && <Badge variant="success"><CheckCircle2 className="size-3" />{t('أكمل مهامه')}</Badge>}
                        </div>

                        <ul className="divide-y divide-[#f4f4f5] text-[13px]">
                            {person.tasks.map((task) => (
                                <li key={task.id} className="flex items-center justify-between gap-2 py-1.5">
                                    <span className={task.status === 'done' ? 'text-[#9ca3af] line-through' : ''}>
                                        {task.title}
                                        {task.priority === 'high' && task.status !== 'done' && <span className="ms-2 rounded-full bg-[#fef2f2] px-2 py-0.5 text-[11px] text-[#b91c1c]">{t('عاجل')}</span>}
                                    </span>
                                    <span className="shrink-0 text-[11.5px] tabular-nums text-[#9ca3af]" dir="ltr">{task.status === 'done' ? task.completed_at : task.due_at ?? ''}</span>
                                </li>
                            ))}
                        </ul>

                        <div className="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-[#f4f4f5] pt-3">
                            <div className="text-[12px]">
                                {person.last_dispatch ? (
                                    <span title={person.last_dispatch.failure ?? undefined}>
                                        <Badge variant={DISPATCH_TONE[person.last_dispatch.status] ?? 'neutral'}><MessageCircle className="size-3" />{t(DISPATCH_LABEL[person.last_dispatch.status] ?? person.last_dispatch.status)}</Badge>
                                        <span className="ms-2 text-[#9ca3af]" dir="ltr">{person.last_dispatch.at}</span>
                                        {person.last_dispatch.failure && <span className="mt-1 block text-[#b91c1c]">{person.last_dispatch.failure}</span>}
                                    </span>
                                ) : <span className="text-[#9ca3af]">{t('لم يُرسل إليه بعد')}</span>}
                            </div>
                            {canManage && person.user_id !== null && (
                                <div className="flex gap-1">
                                    {person.link && <CopyLink link={person.link} />}
                                    <Button variant="ghost" size="sm" title={t('رابط جديد — القديم لا يفتح بعده')}
                                        onClick={() => router.post(route('admin.operations.tasks.rotate', [operationId, person.user_id as number]), {}, { preserveScroll: true })}>
                                        <RefreshCw />{person.link ? t('تجديد') : t('إنشاء رابط')}
                                    </Button>
                                </div>
                            )}
                        </div>
                    </Card>
                ))}
            </div>

            {unassigned && (
                <Card className="p-4 text-[13px] text-[#6b7280]">
                    {t(':n مهام على الفعالية بلا منفّذ — لا تصل أحدًا حتى تُعيَّن.', { n: String(unassigned.tasks.length) })}
                </Card>
            )}
        </div>
    );
}

/** نسخُ رابط موظّف — من الحافظة الواحدة (`useCopy`)، و«نُسخ» لا يُعلَن إلّا بعد أن يقع */
function CopyLink({ link }: { link: string }) {
    const t = useTranslate();
    const { copied, copy } = useCopy();

    return (
        <Button variant="outline" size="sm" onClick={() => void copy(link)}>
            <Copy />{copied ? t('نُسخ') : t('نسخ الرابط')}
        </Button>
    );
}
