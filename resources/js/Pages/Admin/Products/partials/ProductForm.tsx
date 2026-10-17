import { useRef, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Check, Plus, X } from 'lucide-react';
import SmartLink from '@/Components/SmartLink';
import Tabs from '@/Components/Tabs';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input, Textarea } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { csrfHeaders } from '@/lib/csrf';
import { cn } from '@/lib/utils';
import PackageItems, { type PackageMember, type PackageOption } from './PackageItems';
import type { Currency } from '@/types';
import type { Category, Product } from '@/types/models';

/** بنودُ الباقة وما يصلح أن يكون منها — انظر `ProductPackageController::payload` */
export interface PackageData {
    items: PackageMember[];
    options: PackageOption[];
}

interface Props {
    categories: Category[];
    /** موجود = تعديل، غائب = إنشاء */
    product?: Product;
    description?: string;
    currencyLabel: string;
    package?: PackageData | null;
    currency?: Currency;
}

/*
 * الأقسامُ أربعة، والرابعُ للباقة وحدها.
 *
 * وكانت ستّة: «صور المنتج» و«التركيب» (مقاساتٌ ووصفةٌ وإضافات) من بقايا متجر
 * التجزئة — خرجا من الشاشة وصفوفُهما القديمة باقيةٌ لا تُمحى. والباقةُ تبقى:
 * عرضُ السعر يفكّها إلى بنودها.
 */
const NAV = [
    { key: 'basic', label: 'المعلومات الأساسية' },
    { key: 'pricing', label: 'التسعير' },
    { key: 'stock', label: 'المخزون' },
    { key: 'package', label: 'بنود الباقة' },
] as const;

type TabKey = (typeof NAV)[number]['key'];

/**
 * القسم الذي يقع فيه كل حقل — يخدم غرضين:
 * إبراز الأقسام التي فيها أخطاء، والقفز إلى أوّلها بعد ردّ الخادم.
 *
 * بلا هذه الخريطة يبقى الخطأ في قسم مطويّ فلا يراه المستخدم، ويظنّ أن الحفظ
 * لم يستجب أصلًا.
 */
const FIELD_SECTION: Record<string, TabKey> = {
    name: 'basic',
    name_en: 'basic',
    description: 'basic',
    category_id: 'basic',
    kind: 'basic',
    sku: 'basic',
    active: 'basic',
    price: 'pricing',
    cost: 'pricing',
    tax: 'pricing',
    discount: 'pricing',
    quantity: 'stock',
    alert_qty: 'stock',
};

/**
 * نموذج الصنف أو الخدمة أو الباقة — يخدم الإنشاء والتعديل معًا.
 *
 * كتالوجُ خدماتٍ وتجهيزاتِ معارضَ وفعاليّات: الاسمُ ونوعُه وتصنيفُه ووصفُه
 * وكودُه وحالُه أوّلًا، ثمّ السعر، ثمّ المخزون لما له رفّ، ثمّ بنودُ الباقة.
 *
 * الأقسام تُعرض بشريط تبويبات علوي بدل تكديس البطاقات في صفحة طويلة:
 * المستخدم يرى أين هو وكم بقي. والحقول المخفيّة تبقى قيمها محفوظة في حالة
 * النموذج، فالتنقّل بين الأقسام لا يفقد شيئًا.
 */
export default function ProductForm({ categories, product, description, currencyLabel, package: pkg, currency }: Props) {
    const t = useTranslate();
    const editing = !!product;
    const [tab, setTab] = useState<TabKey>('basic');

    const form = useForm({
        name: product?.name ?? '',
        name_en: product?.name_en ?? '',
        description: description ?? '',
        category_id: String(categories.find((c) => c.name === product?.cat)?.id ?? ''),
        kind: (product?.kind ?? 'item') as Product['kind'],
        sku: product?.sku ?? '',
        price: product ? String(product.price) : '',
        cost: product ? String(product.cost) : '',
        // فراغٌ لا «٥»: الفراغ يعني «اتبع نسبة الشركة» فلا تُثبَّت نسبةٌ على البند بلا قصد
        tax: product?.tax == null ? '' : String(product.tax),
        discount: product ? String(product.discount) : '',
        quantity: product ? String(product.qty) : '',
        alert_qty: product ? String(product.alert) : '',
        active: product ? product.active : true,
    });

    /*
     * تصنيفٌ يُنشأ من جانب حقله.
     *
     * وبـfetch لا بتنقّل: النموذج نصفُه مملوء، وإعادةُ تحميل الصفحة تمحو ما
     * كُتب ولم يُحفظ.
     */
    const [cats, setCats] = useState(categories);
    const [newCat, setNewCat] = useState<string | null>(null);
    const [catError, setCatError] = useState<string | null>(null);
    const [savingCat, setSavingCat] = useState(false);

    const addCategory = async () => {
        const name = (newCat ?? '').trim();
        if (!name) return;

        setSavingCat(true);
        setCatError(null);
        try {
            const res = await fetch(route('admin.products.categories.store'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeaders() },
                body: JSON.stringify({ name }),
            });
            const body = await res.json();

            if (!res.ok) {
                setCatError(body?.errors?.name?.[0] ?? t('تعذّر إضافة التصنيف'));

                return;
            }

            setCats((prev) => [...prev, body.category]);
            form.setData('category_id', String(body.category.id));
            setNewCat(null);
        } catch {
            setCatError(t('تعذّر الاتصال بالخادم'));
        } finally {
            setSavingCat(false);
        }
    };

    /**
     * الشريط العلوي قد يخرج عن الشاشة بعد التمرير داخل قسم طويل، فالقفزُ إليه
     * يُبقي التبويبات في المشهد ويُظهر أن القسم تبدّل فعلًا.
     */
    const contentRef = useRef<HTMLFormElement>(null);
    /** ما لا رفَّ له: خدمةٌ أو باقة — انظر `Product::UNSTOCKED` */
    const unstocked = form.data.kind === 'service' || form.data.kind === 'package';
    /* وتبويبُ البنود للباقة وحدها — ومن غيّر النوعَ وهو عليه يعود إلى الأساسيّة */
    const tabs = NAV.filter((x) => x.key !== 'package' || form.data.kind === 'package');
    const shown: TabKey = tabs.some((x) => x.key === tab) ? tab : 'basic';

    const pick = (key: TabKey) => {
        setTab(key);
        requestAnimationFrame(() =>
            contentRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' }),
        );
    };

    const sectionOf = (field: string): TabKey => FIELD_SECTION[field] ?? 'basic';

    const sectionsWithErrors = new Set(Object.keys(form.errors).map(sectionOf));

    /*
     * كلّ رسائل الخطأ في مكانٍ واحد يُرى من أيّ قسم.
     *
     * الخطأ الذي لا يعرضه حقلٌ لا وجود له في نظر المستخدم — ورسالةٌ واحدة
     * غير معروضة تكفي لأن يبدو «حفظ» ميّتًا.
     */
    const errorList = Object.values(form.errors).filter(Boolean) as string[];

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        /*
         * الحقول الإلزامية قد تكون في قسم غير معروض، فلا يستطيع المتصفّح
         * إبرازها ويبدو الزرّ كأنه لا يعمل. نكشفها بأنفسنا ونقفز إليها.
         */
        if (!form.data.name.trim()) {
            form.setError('name', t('اكتب اسم المنتج قبل الحفظ.'));
            pick('basic');

            return;
        }

        if (!String(form.data.price).trim()) {
            form.setError('price', t('اكتب سعر البيع قبل الحفظ.'));
            pick('pricing');

            return;
        }

        form.clearErrors();

        const options = {
            // التعديل يعود إلى صفحته نفسها، فبقاء موضع التمرير يمنع قفزةً
            // إلى الأعلى تُربك من كان أسفل قسمٍ طويل. والإضافة تنتقل إلى
            // القائمة فلا يعنيها هذا.
            preserveScroll: true,
            onError: (errors: Record<string, string>) => {
                const first = Object.keys(errors)[0];
                if (first) pick(sectionOf(first));
            },
        };

        if (editing) form.put(route('admin.products.update', product!.id), options);
        else form.post(route('admin.products.store'), options);
    };

    /* سقفٌ للعرض مع توسيط: سطرٌ يمتدّ عبر الشاشة كاملةً يصعب تتبّعه، وحقلان
       متباعدان بفراغٍ عريض يبدوان غير مرتبطين. نفس حدّ صفحة الإعدادات
       (max-w-4xl) فيتّسق النموذجان. */
    return (
        <form onSubmit={submit} ref={contentRef} className="mx-auto min-w-0 max-w-4xl scroll-mt-4">
            <Tabs
                tabs={tabs.map((x) => ({
                    key: x.key,
                    label: x.label,
                    alert: sectionsWithErrors.has(x.key),
                }))}
                current={shown}
                onChange={(k) => pick(k as TabKey)}
                className="mb-6"
            />

            <div className="min-w-0">
                {shown === 'basic' && (
                    <div className="space-y-6">
                        <Card className="p-6">
                            <h3 className="mb-4 font-bold text-[#111]">{t('المعلومات الأساسية')}</h3>
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <Field label="اسم الصنف أو الخدمة" required error={form.errors.name} className="md:col-span-2">
                                    <Input
                                        value={form.data.name}
                                        onChange={(e) => form.setData('name', e.target.value)}
                                        placeholder={t('مثال: شاشة LED مقاس 3×2 م')}
                                    />
                                </Field>
                                <Field
                                    label="الاسم بالإنجليزية (اختياري)"
                                    hint="يظهر تلقائيًا عند تشغيل الواجهة بالإنجليزية"
                                    error={form.errors.name_en}
                                    className="md:col-span-2"
                                >
                                    <Input
                                        dir="ltr"
                                        value={form.data.name_en}
                                        onChange={(e) => form.setData('name_en', e.target.value)}
                                        placeholder="e.g. LED screen 3×2 m"
                                    />
                                </Field>

                                {/*
                                    ونوعُ البند قبل تصنيفه: هو ما يقرّر أيّ حقولٍ
                                    تُعرض بعده. ومن اختار «خدمة» لا يُسأل عن
                                    كميّةٍ على رفّ، ومن اختار «باقة» يُعطى بنودَها.
                                */}
                                <Field
                                    label="نوع البند"
                                    hint="الخدمة والباقة لا كمية لهما — تُخفى حقول المخزون"
                                    error={form.errors.kind}
                                >
                                    <Select
                                        value={form.data.kind}
                                        onChange={(e) =>
                                            form.setData('kind', e.target.value as Product['kind'])
                                        }
                                        options={[
                                            { label: 'صنف', value: 'item' },
                                            { label: 'خدمة', value: 'service' },
                                            { label: 'باقة', value: 'package' },
                                        ]}
                                    />
                                </Field>

                                <Field label="التصنيف" error={form.errors.category_id ?? catError ?? undefined}>
                                    {newCat === null ? (
                                        <span className="flex items-center gap-2">
                                            <Select
                                                className="flex-1"
                                                value={form.data.category_id}
                                                onChange={(e) => form.setData('category_id', e.target.value)}
                                                options={cats.map((c) => ({
                                                    label: c.name,
                                                    value: c.id,
                                                }))}
                                                placeholder="اختر التصنيف"
                                            />
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="icon"
                                                aria-label={t('إضافة تصنيف')}
                                                title={t('إضافة تصنيف')}
                                                onClick={() => {
                                                    setNewCat('');
                                                    setCatError(null);
                                                }}
                                            >
                                                <Plus />
                                            </Button>
                                        </span>
                                    ) : (
                                        <span className="flex items-center gap-2">
                                            <Input
                                                autoFocus
                                                className="flex-1"
                                                value={newCat}
                                                placeholder={t('اسم التصنيف الجديد')}
                                                onChange={(e) => setNewCat(e.target.value)}
                                                /* «إدخال» يحفظ التصنيف ولا يُرسل البند: النموذج
                                                   محيطٌ بهذا الحقل، وتركُ الحدث يصعد كان يحفظ
                                                   بندًا نصفَ مكتمل */
                                                onKeyDown={(e) => {
                                                    if (e.key === 'Enter') {
                                                        e.preventDefault();
                                                        void addCategory();
                                                    }
                                                    if (e.key === 'Escape') {
                                                        setNewCat(null);
                                                        setCatError(null);
                                                    }
                                                }}
                                            />
                                            <Button
                                                type="button"
                                                size="icon"
                                                aria-label={t('حفظ التصنيف')}
                                                loading={savingCat}
                                                onClick={() => void addCategory()}
                                            >
                                                <Check />
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="icon"
                                                aria-label={t('إلغاء')}
                                                onClick={() => {
                                                    setNewCat(null);
                                                    setCatError(null);
                                                }}
                                            >
                                                <X />
                                            </Button>
                                        </span>
                                    )}
                                </Field>

                                <Field label="الوصف" error={form.errors.description} className="md:col-span-2">
                                    <Textarea
                                        rows={4}
                                        value={form.data.description}
                                        onChange={(e) => form.setData('description', e.target.value)}
                                        placeholder={t('ما يشمله البند وما يميّزه — مثل: المقاس، المدّة، ما يُسلَّم معه')}
                                    />
                                </Field>

                                <Field
                                    label="الكود / SKU (اختياري)"
                                    hint="اتركه فارغًا ليُولَّد تلقائيًا"
                                    error={form.errors.sku}
                                >
                                    <Input
                                        dir="ltr"
                                        value={form.data.sku}
                                        onChange={(e) => form.setData('sku', e.target.value)}
                                        placeholder={t('يُولّد تلقائيًا')}
                                    />
                                </Field>
                            </div>
                        </Card>

                        <Card className="p-6">
                            <h3 className="mb-4 font-bold text-[#111]">{t('الحالة')}</h3>
                            <div className="flex items-center justify-between gap-3">
                                <div>
                                    <p className="text-sm font-medium text-[#111]">{t('نشط')}</p>
                                    <p className="mt-0.5 text-[12px] text-[#9ca3af]">
                                        {t('البند النشط يُختار في عروض الأسعار والمشاريع والمشتريات')}
                                    </p>
                                </div>
                                <Switch on={form.data.active} onChange={() => form.setData('active', !form.data.active)} />
                            </div>
                        </Card>
                    </div>
                )}

                {shown === 'pricing' && (
                    <Card className="p-6">
                        <h3 className="mb-4 font-bold text-[#111]">{t('التسعير')}</h3>
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <Field
                                label={`${t('سعر البيع')} (${currencyLabel})`}
                                required
                                error={form.errors.price}
                            >
                                <Input
                                    type="number"
                                    step="0.001"
                                    min="0"
                                    dir="ltr"
                                    value={form.data.price}
                                    onChange={(e) => form.setData('price', e.target.value)}
                                    placeholder="0.000"
                                />
                            </Field>
                            <Field
                                label={`${t('سعر التكلفة')} (${currencyLabel})`}
                                error={form.errors.cost}
                            >
                                <Input
                                    type="number"
                                    step="0.001"
                                    min="0"
                                    dir="ltr"
                                    value={form.data.cost}
                                    onChange={(e) => form.setData('cost', e.target.value)}
                                    placeholder="0.000"
                                />
                            </Field>
                            {/* الفراغ يتبع نسبة الشركة، والصفر إعلانٌ بأن البند معفى */}
                            <Field label="ضريبة الصنف" error={form.errors.tax} hint="اتركها فارغة لتتبع نسبة الشركة">
                                <Select
                                    value={form.data.tax}
                                    onChange={(e) => form.setData('tax', e.target.value)}
                                    options={[
                                        { label: 'نسبة الشركة', value: '' },
                                        { label: 'صفرية (معفى)', value: '0' },
                                        { label: '5%', value: '5' },
                                        { label: '10%', value: '10' },
                                    ]}
                                />
                            </Field>
                            <Field label="الخصم (%)" error={form.errors.discount} hint="يُخصم من سعر الصنف عند البيع">
                                <Input
                                    type="number"
                                    min="0"
                                    dir="ltr"
                                    value={form.data.discount}
                                    onChange={(e) => form.setData('discount', e.target.value)}
                                    placeholder="0"
                                />
                            </Field>
                        </div>
                    </Card>
                )}

                {shown === 'stock' && (
                    <Card className="p-6">
                        <h3 className="mb-4 font-bold text-[#111]">{t('المخزون')}</h3>
                        {/*
                            وما لا رفَّ له يقول ذلك بدل أن يعرض حقلين
                            يكتبهما صاحبُه فلا يُقرآن. والخادمُ يصفّرهما على
                            أيّ حال — انظر `ProductController::store`.
                        */}
                        {unstocked ? (
                            <p className="rounded-lg bg-[#f4f4f5] px-4 py-3 text-[13px] text-[#52525b]">
                                {t('لا مخزون لهذا البند — الخدمات والباقات لا تُعدّ على رفّ.')}
                            </p>
                        ) : (
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <Field label="الكمية المتوفرة" error={form.errors.quantity}>
                                <Input
                                    type="number"
                                    min="0"
                                    dir="ltr"
                                    value={form.data.quantity}
                                    onChange={(e) => form.setData('quantity', e.target.value)}
                                    placeholder="0"
                                />
                            </Field>
                            <Field
                                label="حد التنبيه"
                                hint="تنبيه عند انخفاض الكمية عن هذا الحد"
                                error={form.errors.alert_qty}
                            >
                                <Input
                                    type="number"
                                    min="0"
                                    dir="ltr"
                                    value={form.data.alert_qty}
                                    onChange={(e) => form.setData('alert_qty', e.target.value)}
                                    placeholder="10"
                                />
                            </Field>
                        </div>
                        )}
                    </Card>
                )}

                {/*
                    بنودُ الباقة — ما تشمله، لا ما يُخصم من الرفّ. ولباقةٍ لم
                    تُحفظ بعد يقول المكوّنُ نفسُه «احفظ أوّلًا» — انظر `PackageItems`.
                */}
                {shown === 'package' && currency && (
                    <PackageItems
                        packageId={product ? Number(product.id) : null}
                        items={pkg?.items ?? []}
                        options={pkg?.options ?? []}
                        currency={currency}
                    />
                )}

                {errorList.length > 0 && (
                    <Card className="mt-6 border-[#fca5a5] bg-[#fef2f2] p-4">
                        <p className="mb-1 text-[13px] font-semibold text-[#b91c1c]">
                            {t('لم يُحفظ المنتج:')}
                        </p>
                        <ul className="space-y-0.5 ps-4 text-[13px] text-[#b91c1c]">
                            {errorList.map((message, i) => (
                                <li key={i} className="list-disc">{message}</li>
                            ))}
                        </ul>
                    </Card>
                )}

                {/* شريط الحفظ ثابت أسفل كل قسم — فلا يضطر المستخدم للعودة
                    إلى قسم بعينه ليحفظ ما كتبه */}
                <Card className="mt-6 flex flex-col gap-3 p-4 sm:flex-row sm:justify-end">
                    <Button variant="outline" className="sm:w-32" asChild>
                        <SmartLink
                            routeName="admin.products.index"
                            href={route('admin.products.index')}
                        >
                            {t('إلغاء')}
                        </SmartLink>
                    </Button>
                    <Button type="submit" className="sm:w-40" loading={form.processing}>
                        <Check />
                        {editing ? t('حفظ التغييرات') : t('حفظ المنتج')}
                    </Button>
                </Card>
            </div>
        </form>
    );
}

/** مفتاح تبديل صغير */
function Switch({ on, onChange }: { on: boolean; onChange: () => void }) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={on}
            onClick={onChange}
            className={cn(
                'relative h-6 w-12 shrink-0 rounded-full transition-colors',
                on ? 'bg-[#111]' : 'bg-[#d1d5db]',
            )}
        >
            {/* المقبض يتحرك بالخاصية المنطقية فينعكس تلقائيًا في RTL */}
            <span
                className={cn(
                    'absolute top-0.5 size-5 rounded-full bg-white shadow transition-[inset-inline-start]',
                    on ? 'start-[26px]' : 'start-0.5',
                )}
            />
        </button>
    );
}
