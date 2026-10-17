import type { ReactNode } from 'react';
import { usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Banknote, CalendarClock, HandCoins, Landmark, type LucideIcon } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { FINANCE_TABS } from '@/Components/SectionTabs';
import RangeTabs, { type ReportRange } from '@/Components/RangeTabs';
import SmartLink from '@/Components/SmartLink';
import StatCard from '@/Components/StatCard';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import MonthlyExpensesCard, { type MonthlyExpenses } from './partials/MonthlyExpensesCard';

interface Props {
    range: ReportRange;
    /** رصيد الصندوق كما يقوله الدفتر */
    cash: number;
    /** مجموع أرصدة الحسابات البنكية المفعّلة */
    bank: number;
    accounts: { id: number; label: string; balance: number; active: boolean }[];
    period: {
        /** إجمالي الفواتير الصادرة شاملًا الضريبة — `Demo::reportSummary()['sales']` */
        invoice_total: number;
        expenses: number;
        profit: number;
        tax: number;
        in: number;
        out: number;
        transfers: number;
    };
    dues: { expenses: number; invoices: number; payroll: number; total: number; overdue: number };
    /** «ما لك» — ذمم العملاء، من `Receivables` نفسها التي تقرأ منها شاشة الذمم */
    receivables: { total: number; overdue: number; due_soon: number; credit: number; invoices: number; customers: number };
    /** مصروفات الشهر — شهرٌ يُختار بنفسه، من الدفتر (`MonthlyExpenses`) */
    monthly?: MonthlyExpenses;
    monthly_links?: { expenses: boolean; supplier_invoices: boolean; payroll: boolean };
}

/** بطاقةُ «وضعك الآن» — رقمٌ واحد، وسطرٌ إن كان فيه ما يُنبَّه إليه، وبابُ تفصيله */
function NowCard({ label, value, icon: Icon, tone, alarm, note, action }: {
    label: string;
    value: string;
    icon: LucideIcon;
    tone: string;
    /** لونُ الرقم حين يستدعي الانتباه — رصيدٌ سالب أو مستحقٌّ قائم */
    alarm?: string;
    note?: ReactNode;
    action?: { label: string; routeName: string };
}) {
    const t = useTranslate();

    return (
        <Card className="flex h-full flex-col justify-between gap-3 p-5">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="text-[12px] text-[#9ca3af]">{t(label)}</p>
                    <p className={cn('mt-1 text-[22px] font-bold tabular-nums tracking-tight', alarm ?? 'text-[#111]')}>{value}</p>
                    {note && <p className="mt-1 text-[12px]">{note}</p>}
                </div>
                <span className={cn('flex size-10 shrink-0 items-center justify-center rounded-[12px]', tone)}>
                    <Icon className="size-5" aria-hidden />
                </span>
            </div>
            {action && (
                <Button variant="outline" size="sm" className="self-start" asChild>
                    <SmartLink routeName={action.routeName} href={route(action.routeName)}>
                        {t(action.label)}
                    </SmartLink>
                </Button>
            )}
        </Card>
    );
}

/**
 * الملخّص المالي — الشاشةُ الأولى في المالية، بأربعة أجزاءٍ لا أكثر.
 *
 * ١. **وضعك الآن**: الصندوق والبنك ولك وعليك — حالةٌ لا حصيلةُ فترة، فلا
 *    يمسّها مبدّلُ المدّة. وكلُّ بطاقةٍ رقمُها وتنبيهُها وبابُ تفصيلها؛ والتفصيلُ
 *    في شاشته (الحسابات البنكية، لك عند العملاء، عليك الدفع) لا مكرّرًا هنا.
 * ٢. **مصروفات الشهر من الدفتر**: شهرٌ يُختار بنفسه، مختصرةً حتى تُفتح.
 * ٣. **ما جرى في المدة**: الفواتير والمصروفات المدفوعة والربح والضريبة.
 * ٤. **حركة المال**: دخلٌ وخرجٌ وتحويلات — سطرٌ صغير.
 *
 * وكلُّ رقمٍ من مصدره كما كان (`OverviewController::summary`) — الشاشةُ
 * ترتّب ولا تحسب.
 */
export default function Summary() {
    const { range, cash, bank, accounts, period, dues, receivables, monthly, monthly_links, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    return (
        <AdminLayout title="الملخّص المالي">
            <PageHeader
                title="الملخّص المالي"
                subtitle={t('أين المال الآن، وماذا جرى في المدة، وماذا على الشركة')}
            />

            <SectionTabs tabs={FINANCE_TABS} current="admin.finance.summary" />

            {/* ١. وضعك الآن — أربعُ بطاقاتٍ متوازنة: عمودٌ على الهاتف، ٢×٢ على اللوح، أربعةٌ على الشاشة العريضة */}
            <section aria-labelledby="finance-now" className="mb-6">
                <h2 id="finance-now" className="mb-3 text-[15px] font-bold text-[#111]">{t('وضعك الآن')}</h2>
                <div data-testid="finance-now" className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <NowCard
                        label="الصندوق"
                        value={m(cash)}
                        icon={Banknote}
                        tone="bg-[#ecfdf5] text-[#047857]"
                        alarm={cash < 0 ? 'text-[#b91c1c]' : undefined}
                    />
                    {/* إجماليُّ البنك وحده — الحساباتُ واحدًا واحدًا في شاشتها */}
                    <NowCard
                        label="البنك"
                        value={m(bank)}
                        icon={Landmark}
                        tone="bg-[#eff6ff] text-[#2563eb]"
                        alarm={bank < 0 ? 'text-[#b91c1c]' : undefined}
                        note={accounts.length > 1 ? <span className="text-[#9ca3af]">{accounts.length} {t('حسابات')}</span> : undefined}
                        action={{ label: 'عرض الحسابات', routeName: 'admin.finance.index' }}
                    />
                    {/* والإجماليُّ والمتأخّرُ وحدهما — العملاءُ والفواتيرُ وما يستحقّ قريبًا في شاشة الذمم */}
                    <NowCard
                        label="لك عند العملاء"
                        value={m(receivables.total)}
                        icon={HandCoins}
                        tone="bg-[#f0fdf4] text-[#15803d]"
                        note={receivables.overdue > 0 ? <span className="text-[#b91c1c]">{m(receivables.overdue)} {t('متأخّر عن موعده')}</span> : undefined}
                        action={{ label: 'التفاصيل', routeName: 'admin.finance.receivables' }}
                    />
                    {/* والمصروفاتُ والسنداتُ والرواتبُ مفصّلةً في «عليك الدفع» — لا تُعاد هنا */}
                    <NowCard
                        label="عليك الدفع"
                        value={m(dues.total)}
                        icon={CalendarClock}
                        tone="bg-[#fffbeb] text-[#b45309]"
                        alarm={dues.total > 0 ? 'text-[#b45309]' : undefined}
                        note={dues.overdue > 0 ? <span className="text-[#b91c1c]">{dues.overdue} {t('متأخّرة عن موعدها')}</span> : undefined}
                        action={{ label: 'التفاصيل', routeName: 'admin.finance.dues' }}
                    />
                </div>
            </section>

            {/* ٢. مصروفاتُ الشهر — شهرٌ يُختار بنفسه، فلا يُقرأ رقمُ المدّة تحته على أنّه رقمُ الشهر */}
            {monthly && (
                <MonthlyExpensesCard data={monthly} links={monthly_links ?? { expenses: false, supplier_invoices: false, payroll: false }} m={m} />
            )}

            {/* ٣. ما جرى في المدة — والمبدّل فوقه وحده كي لا يُقرأ رقمُ فترةٍ على أنه رقمُ أخرى */}
            <section aria-labelledby="finance-period">
                <h2 id="finance-period" className="mb-3 text-[15px] font-bold text-[#111]">{t('ما جرى في المدة')}</h2>
                <RangeTabs current={range} />

                <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard stat={{ label: t('إجمالي الفواتير (شامل الضريبة)'), value: m(period.invoice_total), icon: 'receipt', color: 'primary' }} index={0} />
                    {/* المسدَّدُ من سجلّ المصروفات وحده — لا الرواتبُ ولا السنداتُ ولا التكلفة؛ وكاملُ الشهر من الدفتر في البطاقة أعلاه */}
                    <StatCard stat={{ label: t('المصروفات المدفوعة في المدة'), value: m(period.expenses), icon: 'arrow-down-circle', color: 'danger', hint: t('المسدَّدة من سجل المصروفات فقط') }} index={1} />
                    <StatCard stat={{ label: t('صافي الربح'), value: m(period.profit), icon: 'trending-up', color: 'success' }} index={2} />
                    <StatCard stat={{ label: t('ضريبة محصّلة'), value: m(period.tax), icon: 'receipt', color: 'warning' }} index={3} />
                </div>

                {/* ٤. حركةُ المال — ثلاثةُ أرقامٍ في سطر، والتحويلُ يُعرض ولا يُجمع: مالٌ انتقل من جيبٍ إلى جيب */}
                <Card className="px-5 py-4" aria-labelledby="finance-flow">
                    <h3 id="finance-flow" className="mb-2 text-[13px] font-semibold text-[#374151]">{t('حركة المال في المدة')}</h3>
                    <dl className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div className="flex items-center justify-between gap-3 sm:block">
                            <dt className="flex items-center gap-1 text-[12px] text-[#9ca3af]"><ArrowDown className="size-3.5 text-[#15803d]" aria-hidden />{t('دخل')}</dt>
                            <dd className="text-[16px] font-semibold tabular-nums text-[#15803d]">{m(period.in)}</dd>
                        </div>
                        <div className="flex items-center justify-between gap-3 sm:block">
                            <dt className="flex items-center gap-1 text-[12px] text-[#9ca3af]"><ArrowUp className="size-3.5 text-[#b91c1c]" aria-hidden />{t('خرج')}</dt>
                            <dd className="text-[16px] font-semibold tabular-nums text-[#b91c1c]">{m(period.out)}</dd>
                        </div>
                        <div className="flex items-center justify-between gap-3 sm:block">
                            <dt className="text-[12px] text-[#9ca3af]">{t('تحويلات بين الصندوق والبنك')}</dt>
                            <dd className="text-[16px] font-semibold tabular-nums text-[#6b7280]">{m(period.transfers)}</dd>
                        </div>
                    </dl>
                    <p className="mt-2 text-[11.5px] text-[#9ca3af]">{t('التحويل بين الصندوق والبنك لا يُقرأ دخلًا ولا مصروفًا')}</p>
                </Card>
            </section>
        </AdminLayout>
    );
}
