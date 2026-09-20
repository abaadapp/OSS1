import { ArrowLeft } from 'lucide-react';
import { useTranslate } from '@/lib/i18n';
import { SEVERITY_LABEL, severityTone, type Alert } from '@/lib/management';

/** التنبيهاتُ الإداريّة — ما وقع ورقمُه ومدّتُه وعتبتُه، ورابطٌ إلى مصدره */
export default function AlertList({ alerts, empty = 'لا تنبيهات إدارية' }: { alerts: Alert[]; empty?: string }) {
    const t = useTranslate();

    if (alerts.length === 0) {
        return <p className="py-4 text-center text-[13px] text-[#9ca3af]">{t(empty)}</p>;
    }

    return (
        <ul className="divide-y divide-[#f3f4f6]">
            {alerts.map((a, i) => (
                <li key={a.kind + i} className="flex items-start gap-3 py-2.5">
                    <span className={'mt-0.5 shrink-0 rounded-full px-2 py-0.5 text-[10px] ' + severityTone(a.severity)}>{t(SEVERITY_LABEL[a.severity] ?? a.severity)}</span>
                    <div className="min-w-0 flex-1">
                        <a href={a.url} className="font-medium text-[#111] hover:text-[#1d4ed8]">{a.title}</a>
                        <div className="text-[12px] text-[#6b7280]">{a.detail}</div>
                        {a.facts.period && <div className="text-[11px] text-[#9ca3af]">{t('المدّة')}: {a.facts.period}</div>}
                    </div>
                    <a href={a.url} className="inline-flex shrink-0 items-center gap-1 text-[12px] text-[#1d4ed8]">
                        {t('افتح')}
                        <ArrowLeft className="size-3" />
                    </a>
                </li>
            ))}
        </ul>
    );
}
