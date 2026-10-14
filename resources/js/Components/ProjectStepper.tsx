import { Check, X } from 'lucide-react';
import { useTranslate } from '@/lib/i18n';
import type { Stage, StageKey } from '@/lib/projectStages';
import { cn } from '@/lib/utils';

const PURPLE = '#6d28d9';

/**
 * شريطُ مراحل المشروع — المنجزةُ دائرةٌ ممتلئة، والحاليّةُ بلونها، وما
 * بعدها رماديّ، والموقوفةُ ✕ حمراء.
 *
 * وبـ`onPick` تصير كلُّ مرحلةٍ زرًّا يقود إلى موضعها في صفحة المشروع.
 */
export default function ProjectStepper({ stages, onPick, size = 'sm', className }: { stages: Stage[]; onPick?: (key: StageKey) => void; size?: 'sm' | 'lg'; className?: string }) {
    const t = useTranslate();
    const lg = size === 'lg';

    return (
        <div className={cn('flex min-w-0 items-center overflow-x-auto rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-white', lg ? 'px-4 py-3' : 'px-3 py-2', className)}>
            {stages.map((s, k) => {
                const Icon = s.icon;
                const Tag = onPick ? 'button' : 'span';

                return (
                    <div key={s.key} className={cn('flex items-center', lg ? 'min-w-0 flex-1 last:flex-none' : 'shrink-0')}>
                        <Tag
                            {...(onPick ? { type: 'button' as const, onClick: () => onPick(s.key) } : {})}
                            className={cn(
                                'inline-flex shrink-0 items-center gap-1.5 font-medium',
                                lg ? 'text-[13.5px]' : 'text-[12.5px]',
                                onPick && 'rounded-md px-1 py-0.5 hover:bg-[#f5f3ff]',
                                (s.state === 'done' || s.state === 'current') && 'text-[#6d28d9]',
                                s.state === 'todo' && 'text-[#a1a1aa]',
                                s.state === 'failed' && 'text-[#dc2626]',
                            )}
                        >
                            {s.state === 'done' ? (
                                <span className={cn('flex items-center justify-center rounded-full bg-[#6d28d9] text-white', lg ? 'size-6' : 'size-[18px]')}>
                                    <Check className={lg ? 'size-3.5' : 'size-3'} strokeWidth={3} />
                                </span>
                            ) : s.state === 'failed' ? (
                                <span className={cn('flex items-center justify-center rounded-full bg-[#dc2626] text-white', lg ? 'size-6' : 'size-[18px]')}>
                                    <X className={lg ? 'size-3.5' : 'size-3'} strokeWidth={3} />
                                </span>
                            ) : s.state === 'current' ? (
                                <span className={cn('flex items-center justify-center rounded-full bg-[#f3e8ff] ring-2 ring-[#6d28d9]', lg ? 'size-6' : 'size-[18px]')}>
                                    <Icon className={lg ? 'size-3.5' : 'size-3'} />
                                </span>
                            ) : (
                                <Icon className={lg ? 'size-5' : 'size-[18px]'} />
                            )}
                            {s.state === 'failed' && s.note ? t(s.note) : t(s.label)}
                            {s.state === 'current' && s.note && <span className="text-[11px] font-normal text-[#8b5cf6]">· {t(s.note)}</span>}
                        </Tag>
                        {k < stages.length - 1 && (
                            <span
                                className={cn('mx-2 h-px', lg ? 'min-w-6 flex-1' : 'w-6 sm:w-10')}
                                style={{ backgroundColor: s.state === 'done' ? PURPLE : '#e4e4e7' }}
                            />
                        )}
                    </div>
                );
            })}
        </div>
    );
}
