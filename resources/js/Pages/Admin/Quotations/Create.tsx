import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { Boxes, Package, Plus, Save, Trash2, Wrench } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import BackLink from '@/Components/BackLink';
import PageHeader from '@/Components/PageHeader';
import PaperFrame from '@/Components/PaperFrame';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input, Textarea } from '@/Components/ui/input';
import { csrfHeaders } from '@/lib/csrf';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Currency, PageProps } from '@/types';

interface CustomerRow {
    id: number;
    name: string;
    legal_name: string | null;
    department: string | null;
    contact_person: string | null;
    contact_email: string | null;
    contact_phone: string | null;
    payment_terms_days: number | null;
}

interface CatalogRow {
    id: number;
    name: string;
    sku: string | null;
    kind: 'item' | 'service' | 'package';
    /** السعرُ المعتمد — من الخادم، وهو ما سيُحسب به عند الحفظ */
    list_price: number;
    tax_rate: number;
    members: { product_id: number; description: string; unit: string | null; quantity: number; unit_price: number }[];
}

interface Line {
    product_id: number | null;
    description: string;
    unit: string;
    quantity: string;
    unit_price: string;
    discount_reason: string;
    notes: string;
    /** السعرُ المعتمد كما أرسله الخادم — للعرض والمقارنة، ولا يُرسَل */
    list_price: number | null;
}

interface Props {
    quotation: (Record<string, unknown> & { id: number; items: Record<string, unknown>[] }) | null;
    customers: CustomerRow[];
    catalog: CatalogRow[];
    may: { discount: boolean; override_price: boolean; approve: boolean; discount_threshold: number };
    vat_rate: number;
    currency: Currency;
}

/** شكلُ البند كما يُرسَل — ولا `list_price` فيه: السعرُ يُقرأ في الخادم */
interface LinePayload {
    product_id: number | null;
    description: string;
    unit: string | null;
    quantity: string;
    unit_price: string;
    discount_reason: string | null;
    notes: string | null;
}

const blankLine = (): Line => ({
    product_id: null,
    description: '',
    unit: '',
    quantity: '1',
    unit_price: '0',
    discount_reason: '',
    notes: '',
    list_price: null,
});

/** أيقونةُ النوع — الخدمةُ ليست صنفًا، والباقةُ ليست أيًّا منهما */
const KIND_ICON = { item: Boxes, service: Wrench, package: Package } as const;

const KIND_LABEL = { item: 'صنف', service: 'خدمة', package: 'باقة' } as const;

/**
 * كتابةُ عرض سعرٍ — وشاشةٌ واحدة للجديد ولتعديل المسودّة.
 *
 * ═══ ولمَ المعاينةُ إلى جانب النموذج ═══
 *
 * عرضُ السعر ورقةٌ تخرج إلى جهةٍ تقارنه بعروضٍ أخرى. وشكلُها جزءٌ من
 * الرسالة: من يكتب البنودَ ولا يرى ورقتَه يرسل ما لم يقرأه. وهو النمطُ
 * نفسُه في شاشة الفاتورة — ولا يُخترع له شكلٌ ثانٍ بلا سبب.
 *
 * ═══ والسعرُ لا تملكه هذه الشاشة ═══
 *
 * `list_price` يُعرض ولا يُرسَل: يُقرأ من الكتالوج في الخادم عند كلّ حفظ.
 * وما تفعله الشاشةُ هنا **إخبارٌ لا حساب** — تقول لمن يخصم إنّه يخصم،
 * وكم، وهل سينتظر اعتمادًا. والقرارُ يقع في `Quotations::price`.
 */
export default function QuotationCreate({ quotation, customers, catalog, may, vat_rate, currency }: Props) {
    const t = useTranslate();
    const { context } = usePage<PageProps>().props;
    const m = (v: number) => money(v, currency ?? context!.currency);

    const editing = quotation !== null;

    const [lines, setLines] = useState<Line[]>(() =>
        editing && quotation.items.length > 0
            ? quotation.items.map((i) => ({
                  product_id: (i.product_id as number | null) ?? null,
                  description: String(i.description ?? ''),
                  unit: String(i.unit ?? ''),
                  quantity: String(i.quantity ?? '1'),
                  unit_price: String(i.unit_price ?? '0'),
                  discount_reason: String(i.discount_reason ?? ''),
                  notes: String(i.notes ?? ''),
                  list_price: (i.list_price as number | null) ?? null,
              }))
            : [blankLine()],
    );

    const form = useForm({
        customer_id: editing ? String(quotation.customer_id ?? '') : '',
        title: editing ? String(quotation.title ?? '') : '',
        issued_at: editing ? String(quotation.issued_at ?? '') : new Date().toISOString().slice(0, 10),
        valid_until: editing ? String(quotation.valid_until ?? '') : '',
        event_location: editing ? String(quotation.event_location ?? '') : '',
        event_starts_at: editing ? String(quotation.event_starts_at ?? '') : '',
        event_ends_at: editing ? String(quotation.event_ends_at ?? '') : '',
        expected_attendees: editing ? String(quotation.expected_attendees ?? '') : '',
        payment_terms_days: editing ? String(quotation.payment_terms_days ?? '') : '',
        customer_reference: editing ? String(quotation.customer_reference ?? '') : '',
        department: editing ? String(quotation.department ?? '') : '',
        attention_to: editing ? String(quotation.attention_to ?? '') : '',
        contact_email: editing ? String(quotation.contact_email ?? '') : '',
        contact_phone: editing ? String(quotation.contact_phone ?? '') : '',
        scope: editing ? String(quotation.scope ?? '') : '',
        terms: editing ? String(quotation.terms ?? '') : '',
        notes: editing ? String(quotation.notes ?? '') : '',
        internal_notes: editing ? String(quotation.internal_notes ?? '') : '',
        /*
         * والبنودُ تُبنى عند الإرسال لا تُحفظ في النموذج.
         *
         * `useForm` تعيد بناءَ الحقل مع كلّ ضغطةٍ في أيّ سطر، والبنودُ حالةٌ
         * مستقلّة (`lines`) تتغيّر أسرعَ من ذلك. فيُترك الحقلُ فارغًا ويُملأ
         * في `transform` — انظر `submit`.
         */
        items: [] as LinePayload[],
    });

    const byId = useMemo(() => new Map(catalog.map((c) => [c.id, c])), [catalog]);

    /* ─────────────── البنود ─────────────── */

    const patch = (index: number, next: Partial<Line>) =>
        setLines((rows) => rows.map((row, i) => (i === index ? { ...row, ...next } : row)));

    /** اختيارُ صنفٍ يجلب اسمَه وسعرَه المعتمد — والسعرُ يُملأ لا يُقفل */
    const pick = (index: number, productId: string) => {
        const product = byId.get(Number(productId));

        if (!product) {
            patch(index, { product_id: null, list_price: null });

            return;
        }

        patch(index, {
            product_id: product.id,
            description: product.name,
            unit_price: String(product.list_price),
            list_price: product.list_price,
        });
    };

    /**
     * فكُّ الباقة إلى بنودها.
     *
     * وبنودُها بأسعارها المعتمدة كلٌّ على حدة — لا بسعر الباقة موزَّعًا
     * عليها: الأوّلُ يُقرأ، والثاني يُخرج أرقامًا كسريّةً لا يعرف أحدٌ من
     * أين جاءت.
     */
    const explode = (index: number) => {
        const row = lines[index];
        const product = row.product_id ? byId.get(row.product_id) : null;

        if (!product || product.members.length === 0) return;

        setLines((rows) => [
            ...rows.slice(0, index),
            ...product.members.map((mem) => ({
                ...blankLine(),
                product_id: mem.product_id,
                description: mem.description,
                unit: mem.unit ?? '',
                quantity: String(mem.quantity),
                unit_price: String(mem.unit_price),
                list_price: mem.unit_price,
            })),
            ...rows.slice(index + 1),
        ]);
    };

    /* ─────────────── الحساب في الشاشة — إخبارٌ لا مصدرُ حقيقة ─────────────── */

    const totals = useMemo(() => {
        let subtotal = 0;
        let discount = 0;
        let tax = 0;

        for (const row of lines) {
            const qty = Number(row.quantity) || 0;
            const offered = Number(row.unit_price) || 0;

            if (qty <= 0) continue;

            const list = row.list_price ?? offered;
            const gross = Math.max(list, offered) * qty;
            const lineDiscount = Math.max(0, (list - offered) * qty);
            const net = gross - lineDiscount;
            const rate = row.product_id ? (byId.get(row.product_id)?.tax_rate ?? vat_rate) : vat_rate;

            subtotal += gross;
            discount += lineDiscount;
            tax += (net * rate) / 100;
        }

        return { subtotal, discount, tax, total: subtotal - discount + tax };
    }, [lines, byId, vat_rate]);

    /** ما يقوله كلُّ سطرٍ عن سعره — وهو ما يمنع المفاجأةَ عند الحفظ */
    const verdict = (row: Line) => {
        const offered = Number(row.unit_price) || 0;
        const list = row.list_price;

        if (list === null) return { kind: 'free' as const, percent: 0 };
        if (Math.abs(offered - list) < 0.0005) return { kind: 'list' as const, percent: 0 };
        if (offered > list) return { kind: 'override' as const, percent: 0 };

        return { kind: 'discount' as const, percent: list <= 0 ? 0 : ((list - offered) / list) * 100 };
    };

    /* ─────────────── المعاينة ─────────────── */

    const [html, setHtml] = useState('');
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    const payload = useCallback(
        () => ({
            ...form.data,
            items: lines
                .filter((row) => Number(row.quantity) > 0)
                .map((row) => ({
                    product_id: row.product_id,
                    description: row.description,
                    unit: row.unit || null,
                    quantity: row.quantity,
                    unit_price: row.unit_price,
                    discount_reason: row.discount_reason || null,
                    notes: row.notes || null,
                })),
        }),
        [form.data, lines],
    );

    /*
     * والمعاينةُ تُطلب بعد سكون.
     *
     * كلُّ حرفٍ يُكتب في «نطاق العمل» يعني رحلةً إلى الخادم ورسمًا كاملًا
     * للورقة. ونصفُ ثانيةٍ من السكون تكفي ليُقرأ ما كُتب، وتُسقط تسعةَ
     * أعشار الطلبات.
     */
    useEffect(() => {
        if (timer.current) clearTimeout(timer.current);

        const body = payload();

        if (body.items.length === 0) {
            setHtml('');

            return;
        }

        timer.current = setTimeout(() => {
            fetch(route('admin.quotations.preview'), {
                method: 'POST',
                /*
                    والرمزُ من `csrfHeaders` لا من وسم الصفحة.

                    الوسمُ يُطبع مرّةً عند أوّل تحميل، وتسجيلُ الدخول يجدّد
                    رمزَ الجلسة — فيبقى الوسمُ على رمزٍ مُبطَل وتُردّ المعاينةُ
                    بـ٤١٩ بلا أن يظهر شيءٌ في الشاشة إلّا ورقةٌ لا تتحدّث.
                */
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...csrfHeaders(),
                },
                body: JSON.stringify(body),
            })
                .then((r) => (r.ok ? r.json() : null))
                /* وخطأُ المعاينة لا يُفرغ الورقة: آخرُ رسمةٍ صحيحة أنفعُ من بياض */
                .then((data) => data?.html && setHtml(data.html))
                .catch(() => undefined);
        }, 500);

        return () => {
            if (timer.current) clearTimeout(timer.current);
        };
    }, [payload]);

    /* ─────────────── الحفظ ─────────────── */

    const submit = () => {
        const body = payload();

        form.transform(() => body as unknown as typeof form.data);

        if (editing) {
            form.put(route('admin.quotations.update', quotation.id));
        } else {
            form.post(route('admin.quotations.store'));
        }
    };

    /** اختيارُ عميلٍ يملأ ما يعرفه عنه — ولا يدوس ما كتبه المستخدم */
    const chooseCustomer = (id: string) => {
        form.setData('customer_id', id);

        const customer = customers.find((c) => String(c.id) === id);

        if (!customer) return;

        form.setData((data: typeof form.data) => ({
            ...data,
            customer_id: id,
            department: data.department || (customer.department ?? ''),
            attention_to: data.attention_to || (customer.contact_person ?? ''),
            contact_email: data.contact_email || (customer.contact_email ?? ''),
            contact_phone: data.contact_phone || (customer.contact_phone ?? ''),
            payment_terms_days:
                data.payment_terms_days ||
                (customer.payment_terms_days === null ? '' : String(customer.payment_terms_days)),
        }));
    };

    const title = editing ? t('تعديل عرض السعر') : t('عرض سعر جديد');

    return (
        <AdminLayout title={title}>
            <BackLink routeName="admin.quotations.index" href={route('admin.quotations.index')} label="عروض الأسعار" />

            <PageHeader
                title={title}
                subtitle={t('اختر العميل والخدمات — والسعر يأتي من الكتالوج.')}
                actions={
                    <Button onClick={submit} disabled={form.processing}>
                        <Save />
                        {t('حفظ')}
                    </Button>
                }
            />

            <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_420px]">
                <div className="space-y-4">
                    {/* ─────────── الجهة والفعاليّة ─────────── */}
                    <Card className="space-y-4 p-4">
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="العميل" required error={form.errors.customer_id}>
                                <Select
                                    value={form.data.customer_id}
                                    onChange={(e) => chooseCustomer(e.target.value)}
                                    placeholder="اختر العميل"
                                    options={customers.map((c) => ({
                                        value: String(c.id),
                                        label: c.legal_name || c.name,
                                    }))}
                                />
                            </Field>

                            <Field label="عنوان الفعالية" required error={form.errors.title}>
                                <Input
                                    value={form.data.title}
                                    onChange={(e) => form.setData('title', e.target.value)}
                                    placeholder={t('مؤتمر عُمان للطاقة ٢٠٢٦')}
                                />
                            </Field>
                        </div>

                        <div className="grid gap-3 sm:grid-cols-3">
                            <Field label="التاريخ" error={form.errors.issued_at}>
                                <Input
                                    type="date"
                                    value={form.data.issued_at}
                                    onChange={(e) => form.setData('issued_at', e.target.value)}
                                />
                            </Field>
                            <Field
                                label="صالح حتى"
                                hint="عرضٌ بلا تاريخ انتهاء يُؤجَّل بلا أجل"
                                error={form.errors.valid_until}
                            >
                                <Input
                                    type="date"
                                    value={form.data.valid_until}
                                    onChange={(e) => form.setData('valid_until', e.target.value)}
                                />
                            </Field>
                            <Field label="شروط الدفع (يومًا)" error={form.errors.payment_terms_days}>
                                <Input
                                    type="number"
                                    min={0}
                                    value={form.data.payment_terms_days}
                                    onChange={(e) => form.setData('payment_terms_days', e.target.value)}
                                />
                            </Field>
                        </div>

                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <Field label="من تاريخ" error={form.errors.event_starts_at}>
                                <Input
                                    type="date"
                                    value={form.data.event_starts_at}
                                    onChange={(e) => form.setData('event_starts_at', e.target.value)}
                                />
                            </Field>
                            <Field label="إلى تاريخ" error={form.errors.event_ends_at}>
                                <Input
                                    type="date"
                                    value={form.data.event_ends_at}
                                    onChange={(e) => form.setData('event_ends_at', e.target.value)}
                                />
                            </Field>
                            <Field label="الموقع" error={form.errors.event_location}>
                                <Input
                                    value={form.data.event_location}
                                    onChange={(e) => form.setData('event_location', e.target.value)}
                                />
                            </Field>
                            <Field label="عدد الحضور المتوقع" error={form.errors.expected_attendees}>
                                <Input
                                    type="number"
                                    min={0}
                                    value={form.data.expected_attendees}
                                    onChange={(e) => form.setData('expected_attendees', e.target.value)}
                                />
                            </Field>
                        </div>

                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <Field label="مرجع العميل" hint="رقم المناقصة أو طلب التسعير">
                                <Input
                                    value={form.data.customer_reference}
                                    onChange={(e) => form.setData('customer_reference', e.target.value)}
                                />
                            </Field>
                            <Field label="القسم / الإدارة">
                                <Input
                                    value={form.data.department}
                                    onChange={(e) => form.setData('department', e.target.value)}
                                />
                            </Field>
                            <Field label="موجه إلى / عناية">
                                <Input
                                    value={form.data.attention_to}
                                    onChange={(e) => form.setData('attention_to', e.target.value)}
                                />
                            </Field>
                            <Field label="بريد المُرسَل إليه" error={form.errors.contact_email}>
                                <Input
                                    type="email"
                                    dir="ltr"
                                    value={form.data.contact_email}
                                    onChange={(e) => form.setData('contact_email', e.target.value)}
                                />
                            </Field>
                        </div>
                    </Card>

                    {/* ─────────── البنود ─────────── */}
                    <Card className="p-4">
                        <div className="mb-3 flex items-center justify-between">
                            <h2 className="text-[15px] font-semibold">{t('البنود')}</h2>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => setLines((rows) => [...rows, blankLine()])}
                            >
                                <Plus />
                                {t('بند')}
                            </Button>
                        </div>

                        {form.errors.items && (
                            <p className="mb-3 rounded-md bg-[#fef2f2] px-3 py-2 text-[13px] text-[#b91c1c]">
                                {form.errors.items}
                            </p>
                        )}

                        <div className="space-y-3">
                            {lines.map((row, index) => {
                                const v = verdict(row);
                                const product = row.product_id ? byId.get(row.product_id) : null;
                                const Icon = product ? KIND_ICON[product.kind] : null;
                                /*
                                    وينتظر اعتمادًا: كلُّ تجاوزٍ للسعر، وكلُّ
                                    خصمٍ فوق الحدّ — إلّا لمن يملك الاعتماد،
                                    فهو لا ينتظر نفسَه.
                                */
                                const pending =
                                    !may.approve &&
                                    (v.kind === 'override' ||
                                        (v.kind === 'discount' && v.percent > may.discount_threshold));

                                return (
                                    <div key={index} className="rounded-lg border border-[#e8e8e8] p-3">
                                        <div className="grid gap-2 sm:grid-cols-12">
                                            <div className="sm:col-span-5">
                                                <Select
                                                    value={row.product_id ? String(row.product_id) : ''}
                                                    onChange={(e) => pick(index, e.target.value)}
                                                    placeholder="بند حر (بلا كتالوج)"
                                                    options={catalog.map((c) => ({
                                                        value: String(c.id),
                                                        label: `${t(KIND_LABEL[c.kind])} — ${c.name}`,
                                                    }))}
                                                />
                                                <Input
                                                    className="mt-2"
                                                    value={row.description}
                                                    onChange={(e) => patch(index, { description: e.target.value })}
                                                    placeholder={t('وصف البند')}
                                                />
                                            </div>

                                            <div className="sm:col-span-2">
                                                <Input
                                                    type="number"
                                                    step="0.001"
                                                    min="0"
                                                    value={row.quantity}
                                                    onChange={(e) => patch(index, { quantity: e.target.value })}
                                                    placeholder={t('الكمية')}
                                                />
                                                <Input
                                                    className="mt-2"
                                                    value={row.unit}
                                                    onChange={(e) => patch(index, { unit: e.target.value })}
                                                    placeholder={t('الوحدة')}
                                                />
                                            </div>

                                            <div className="sm:col-span-3">
                                                <Input
                                                    type="number"
                                                    step="0.001"
                                                    min="0"
                                                    value={row.unit_price}
                                                    onChange={(e) => patch(index, { unit_price: e.target.value })}
                                                    placeholder={t('سعر الوحدة')}
                                                />
                                                {/*
                                                    والسعرُ المعتمد يُعرض تحته دائمًا حين يكون
                                                    البندُ من الكتالوج: من يخصم يرى ممّ يخصم.
                                                */}
                                                {row.list_price !== null && (
                                                    <div className="mt-1 text-[11px] text-[#9ca3af]">
                                                        {t('السعر المعتمد')}: {m(row.list_price)}
                                                    </div>
                                                )}
                                            </div>

                                            <div className="flex items-start justify-between gap-2 sm:col-span-2">
                                                <span className="pt-2 text-[13px] font-semibold tabular-nums">
                                                    {m((Number(row.quantity) || 0) * (Number(row.unit_price) || 0))}
                                                </span>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    onClick={() =>
                                                        setLines((rows) =>
                                                            rows.length === 1
                                                                ? [blankLine()]
                                                                : rows.filter((_, i) => i !== index),
                                                        )
                                                    }
                                                    aria-label={t('حذف البند')}
                                                >
                                                    <Trash2 />
                                                </Button>
                                            </div>
                                        </div>

                                        <div className="mt-2 flex flex-wrap items-center gap-2">
                                            {Icon && product && (
                                                <span className="inline-flex items-center gap-1 rounded-full bg-[#f4f4f5] px-2 py-1 text-[11px] text-[#52525b]">
                                                    <Icon className="size-3" />
                                                    {t(KIND_LABEL[product.kind])}
                                                </span>
                                            )}

                                            {product?.members.length ? (
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() => explode(index)}
                                                >
                                                    {t('فكّ الباقة إلى بنودها')}
                                                </Button>
                                            ) : null}

                                            {v.kind === 'discount' && (
                                                <span
                                                    className={cn(
                                                        'rounded-full px-2 py-1 text-[11px]',
                                                        pending
                                                            ? 'bg-[#fffbeb] text-[#b45309]'
                                                            : 'bg-[#eff6ff] text-[#1d4ed8]',
                                                    )}
                                                >
                                                    {t('خصم :n٪', { n: v.percent.toFixed(1) })}
                                                    {pending ? ` — ${t('ينتظر اعتمادًا')}` : ''}
                                                </span>
                                            )}

                                            {v.kind === 'override' && (
                                                <span className="rounded-full bg-[#fffbeb] px-2 py-1 text-[11px] text-[#b45309]">
                                                    {t('فوق سعر الكتالوج')}
                                                    {pending ? ` — ${t('ينتظر اعتمادًا')}` : ''}
                                                </span>
                                            )}
                                        </div>

                                        {/*
                                            وحقلُ السبب يظهر حين يقع تغييرُ سعرٍ لا قبله:
                                            حقلٌ فارغٌ في كلّ سطرٍ يُقرأ مطلوبًا في كلّ سطر.
                                        */}
                                        {(v.kind === 'discount' || v.kind === 'override') && (
                                            <Input
                                                className="mt-2"
                                                value={row.discount_reason}
                                                onChange={(e) => patch(index, { discount_reason: e.target.value })}
                                                placeholder={t('سبب تغيير السعر — مطلوب')}
                                            />
                                        )}

                                        <Input
                                            className="mt-2"
                                            value={row.notes}
                                            onChange={(e) => patch(index, { notes: e.target.value })}
                                            placeholder={t('نطاق هذا البند (اختياري)')}
                                        />
                                    </div>
                                );
                            })}
                        </div>

                        {/*
                            والمجاميعُ هنا **تقديرٌ يُرى**، والمعتمدُ ما يحسبه
                            الخادم. ويُقال ذلك صراحةً: رقمٌ في الشاشة يخالف
                            ما يُحفظ بلا كلمةٍ تفسّره يُقرأ عطبًا.
                        */}
                        <div className="mt-4 space-y-1 border-t border-[#e8e8e8] pt-3 text-[13px]">
                            <div className="flex justify-between">
                                <span className="text-[#6b7280]">{t('المجموع الفرعي')}</span>
                                <span className="tabular-nums">{m(totals.subtotal)}</span>
                            </div>
                            {totals.discount > 0 && (
                                <div className="flex justify-between">
                                    <span className="text-[#6b7280]">{t('الخصم')}</span>
                                    <span className="tabular-nums text-[#b91c1c]">− {m(totals.discount)}</span>
                                </div>
                            )}
                            <div className="flex justify-between">
                                <span className="text-[#6b7280]">{t('ضريبة القيمة المضافة')}</span>
                                <span className="tabular-nums">{m(totals.tax)}</span>
                            </div>
                            <div className="flex justify-between border-t border-[#e8e8e8] pt-2 font-semibold">
                                <span>{t('الإجمالي')}</span>
                                <span className="tabular-nums">{m(totals.total)}</span>
                            </div>
                        </div>
                    </Card>

                    {/* ─────────── النطاق والشروط ─────────── */}
                    <Card className="space-y-3 p-4">
                        <Field label="نطاق العمل" hint="ما سيُنفَّذ وما لا يُنفَّذ — وهو أكثر ما يُختلف عليه بعد القبول">
                            <Textarea
                                rows={4}
                                value={form.data.scope}
                                onChange={(e) => form.setData('scope', e.target.value)}
                            />
                        </Field>
                        <Field label="الشروط التجارية" hint="الإلغاء والتأجيل والمسؤولية">
                            <Textarea
                                rows={3}
                                value={form.data.terms}
                                onChange={(e) => form.setData('terms', e.target.value)}
                            />
                        </Field>
                        <Field label="ملاحظات للعميل">
                            <Textarea
                                rows={2}
                                value={form.data.notes}
                                onChange={(e) => form.setData('notes', e.target.value)}
                            />
                        </Field>
                        <Field label="ملاحظات داخلية" hint="لا تظهر في الورقة">
                            <Textarea
                                rows={2}
                                value={form.data.internal_notes}
                                onChange={(e) => form.setData('internal_notes', e.target.value)}
                            />
                        </Field>
                    </Card>
                </div>

                {/* ─────────── المعاينة ─────────── */}
                <div className="space-y-2 lg:sticky lg:top-4 lg:self-start">
                    {html ? (
                        <PaperFrame html={html} title={t('معاينة عرض السعر')} viewport="min(72svh, 1000px)" />
                    ) : (
                        <Card className="p-8 text-center text-[13px] text-[#9ca3af]">
                            {t('أضف بندًا لترى الورقة.')}
                        </Card>
                    )}
                    <p className="px-1 text-[12px] leading-relaxed text-[#9ca3af]">
                        {t('الملاحظات الداخلية لا تظهر في الورقة المطبوعة.')}
                    </p>
                </div>
            </div>
        </AdminLayout>
    );
}
