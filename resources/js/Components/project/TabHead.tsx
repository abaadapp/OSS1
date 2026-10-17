import type { ReactNode } from 'react';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/** رقمٌ في سطر الخلاصة — اسمُه ثمّ قيمتُه: «المتأخّرة ١» */
export interface Fact {
    label: string;
    /** عدد — والصفرُ لا يُكتب: «المتأخّرة ٠» سطرٌ يشغل مكانًا ليقول لا شيء */
    n?: number;
    /** أو قيمةٌ مكتوبة (مبلغٌ أو تاريخ) */
    value?: string | null;
    /** الأحمرُ لما يحتاج انتباهًا وحده، والأخضرُ لما اكتمل */
    tone?: 'danger' | 'ok';
}

/**
 * رأسُ التبويب في صفحة المشروع — سطرُ خلاصةٍ قصير، ثمّ الفعلُ الأوّل.
 *
 * الخلاصةُ أرقامٌ تُقرأ بنظرة («المفتوحة ٤ · المتأخّرة ١ · المكتملة ٧») لا
 * فقرةُ شرح، والفعلُ زرٌّ واحدٌ قبل البيانات. وما يُشرح يُطوى في «عرض
 * التفاصيل» (`MoreDetails`) — لا يُحذف، ولا يُلقى كلُّه في أوّل نظرة.
 */
export default function TabHead({ facts, empty, action, className }: { facts: (Fact | false | null | undefined)[]; empty?: string; action?: ReactNode; className?: string }) {
    const t = useTranslate();
    const shown = facts.filter((f): f is Fact => !!f && f.n !== 0 && f.value !== null);

    return (
        <div className={cn('mb-3 flex flex-wrap items-center justify-between gap-2', className)}>
            <p data-tab-summary className="min-w-0 text-[13px] text-[#52525b]">
                {shown.length === 0 && empty
                    ? t(empty)
                    : shown.map((f, k) => (
                          <span key={f.label}>
                              {/* والفاصلُ نصٌّ بمسافتيه لا هامش — فالسطرُ يُنسخ كما يُقرأ */}
                              {k > 0 && <span className="whitespace-pre text-[#d4d4d8]"> · </span>}
                              {t(f.label)}
                              {(f.n !== undefined || f.value !== undefined) && ' '}
                              {f.n !== undefined && <b className={cn('font-semibold tabular-nums text-[#18181b]', tone(f.tone))}>{number(f.n)}</b>}
                              {f.value !== undefined && <b className={cn('font-semibold tabular-nums text-[#18181b]', tone(f.tone))}>{f.value}</b>}
                          </span>
                      ))}
            </p>
            {action && <div className="flex shrink-0 flex-wrap items-center gap-2">{action}</div>}
        </div>
    );
}

const tone = (v: Fact['tone']) => (v === 'danger' ? 'text-[#b91c1c]' : v === 'ok' ? 'text-[#047857]' : '');
