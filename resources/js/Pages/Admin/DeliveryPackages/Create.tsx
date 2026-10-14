import { useForm, usePage } from '@inertiajs/react';
import { Save } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import BackLink from '@/Components/BackLink';
import PageHeader from '@/Components/PageHeader';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input, Textarea } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';

interface Props {
    customers: { id: number; name: string }[];
    quotations: { id: number; label: string; customer_id: number | null; customer: string | null }[];
    projects: { id: number; label: string; customer_id: number | null; customer: string | null; quotation_id: number | null }[];
    preset: { quotation_id: number | null; project_id: number | null };
}

/**
 * حزمةُ تسليمٍ جديدة — رأسُها هنا، وبنودُها وملفّاتُها في صفحتها.
 *
 * والعرضُ المقبول أوّلًا: يختاره فيرث العميلَ والعنوان. وحزمةٌ بلا عرضٍ
 * تُقبل — عملٌ قديم لم يمرّ بالنظام — بعميلٍ يُسمّى.
 */
export default function DeliveryPackageCreate({ customers, quotations, projects, preset }: Props) {
    const t = useTranslate();
    const { errors } = usePage().props as { errors?: Record<string, string> };
    const presetP = projects.find((x) => x.id === preset.project_id);
    const presetQ = quotations.find((q) => q.id === (presetP?.quotation_id ?? preset.quotation_id));

    const form = useForm({
        project_id: presetP ? String(presetP.id) : '',
        quotation_id: presetQ ? String(presetQ.id) : '',
        customer_id: presetP?.customer_id ? String(presetP.customer_id) : presetQ?.customer_id ? String(presetQ.customer_id) : '',
        title: '',
        description: '',
        client_notes: '',
        internal_notes: '',
        contact_email: '',
    });

    /* والمشروعُ أوّلًا: يجرّ عرضَه وعميلَه — فالحزمةُ تُسلِّم من بنوده */
    const chooseProject = (id: string) => {
        const x = projects.find((y) => String(y.id) === id);
        form.setData((d) => ({
            ...d,
            project_id: id,
            quotation_id: x?.quotation_id ? String(x.quotation_id) : d.quotation_id,
            customer_id: x?.customer_id ? String(x.customer_id) : d.customer_id,
        }));
    };

    const chooseQuotation = (id: string) => {
        const q = quotations.find((x) => String(x.id) === id);
        form.setData((d) => ({
            ...d,
            quotation_id: id,
            customer_id: q?.customer_id ? String(q.customer_id) : d.customer_id,
        }));
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(route('admin.deliveries.store'));
    };

    return (
        <AdminLayout title={t('حزمة تسليم جديدة')}>
            {presetP ? (
                <BackLink routeName="admin.projects.show" href={route('admin.projects.show', { id: presetP.id, tab: 'delivery' })} label={presetP.label} />
            ) : (
                <BackLink routeName="admin.projects.index" href={route('admin.projects.index')} label="المشاريع" />
            )}
            <PageHeader title={t('حزمة تسليم جديدة')} subtitle={t('ما سيُسلَّم للعميل — والبنود والملفّات تُضاف بعد الحفظ.')} />

            <form onSubmit={submit} className="max-w-3xl space-y-4">
                <Card className="space-y-4 p-4">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field label="المشروع" hint="ما يُسلَّم من بنوده — ويرث الحزمةَ عميلَه وعرضَه" error={form.errors.project_id} className="sm:col-span-2">
                            <Select
                                value={form.data.project_id}
                                onChange={(e) => chooseProject(e.target.value)}
                                placeholder="بلا مشروع"
                                options={projects.map((x) => ({ value: String(x.id), label: x.label }))}
                            />
                        </Field>
                        <Field label="العرض المقبول" hint="اختياري — يرث الحزمةَ عميلَه وعنوانَه" error={form.errors.quotation_id}>
                            <Select
                                value={form.data.quotation_id}
                                onChange={(e) => chooseQuotation(e.target.value)}
                                placeholder="بلا عرض"
                                options={quotations.map((q) => ({ value: String(q.id), label: q.label }))}
                            />
                        </Field>
                        <Field label="العميل" required error={form.errors.customer_id}>
                            <Select
                                value={form.data.customer_id}
                                onChange={(e) => form.setData('customer_id', e.target.value)}
                                placeholder="اختر العميل"
                                options={customers.map((c) => ({ value: String(c.id), label: c.name }))}
                            />
                        </Field>
                        <Field label="عنوان الحزمة" required error={form.errors.title} className="sm:col-span-2">
                            <Input
                                value={form.data.title}
                                onChange={(e) => form.setData('title', e.target.value)}
                                placeholder={t('تسليم مؤتمر عُمان للطاقة ٢٠٢٦')}
                            />
                        </Field>
                        <Field label="بريد جهة الاتصال" hint="يُقترح للعميل عند القبول" error={form.errors.contact_email}>
                            <Input
                                type="email"
                                dir="ltr"
                                value={form.data.contact_email}
                                onChange={(e) => form.setData('contact_email', e.target.value)}
                            />
                        </Field>
                    </div>
                    <Field label="وصف" hint="ما أُنجز — يقرؤه العميل" error={form.errors.description}>
                        <Textarea rows={3} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                    </Field>
                    <Field label="ملاحظات للعميل" hint="تظهر على صفحة التسليم" error={form.errors.client_notes}>
                        <Textarea rows={3} value={form.data.client_notes} onChange={(e) => form.setData('client_notes', e.target.value)} />
                    </Field>
                    <Field label="ملاحظات داخلية" hint="لا يراها العميل" error={form.errors.internal_notes}>
                        <Textarea rows={2} value={form.data.internal_notes} onChange={(e) => form.setData('internal_notes', e.target.value)} />
                    </Field>
                    {errors?.package && <p className="text-[12px] text-[#b91c1c]">{errors.package}</p>}
                </Card>

                <div className="flex justify-end">
                    <Button type="submit" disabled={form.processing}>
                        <Save />
                        {t('حفظ والانتقال إلى البنود')}
                    </Button>
                </div>
            </form>
        </AdminLayout>
    );
}
