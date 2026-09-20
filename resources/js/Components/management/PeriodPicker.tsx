import { useState } from 'react';
import { router } from '@inertiajs/react';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import type { PeriodInfo, PeriodOption } from '@/lib/management';

/**
 * مختارُ المدّة — مفاتيحُ الشركة القياسيّة ومدّةٌ مخصَّصة بحدّين.
 *
 * يُعيد التوجيه بالمعاملات نفسِها التي يقرؤها الخادم: `period` ومعه `from`
 * و`to` للمخصَّصة. والشاشةُ لا تحسب شيئًا — تطلب.
 */
export default function PeriodPicker({ period, periods, routeName, extra = {}, allowAll = false }: {
    period: PeriodInfo;
    periods: PeriodOption[];
    routeName: string;
    extra?: Record<string, string | number | undefined>;
    allowAll?: boolean;
}) {
    const t = useTranslate();
    const [from, setFrom] = useState(period.from);
    const [to, setTo] = useState(period.to);
    const [custom, setCustom] = useState(period.key === 'custom');

    const go = (key: string) => {
        if (key === 'custom') {
            setCustom(true);

            return;
        }
        setCustom(false);
        router.get(route(routeName), { ...extra, period: key }, { preserveState: true, replace: true });
    };

    return (
        <div className="flex flex-wrap items-end gap-2">
            <Field label="المدّة" className="min-w-[180px]">
                <Select
                    value={custom ? 'custom' : period.key}
                    onChange={(e) => go(e.target.value)}
                    options={[...(allowAll ? [{ value: 'all', label: t('الكلّ') }] : []), ...periods.map((p) => ({ value: p.key, label: p.label }))]}
                />
            </Field>
            {custom && (
                <>
                    <Field label="من" className="min-w-[150px]"><Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} /></Field>
                    <Field label="إلى" className="min-w-[150px]"><Input type="date" value={to} onChange={(e) => setTo(e.target.value)} /></Field>
                    <Button variant="outline" onClick={() => router.get(route(routeName), { ...extra, period: 'custom', from, to }, { preserveState: true, replace: true })}>{t('تطبيق')}</Button>
                </>
            )}
            <span className="pb-2 text-[12px] text-[#9ca3af]" dir="ltr">{period.from} → {period.to}</span>
        </div>
    );
}
