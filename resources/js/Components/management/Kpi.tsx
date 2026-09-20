import type { ReactNode } from 'react';
import { ArrowDownRight, ArrowUpRight, Info } from 'lucide-react';
import { useTranslate } from '@/lib/i18n';
import type { Change } from '@/lib/management';

/**
 * بطاقةُ مؤشّرٍ إداريّ — رقمٌ وتسميةٌ وأساسٌ يُقرأ بالمرور، وتغيّرٌ لا يُختلق.
 *
 * «لا بيانات كافية» تُكتب حين لا أساسَ للمقارنة — لا «+100٪» بسهمٍ أخضر.
 * والسهمُ لا يحكم: لا أخضرَ لارتفاع المصروف ولا أحمرَ لانخفاضه — يُقال
 * الاتّجاهُ بلونٍ محايد ويقرؤه من يعرف ما يقرأ.
 */
export default function Kpi({ label, value, basis, change, sub, href, tone, invert }: {
    label: string;
    value: ReactNode;
    basis?: string;
    change?: Change | null;
    sub?: ReactNode;
    href?: string;
    tone?: 'danger' | 'warning' | 'ok';
    /** للمصروفات: الارتفاعُ ليس خبرًا طيّبًا — فيُعرض بلا لونٍ حاكم */
    invert?: boolean;
}) {
    const t = useTranslate();
    const color = tone === 'danger' ? 'text-[#b91c1c]' : tone === 'warning' ? 'text-[#b45309]' : tone === 'ok' ? 'text-[#047857]' : 'text-[#111]';

    const body = (
        <>
            <div className="flex items-start justify-between gap-2">
                <span className="text-[12px] text-[#6b7280]">{t(label)}</span>
                {basis && (
                    <span className="group relative shrink-0">
                        <Info className="size-3.5 text-[#c4c4c4]" />
                        <span className="pointer-events-none absolute end-0 top-5 z-10 hidden w-64 rounded-lg bg-[#111] p-2 text-[11px] leading-relaxed text-white shadow-lg group-hover:block">{basis}</span>
                    </span>
                )}
            </div>
            <div className={'mt-1 text-[22px] font-bold leading-none tabular-nums ' + color} dir="ltr">{value}</div>
            {change !== undefined && change !== null && (
                <div className="mt-1 flex items-center gap-1 text-[11px] text-[#6b7280]">
                    {change.status === 'insufficient_data' ? (
                        <span>{t('لا بيانات كافية للمقارنة')}</span>
                    ) : (
                        <>
                            {(change.percent ?? 0) >= 0 ? <ArrowUpRight className={'size-3 ' + (invert ? 'text-[#b45309]' : 'text-[#047857]')} /> : <ArrowDownRight className={'size-3 ' + (invert ? 'text-[#047857]' : 'text-[#b45309]')} />}
                            <span dir="ltr">{change.percent}%</span>
                            <span>{t('مقابل المدّة السابقة')}</span>
                        </>
                    )}
                </div>
            )}
            {sub && <div className="mt-1 text-[11px] text-[#9ca3af]">{sub}</div>}
        </>
    );

    return href ? (
        <a href={href} className="block rounded-xl border border-[#e5e7eb] bg-white p-3 transition hover:border-[#111]">{body}</a>
    ) : (
        <div className="rounded-xl border border-[#e5e7eb] bg-white p-3">{body}</div>
    );
}
