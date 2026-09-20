import { useTranslate } from '@/lib/i18n';
import { COMPLETENESS_LABEL, completenessTone, type CompletenessInfo } from '@/lib/management';

/** شارةُ اكتمال البيانات — والأسبابُ تُقرأ بالمرور؛ الرقمُ الناقص لا يُخفى ولا يُقدَّم واثقًا */
export default function CompletenessBadge({ completeness, className = '' }: { completeness: CompletenessInfo; className?: string }) {
    const t = useTranslate();

    return (
        <span className={'group relative inline-flex ' + className}>
            <span className={'rounded-full px-2 py-0.5 text-[10px] ' + completenessTone(completeness.status)}>{t(COMPLETENESS_LABEL[completeness.status] ?? completeness.status)}</span>
            {completeness.reasons.length > 0 && (
                <span className="pointer-events-none absolute start-0 top-6 z-10 hidden w-72 rounded-lg bg-[#111] p-2 text-[11px] leading-relaxed text-white shadow-lg group-hover:block">
                    {completeness.reasons.map((r, i) => <span key={i} className="block">• {r}</span>)}
                </span>
            )}
        </span>
    );
}
