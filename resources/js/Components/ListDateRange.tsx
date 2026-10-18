import { router } from '@inertiajs/react';
import { X } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

interface Props {
    /** الشاشةُ التي تُعاد بالحدّين */
    routeName: string;
    routeParams?: Record<string, string | number>;
    /** بقيّةُ المرشّحات — تبقى كما هي حين يتغيّر الحدّان */
    params?: Record<string, string | number | null | undefined>;
    from?: string | null;
    to?: string | null;
    /** معنى التاريخ في هذه الشاشة: «الاستلام»، «تاريخ الأمر»، «فترة الراتب»… */
    label?: string;
    className?: string;
}

/**
 * حدّا مدّةٍ لشاشةٍ تشغيليّة — من يومٍ إلى يوم، وكلٌّ منهما اختياريّ.
 *
 * يُكتبان في الرابط (`from` · `to`) فيقرؤهما الخادمُ للقائمة ولملفّ تصديرها
 * معًا، ويُرسَل الرابطُ فيفتح على ما فُتح عليه. و«إلى» لا تسبق «من» في الحقل
 * نفسه، والخادمُ يتجاهل التاريخَ الخاطئ في الشاشة ويردّه في التصدير.
 */
export default function ListDateRange({ routeName, routeParams, params, from, to, label, className }: Props) {
    const t = useTranslate();

    const go = (patch: Record<string, string | null>) => {
        const query: Record<string, string | number> = {};
        for (const [k, v] of Object.entries({ ...(params ?? {}), from: from ?? null, to: to ?? null, ...patch })) {
            if (v !== null && v !== undefined && v !== '') query[k] = v;
        }
        router.get(route(routeName, routeParams), query, { preserveState: true, preserveScroll: true, replace: true });
    };

    return (
        <div role="group" aria-label={t(label ?? 'المدّة')} className={cn('mb-4 flex flex-wrap items-end gap-2', className)}>
            {label && <span className="w-full text-[12px] text-[#6b7280] sm:w-auto sm:pb-2.5">{t(label)}</span>}
            <label className="flex min-w-0 flex-1 flex-col gap-1 sm:max-w-[180px]">
                <span className="text-[12px] text-[#6b7280]">{t('من تاريخ')}</span>
                <Input type="date" dir="ltr" value={from ?? ''} max={to || undefined} onChange={(e) => go({ from: e.target.value || null })} />
            </label>
            <label className="flex min-w-0 flex-1 flex-col gap-1 sm:max-w-[180px]">
                <span className="text-[12px] text-[#6b7280]">{t('إلى تاريخ')}</span>
                <Input type="date" dir="ltr" value={to ?? ''} min={from || undefined} onChange={(e) => go({ to: e.target.value || null })} />
            </label>
            {(from || to) && (
                <Button variant="ghost" size="sm" className="mb-0.5" onClick={() => go({ from: null, to: null })}>
                    <X />
                    {t('إعادة تعيين')}
                </Button>
            )}
        </div>
    );
}
