import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { AlertTriangle, Pencil, Wrench } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import BackLink from '@/Components/BackLink';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input, Textarea } from '@/Components/ui/input';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { RESERVATION_LABEL, assetTone, incidentTone, label, reservationTone, severityTone, type Labels } from '@/lib/operations';
import type { PageProps } from '@/types';

interface Asset {
    id: number;
    code: string;
    name: string;
    category: string;
    category_label: string;
    status: string;
    condition: string;
    location: string | null;
    brand: string | null;
    model: string | null;
    serial_number: string | null;
    purchase_date: string | null;
    purchase_cost: number | null;
    notes: string | null;
    fixed_asset_id: number | null;
    fixed_asset: string | null;
    retired_at: string | null;
    out_of_service: boolean;
}

interface Reservation {
    id: number;
    status: string;
    operation: { id: number; code: string; name: string; status: string } | null;
    reserved_from: string | null;
    reserved_until: string | null;
    checked_out_at: string | null;
    custodian: string | null;
    assigned_by: string | null;
    returned_at: string | null;
    return_condition: string | null;
    missing_accessories: string | null;
    damage_notes: string | null;
}

interface Props {
    asset: Asset;
    current: Reservation | null;
    upcoming: Reservation[];
    history: Reservation[];
    incidents: { id: number; title: string; severity: string; status: string; occurred_at: string | null; operation: string | null; operation_id: number }[];
    categories: { value: string; label: string }[];
    fixed_assets: { id: number; name: string }[];
    labels: Labels;
    may: { manage: boolean; return: boolean; operations: boolean };
}

/** بطاقةُ القطعة — هويّتُها وحالُها وأين هي، وما مضى وما سيأتي */
export default function AssetShow({ asset: a, current, upcoming, history, incidents, categories, fixed_assets, labels, may }: Props) {
    const t = useTranslate();
    const { context } = usePage<PageProps>().props;
    const { errors } = usePage().props as { errors?: Record<string, string> };
    const [editing, setEditing] = useState(false);
    const [form, setForm] = useState({ name: a.name, category: a.category, brand: a.brand ?? '', model: a.model ?? '', serial_number: a.serial_number ?? '', purchase_date: a.purchase_date ?? '', purchase_cost: a.purchase_cost === null ? '' : String(a.purchase_cost), condition: a.condition, location: a.location ?? '', notes: a.notes ?? '', fixed_asset_id: a.fixed_asset_id ? String(a.fixed_asset_id) : '' });
    const [status, setStatus] = useState<string | null>(null);
    const [reason, setReason] = useState('');
    const [returning, setReturning] = useState<Reservation | null>(null);
    const [returnForm, setReturnForm] = useState({ condition: 'good', missing_accessories: '', damage_notes: '' });

    const conditionOptions = Object.entries(labels.condition ?? {}).map(([v, l]) => ({ value: v, label: l }));

    return (
        <AdminLayout title={a.code}>
            <BackLink routeName="admin.assets.index" href={route('admin.assets.index')} label="المعدّات" />
            <PageHeader
                title={a.code}
                subtitle={`${a.name} — ${a.category_label}`}
                actions={
                    may.manage && (
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" onClick={() => setEditing(true)}><Pencil />{t('تعديل')}</Button>
                            {a.out_of_service ? (
                                <Button variant="success" onClick={() => { setStatus('available'); setReason(''); }}>{t('إعادة إلى الخدمة')}</Button>
                            ) : (
                                <>
                                    <Button variant="outline" onClick={() => { setStatus('maintenance'); setReason(''); }}><Wrench />{t('صيانة')}</Button>
                                    <Button variant="outline" onClick={() => { setStatus('damaged'); setReason(''); }}><AlertTriangle />{t('تالفة')}</Button>
                                    <Button variant="ghost" onClick={() => { setStatus('lost'); setReason(''); }}>{t('مفقودة')}</Button>
                                    <Button variant="ghost" onClick={() => { setStatus('retired'); setReason(''); }}>{t('سحب')}</Button>
                                </>
                            )}
                        </div>
                    )
                }
            />

            {errors?.asset && <div className="mb-4 rounded-lg bg-[#fef2f2] px-4 py-3 text-[13px] text-[#b91c1c]">{errors.asset}</div>}

            <div className="mb-4 flex flex-wrap items-center gap-2 text-[13px]">
                <span className={'rounded-full px-2 py-1 text-[12px] ' + assetTone(a.status)}>{label(labels, 'asset_status', a.status)}</span>
                <span className="text-[#6b7280]">{t('الحال')}: {label(labels, 'condition', a.condition)}</span>
                {a.location && <span className="text-[#6b7280]">{t('المكان')}: {a.location}</span>}
                {a.retired_at && <span className="text-[#9ca3af]">{t('سُحبت')} {a.retired_at}</span>}
            </div>

            <div className="grid gap-4 lg:grid-cols-3">
                <Card className="p-4">
                    <h3 className="mb-2 text-[14px] font-semibold">{t('الهويّة')}</h3>
                    <dl className="space-y-1 text-[13px]">
                        <Row label="العلامة" value={a.brand} />
                        <Row label="الطراز" value={a.model} />
                        <Row label="الرقم التسلسلي" value={a.serial_number} ltr />
                        <Row label="تاريخ الشراء" value={a.purchase_date} ltr />
                        <Row label="تكلفة الشراء" value={a.purchase_cost === null ? null : money(a.purchase_cost, context!.currency)} />
                        <Row label="صفّ الإهلاك" value={a.fixed_asset} />
                        {a.notes && <div className="pt-2 text-[#6b7280]">{a.notes}</div>}
                    </dl>
                </Card>

                <Card className="p-4">
                    <h3 className="mb-2 text-[14px] font-semibold">{t('الآن')}</h3>
                    {current === null ? (
                        <p className="text-[13px] text-[#9ca3af]">{t('لا حجز جارٍ — القطعة في المكتب')}</p>
                    ) : (
                        <ReservationCard r={current} may={may} onReturn={() => { setReturning(current); setReturnForm({ condition: 'good', missing_accessories: '', damage_notes: '' }); }} />
                    )}
                </Card>

                <Card className="p-4">
                    <h3 className="mb-2 text-[14px] font-semibold">{t('الحجوز القادمة')} <span className="text-[11px] font-normal text-[#9ca3af]">({upcoming.length})</span></h3>
                    {upcoming.length === 0 && <p className="text-[13px] text-[#9ca3af]">{t('لا حجوز قادمة')}</p>}
                    <ul className="divide-y divide-[#f4f4f5] text-[13px]">
                        {upcoming.map((r) => (
                            <li key={r.id} className="py-2">
                                <span className={'me-2 rounded-full px-2 py-0.5 text-[11px] ' + reservationTone(r.status)}>{t(RESERVATION_LABEL[r.status] ?? r.status)}</span>
                                {r.operation && (may.operations ? <SmartLink routeName="admin.operations.show" href={route('admin.operations.show', r.operation.id)} className="text-[#1d4ed8]">{r.operation.code}</SmartLink> : r.operation.code)}
                                <span className="block text-[11px] text-[#9ca3af]" dir="ltr">{r.reserved_from} → {r.reserved_until}</span>
                            </li>
                        ))}
                    </ul>
                </Card>
            </div>

            <div className="mt-4 grid gap-4 lg:grid-cols-2">
                <Card className="p-4">
                    <h3 className="mb-2 text-[14px] font-semibold">{t('سجلّ الحجوز')}</h3>
                    {history.length === 0 && <p className="text-[13px] text-[#9ca3af]">{t('لم تُحجز بعد')}</p>}
                    <ul className="divide-y divide-[#f4f4f5] text-[13px]">
                        {history.map((r) => (
                            <li key={r.id} className="py-2">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className={'rounded-full px-2 py-0.5 text-[11px] ' + reservationTone(r.status)}>{t(RESERVATION_LABEL[r.status] ?? r.status)}</span>
                                    <span className="font-medium">{r.operation?.code} — {r.operation?.name}</span>
                                    <span className="text-[11px] text-[#9ca3af]" dir="ltr">{r.reserved_from} → {r.reserved_until}</span>
                                </div>
                                <div className="text-[11px] text-[#6b7280]">
                                    {r.custodian && `${t('في عهدة')} ${r.custodian}`}
                                    {r.returned_at && ` · ${t('رُدّت')} ${r.returned_at} (${r.return_condition === 'lost' ? t('مفقودة') : label(labels, 'condition', r.return_condition)})`}
                                    {r.missing_accessories && <span className="text-[#b91c1c]"> · {t('ناقص')}: {r.missing_accessories}</span>}
                                    {r.damage_notes && <span className="text-[#b91c1c]"> · {t('تلف')}: {r.damage_notes}</span>}
                                </div>
                            </li>
                        ))}
                    </ul>
                </Card>
                <Card className="p-4">
                    <h3 className="mb-2 text-[14px] font-semibold">{t('الحوادث المرتبطة')}</h3>
                    {incidents.length === 0 && <p className="text-[13px] text-[#9ca3af]">{t('لا حوادث على هذه القطعة')}</p>}
                    <ul className="divide-y divide-[#f4f4f5] text-[13px]">
                        {incidents.map((i) => (
                            <li key={i.id} className="flex flex-wrap items-center gap-2 py-2">
                                <span className={'rounded-full px-2 py-0.5 text-[11px] ' + severityTone(i.severity)}>{label(labels, 'severity', i.severity)}</span>
                                <span className={'rounded-full px-2 py-0.5 text-[11px] ' + incidentTone(i.status)}>{label(labels, 'incident_status', i.status)}</span>
                                <span className="font-medium">{i.title}</span>
                                <span className="text-[11px] text-[#9ca3af]">{i.occurred_at} · {i.operation}</span>
                            </li>
                        ))}
                    </ul>
                </Card>
            </div>

            <Dialog open={editing} onOpenChange={setEditing}>
                <DialogContent>
                    <DialogHeader><DialogTitle>{t('تعديل القطعة')}</DialogTitle></DialogHeader>
                    <div className="space-y-3 px-5 pb-5">
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="الاسم" required><Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></Field>
                            <Field label="الصنف" required><Select value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} options={categories} /></Field>
                            <Field label="العلامة"><Input value={form.brand} onChange={(e) => setForm({ ...form, brand: e.target.value })} /></Field>
                            <Field label="الطراز"><Input value={form.model} onChange={(e) => setForm({ ...form, model: e.target.value })} /></Field>
                            <Field label="الرقم التسلسلي"><Input value={form.serial_number} onChange={(e) => setForm({ ...form, serial_number: e.target.value })} dir="ltr" /></Field>
                            <Field label="الحال"><Select value={form.condition} onChange={(e) => setForm({ ...form, condition: e.target.value })} options={conditionOptions} /></Field>
                            <Field label="تاريخ الشراء"><Input type="date" value={form.purchase_date} onChange={(e) => setForm({ ...form, purchase_date: e.target.value })} /></Field>
                            <Field label="تكلفة الشراء"><Input type="number" step="0.001" value={form.purchase_cost} onChange={(e) => setForm({ ...form, purchase_cost: e.target.value })} dir="ltr" /></Field>
                            <Field label="المكان"><Input value={form.location} onChange={(e) => setForm({ ...form, location: e.target.value })} /></Field>
                            <Field label="صفّ الإهلاك" hint="ربطٌ اختياريّ بدفتر الأصول الثابتة">
                                <Select value={form.fixed_asset_id} placeholder="—" onChange={(e) => setForm({ ...form, fixed_asset_id: e.target.value })} options={fixed_assets.map((f) => ({ value: f.id, label: f.name }))} />
                            </Field>
                        </div>
                        <Field label="ملاحظات"><Textarea rows={2} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} /></Field>
                    </div>
                    <DialogFooter>
                        <Button onClick={() => router.put(route('admin.assets.update', a.id), form, { preserveScroll: true, onSuccess: () => setEditing(false) })}>{t('حفظ')}</Button>
                        <Button variant="outline" onClick={() => setEditing(false)}>{t('إلغاء')}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={status !== null} onOpenChange={(v) => !v && setStatus(null)}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('تغيير حال القطعة')}</DialogTitle>
                        <DialogDescription>{t('ما في الميدان لا يُسحب ولا يُفقد من هنا قبل أن يُردّ. والتغييرُ يُقيَّد باسمك وسببك.')}</DialogDescription>
                    </DialogHeader>
                    <div className="space-y-3 px-5 pb-5">
                        <p className="text-[13px]">{t('إلى')}: <span className="font-semibold">{status ? label(labels, 'asset_status', status) : ''}</span></p>
                        <Field label="السبب"><Textarea rows={2} value={reason} onChange={(e) => setReason(e.target.value)} /></Field>
                    </div>
                    <DialogFooter>
                        <Button variant={status === 'available' ? 'success' : 'danger'} onClick={() => { router.post(route('admin.assets.status', a.id), { status, reason }, { preserveScroll: true }); setStatus(null); }}>{t('تأكيد')}</Button>
                        <Button variant="outline" onClick={() => setStatus(null)}>{t('إلغاء')}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={returning !== null} onOpenChange={(v) => !v && setReturning(null)}>
                <DialogContent className="max-w-md">
                    <DialogHeader><DialogTitle>{t('ردّ :code', { code: a.code })}</DialogTitle></DialogHeader>
                    <div className="space-y-3 px-5 pb-5">
                        <Field label="الحال عند العودة" required>
                            <Select value={returnForm.condition} onChange={(e) => setReturnForm({ ...returnForm, condition: e.target.value })} options={[...conditionOptions, { value: 'lost', label: t('مفقودة') }]} />
                        </Field>
                        <Field label="ملحقات ناقصة"><Input value={returnForm.missing_accessories} onChange={(e) => setReturnForm({ ...returnForm, missing_accessories: e.target.value })} /></Field>
                        <Field label="وصف التلف"><Textarea rows={2} value={returnForm.damage_notes} onChange={(e) => setReturnForm({ ...returnForm, damage_notes: e.target.value })} /></Field>
                    </div>
                    <DialogFooter>
                        <Button onClick={() => returning && router.post(route('admin.assets.reservations.return', [a.id, returning.id]), returnForm, { preserveScroll: true, onSuccess: () => setReturning(null) })}>{t('تسجيل الردّ')}</Button>
                        <Button variant="outline" onClick={() => setReturning(null)}>{t('إلغاء')}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}

function ReservationCard({ r, may, onReturn }: { r: Reservation; may: Props['may']; onReturn: () => void }) {
    const t = useTranslate();

    return (
        <div className="space-y-1 text-[13px]">
            <div className="flex flex-wrap items-center gap-2">
                <span className={'rounded-full px-2 py-0.5 text-[11px] ' + reservationTone(r.status)}>{t(RESERVATION_LABEL[r.status] ?? r.status)}</span>
                {r.operation && (may.operations ? <SmartLink routeName="admin.operations.show" href={route('admin.operations.show', r.operation.id)} className="font-medium text-[#1d4ed8]">{r.operation.code} — {r.operation.name}</SmartLink> : <span className="font-medium">{r.operation.code} — {r.operation.name}</span>)}
            </div>
            <div className="text-[11px] text-[#9ca3af]" dir="ltr">{r.reserved_from} → {r.reserved_until}</div>
            {r.custodian && <div className="text-[#6b7280]">{t('في عهدة')} {r.custodian}{r.checked_out_at ? ` · ${r.checked_out_at}` : ''}</div>}
            {r.status === 'checked_out' && may.return && <Button size="sm" className="mt-2" onClick={onReturn}>{t('تسجيل الردّ')}</Button>}
        </div>
    );
}

function Row({ label: text, value, ltr }: { label: string; value: string | null | undefined; ltr?: boolean }) {
    const t = useTranslate();
    if (!value) return null;

    return (
        <div className="flex justify-between gap-3">
            <dt className="text-[#6b7280]">{t(text)}</dt>
            <dd className={ltr ? 'tabular-nums' : ''} dir={ltr ? 'ltr' : undefined}>{value}</dd>
        </div>
    );
}
