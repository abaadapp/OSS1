import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Pencil, Plus, ScrollText, ShieldAlert, Trash2 } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import Field, { Select } from '@/Components/Field';
import Toggle from '@/Components/Toggle';
import { SettingsPage, SettingsSection } from '@/Components/Settings';
import { Button } from '@/Components/ui/button';
import { Input, Textarea } from '@/Components/ui/input';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { useConfirm } from '@/Components/ConfirmDialog';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import BackToSettings from './partials/BackToSettings';

type ParamType = 'integer' | 'percent';

interface CatalogEntry {
    key: string;
    label: string;
    hint: string;
    params: Record<string, ParamType>;
    checked: boolean;
    text: string;
}

interface Policy {
    id: number;
    key: string;
    label: string;
    scope_type: 'customer' | 'product' | 'category' | null;
    scope_id: number | null;
    scope_label: string | null;
    params: Record<string, number>;
    text_ar: string;
    text_en: string | null;
    active: boolean;
    in_force: boolean;
    effective_from: string | null;
    version: number;
}

interface Option {
    value: number;
    label: string;
}

interface Props {
    policies: Policy[];
    catalog: CatalogEntry[];
    customers: Option[];
    products: Option[];
    categories: Option[];
}

/** أسماءُ المعاملات كما تُقرأ — وقائمةٌ مغلقةٌ كقائمة المفاتيح */
const PARAM_LABEL: Record<string, string> = {
    days: 'الأيام',
    surcharge_percent: 'رسوم الاستعجال ٪',
    fee_percent: 'رسوم الإلغاء ٪',
    advance_percent: 'الدفعة المقدَّمة ٪',
};

const SCOPE_LABEL = { customer: 'عميل', product: 'صنف', category: 'تصنيف' } as const;

/**
 * سياساتُ الشركة — النصُّ الذي يقرؤه العميل، والمعاملاتُ التي يفحصها النظام.
 *
 * ═══ ولمَ النصُّ أوّلًا ═══
 *
 * فحصٌ آليٌّ مفقودٌ يعني موظّفًا ينتبه أو لا ينتبه. أمّا نصُّ الشروط فهو
 * ما يُثبَت به الحقُّ إن اختلفتم مع عميل — ويُختم في كلّ عرضٍ لحظةَ
 * إرساله بنسخته. فتعديلُ النصّ هنا لا يمسّ ما خرج، ويرفع النسخة.
 *
 * ═══ والمفاتيحُ مغلقة ═══
 *
 * من القائمة وحدها، ولكلٍّ معاملاتُه بأسمائها. والمديرُ لا يكتب شرطًا:
 * يكتب رقمًا ونصًّا، والمنطقُ في الكود — `Policy\Registry`.
 */
export default function Policies({ policies, catalog, customers, products, categories }: Props) {
    const t = useTranslate();
    const [ask, confirmDialog] = useConfirm();
    const [editing, setEditing] = useState<Policy | null | 'new'>(null);

    const byKey = new Map(catalog.map((c) => [c.key, c]));

    const form = useForm({
        key: '',
        scope_type: '' as '' | 'customer' | 'product' | 'category',
        scope_id: '',
        params: {} as Record<string, string>,
        text_ar: '',
        text_en: '',
        effective_from: '',
        active: true,
    });

    const open = (policy: Policy | 'new') => {
        if (policy === 'new') {
            const first = catalog[0];
            form.setData({
                key: first?.key ?? '',
                scope_type: '',
                scope_id: '',
                params: {},
                text_ar: first?.text ?? '',
                text_en: '',
                effective_from: '',
                active: true,
            });
        } else {
            form.setData({
                key: policy.key,
                scope_type: policy.scope_type ?? '',
                scope_id: policy.scope_id ? String(policy.scope_id) : '',
                params: Object.fromEntries(Object.entries(policy.params).map(([k, v]) => [k, String(v)])),
                text_ar: policy.text_ar,
                text_en: policy.text_en ?? '',
                effective_from: policy.effective_from ?? '',
                active: policy.active,
            });
        }
        form.clearErrors();
        setEditing(policy);
    };

    /** تبديلُ المفتاح يقترح نصَّه — ولا يدوس نصًّا كُتب */
    const chooseKey = (key: string) => {
        const entry = byKey.get(key);
        form.setData((d) => ({
            ...d,
            key,
            params: {},
            text_ar: d.text_ar.trim() === '' || catalog.some((c) => c.text === d.text_ar) ? (entry?.text ?? '') : d.text_ar,
        }));
    };

    const submit = () => {
        const options = { preserveScroll: true, onSuccess: () => setEditing(null) };

        if (editing === 'new') {
            form.post(route('admin.settings.policies.store'), options);
        } else if (editing) {
            form.put(route('admin.settings.policies.update', editing.id), options);
        }
    };

    const remove = async (policy: Policy) => {
        const ok = await ask({
            title: 'حذف السياسة',
            message: 'ما خُتم منها في العروض المرسلة يبقى كما هو. أتحذفها؟',
            action: 'حذف',
            danger: true,
        });

        if (ok) {
            router.delete(route('admin.settings.policies.destroy', policy.id), { preserveScroll: true });
        }
    };

    const scopeOptions =
        form.data.scope_type === 'customer'
            ? customers
            : form.data.scope_type === 'product'
              ? products
              : form.data.scope_type === 'category'
                ? categories
                : [];

    const entry = byKey.get(form.data.key);

    return (
        <AdminLayout title="سياسات الشركة">
            <PageHeader
                title="سياسات الشركة"
                subtitle={t('ما يقرؤه العميل على كل عرض سعر — ويُختم فيه لحظة إرساله.')}
                actions={
                    <Button onClick={() => open('new')}>
                        <Plus />
                        {t('سياسة')}
                    </Button>
                }
            />

            <BackToSettings />

            <SettingsPage width="wide">
                <SettingsSection
                    title="السياسات السارية"
                    description="النصّ هو ما يُثبَت به الحقّ. والفحص الآليّ اليوم لمهلة الطلب وحدها: ما دونها طلبٌ عاجل يحتاج سببًا واعتمادًا."
                    icon={ScrollText}
                >
                    {policies.length === 0 ? (
                        <p className="rounded-[12px] bg-[#fafafa] px-4 py-8 text-center text-[13px] text-[#9ca3af]">
                            {t('لا سياسات بعد — وعروض الأسعار تخرج بلا شروط عامة.')}
                        </p>
                    ) : (
                        <ul className="divide-y divide-[#f4f4f5]">
                            {policies.map((p) => (
                                <li key={p.id} className="flex flex-wrap items-start gap-3 py-4">
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-semibold text-[#111]">{t(p.label)}</span>
                                            {p.scope_type && (
                                                <span className="rounded-full bg-[#f4f4f5] px-2 py-0.5 text-[11px] text-[#52525b]">
                                                    {t(SCOPE_LABEL[p.scope_type])}: {p.scope_label}
                                                </span>
                                            )}
                                            <span className="rounded-full bg-[#f4f4f5] px-2 py-0.5 text-[11px] text-[#9ca3af]">
                                                {t('النسخة :n', { n: String(p.version) })}
                                            </span>
                                            {byKey.get(p.key)?.checked && (
                                                <span className="inline-flex items-center gap-1 rounded-full bg-[#eff6ff] px-2 py-0.5 text-[11px] text-[#1d4ed8]">
                                                    <ShieldAlert className="size-3" />
                                                    {t('يُفحص آليًا')}
                                                </span>
                                            )}
                                            {!p.in_force && (
                                                <span className="rounded-full bg-[#fffbeb] px-2 py-0.5 text-[11px] text-[#b45309]">
                                                    {p.active ? t('يسري من :d', { d: p.effective_from ?? '' }) : t('معطَّلة')}
                                                </span>
                                            )}
                                        </div>
                                        <p className="mt-1 whitespace-pre-line text-[13px] leading-relaxed text-[#52525b]">
                                            {p.text_ar}
                                        </p>
                                        {Object.keys(p.params).length > 0 && (
                                            <p className="mt-1 text-[12px] text-[#9ca3af]">
                                                {Object.entries(p.params)
                                                    .map(([k, v]) => `${t(PARAM_LABEL[k] ?? k)}: ${v}`)
                                                    .join(' · ')}
                                            </p>
                                        )}
                                    </div>
                                    <div className="flex items-center gap-1">
                                        <Toggle
                                            on={p.active}
                                            label="سارية"
                                            onChange={(on) =>
                                                router.put(
                                                    route('admin.settings.policies.update', p.id),
                                                    { active: on },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        />
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            aria-label={t('تعديل')}
                                            onClick={() => open(p)}
                                        >
                                            <Pencil />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            aria-label={t('حذف')}
                                            onClick={() => remove(p)}
                                        >
                                            <Trash2 />
                                        </Button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </SettingsSection>
            </SettingsPage>

            <Dialog open={editing !== null} onOpenChange={(o) => !o && setEditing(null)}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{editing === 'new' ? t('سياسة جديدة') : t('تعديل السياسة')}</DialogTitle>
                        <DialogDescription>
                            {t('تعديل النص أو المعاملات يرفع النسخة — وما خُتم في العروض المرسلة يبقى.')}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="max-h-[70svh] space-y-3 overflow-y-auto px-5">
                        <Field label="السياسة" required error={form.errors.key} hint={entry?.hint}>
                            <Select
                                value={form.data.key}
                                onChange={(e) => chooseKey(e.target.value)}
                                options={catalog.map((c) => ({ value: c.key, label: t(c.label) }))}
                            />
                        </Field>

                        {entry && Object.keys(entry.params).length > 0 && (
                            <div className={cn('grid gap-3', Object.keys(entry.params).length > 1 && 'sm:grid-cols-2')}>
                                {Object.entries(entry.params).map(([name, type]) => (
                                    <Field
                                        key={name}
                                        label={PARAM_LABEL[name] ?? name}
                                        error={form.errors[`params.${name}` as keyof typeof form.errors]}
                                    >
                                        <Input
                                            type="number"
                                            dir="ltr"
                                            min={0}
                                            max={type === 'integer' ? 365 : 100}
                                            step={type === 'integer' ? 1 : 0.5}
                                            value={form.data.params[name] ?? ''}
                                            onChange={(e) =>
                                                form.setData('params', { ...form.data.params, [name]: e.target.value })
                                            }
                                        />
                                    </Field>
                                ))}
                            </div>
                        )}

                        <Field
                            label="النص العربي"
                            required
                            hint="ما يقرؤه العميل على الورقة"
                            error={form.errors.text_ar}
                        >
                            <Textarea
                                rows={4}
                                value={form.data.text_ar}
                                onChange={(e) => form.setData('text_ar', e.target.value)}
                            />
                        </Field>
                        <Field label="النص الإنجليزي" hint="لمن يفتح الرابط بالإنجليزية — اختياري" error={form.errors.text_en}>
                            <Textarea
                                rows={3}
                                dir="ltr"
                                value={form.data.text_en}
                                onChange={(e) => form.setData('text_en', e.target.value)}
                            />
                        </Field>

                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="النطاق" hint="عامة، أو لعميل أو صنف أو تصنيف بعينه" error={form.errors.scope_type}>
                                <Select
                                    value={form.data.scope_type}
                                    onChange={(e) =>
                                        form.setData((d) => ({
                                            ...d,
                                            scope_type: e.target.value as typeof d.scope_type,
                                            scope_id: '',
                                        }))
                                    }
                                    placeholder="عامة — على كل العروض"
                                    options={[
                                        { value: 'customer', label: t('عميل بعينه') },
                                        { value: 'product', label: t('صنف بعينه') },
                                        { value: 'category', label: t('تصنيف بعينه') },
                                    ]}
                                />
                            </Field>
                            {form.data.scope_type !== '' && (
                                <Field label="الهدف" required error={form.errors.scope_id}>
                                    <Select
                                        value={form.data.scope_id}
                                        onChange={(e) => form.setData('scope_id', e.target.value)}
                                        placeholder="اختر"
                                        options={scopeOptions.map((o) => ({ value: String(o.value), label: o.label }))}
                                    />
                                </Field>
                            )}
                        </div>

                        <Field label="يسري من" hint="اتركه فارغًا لتسري الآن" error={form.errors.effective_from}>
                            <Input
                                type="date"
                                value={form.data.effective_from}
                                onChange={(e) => form.setData('effective_from', e.target.value)}
                            />
                        </Field>
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setEditing(null)}>
                            {t('تراجع')}
                        </Button>
                        <Button onClick={submit} disabled={form.processing || form.data.text_ar.trim() === ''}>
                            {t('حفظ')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {confirmDialog}
        </AdminLayout>
    );
}
