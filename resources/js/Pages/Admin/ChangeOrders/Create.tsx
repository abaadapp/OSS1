import { useMemo, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Minus, Plus, Save, Trash2 } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import BackLink from '@/Components/BackLink';
import PageHeader from '@/Components/PageHeader';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input, Textarea } from '@/Components/ui/input';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface ProjectItem { id: number; description: string; unit: string | null; quantity: number; unit_price: number; product_id: number | null }
interface Requirement { id: number; title: string; details: string | null; held_by: string | null }
interface CatalogRow { id: number; name: string; sku: string | null; kind: string; list_price: number; tax_rate: number }

interface OrderItem {
    project_item_id: number | null;
    product_id: number | null;
    description: string;
    unit: string | null;
    quantity: number;
    unit_price: number;
    discount_reason: string | null;
    notes: string | null;
}

interface Order {
    id: number;
    title: string;
    reason: string | null;
    requested_by_party: string;
    requested_by_name: string | null;
    new_starts_at: string | null;
    new_ends_at: string | null;
    valid_until: string | null;
    items: OrderItem[];
    added: { title: string; details: string | null; project_item_id: number | null }[];
    removed: { id: number }[];
    exceptions: { key: string; reason: string }[];
}

interface Props {
    project: {
        id: number; code: string; name: string; customer: string | null;
        starts_at: string | null; ends_at: string | null; current_value: number;
        items: ProjectItem[]; requirements: Requirement[];
    };
    order: Order | null;
    catalog: CatalogRow[];
    may: { discount: boolean; override_price: boolean; approve: boolean; discount_threshold: number };
    vat_rate: number;
}

/** سطرٌ في المحرّر — ثلاثةُ أشكال: تعديلُ كميّةٍ على قائم، أو بندٌ جديد */
interface Line {
    mode: 'adjust' | 'new';
    project_item_id: string;
    product_id: string;
    description: string;
    unit: string;
    /** موقَّعة في «تعديل»: سالبٌ نقص */
    quantity: string;
    unit_price: string;
    discount_reason: string;
    notes: string;
}

const blank = (): Line => ({ mode: 'new', project_item_id: '', product_id: '', description: '', unit: '', quantity: '1', unit_price: '', discount_reason: '', notes: '' });

/**
 * كتابةُ أمر تغيير — فروقٌ على النطاق المعتمَد، لا عرضٌ ثانٍ.
 *
 * ثلاثةُ محاور: بنودٌ (زيادةٌ أو نقصٌ على قائم، أو جديد)، ومتطلّباتٌ تُضاف
 * أو تُزال، وموعدٌ جديد. والسعرُ للجديد من الكتالوج في الخادم — والشاشةُ
 * تُري السعرَ المعتمد ولا تُؤتمن عليه. والنقصُ بسعر البند القائم ولا يُسعَّر.
 */
export default function ChangeOrderCreate({ project, order, catalog, may }: Props) {
    const t = useTranslate();
    const { context } = usePage<PageProps>().props;
    const { errors } = usePage().props as { errors?: Record<string, string> };
    const m = (v: number) => money(v, context!.currency);
    const byId = useMemo(() => new Map(catalog.map((c) => [c.id, c])), [catalog]);
    const itemById = useMemo(() => new Map(project.items.map((i) => [i.id, i])), [project.items]);

    const [head, setHead] = useState({
        title: order?.title ?? '',
        reason: order?.reason ?? '',
        requested_by_party: order?.requested_by_party ?? 'customer',
        requested_by_name: order?.requested_by_name ?? '',
        new_starts_at: order?.new_starts_at ?? '',
        new_ends_at: order?.new_ends_at ?? '',
        valid_until: order?.valid_until ?? '',
    });
    const [lines, setLines] = useState<Line[]>(
        order && order.items.length > 0
            ? order.items.map((i) => ({
                  mode: i.project_item_id ? 'adjust' : 'new',
                  project_item_id: i.project_item_id ? String(i.project_item_id) : '',
                  product_id: i.product_id ? String(i.product_id) : '',
                  description: i.description,
                  unit: i.unit ?? '',
                  quantity: String(i.quantity),
                  unit_price: String(i.unit_price),
                  discount_reason: i.discount_reason ?? '',
                  notes: i.notes ?? '',
              }))
            : [],
    );
    const [adds, setAdds] = useState<{ title: string; details: string; project_item_id: string }[]>(
        order?.added.map((r) => ({ title: r.title, details: r.details ?? '', project_item_id: r.project_item_id ? String(r.project_item_id) : '' })) ?? [],
    );
    const [removes, setRemoves] = useState<number[]>(order?.removed.map((r) => r.id) ?? []);
    const [postponeReason, setPostponeReason] = useState(order?.exceptions.find((e) => e.key === 'postponement_window')?.reason ?? '');
    const [saving, setSaving] = useState(false);

    const patch = (i: number, p: Partial<Line>) => setLines((rows) => rows.map((r, k) => (k === i ? { ...r, ...p } : r)));

    const pickItem = (i: number, id: string) => {
        const item = itemById.get(Number(id));
        patch(i, { project_item_id: id, product_id: item?.product_id ? String(item.product_id) : '', description: item?.description ?? '', unit: item?.unit ?? '', unit_price: item ? String(item.unit_price) : '' });
    };

    const pickProduct = (i: number, id: string) => {
        const c = byId.get(Number(id));
        patch(i, { product_id: id, description: c?.name ?? lines[i].description, unit_price: c ? String(c.list_price) : lines[i].unit_price });
    };

    /* الفرقُ التقريبيّ قبل الضريبة — للعين؛ والخادمُ يحسب ما يُحفظ */
    const delta = lines.reduce((s, l) => s + (Number(l.quantity) || 0) * (Number(l.unit_price) || 0), 0);

    const payload = () => ({
        ...head,
        items: lines
            .filter((l) => (Number(l.quantity) || 0) !== 0)
            .map((l) => ({
                project_item_id: l.mode === 'adjust' && l.project_item_id ? Number(l.project_item_id) : null,
                product_id: l.product_id ? Number(l.product_id) : null,
                description: l.description || null,
                unit: l.unit || null,
                quantity: Number(l.quantity),
                unit_price: Number(l.unit_price) || 0,
                discount_reason: l.discount_reason || null,
                notes: l.notes || null,
            })),
        requirements: {
            add: adds.filter((a) => a.title.trim() !== '').map((a) => ({ title: a.title, details: a.details || null, project_item_id: a.project_item_id ? Number(a.project_item_id) : null })),
            remove: removes,
        },
        exceptions: postponeReason.trim() !== '' ? [{ key: 'postponement_window', reason: postponeReason }] : [],
    });

    const submit = () => {
        setSaving(true);
        const opts = { preserveScroll: true, onFinish: () => setSaving(false) };
        if (order) router.put(route('admin.changeOrders.update', order.id), payload(), opts);
        else router.post(route('admin.changeOrders.store', project.id), payload(), opts);
    };

    return (
        <AdminLayout title={t('أمر تغيير')}>
            <BackLink routeName="admin.projects.show" href={route('admin.projects.show', project.id)} label={project.code} />
            <PageHeader
                title={order ? t('تعديل أمر التغيير') : t('أمر تغيير جديد')}
                subtitle={`${project.code} — ${project.name}${project.customer ? ` · ${project.customer}` : ''}`}
                actions={
                    <Button disabled={saving || head.title.trim() === ''} onClick={submit}>
                        <Save />
                        {t('حفظ')}
                    </Button>
                }
            />

            {errors?.items && <div className="mb-4 rounded-lg bg-[#fef2f2] px-4 py-3 text-[13px] text-[#b91c1c]">{errors.items}</div>}
            {errors?.title && <div className="mb-4 rounded-lg bg-[#fef2f2] px-4 py-3 text-[13px] text-[#b91c1c]">{errors.title}</div>}

            <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_340px]">
                <div className="space-y-4">
                    {/* ─────────── الرأس ─────────── */}
                    <Card className="p-4">
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="عنوان التغيير" required className="sm:col-span-2">
                                <Input value={head.title} onChange={(e) => setHead({ ...head, title: e.target.value })} placeholder={t('يوم إضافي وشاشة في القاعة')} />
                            </Field>
                            <Field label="من طلب التغيير" required>
                                <Select value={head.requested_by_party} onChange={(e) => setHead({ ...head, requested_by_party: e.target.value })} options={[{ label: 'العميل', value: 'customer' }, { label: 'الشركة', value: 'company' }]} />
                            </Field>
                            <Field label="باسم" hint="من عند العميل أو الشركة طلبه">
                                <Input value={head.requested_by_name} onChange={(e) => setHead({ ...head, requested_by_name: e.target.value })} />
                            </Field>
                            <Field label="سبب التغيير" className="sm:col-span-2">
                                <Textarea rows={2} value={head.reason} onChange={(e) => setHead({ ...head, reason: e.target.value })} />
                            </Field>
                            <Field label="صالح حتى" hint="بعده لا يقبل العميل الأمر من رابطه">
                                <Input type="date" dir="ltr" value={head.valid_until} onChange={(e) => setHead({ ...head, valid_until: e.target.value })} />
                            </Field>
                        </div>
                    </Card>

                    {/* ─────────── البنود ─────────── */}
                    <Card className="p-4">
                        <div className="mb-3 flex items-center justify-between">
                            <h2 className="text-[15px] font-semibold">{t('البنود')}</h2>
                            <div className="flex gap-2">
                                <Button variant="outline" size="sm" onClick={() => setLines((r) => [...r, { ...blank(), mode: 'adjust', quantity: '1' }])}><Plus />{t('تعديل بند قائم')}</Button>
                                <Button variant="outline" size="sm" onClick={() => setLines((r) => [...r, blank()])}><Plus />{t('بند جديد')}</Button>
                            </div>
                        </div>
                        {lines.length === 0 && <p className="text-[13px] text-[#9ca3af]">{t('لا بنود — أمرٌ بلا بنود يغيّر المتطلّبات أو الموعد وحدهما.')}</p>}
                        <div className="space-y-3">
                            {lines.map((row, i) => {
                                const product = row.product_id ? byId.get(Number(row.product_id)) : undefined;
                                const list = product?.list_price ?? null;
                                const offered = Number(row.unit_price) || 0;
                                const qty = Number(row.quantity) || 0;
                                const reduction = qty < 0;
                                /* تعديلُ بندٍ قائم — زيادةً أو نقصًا — بسعره المعتمَد: الخادمُ لا يقرأ سعرًا هنا */
                                const adjust = row.mode === 'adjust';
                                const discounted = !adjust && list !== null && offered < list - 0.0005;
                                const overridden = !adjust && list !== null && offered > list + 0.0005;
                                const percent = discounted && list ? ((list - offered) / list) * 100 : 0;
                                const pending = !may.approve && (overridden || (discounted && percent > may.discount_threshold));

                                return (
                                    <div key={i} className="rounded-lg border border-[#e8e8e8] p-3">
                                        <div className="grid gap-2 sm:grid-cols-12">
                                            <div className="sm:col-span-5">
                                                {row.mode === 'adjust' ? (
                                                    <Select value={row.project_item_id} onChange={(e) => pickItem(i, e.target.value)} placeholder="اختر البند القائم" options={project.items.map((it) => ({ value: it.id, label: `${it.description} — ${it.quantity} ${it.unit ?? ''}` }))} />
                                                ) : (
                                                    <>
                                                        <Select value={row.product_id} onChange={(e) => pickProduct(i, e.target.value)} placeholder="بند حر (بلا كتالوج)" options={catalog.map((c) => ({ value: c.id, label: c.name }))} />
                                                        <Input className="mt-2" value={row.description} onChange={(e) => patch(i, { description: e.target.value })} placeholder={t('وصف البند')} />
                                                    </>
                                                )}
                                            </div>
                                            <div className="sm:col-span-2">
                                                <div className="flex items-center gap-1">
                                                    {row.mode === 'adjust' && (
                                                        <Button type="button" variant="ghost" size="icon" title={t('اجعله نقصًا')} onClick={() => patch(i, { quantity: String(-Math.abs(qty || 1)) })}><Minus /></Button>
                                                    )}
                                                    <Input type="number" step="0.001" value={row.quantity} onChange={(e) => patch(i, { quantity: e.target.value })} placeholder={t('الكمية')} />
                                                </div>
                                                {row.mode === 'new' && <Input className="mt-2" value={row.unit} onChange={(e) => patch(i, { unit: e.target.value })} placeholder={t('الوحدة')} />}
                                                {reduction && <div className="mt-1 text-[11px] text-[#b91c1c]">{t('نقص')}</div>}
                                            </div>
                                            <div className="sm:col-span-3">
                                                <Input type="number" step="0.001" min="0" value={row.unit_price} disabled={adjust} onChange={(e) => patch(i, { unit_price: e.target.value })} placeholder={t('سعر الوحدة')} />
                                                {list !== null && !adjust && <div className="mt-1 text-[11px] text-[#9ca3af]">{t('السعر المعتمد')}: {m(list)}</div>}
                                                {adjust && <div className="mt-1 text-[11px] text-[#9ca3af]">{reduction ? t('يُردّ بسعر البند القائم') : t('يُزاد بسعر البند القائم — سعرٌ آخر يكون بندًا جديدًا')}</div>}
                                            </div>
                                            <div className="flex items-start justify-between gap-2 sm:col-span-2">
                                                <span className={'pt-2 text-[13px] font-semibold tabular-nums ' + (qty * offered < 0 ? 'text-[#b91c1c]' : '')}>{m(qty * offered)}</span>
                                                <Button type="button" variant="ghost" size="icon" onClick={() => setLines((r) => r.filter((_, k) => k !== i))}><Trash2 /></Button>
                                            </div>
                                        </div>
                                        {(discounted || overridden) && (
                                            <div className="mt-2">
                                                <Input value={row.discount_reason} onChange={(e) => patch(i, { discount_reason: e.target.value })} placeholder={overridden ? t('سبب البيع فوق السعر المعتمد') : t('سبب الخصم — مطلوب')} />
                                                <div className="mt-1 text-[11px] text-[#b45309]">
                                                    {overridden ? t('فوق السعر المعتمد — يحتاج صلاحية البيع فوق الكتالوج') : t('خصم :p%', { p: percent.toFixed(1) })}
                                                    {pending && ` · ${t('سينتظر اعتمادًا')}`}
                                                </div>
                                            </div>
                                        )}
                                        <Input className="mt-2" value={row.notes} onChange={(e) => patch(i, { notes: e.target.value })} placeholder={t('ملاحظة على البند (اختياري)')} />
                                    </div>
                                );
                            })}
                        </div>
                    </Card>

                    {/* ─────────── المتطلّبات ─────────── */}
                    <Card className="p-4">
                        <div className="mb-3 flex items-center justify-between">
                            <h2 className="text-[15px] font-semibold">{t('المتطلّبات')}</h2>
                            <Button variant="outline" size="sm" onClick={() => setAdds((a) => [...a, { title: '', details: '', project_item_id: '' }])}><Plus />{t('متطلّب يُضاف')}</Button>
                        </div>
                        {adds.map((a, i) => (
                            <div key={i} className="mb-2 grid gap-2 rounded-lg border border-[#e8e8e8] p-3 sm:grid-cols-12">
                                <div className="sm:col-span-5"><Input value={a.title} onChange={(e) => setAdds((r) => r.map((x, k) => (k === i ? { ...x, title: e.target.value } : x)))} placeholder={t('المتطلّب')} /></div>
                                <div className="sm:col-span-4"><Input value={a.details} onChange={(e) => setAdds((r) => r.map((x, k) => (k === i ? { ...x, details: e.target.value } : x)))} placeholder={t('التفاصيل')} /></div>
                                <div className="sm:col-span-2"><Select value={a.project_item_id} placeholder="المشروع كلّه" onChange={(e) => setAdds((r) => r.map((x, k) => (k === i ? { ...x, project_item_id: e.target.value } : x)))} options={project.items.map((it) => ({ value: it.id, label: it.description }))} /></div>
                                <div className="sm:col-span-1"><Button type="button" variant="ghost" size="icon" onClick={() => setAdds((r) => r.filter((_, k) => k !== i))}><Trash2 /></Button></div>
                            </div>
                        ))}
                        {project.requirements.length > 0 && (
                            <>
                                <h3 className="mb-2 mt-3 text-[13px] font-semibold text-[#6b7280]">{t('ما يُزال من المتطلّبات القائمة')}</h3>
                                <ul className="space-y-1 text-[13px]">
                                    {project.requirements.map((r) => (
                                        <li key={r.id} className="flex items-center gap-2">
                                            <input type="checkbox" id={`rm-${r.id}`} disabled={r.held_by !== null} checked={removes.includes(r.id)} onChange={(e) => setRemoves((x) => (e.target.checked ? [...x, r.id] : x.filter((id) => id !== r.id)))} />
                                            <label htmlFor={`rm-${r.id}`} className={removes.includes(r.id) ? 'line-through' : ''}>{r.title}</label>
                                            {r.held_by && <span className="text-[11px] text-[#9ca3af]">{t('محجوز لـ')} {r.held_by}</span>}
                                        </li>
                                    ))}
                                </ul>
                            </>
                        )}
                    </Card>

                    {/* ─────────── الموعد ─────────── */}
                    <Card className="p-4">
                        <h2 className="mb-1 text-[15px] font-semibold">{t('الموعد')}</h2>
                        <p className="mb-3 text-[12px] text-[#9ca3af]">
                            {t('الموعد المعتمَد')}: <span dir="ltr">{project.starts_at ?? '—'}{project.ends_at && project.ends_at !== project.starts_at ? ` → ${project.ends_at}` : ''}</span> — {t('اتركه فارغًا إن لم يتغيّر.')}
                        </p>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="يبدأ في"><Input type="date" dir="ltr" value={head.new_starts_at} onChange={(e) => setHead({ ...head, new_starts_at: e.target.value })} /></Field>
                            <Field label="ينتهي في"><Input type="date" dir="ltr" value={head.new_ends_at} onChange={(e) => setHead({ ...head, new_ends_at: e.target.value })} /></Field>
                            {head.new_starts_at && (
                                <Field label="سبب الاستثناء من سياسة التأجيل" hint="يُطلب إن كان التأجيل خارج نافذة السياسة — ويُترك فارغًا إن لم يُطلب" className="sm:col-span-2">
                                    <Input value={postponeReason} onChange={(e) => setPostponeReason(e.target.value)} />
                                </Field>
                            )}
                        </div>
                    </Card>
                </div>

                <div className="space-y-4">
                    <Card className="p-4 text-[13px]">
                        <h2 className="mb-2 text-[15px] font-semibold">{t('الأثر على القيمة')}</h2>
                        <div className="flex justify-between py-1"><span className="text-[#6b7280]">{t('القيمة المعتمَدة الحالية')}</span><span className="tabular-nums">{m(project.current_value)}</span></div>
                        <div className="flex justify-between py-1"><span className="text-[#6b7280]">{t('الفرق قبل الضريبة')}</span><span className={'tabular-nums font-semibold ' + (delta < 0 ? 'text-[#b91c1c]' : delta > 0 ? 'text-[#047857]' : '')}>{delta >= 0 ? '+' : ''}{m(delta)}</span></div>
                        <p className="mt-2 text-[12px] text-[#9ca3af]">{t('الرقم النهائي بضريبته يحسبه الخادم عند الحفظ — والعرض المقبول لا يُمسّ.')}</p>
                    </Card>
                    <Card className="p-4 text-[12px] text-[#6b7280]">
                        <p>{t('يُحفظ الأمر مسودّةً، ثم يُرسَل إلى العميل فيُختم ويُبصَم ويُقطع رقمه. وحين يقبله العميل يُطبَّق على النطاق مرّةً واحدة.')}</p>
                    </Card>
                </div>
            </div>
        </AdminLayout>
    );
}
