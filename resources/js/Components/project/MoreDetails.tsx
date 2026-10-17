import type { ReactNode } from 'react';
import { ChevronDown } from 'lucide-react';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * ما لا يُحتاج في أوّل نظرة — الأصلُ وخطُّ الزمن والأرقامُ المفصّلة.
 *
 * مطويٌّ لا مخفيّ: العنوانُ مقروءٌ فيُعرف أنّ التفصيل هنا. و`<details>` لا
 * زرٌّ بحالة — كقسم الإعدادات المطويّ (`Advanced`): المتصفّح يمنحها لوحةَ
 * المفاتيح والقارئَ الآليّ، وما تحتها في الصفحة يُبحث فيه ولو كان مطويًّا.
 */
export default function MoreDetails({ label = 'عرض التفاصيل', children, className }: { label?: string; children: ReactNode; className?: string }) {
    const t = useTranslate();

    return (
        <details className={cn('group rounded-[12px] border border-[var(--ui-border,#e8e8e8)] bg-white', className)}>
            <summary
                className={cn(
                    'flex cursor-pointer list-none items-center justify-between gap-2 rounded-[12px] px-4 py-2.5 text-[13px] font-medium text-[#3f3f46] hover:bg-[#fafafa]',
                    'focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-[rgba(17,17,17,0.35)] [&::-webkit-details-marker]:hidden',
                )}
            >
                {t(label)}
                <ChevronDown aria-hidden className="size-4 shrink-0 text-[#9ca3af] transition-transform group-open:rotate-180" />
            </summary>
            <div className="border-t border-[var(--ui-border,#e8e8e8)] p-4">{children}</div>
        </details>
    );
}
