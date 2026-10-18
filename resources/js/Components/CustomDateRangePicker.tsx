import { useEffect, useState } from 'react';
import { CalendarRange, RotateCcw } from 'lucide-react';
import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

interface Props {
    from: string | null;
    to: string | null;
    /** سببُ رفض الخادم لفترةٍ وصلته — يُقال هنا لا يُبتلع */
    error?: string | null;
    onApply: (from: string, to: string) => void;
    onReset: () => void;
    className?: string;
}

/** صيغةُ اليوم كما يكتبه حقلُ التاريخ ويقرؤه الخادم — سنة-شهر-يوم */
const DAY = /^\d{4}-\d{2}-\d{2}$/;

/** سببُ رفض الفترة قبل أن تُرسَل — أو null فهي سليمة. والخادمُ يعيد السؤال على كلّ حال */
export function rangeProblem(from: string, to: string): string | null {
    if (!from || !to) return 'اختر تاريخ البداية وتاريخ النهاية.';
    if (!DAY.test(from) || !DAY.test(to)) return 'تاريخٌ غير صحيح — الصيغة سنة-شهر-يوم.';
    if (to < from) return 'تاريخ النهاية قبل تاريخ البداية.';

    return null;
}

/**
 * فترةٌ مخصَّصة — من يومٍ إلى يوم، بيومَيها كاملين.
 *
 * حقلان وزرّ: الفترةُ لا تُطلب عند كلّ حرفٍ يُكتب في الحقل — تاريخٌ نصفُه
 * مكتوب يرسل طلبًا لفترةٍ لم يُردها أحد. فتُكتب ثمّ تُطبَّق.
 *
 * و«إلى» لا تسبق «من»: يُمنع في الحقل نفسه (`min`) ويُقال قبل الإرسال،
 * والخادمُ يردّها إن وصلته (`ReportPeriod::check`).
 */
export default function CustomDateRangePicker({ from, to, error, onApply, onReset, className }: Props) {
    const t = useTranslate();
    const [start, setStart] = useState(from ?? '');
    const [end, setEnd] = useState(to ?? '');

    useEffect(() => {
        setStart(from ?? '');
        setEnd(to ?? '');
    }, [from, to]);

    const problem = start || end ? rangeProblem(start, end) : null;
    const shown = problem ?? error ?? null;

    return (
        <div
            role="group"
            aria-label={t('فترة مخصّصة')}
            className={cn('mb-4 flex flex-wrap items-end gap-3 rounded-[12px] border border-[var(--ui-border,#e8e8e8)] bg-white p-3', className)}
        >
            <Field label="من تاريخ" className="w-full min-w-0 sm:w-auto sm:min-w-[160px]">
                <Input type="date" value={start} max={end || undefined} onChange={(e) => setStart(e.target.value)} aria-label={t('من تاريخ')} />
            </Field>
            <Field label="إلى تاريخ" className="w-full min-w-0 sm:w-auto sm:min-w-[160px]">
                <Input type="date" value={end} min={start || undefined} onChange={(e) => setEnd(e.target.value)} aria-label={t('إلى تاريخ')} />
            </Field>
            <div className="flex w-full gap-2 sm:w-auto">
                <Button className="flex-1 sm:flex-none" disabled={rangeProblem(start, end) !== null} onClick={() => onApply(start, end)}>
                    <CalendarRange />
                    {t('تطبيق الفترة')}
                </Button>
                <Button variant="outline" className="flex-1 sm:flex-none" onClick={onReset}>
                    <RotateCcw />
                    {t('إعادة تعيين')}
                </Button>
            </div>
            {shown && (
                <p role="alert" className="w-full text-[12.5px] text-[#b91c1c]">
                    {t(shown)}
                </p>
            )}
        </div>
    );
}
