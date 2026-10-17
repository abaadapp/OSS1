import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Plus, Tags, UsersRound } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { CUSTOMER_TABS } from '@/Components/SectionTabs';
import SmartLink from '@/Components/SmartLink';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { organizerTone, type OrganizerCategory, type OrganizerLabels, type OrganizerRow } from '@/lib/organizers';
import OrganizerDialog from './partials/OrganizerDialog';
import CatalogDialog from '@/Components/CatalogDialog';

interface Props {
    organizers: OrganizerRow[];
    stats: { total: number; available: number; male: number; female: number };
    filters: { q: string | null; gender: string | null; status: string | null; category: number | null };
    categories: OrganizerCategory[];
    labels: OrganizerLabels;
    may: { manage: boolean };
}

/**
 * المنظّمون — تبويبٌ في قسم «العملاء»، وسجلُّهم مستقلّ: ليسوا عملاءَ ولا موظّفين بحسابات.
 *
 * يُبحث بالاسم والهاتف، ويُرشَّح بالجنس والتصنيف والحال. والتعيينُ على
 * الفعاليّة من شاشتها، ومسؤولُ المنظّمين موظّفٌ يُختار هناك.
 */
export default function OrganizersIndex() {
    const { organizers, stats, filters, categories, labels, may, errors } = usePage().props as unknown as Props & { errors?: Record<string, string> };
    const t = useTranslate();
    const [creating, setCreating] = useState(false);
    const [managing, setManaging] = useState(false);

    const filter = (patch: Record<string, string>) =>
        router.get(route('admin.organizers.index'), { ...filters, ...patch }, { preserveState: true, replace: true });
    const options = (group: keyof OrganizerLabels) => Object.entries(labels[group]).map(([value, label]) => ({ value, label }));

    return (
        <AdminLayout title="المنظّمون">
            <PageHeader
                title="المنظّمون"
                subtitle={t('من يعمل في الميدان من فعاليّةٍ إلى أخرى — بتصنيفاته وحاله')}
                actions={
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" onClick={() => setManaging(true)}><Tags />{t('التصنيفات')}</Button>
                        {may.manage && <Button onClick={() => setCreating(true)}><Plus />{t('منظّم جديد')}</Button>}
                    </div>
                }
            />

            <SectionTabs tabs={CUSTOMER_TABS} current="admin.organizers.index" />

            {errors?.organizer && <div className="mb-4 rounded-lg bg-[#fef2f2] px-4 py-3 text-[13px] text-[#b91c1c]">{errors.organizer}</div>}

            <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4">
                {[
                    { label: 'كلّ المنظّمين', value: stats.total, patch: { status: '', gender: '' } },
                    { label: 'متاح', value: stats.available, patch: { status: 'available', gender: '' } },
                    { label: 'ذكر', value: stats.male, patch: { status: '', gender: 'male' } },
                    { label: 'أنثى', value: stats.female, patch: { status: '', gender: 'female' } },
                ].map((s) => (
                    <button key={s.label} type="button" onClick={() => filter(s.patch)}
                        className="rounded-[12px] border border-[var(--ui-border,#e8e8e8)] bg-white p-4 text-start hover:border-[#c4b5fd]">
                        <span className="block text-[12px] text-[#6b7280]">{t(s.label)}</span>
                        <span className="mt-1 block text-[22px] font-bold tabular-nums text-[#111]">{s.value}</span>
                    </button>
                ))}
            </div>

            <Card className="mb-4 p-4">
                <div className="flex flex-wrap items-end gap-3">
                    <Field label="بحث" className="min-w-[200px] flex-1">
                        <Input defaultValue={filters.q ?? ''} placeholder="الاسم أو الهاتف أو البريد"
                            onKeyDown={(e) => e.key === 'Enter' && filter({ q: (e.target as HTMLInputElement).value })} />
                    </Field>
                    <Field label="الجنس" className="min-w-[120px]">
                        <Select value={filters.gender ?? ''} placeholder="الكلّ" onChange={(e) => filter({ gender: e.target.value })} options={options('gender')} />
                    </Field>
                    <Field label="التصنيف" className="min-w-[150px]">
                        <Select value={filters.category ? String(filters.category) : ''} placeholder="الكلّ" onChange={(e) => filter({ category: e.target.value })}
                            options={categories.map((c) => ({ value: String(c.id), label: c.name }))} />
                    </Field>
                    <Field label="الحالة" className="min-w-[130px]">
                        <Select value={filters.status ?? ''} placeholder="الكلّ" onChange={(e) => filter({ status: e.target.value })} options={options('status')} />
                    </Field>
                </div>
            </Card>

            <Card className="overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-[13px]">
                        <thead className="bg-[#fafafa] text-[12px] text-[#6b7280]">
                            <tr>
                                <th className="px-4 py-2 text-start">{t('المنظّم')}</th>
                                <th className="px-4 py-2 text-start">{t('الهاتف')}</th>
                                <th className="px-4 py-2 text-center">{t('الجنس')}</th>
                                <th className="px-4 py-2 text-start">{t('التصنيفات')}</th>
                                <th className="px-4 py-2 text-center">{t('الحالة')}</th>
                                <th className="px-4 py-2 text-center">{t('فعاليّات قادمة')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {organizers.length === 0 && (
                                <tr><td colSpan={6} className="px-4 py-10 text-center text-[#9ca3af]"><UsersRound className="mx-auto mb-2 size-6" />{t('لا منظّمين يطابقون — أضف أوّل منظّم')}</td></tr>
                            )}
                            {organizers.map((o) => (
                                <tr key={o.id} className="border-t border-[#f4f4f5]">
                                    <td className="px-4 py-2">
                                        <SmartLink routeName="admin.organizers.show" href={route('admin.organizers.show', o.id)} className="font-medium text-[#1d4ed8]">{o.name}</SmartLink>
                                    </td>
                                    <td className="px-4 py-2 font-mono text-[12px]" dir="ltr">{o.whatsapp || o.phone || '—'}</td>
                                    <td className="px-4 py-2 text-center text-[12px]">{labels.gender[o.gender] ?? o.gender}</td>
                                    <td className="px-4 py-2">
                                        <span className="flex flex-wrap gap-1">
                                            {o.categories.map((c) => <span key={c.id} className="rounded-full bg-[#f5f3ff] px-2 py-0.5 text-[11.5px] text-[#6d28d9]">{c.name}</span>)}
                                        </span>
                                    </td>
                                    <td className="px-4 py-2 text-center"><span className={'rounded-full px-2 py-1 text-[11px] ' + organizerTone(o.status)}>{labels.status[o.status] ?? o.status}</span></td>
                                    <td className="px-4 py-2 text-center tabular-nums">{o.upcoming ?? 0}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </Card>

            <OrganizerDialog open={creating} onClose={() => setCreating(false)} organizer={null} categories={categories} labels={labels} />
            <CatalogDialog
                open={managing}
                onClose={() => setManaging(false)}
                title="تصنيفات المنظّمين"
                items={categories}
                canEdit={may.manage}
                routes={{ store: 'admin.organizers.categories.store', update: 'admin.organizers.categories.update', destroy: 'admin.organizers.categories.destroy' }}
            />
        </AdminLayout>
    );
}
