import { useState } from 'react';
import { router } from '@inertiajs/react';
import { ArrowDownRight, ArrowUpRight, CalendarDays, ChevronDown, ChevronUp } from 'lucide-react';
import Field, { Select } from '@/Components/Field';
import SmartLink from '@/Components/SmartLink';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { useTranslate } from '@/lib/i18n';

export interface MonthlyExpenses {
    month: string;
    label: string;
    from: string;
    to: string;
    total: number;
    groups: { key: string; label: string; total: number; accounts: { account_id: number; code: string; name: string; total: number; entries: number }[] }[];
    previous: { month: string; label: string; total: number };
    change: { amount: number; percent: number | null };
    entries: number;
    inventory_purchases: number;
    obligations: { paid: number; due: number; overdue: number; documents: { expenses: number; supplier_invoices: number; payroll: number } };
    months: { value: string; label: string }[];
}

/**
 * «مصروفات الشهر» — شهرٌ تقويميٌّ يُختار بنفسه، مستقلٌّ عن تبويبات المدّة.
 *
 * الإجماليُّ وتفصيلُه من الدفتر (صافي حسابات المصروف في قيود الشهر) فيطابق
 * اليوميّة؛ والمدفوعُ والمستحقُّ والمتأخّر من مستندات الشهر. وما ذهب إلى المخزون
 * معلومةٌ لا يُجمع — أصلٌ حتى يُستهلك. انظر `MonthlyExpenses`.
 */
export default function MonthlyExpensesCard({ data, links, m }: {
    data: MonthlyExpenses;
    links: { expenses: boolean; supplier_invoices: boolean; payroll: boolean };
    m: (v: number) => string;
}) {
    const t = useTranslate();
    const [open, setOpen] = useState(false);
    const up = data.change.amount > 0.0005;
    const down = data.change.amount < -0.0005;
    const documents = data.obligations.documents.expenses + data.obligations.documents.supplier_invoices + data.obligations.documents.payroll;

    const choose = (month: string) => {
        const params = Object.fromEntries(new URLSearchParams(window.location.search));
        router.get(route('admin.finance.summary'), { ...params, expense_month: month }, { preserveState: true, preserveScroll: true, replace: true, only: ['monthly'] });
    };

    return (
        <Card className="mb-6 p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="flex items-center gap-2 text-[15px] font-bold text-[#111]"><CalendarDays className="size-4" />{t('مصروفات الشهر')}</h2>
                    <p className="mt-0.5 text-[12px] text-[#9ca3af]">{t('شهرية — مستقلة عن تبويبات المدة أدناه، ومن دفتر القيود')}</p>
                </div>
                <Field label="الشهر" className="w-[190px]">
                    <Select value={data.month} onChange={(e) => choose(e.target.value)} options={data.months.map((x) => ({ value: x.value, label: x.label }))} />
                </Field>
            </div>

            <div className="mt-4 flex flex-wrap items-end gap-x-6 gap-y-2">
                <div>
                    <div className="text-[12px] text-[#9ca3af]">{t('إجمالي مصروفات :month', { month: data.label })}</div>
                    <div className="mt-1 text-[28px] font-bold tabular-nums tracking-tight text-[#111]">{m(data.total)}</div>
                </div>
                <div className={'mb-1 flex items-center gap-1 text-[12.5px] ' + (up ? 'text-[#b91c1c]' : down ? 'text-[#047857]' : 'text-[#6b7280]')}>
                    {up ? <ArrowUpRight className="size-4" /> : down ? <ArrowDownRight className="size-4" /> : null}
                    {data.change.percent === null
                        ? t('لا مصروفات في :month للمقارنة', { month: data.previous.label })
                        : t(':percent٪ عن :month (:amount)', { percent: String(Math.abs(data.change.percent)), month: data.previous.label, amount: m(data.previous.total) })}
                </div>
            </div>

            {data.groups.length === 0 ? (
                <p className="mt-4 text-[13px] text-[#9ca3af]">{t('لا مصروفات مقيّدة في هذا الشهر')}</p>
            ) : (
                <ul className="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    {data.groups.map((g) => (
                        <li key={g.key} className="flex items-center justify-between gap-3 rounded-[12px] bg-[#fafafa] px-3 py-2 text-[13px]">
                            <span className="text-[#4b4b4b]">{g.label}</span>
                            <span className="font-semibold tabular-nums text-[#111]">{m(g.total)}</span>
                        </li>
                    ))}
                </ul>
            )}

            <dl className="mt-4 grid grid-cols-2 gap-4 border-t border-[var(--ui-border,#e8e8e8)] pt-4 sm:grid-cols-4">
                <div>
                    <dt className="text-[12px] text-[#9ca3af]">{t('المدفوع')}</dt>
                    <dd className="mt-0.5 text-[16px] font-semibold tabular-nums text-[#047857]">{m(data.obligations.paid)}</dd>
                </div>
                <div>
                    <dt className="text-[12px] text-[#9ca3af]">{t('المستحق')}</dt>
                    <dd className="mt-0.5 text-[16px] font-semibold tabular-nums text-[#b45309]">{m(data.obligations.due)}</dd>
                </div>
                <div>
                    <dt className="text-[12px] text-[#9ca3af]">{t('المتأخر')}</dt>
                    <dd className={'mt-0.5 text-[16px] font-semibold tabular-nums ' + (data.obligations.overdue > 0.0005 ? 'text-[#b91c1c]' : 'text-[#6b7280]')}>{m(data.obligations.overdue)}</dd>
                </div>
                <div>
                    <dt className="text-[12px] text-[#9ca3af]">{t('العمليات')}</dt>
                    <dd className="mt-0.5 text-[16px] font-semibold tabular-nums text-[#111]">{t(':entries قيدًا · :documents مستندًا', { entries: String(data.entries), documents: String(documents) })}</dd>
                </div>
            </dl>
            <p className="mt-2 text-[11.5px] leading-relaxed text-[#9ca3af]">
                {t('المدفوع والمستحق والمتأخر من مستندات الشهر: المصروفات وسندات الموردين المعتمدة ومسيرة الرواتب — والمصروف غير المسدَّد يُحسب مستحقًّا ولا يدخل الإجمالي حتى يُسدَّد.')}
                {data.inventory_purchases > 0.0005 && <> {t('ومشتريات للمخزون :amount لا تُحسب مصروفًا حتى تُستهلك.', { amount: m(data.inventory_purchases) })}</>}
            </p>

            <Button variant="outline" size="sm" className="mt-3" onClick={() => setOpen((v) => !v)}>
                {open ? <ChevronUp /> : <ChevronDown />}{t('عرض تفاصيل المصروفات')}
            </Button>

            {open && (
                <div className="mt-3 space-y-3">
                    {data.groups.map((g) => (
                        <div key={g.key}>
                            <div className="mb-1 text-[12px] font-semibold text-[#374151]">{g.label}</div>
                            <table className="w-full text-[12.5px]">
                                <tbody>
                                    {g.accounts.map((a) => (
                                        <tr key={a.account_id} className="border-t border-[#f4f4f5]">
                                            <td className="w-14 py-1.5 text-start font-mono text-[11.5px] text-[#9ca3af]"><span dir="ltr">{a.code}</span></td>
                                            <td className="py-1.5 ps-2">{a.name}</td>
                                            <td className="py-1.5 text-[#9ca3af]">{t(':n قيد', { n: String(a.entries) })}</td>
                                            <td className="py-1.5 text-end font-semibold tabular-nums">{m(a.total)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ))}
                    <div className="flex flex-wrap gap-2 border-t border-[#f4f4f5] pt-3">
                        <Button variant="ghost" size="sm" asChild>
                            <SmartLink routeName="admin.finance.journal" href={route('admin.finance.journal', { from: data.from, to: data.to })}>{t('قيود الشهر في اليومية')}</SmartLink>
                        </Button>
                        {links.expenses && (
                            <Button variant="ghost" size="sm" asChild>
                                <SmartLink routeName="admin.expenses.index" href={route('admin.expenses.index', { month: data.month })}>{t('مصروفات الشهر')}</SmartLink>
                            </Button>
                        )}
                        {links.supplier_invoices && (
                            <Button variant="ghost" size="sm" asChild>
                                <SmartLink routeName="admin.purchases.invoices" href={route('admin.purchases.invoices')}>{t('سندات الموردين')}</SmartLink>
                            </Button>
                        )}
                        {links.payroll && (
                            <Button variant="ghost" size="sm" asChild>
                                <SmartLink routeName="admin.payroll.index" href={route('admin.payroll.index')}>{t('مسيرات الرواتب')}</SmartLink>
                            </Button>
                        )}
                    </div>
                </div>
            )}
        </Card>
    );
}
