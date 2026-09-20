import { router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import BackLink from '@/Components/BackLink';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { assetTone, label, type Labels } from '@/lib/operations';

interface AssetRow {
    id: number;
    code: string;
    name: string;
    category: string;
    category_label: string;
    status: string;
    out_of_service: boolean;
}

interface ReservationRow {
    id: number;
    asset_id: number;
    status: string;
    from: string;
    until: string;
    operation: { id: number; code: string; name: string } | null;
    custodian: string | null;
}

interface Props {
    from: string;
    days: number;
    filters: { from?: string; days?: string; category?: string };
    assets: AssetRow[];
    reservations: ReservationRow[];
    categories: { value: string; label: string }[];
    labels: Labels;
    may: { operations: boolean };
}

/**
 * تقويمُ المعدّات — قطعةٌ في كلّ صفّ، ويومٌ في كلّ عمود، والحجزُ شريط.
 *
 * ليس محرّكَ جدولةٍ رسوميًّا: شبكةٌ تُقرأ. الصوابُ قبل الزينة — والحجزُ نفسُه
 * من شاشة الفعاليّة حيث يُفحص التداخل.
 */
export default function AssetCalendar({ from, days, filters, assets, reservations, categories, labels, may }: Props) {
    const t = useTranslate();
    const start = new Date(from + 'T00:00:00');
    const columns = Array.from({ length: days }, (_, i) => {
        const d = new Date(start);
        d.setDate(start.getDate() + i);

        return d;
    });
    const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    const today = iso(new Date());

    const byAsset = new Map<number, ReservationRow[]>();
    for (const r of reservations) {
        byAsset.set(r.asset_id, [...(byAsset.get(r.asset_id) ?? []), r]);
    }

    const covers = (r: ReservationRow, day: string) => r.from.slice(0, 10) <= day && r.until.slice(0, 10) >= day;

    const go = (patch: Record<string, string>) => router.get(route('admin.assets.calendar'), { ...filters, ...patch }, { preserveState: true, replace: true });

    const shift = (n: number) => {
        const d = new Date(start);
        d.setDate(start.getDate() + n);
        go({ from: iso(d) });
    };

    return (
        <AdminLayout title="تقويم المعدّات">
            <BackLink routeName="admin.assets.index" href={route('admin.assets.index')} label="المعدّات" />
            <PageHeader title="تقويم المعدّات" subtitle={t('ما يشغل كلَّ قطعةٍ يومًا بيوم — والحجزُ من شاشة الفعاليّة')} />

            <Card className="mb-4 p-4">
                <div className="flex flex-wrap items-end gap-3">
                    <Field label="من" className="min-w-[160px]"><Input type="date" value={from} onChange={(e) => go({ from: e.target.value })} /></Field>
                    <Field label="الأيّام" className="min-w-[120px]">
                        <Select value={String(days)} onChange={(e) => go({ days: e.target.value })} options={[7, 14, 30, 60].map((n) => ({ value: n, label: String(n) }))} />
                    </Field>
                    <Field label="الصنف" className="min-w-[160px]">
                        <Select value={filters.category ?? ''} placeholder="الكلّ" onChange={(e) => go({ category: e.target.value })} options={categories} />
                    </Field>
                    <div className="flex gap-2">
                        <Button variant="outline" size="sm" onClick={() => shift(-days)}>{t('السابق')}</Button>
                        <Button variant="outline" size="sm" onClick={() => go({ from: today })}>{t('اليوم')}</Button>
                        <Button variant="outline" size="sm" onClick={() => shift(days)}>{t('التالي')}</Button>
                    </div>
                </div>
            </Card>

            <Card className="overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full border-collapse text-[12px]">
                        <thead className="bg-[#fafafa] text-[11px] text-[#6b7280]">
                            <tr>
                                <th className="sticky start-0 z-10 bg-[#fafafa] px-3 py-2 text-start">{t('القطعة')}</th>
                                {columns.map((d) => {
                                    const key = iso(d);

                                    return (
                                        <th key={key} className={'min-w-[34px] px-1 py-2 text-center font-normal ' + (key === today ? 'bg-[#eff6ff] text-[#1d4ed8]' : '')}>
                                            <span className="block tabular-nums">{d.getDate()}</span>
                                            <span className="block text-[10px]">{String(d.getMonth() + 1).padStart(2, '0')}</span>
                                        </th>
                                    );
                                })}
                            </tr>
                        </thead>
                        <tbody>
                            {assets.length === 0 && <tr><td colSpan={days + 1} className="px-3 py-8 text-center text-[#9ca3af]">{t('لا قطع')}</td></tr>}
                            {assets.map((a) => {
                                const rows = byAsset.get(a.id) ?? [];

                                return (
                                    <tr key={a.id} className="border-t border-[#f4f4f5]">
                                        <td className="sticky start-0 z-10 bg-white px-3 py-1.5">
                                            <SmartLink routeName="admin.assets.show" href={route('admin.assets.show', a.id)} className="font-mono text-[11px] text-[#1d4ed8]">{a.code}</SmartLink>
                                            <span className="ms-2">{a.name}</span>
                                            <span className={'ms-2 rounded-full px-1.5 text-[10px] ' + assetTone(a.status)}>{label(labels, 'asset_status', a.status)}</span>
                                        </td>
                                        {columns.map((d) => {
                                            const key = iso(d);
                                            const hit = rows.find((r) => covers(r, key));

                                            return (
                                                <td key={key} className={'px-0.5 py-1 text-center ' + (key === today ? 'bg-[#eff6ff]/50' : '')}>
                                                    {a.out_of_service ? (
                                                        <span className="block h-5 rounded bg-[#fee2e2]" title={label(labels, 'asset_status', a.status)} />
                                                    ) : hit ? (
                                                        may.operations && hit.operation ? (
                                                            <SmartLink
                                                                routeName="admin.operations.show"
                                                                href={route('admin.operations.show', hit.operation.id)}
                                                                className={'block h-5 rounded text-[10px] leading-5 text-white ' + (hit.status === 'checked_out' ? 'bg-[#2563eb]' : 'bg-[#d97706]')}
                                                            >
                                                                {hit.from.slice(0, 10) === key ? hit.operation.code : ''}
                                                            </SmartLink>
                                                        ) : (
                                                            <span className={'block h-5 rounded text-[10px] leading-5 text-white ' + (hit.status === 'checked_out' ? 'bg-[#2563eb]' : 'bg-[#d97706]')}>{hit.from.slice(0, 10) === key ? hit.operation?.code : ''}</span>
                                                        )
                                                    ) : null}
                                                </td>
                                            );
                                        })}
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
                <div className="flex flex-wrap gap-4 border-t border-[#f4f4f5] px-4 py-2 text-[11px] text-[#6b7280]">
                    <span><span className="me-1 inline-block size-3 rounded bg-[#d97706] align-middle" />{t('محجوزة')}</span>
                    <span><span className="me-1 inline-block size-3 rounded bg-[#2563eb] align-middle" />{t('في الميدان')}</span>
                    <span><span className="me-1 inline-block size-3 rounded bg-[#fee2e2] align-middle" />{t('خارج الخدمة')}</span>
                </div>
            </Card>
        </AdminLayout>
    );
}
