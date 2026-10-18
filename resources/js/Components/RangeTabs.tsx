import { useState } from 'react';
import { router } from '@inertiajs/react';
import Tabs from '@/Components/Tabs';
import CustomDateRangePicker from '@/Components/CustomDateRangePicker';

export type ReportRange = 'today' | 'week' | 'month' | 'year' | 'all' | 'custom';

/** الفترةُ كما يرسلها الخادم (`ReportPeriod::toArray`) */
export interface PeriodInfo {
    range: ReportRange;
    from: string | null;
    to: string | null;
    label: string;
    error: string | null;
}

/** الفترات كما يفهمها الخادم — يجب أن تطابق Demo::RANGES */
const RANGES: { key: ReportRange; label: string }[] = [
    { key: 'today', label: 'اليوم' },
    { key: 'week', label: 'الأسبوع' },
    { key: 'month', label: 'الشهر' },
    { key: 'year', label: 'السنة' },
    { key: 'all', label: 'الكل' },
];

/** ما يُرسَل في الرابط ليفتح على هذه الفترة — للتصدير وللتغذية */
export function periodQuery(period?: Pick<PeriodInfo, 'range' | 'from' | 'to'> | null): Record<string, string> {
    if (!period) return {};

    return period.range === 'custom' && period.from && period.to
        ? { range: 'custom', from: period.from, to: period.to }
        : { range: period.range };
}

interface Props {
    current: ReportRange;
    /** أعمدة الصفحة كلّها تُعاد من الخادم — الفترة تغيّر الأرقام لا شكلها */
    only?: string[];
    /**
     * بقيّةُ المرشّحات لتُحمل مع الفترة.
     *
     * الانتقال يكتب سلسلة الاستعلام كاملةً، فبلا حملها يُفقد كلُّ مرشّحٍ
     * آخر عند تبديل الفترة: يختار التاجر فرعًا ثم يبدّل من «الشهر» إلى
     * «السنة» فيعود الجدول إلى الفروع كلّها بلا أن يلمس المنتقي.
     */
    params?: Record<string, string | null | undefined>;
    /**
     * يفتح «فترة مخصّصة» — للشاشات التي يقرأ خادمُها الحدّين (`ReportPeriod`).
     * وشاشةٌ لا يقرؤهما خادمُها لا تعرضها: فترةٌ تُختار ثمّ تُتجاهل أسوأ من لا فترة.
     */
    period?: PeriodInfo | null;
}

/**
 * مبدّل فترة التقرير.
 *
 * كانت كل شاشة تقرير تقرأ فترتها المخفيّة: البطاقات تجمع عمر المتجر كلّه،
 * والمخطّط تحتها يرسم السنة الجارية، والمقارنة تقيس شهرًا بشهر — ثلاث فترات
 * في شاشةٍ واحدة ولا شيء يقول ذلك. فصارت واحدةً يختارها التاجر ويقرأ الجميعُ
 * منها.
 *
 * والفترة في الرابط لا في الجلسة: رابطٌ يُرسَل أو يُحفَظ يفتح على ما فُتح
 * عليه، ولا تتبدّل شاشة أحدٍ لأن آخر بدّلها من جهازٍ ثانٍ.
 *
 * ═══ والمخصَّصةُ من يومٍ إلى يوم ═══
 *
 * `range=custom&from=…&to=…` — والعودةُ إلى فترةٍ جاهزة تُسقط الحدّين من الرابط:
 * «الشهر» ومعه `from` قديمٌ رابطٌ يقول شيئين. وبقيّةُ المرشّحات تبقى كما هي.
 *
 * وشكلُه شكلُ تبويبات النظام: يُبنى على `Tabs` فلا يفترق عمّا حوله.
 */
export default function RangeTabs({ current, only, params, period }: Props) {
    const custom = period !== undefined && period !== null;
    const [picking, setPicking] = useState(false);
    const open = custom && (current === 'custom' || picking || Boolean(period?.error));

    /* المرشّحاتُ الأخرى تبقى، والحدّان والفترةُ يُكتبان من جديد */
    const rest = () => {
        const out: Record<string, string | null | undefined> = { ...(params ?? {}) };
        delete out.range;
        delete out.from;
        delete out.to;

        return out;
    };

    const visit = (query: Record<string, string | null | undefined>) =>
        router.get(window.location.pathname, { ...rest(), ...query }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only,
        });

    const go = (range: string) => {
        if (range === 'custom') {
            setPicking(true);
            return;
        }
        setPicking(false);
        if (range === current && !period?.error) return;
        visit({ range });
    };

    const tabs = custom ? [...RANGES, { key: 'custom' as ReportRange, label: 'فترة مخصّصة' }] : RANGES;

    return (
        <>
            <Tabs className="mb-4" current={picking ? 'custom' : current} onChange={go} tabs={tabs} />
            {open && (
                <CustomDateRangePicker
                    from={period?.from ?? null}
                    to={period?.to ?? null}
                    error={period?.error}
                    onApply={(from, to) => {
                        setPicking(false);
                        visit({ range: 'custom', from, to });
                    }}
                    onReset={() => {
                        setPicking(false);
                        visit({ range: 'month' });
                    }}
                />
            )}
        </>
    );
}
