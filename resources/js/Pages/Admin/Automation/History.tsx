import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Ban, Eye } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import BackLink from '@/Components/BackLink';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import StatCard from '@/Components/StatCard';
import DataTable, { type Column, type Filter, type ServerPagination } from '@/Components/DataTable';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { CHANNEL_LABEL, RUN_STATUS_LABEL, offsetLabel, runTone } from '@/lib/automation';
import { SUBJECT_LABEL } from '@/lib/communications';

interface Run {
    id: number;
    rule_id: number;
    rule: string | null;
    kind: string | null;
    channel: string | null;
    trigger: string;
    trigger_label: string;
    target_type: string | null;
    target_id: number | null;
    target_label: string | null;
    target_url: string | null;
    step: number;
    audience: string;
    audience_label: string;
    occurrence: string;
    status: string;
    description: string | null;
    result: string | null;
    skipped_reason: string | null;
    skipped_label: string | null;
    failure: string | null;
    communication_id: number | null;
    communication_status: string | null;
    notification_count: number;
    evaluated_at: string | null;
    scheduled_for: string | null;
    executed_at: string | null;
    would: { to: string | string[]; template: string | null; reason: string } | null;
}

interface Detail extends Run {
    metadata: Record<string, unknown>;
    idempotency_key: string;
    notifications: { user: string | null; title: string; read_at: string | null }[];
}

interface Props {
    runs: Run[];
    pagination: ServerPagination;
    filters: Record<string, string | undefined>;
    totals: { evaluated: number; executed: number; observed: number; skipped: number; failed: number; pending: number };
    rules: { id: number; name: string }[];
    statuses: string[];
    target_types: string[];
    may: { manage: boolean; pause: boolean };
}

/**
 * سجلُّ الأتمتة — ماذا وجد المحرّك، وماذا قرّر، وماذا فعل، ولماذا لم يفعل.
 *
 * يُقرأ لا يُفكّ: كلُّ صفٍّ بلغة الناس (المُطلِق، الشرط، القرار، الفعل،
 * النتيجة)، والتفصيلُ في نافذةٍ لا في JSON.
 */
export default function AutomationHistory({ runs, pagination, filters, totals, rules, statuses, target_types, may }: Props) {
    const t = useTranslate();
    const { errors } = usePage().props as { errors?: Record<string, string> };
    const [detail, setDetail] = useState<Detail | null>(null);

    const open = (id: number) =>
        fetch(route('admin.automation.history.show', id), { headers: { Accept: 'application/json' } })
            .then(async (r) => (r.ok ? ((await r.json()) as { run: Detail }) : null))
            .then((d) => d && setDetail(d.run))
            .catch(() => undefined);

    const columns: Column<Run>[] = [
        {
            key: 'rule',
            header: 'القاعدة',
            cell: (r) => (
                <>
                    <button type="button" className="font-medium text-[#1d4ed8]" onClick={() => open(r.id)}>
                        {r.rule ?? '—'}
                    </button>
                    <div className="text-[11px] text-[#9ca3af]">
                        {r.trigger_label} · {t(offsetLabel(r.step))} → {r.audience_label}
                    </div>
                </>
            ),
        },
        {
            key: 'target',
            header: 'عن',
            cell: (r) => (
                <>
                    {r.target_url ? (
                        <a href={r.target_url} className="text-[#111] hover:text-[#1d4ed8]">
                            {r.target_label}
                        </a>
                    ) : (
                        <span>{r.target_label ?? '—'}</span>
                    )}
                    <div className="text-[11px] text-[#9ca3af]">{r.target_type ? t(SUBJECT_LABEL[r.target_type] ?? r.target_type) : ''}</div>
                </>
            ),
        },
        {
            key: 'status',
            header: 'القرار',
            cell: (r) => (
                <>
                    <span className={'rounded-full px-2 py-1 text-[11px] ' + runTone(r.status)}>{t(RUN_STATUS_LABEL[r.status] ?? r.status)}</span>
                    <div className="mt-1 max-w-[36ch] truncate text-[11px] text-[#6b7280]">{r.skipped_label ?? r.result ?? r.failure ?? ''}</div>
                </>
            ),
        },
        {
            key: 'channel',
            header: 'القناة',
            cell: (r) => (
                <span className="text-[#6b7280]">
                    {t(CHANNEL_LABEL[r.channel ?? ''] ?? r.channel ?? '—')}
                    {r.communication_id && may.manage ? (
                        <>
                            {' · '}
                            <SmartLink routeName="admin.communications.show" href={route('admin.communications.show', r.communication_id)} className="text-[#1d4ed8]">
                                {t('الرسالة')}
                            </SmartLink>
                        </>
                    ) : null}
                </span>
            ),
        },
        {
            key: 'when',
            header: 'متى',
            cell: (r) => (
                <div dir="ltr" className="text-start text-[#6b7280]">
                    <div>{r.executed_at ?? r.scheduled_for ?? r.evaluated_at}</div>
                    {r.status === 'pending' && r.scheduled_for && <div className="text-[11px] text-[#b45309]">{t('مجدول')}: {r.scheduled_for}</div>}
                </div>
            ),
        },
        {
            key: 'actions',
            header: '',
            cell: (r) => (
                <div className="flex justify-end gap-1">
                    <Button size="icon-sm" variant="ghost" onClick={() => open(r.id)} aria-label={t('التفاصيل')}>
                        <Eye />
                    </Button>
                    {r.status === 'pending' && may.manage && (
                        <Button size="icon-sm" variant="ghost" onClick={() => router.post(route('admin.automation.runs.cancel', r.id), {}, { preserveScroll: true })} aria-label={t('إلغاء')}>
                            <Ban />
                        </Button>
                    )}
                </div>
            ),
        },
    ];

    const tableFilters: Filter<Run>[] = [
        { label: 'كل القرارات', asTabs: true, param: 'status', options: statuses.map((s) => ({ label: RUN_STATUS_LABEL[s] ?? s, value: s })) },
        { label: 'القاعدة', param: 'rule_id', options: rules.map((r) => ({ label: r.name, value: String(r.id) })) },
        { label: 'نوع المستند', param: 'target_type', options: target_types.map((x) => ({ label: SUBJECT_LABEL[x] ?? x, value: x })) },
        { label: 'القناة', param: 'channel', options: ['internal', 'email', 'whatsapp'].map((c) => ({ label: CHANNEL_LABEL[c], value: c })) },
        { label: 'من تاريخ', type: 'date', param: 'from' },
        { label: 'إلى تاريخ', type: 'date', param: 'to' },
        { label: 'عدد الصفوف', param: 'per_page', options: [25, 50, 100].map((n) => ({ label: String(n), value: String(n) })) },
    ];

    return (
        <AdminLayout title={t('سجلّ الأتمتة')}>
            <BackLink routeName="admin.automation.index" href={route('admin.automation.index')} label="الأتمتة" />
            <PageHeader title={t('سجلّ الأتمتة')} subtitle={t('ماذا وجد المحرّك، وماذا قرّر، وماذا فعل — ولماذا لم يفعل.')} />

            {errors?.run && <div className="mb-4 rounded-lg bg-[#fef2f2] px-4 py-3 text-[13px] text-[#b91c1c]">{errors.run}</div>}

            <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-5">
                <StatCard stat={{ label: t('قُيّم'), value: number(totals.evaluated), icon: 'activity', color: 'primary' }} index={0} />
                <StatCard stat={{ label: t('نُفّذ'), value: number(totals.executed), icon: 'circle-check', color: 'success' }} index={1} />
                <StatCard stat={{ label: t('رُوقب'), value: number(totals.observed), icon: 'badge-check', color: 'info' }} index={2} />
                <StatCard stat={{ label: t('تُخطّي'), value: number(totals.skipped), icon: 'refresh-cw', color: 'warning' }} index={3} />
                <StatCard stat={{ label: t('فشل'), value: number(totals.failed), icon: 'alert-triangle', color: 'danger' }} index={4} />
            </div>

            <DataTable rows={runs} columns={columns} rowKey={(r) => r.id} filters={tableFilters} empty="لا تشغيلات بعد" server={{ pagination, params: filters }} searchable={() => ''} searchPlaceholder="—" />

            <Dialog open={detail !== null} onOpenChange={(o) => !o && setDetail(null)}>
                <DialogContent className="max-w-lg">
                    {detail && (
                        <>
                            <DialogHeader>
                                <DialogTitle>{detail.rule}</DialogTitle>
                                <DialogDescription>
                                    {detail.target_label} · <span className={'rounded-full px-2 py-0.5 text-[11px] ' + runTone(detail.status)}>{t(RUN_STATUS_LABEL[detail.status] ?? detail.status)}</span>
                                </DialogDescription>
                            </DialogHeader>
                            <dl className="space-y-2 px-5 text-[13px]">
                                <Row label="المُطلِق" value={`${detail.trigger_label} — ${t(offsetLabel(detail.step))} → ${detail.audience_label}`} />
                                <Row label="الموعد الذي بُنيت عليه" value={detail.occurrence} ltr />
                                <Row label="الشرط" value={detail.description} />
                                <Row label="القرار" value={detail.skipped_label ?? (detail.status === 'observed' ? t('مراقبة — لم يُنفَّذ') : detail.status === 'failed' ? t('حاول وفشل') : detail.status === 'pending' ? t('مؤجَّل إلى ساعات العمل') : t('نُفّذ'))} />
                                {detail.would && (
                                    <Row label="ما كان سيحدث" value={`${t('إلى')}: ${Array.isArray(detail.would.to) ? detail.would.to.join('، ') : detail.would.to}${detail.would.template ? ` · ${detail.would.template}` : ''}`} />
                                )}
                                <Row label="الفعل" value={detail.result} />
                                <Row label="النتيجة" value={detail.failure ?? (detail.communication_status ? `${t('الرسالة')}: ${detail.communication_status}` : detail.notification_count > 0 ? t(':n تنبيهات', { n: detail.notification_count }) : null)} />
                                <Row label="قُيّم" value={detail.evaluated_at} ltr />
                                <Row label="مجدول" value={detail.scheduled_for} ltr />
                                <Row label="نُفّذ" value={detail.executed_at} ltr />
                                {detail.notifications.length > 0 && (
                                    <div>
                                        <dt className="text-[#9ca3af]">{t('نُبّه')}</dt>
                                        <dd>{detail.notifications.map((n) => `${n.user ?? '—'}${n.read_at ? ` (${t('قُرئ')})` : ''}`).join('، ')}</dd>
                                    </div>
                                )}
                            </dl>
                            <DialogFooter>
                                {detail.target_url && (
                                    <Button variant="outline" asChild>
                                        <a href={detail.target_url}>{t('افتح المستند')}</a>
                                    </Button>
                                )}
                                {detail.communication_id && (
                                    <Button variant="outline" asChild>
                                        <SmartLink routeName="admin.communications.show" href={route('admin.communications.show', detail.communication_id)}>
                                            {t('الرسالة')}
                                        </SmartLink>
                                    </Button>
                                )}
                                <Button onClick={() => setDetail(null)}>{t('إغلاق')}</Button>
                            </DialogFooter>
                        </>
                    )}
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}

function Row({ label, value, ltr }: { label: string; value: string | null | undefined; ltr?: boolean }) {
    const t = useTranslate();

    if (!value) return null;

    return (
        <div>
            <dt className="text-[#9ca3af]">{t(label)}</dt>
            <dd className="whitespace-pre-line text-[#111]" dir={ltr ? 'ltr' : undefined}>
                {value}
            </dd>
        </div>
    );
}
