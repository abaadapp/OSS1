import { Check, Minus, X } from 'lucide-react';
import { useTranslate } from '@/lib/i18n';
import type { Stage, StageKey } from '@/lib/projectStages';
import { cn } from '@/lib/utils';

const PURPLE = '#6d28d9';

/**
 * شريطُ مراحل المشروع — سطرٌ واحدٌ مضغوط: المنجزةُ ✓ في دائرةٍ ممتلئة،
 * والتي يعمل عليها الفريقُ بحلقةٍ واسمِها (تامّةً تنتظر «تم — الانتقال» أو
 * جارية)، وما بعدها رقمُها رماديًّا، والموقوفةُ ✕ حمراء.
 *
 * ═══ قليلٌ من النصّ ═══
 *
 * اسمُ المرحلة الحاليّة يُكتب دائمًا، وبقيّةُ الأسماء حين يتّسع العرض
 * (وتبقى للقارئ الآليّ وفي التلميح). ولا شرحَ تحت المراحل: ما ينقصها وما
 * يمنعها في تبويب «المسار» — وبـ`onPick` تصير كلُّ مرحلةٍ زرًّا يفتحها هناك.
 *
 * والحالاتُ من الخادم (`ProjectWorkflow`) — الشريطُ يرسم ولا يحكم.
 */
export default function ProjectStepper({ stages, onPick, className }: { stages: Stage[]; onPick?: (key: StageKey) => void; className?: string }) {
    const t = useTranslate();

    return (
        <ol
            aria-label={t('مراحل المشروع')}
            className={cn('flex min-w-0 items-center overflow-x-auto rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-white px-2 py-1.5', className)}
        >
            {stages.map((s, k) => {
                const Icon = s.icon;
                const Tag = onPick ? 'button' : 'span';
                /* والموقوفةُ تُقرأ بسببها («ملغى») — هو ما يُنتبه إليه */
                const name = s.state === 'failed' && s.note ? t(s.note) : t(s.label);
                const hint = [t(s.label), s.state === 'done' ? t('تمّت') : null, s.note && s.state !== 'failed' ? t(s.note) : null].filter(Boolean).join(' · ');

                /*
                 * ‏min-w-fit: المرحلةُ لا تضيق عن اسمها الظاهر فيركب جارتَها — وما
                 * يزيد على العرض يمرّ داخل الشريط (`overflow-x-auto`) لا في الصفحة.
                 */
                return (
                    <li key={s.key} data-state={s.state} className="flex min-w-fit flex-1 items-center gap-1 last:flex-none" aria-current={s.focus ? 'step' : undefined}>
                        <Tag
                            {...(onPick ? { type: 'button' as const, onClick: () => onPick(s.key) } : {})}
                            title={hint}
                            className={cn(
                                // ‏relative: الاسمُ المطويُّ للقارئ الآليّ (`sr-only`) يُحسب داخل زرّه، فلا يمدّ عرضَ الصفحة
                                'relative inline-flex shrink-0 items-center gap-1.5 rounded-md px-0.5 py-0.5 text-[12.5px] font-medium',
                                onPick && 'hover:bg-[#f5f3ff]',
                                s.state === 'done' && 'text-[#6d28d9]',
                                (s.state === 'todo' || s.state === 'skipped') && 'text-[#a1a1aa]',
                                s.state === 'failed' && 'text-[#dc2626]',
                                s.focus && 'font-semibold text-[#6d28d9]',
                            )}
                        >
                            <span
                                aria-hidden
                                className={cn(
                                    'flex size-5 shrink-0 items-center justify-center rounded-full',
                                    s.state === 'done' && 'bg-[#6d28d9] text-white',
                                    s.state === 'failed' && 'bg-[#dc2626] text-white',
                                    s.state === 'current' && 'bg-[#f3e8ff] text-[#6d28d9] ring-2 ring-[#6d28d9]',
                                    s.state === 'skipped' && 'border border-dashed border-[#d4d4d8]',
                                    s.state === 'todo' && 'bg-[#f4f4f5] text-[11px] tabular-nums',
                                    s.focus && s.state === 'done' && 'ring-2 ring-[#c4b5fd] ring-offset-1',
                                )}
                            >
                                {s.state === 'done' ? (
                                    <Check className="size-3" strokeWidth={3} />
                                ) : s.state === 'failed' ? (
                                    <X className="size-3" strokeWidth={3} />
                                ) : s.state === 'current' ? (
                                    <Icon className="size-3" />
                                ) : s.state === 'skipped' ? (
                                    <Minus className="size-3" />
                                ) : (
                                    k + 1
                                )}
                            </span>
                            {/* الحاليّةُ والموقوفةُ باسمهما دائمًا، والبقيّةُ حين يتّسع العرض */}
                            <span className={cn('whitespace-nowrap', !(s.focus || s.state === 'failed') && 'sr-only xl:not-sr-only')}>{name}</span>
                        </Tag>
                        {k < stages.length - 1 && (
                            <span aria-hidden className="h-px min-w-1 flex-1" style={{ backgroundColor: s.state === 'done' ? PURPLE : '#e4e4e7' }} />
                        )}
                    </li>
                );
            })}
        </ol>
    );
}
