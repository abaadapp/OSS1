import { useState } from 'react';
import { router } from '@inertiajs/react';
import { motion } from 'framer-motion';
import {
    History,
    LogIn,
    LogOut,
    type LucideIcon,
    Pause,
    Pencil,
    Plus,
    RefreshCw,
    Settings,
    ShoppingCart,
    Timer,
    Trash2,
} from 'lucide-react';
import Field, { Select } from '@/Components/Field';
import ExportMenu from '@/Components/ExportMenu';
import { exportLinks } from '@/lib/exportLink';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { ServerPagination } from '@/Components/DataTable';

export interface Log {
    user: string;
    action: string;
    description: string;
    /** اسم من دخل «كتاجر» وفعلها — null إن فعلها صاحب الحساب */
    icon: string;
    color: string;
    ago: string;
    time: string;
    ip: string | null;
}

export interface ActivityData {
    logs: Log[];
    pagination: ServerPagination;
    filters: Record<string, string | null>;
}

const ACTION_LABEL: Record<string, string> = {
    created: 'إضافة',
    updated: 'تعديل',
    deleted: 'حذف',
    login: 'دخول',
    logout: 'خروج',
    checkout: 'بيع',
    status: 'تغيير حالة',
    settings: 'إعدادات',
};

/** خريطة صريحة — الاستيراد الشامل من lucide يضخّ المكتبة كاملة في الحزمة */
const ICONS: Record<string, LucideIcon> = {
    plus: Plus,
    pencil: Pencil,
    'trash-2': Trash2,
    'log-in': LogIn,
    'log-out': LogOut,
    'shopping-cart': ShoppingCart,
    'refresh-cw': RefreshCw,
    settings: Settings,
    pause: Pause,
    timer: Timer,
    history: History,
};

const TONE: Record<string, string> = {
    success: 'bg-[#ecfdf5] text-[#047857]',
    info: 'bg-[#eff6ff] text-[#2563eb]',
    danger: 'bg-[#fef2f2] text-[#b91c1c]',
    warning: 'bg-[#fffbeb] text-[#d97706]',
    primary: 'bg-[#f5f3ff] text-[#6d28d9]',
    gray: 'bg-[#f2f2f0] text-[#6b7280]',
};

/**
 * جسم قسم «سجل النشاط» بلا قشرة — في لوحة الإعدادات وفي صفحته المستقلّة.
 *
 * والتصفية والتصفّح يحتاجان عنوانًا يعودان إليه، وهو يختلف بين الموضعين:
 * `/admin/activity` هناك، و`/admin/settings?section=activity` هنا. فيصل
 * العنوان من الخارج — ولو ثُبّت في الداخل لقفز المستخدم من الإعدادات إلى
 * الصفحة المستقلّة عند أوّل ضغطة «التالي».
 */
export default function ActivityPanel({
    logs,
    pagination,
    filters,
    endpoint,
    endpointParams = {},
}: ActivityData & { endpoint: string; endpointParams?: Record<string, string> }) {
    const t = useTranslate();
    const [q, setQ] = useState(filters.q ?? '');
    const [action, setAction] = useState(filters.action ?? '');
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');

    const go = (patch: Record<string, string | number | null>) => {
        const next: Record<string, string | number> = { ...endpointParams };
        Object.entries({ q, action, from, to, ...patch }).forEach(([k, v]) => {
            if (v !== null && v !== undefined && v !== '') next[k] = v as string | number;
        });
        router.get(endpoint, next, { preserveState: true, preserveScroll: true, replace: true });
    };

    return (
        <div className="min-w-0">
            <Card className="mb-6 p-4">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        go({ page: null });
                    }}
                    className="grid grid-cols-1 gap-3 sm:grid-cols-2 md:grid-cols-6"
                >
                    <Field className="md:col-span-2">
                        <Input
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            placeholder={t('بحث في الوصف أو المستخدم…')}
                        />
                    </Field>
                    <Field>
                        <Select
                            value={action}
                            onChange={(e) => {
                                setAction(e.target.value);
                                go({ action: e.target.value || null, page: null });
                            }}
                            placeholder="كل العمليات"
                            options={[
                                { label: 'إضافة', value: 'created' },
                                { label: 'تعديل', value: 'updated' },
                                { label: 'حذف', value: 'deleted' },
                                { label: 'بيع', value: 'checkout' },
                                { label: 'تغيير حالة', value: 'status' },
                                { label: 'دخول', value: 'login' },
                                { label: 'إعدادات', value: 'settings' },
                            ]}
                        />
                    </Field>
                    {/* المدّةُ بيوم الحدث — للقائمة ولملفّ تصديرها معًا */}
                    <Field>
                        <Input
                            type="date"
                            dir="ltr"
                            value={from}
                            max={to || undefined}
                            aria-label={t('من تاريخ')}
                            onChange={(e) => {
                                setFrom(e.target.value);
                                go({ from: e.target.value || null, page: null });
                            }}
                        />
                    </Field>
                    <Field>
                        <Input
                            type="date"
                            dir="ltr"
                            value={to}
                            min={from || undefined}
                            aria-label={t('إلى تاريخ')}
                            onChange={(e) => {
                                setTo(e.target.value);
                                go({ to: e.target.value || null, page: null });
                            }}
                        />
                    </Field>
                    {/* flex-wrap: الأزرار في خانةٍ واحدة، وضاق العمود بعد إضافة
                        عمود الإعدادات — فكانت تخرج عن الشاشة */}
                    <div className="flex flex-wrap items-center gap-2 sm:col-span-2 md:col-span-6">
                        <Button type="submit">{t('تصفية')}</Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => {
                                setQ('');
                                setAction('');
                                setFrom('');
                                setTo('');
                                router.get(endpoint, endpointParams);
                            }}
                        >
                            {t('عرض الكل')}
                        </Button>
                        {/* المالكون خارج السجلّ كما في شاشته — والمرشّحاتُ المعروضةُ نفسُها */}
                        <ExportMenu {...exportLinks('admin.activity.export', { q: filters.q, action: filters.action, from: filters.from, to: filters.to })} />
                    </div>
                </form>
            </Card>

            <Card>
                {logs.length === 0 ? (
                    <div className="px-5 py-16 text-center">
                        <History className="mx-auto size-8 text-[#d1d5db]" />
                        <p className="mt-3 font-medium text-[#111]">{t('لا يوجد نشاط بعد')}</p>
                        <p className="mt-1 text-[13px] text-[#9ca3af]">
                            {t('ستظهر هنا كل العمليات التي تُجرى على النظام.')}
                        </p>
                    </div>
                ) : (
                    logs.map((log, i) => {
                        const Icon = ICONS[log.icon] ?? History;

                        return (
                            <motion.div
                                key={i}
                                initial={{ opacity: 0 }}
                                animate={{ opacity: 1 }}
                                transition={{ duration: 0.2, delay: Math.min(i * 0.015, 0.2) }}
                                className={cn(
                                    'flex items-start gap-3 px-5 py-4',
                                    i < logs.length - 1 && 'border-b border-[#f5f5f4]',
                                )}
                            >
                                <span
                                    className={cn(
                                        'flex size-10 shrink-0 items-center justify-center rounded-[12px]',
                                        TONE[log.color] ?? TONE.primary,
                                    )}
                                >
                                    <Icon className="size-5" />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <p className="text-sm font-medium text-[#111]">{log.user}</p>
                                        <Badge
                                            variant={
                                                (log.color as 'success' | 'info' | 'danger' | 'warning' | 'primary') ??
                                                'neutral'
                                            }
                                        >
                                            {t(ACTION_LABEL[log.action] ?? log.action)}
                                        </Badge>
                                    </div>
                                    <p className="mt-0.5 text-sm text-[#4b4b4b]">{log.description}</p>
                                    <p className="mt-1 text-[12px] text-[#9ca3af]">
                                        {log.ago} · {log.time} · IP {log.ip ?? '—'}
                                    </p>
                                </div>
                            </motion.div>
                        );
                    })
                )}

                {pagination.last_page > 1 && (
                    <div className="flex items-center justify-between gap-3 border-t border-[var(--ui-border,#e8e8e8)] px-5 py-3">
                        <p className="text-[12px] text-[#6b7280]">
                            {pagination.from ?? 0}–{pagination.to ?? 0} {t('من')} {number(pagination.total)}
                        </p>
                        <div className="flex gap-1.5">
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={pagination.current_page <= 1}
                                onClick={() => go({ page: pagination.current_page - 1 })}
                            >
                                {t('السابق')}
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={pagination.current_page >= pagination.last_page}
                                onClick={() => go({ page: pagination.current_page + 1 })}
                            >
                                {t('التالي')}
                            </Button>
                        </div>
                    </div>
                )}
            </Card>
        </div>
    );
}
