import { useEffect, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import {
    ClipboardList,
    Eye,
    FileDown,
    FileSpreadsheet,
    FileText,
    MoreVertical,
    Phone,
    Plus,
    Tags,
    Upload,
} from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { CUSTOMER_TABS } from '@/Components/SectionTabs';
import DataTable, { type Column } from '@/Components/DataTable';
import RowActions from '@/Components/RowActions';
import SmartLink from '@/Components/SmartLink';
import StatCard from '@/Components/StatCard';
import Field, { Select } from '@/Components/Field';
import CatalogDialog from '@/Components/CatalogDialog';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { Input, Textarea } from '@/Components/ui/input';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { Supplier } from '@/types/models';

const BLANK = {
    name: '', name_en: '', phone: '', whatsapp: '', contact_person: '', email: '', notes: '',
    supplier_type: 'products', status: 'active', service_ids: [] as number[],
};

interface Catalog {
    services: { id: number; name: string; active: boolean }[];
    types: Record<string, string>;
    statuses: Record<string, string>;
}

const TYPE_TONE: Record<string, 'neutral' | 'info' | 'primary'> = { products: 'neutral', services: 'info', both: 'primary' };

export default function SuppliersIndex() {
    const { suppliers, services = [], types = {}, statuses = {} } = usePage<PageProps<{ suppliers: Supplier[] } & Partial<Catalog>>>().props;
    const { errors } = usePage().props as { errors?: Record<string, string> };
    const t = useTranslate();
    /* الترشيحُ بالنوع وبالخدمة — «مقدّم خدمة ← تصوير» (المرحلة 14) */
    const [typeFilter, setTypeFilter] = useState('');
    const [serviceFilter, setServiceFilter] = useState('');
    const [catalog, setCatalog] = useState(false);
    const visible = suppliers.filter((s) =>
        (typeFilter === '' || (typeFilter === 'services'
            ? s.supplier_type === 'services' || s.supplier_type === 'both'
            : typeFilter === 'products' ? s.supplier_type !== 'services' : s.supplier_type === typeFilter))
        && (serviceFilter === '' || (s.services ?? []).some((x) => String(x.id) === serviceFilter)));
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<Supplier | null>(null);
    const [importing, setImporting] = useState(false);

    const upload = useForm<{ file: File | null }>({ file: null });

    const submitImport = (e: React.FormEvent) => {
        e.preventDefault();
        upload.post(route('admin.suppliers.import.upload'), { forceFormData: true });
    };

    const form = useForm({ ...BLANK });

    // كل فتح يبدأ من بيانات الصف المختار لا من بقايا الفتح السابق
    useEffect(() => {
        if (editing) {
            form.setData({
                name: editing.name,
                name_en: editing.name_en ?? '',
                phone: editing.phone ?? '',
                whatsapp: editing.whatsapp ?? '',
                contact_person: editing.contact ?? '',
                email: editing.email ?? '',
                notes: editing.notes ?? '',
                supplier_type: editing.supplier_type ?? 'products',
                status: editing.status ?? 'active',
                service_ids: (editing.services ?? []).map((x) => x.id),
            });
        } else if (adding) {
            form.setData({ ...BLANK });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [editing, adding]);

    const close = () => {
        setAdding(false);
        setEditing(null);
        form.clearErrors();
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const done = { preserveScroll: true, onSuccess: close };
        if (editing) form.put(route('admin.suppliers.update', editing.id), done);
        else form.post(route('admin.suppliers.store'), done);
    };

    const stats = [
        { label: t('إجمالي المورّدين'), value: number(suppliers.length), icon: 'truck', color: 'primary' },
        {
            label: t('مورّدون لديهم أوامر'),
            value: number(suppliers.filter((s) => s.orders_count > 0).length),
            icon: 'clipboard-check',
            color: 'success',
        },
        {
            label: t('إجمالي أوامر الشراء'),
            value: number(suppliers.reduce((n, s) => n + s.orders_count, 0)),
            icon: 'package',
            color: 'info',
        },
    ];

    const columns: Column<Supplier>[] = [
        {
            key: 'name',
            header: 'المورّد',
            sortable: true,
            value: (s) => s.label ?? s.name,
            // الاسم الآخر تحته رماديًّا: من يبحث بالعربية في شاشةٍ إنجليزية
            // كان يقرأ اسمًا لا يعرفه ولا يعرف أنّه هو
            cell: (s) => (
                <span>
                    {/*
                        واسمُ المورّد يفتح حركتَه.

                        كان نصًّا لا يُضغط: من يقرأ «مشتل الربيع» في القائمة
                        ويريد أن يعرف ماذا اشترينا منه يفتح شاشةً أخرى ويبحث
                        بالاسم. وسجلُّ المشتريات يُرشَّح بالمورّد أصلًا
                        (`?supplier=`) — فيُقاد إليه بدل صفحةٍ ثانيةٍ تُبنى.
                    */}
                    {/* ولكلّ مورّدٍ صفحتُه منذ المرحلة 14 — بياناتُه وخدماتُه وأوامرُه وسنداتُه */}
                    <SmartLink
                        routeName="admin.suppliers.show"
                        href={route('admin.suppliers.show', s.id)}
                        className="text-[#111] hover:text-[#6d28d9] hover:underline"
                    >
                        {s.label ?? s.name}
                    </SmartLink>
                    {s.status === 'inactive' && <Badge variant="neutral" className="ms-2">{t(statuses.inactive ?? 'متوقف')}</Badge>}
                    {s.name_en && (s.label ?? s.name) !== s.name_en && (
                        <span className="block text-[12px] text-[#9ca3af]" dir="ltr">
                            {s.name_en}
                        </span>
                    )}
                    {s.name_en && (s.label ?? s.name) === s.name_en && s.name !== s.name_en && (
                        <span className="block text-[12px] text-[#9ca3af]">{s.name}</span>
                    )}
                </span>
            ),
        },
        {
            key: 'phone',
            header: 'الهاتف',
            cell: (s) => (
                <span dir="ltr" className="text-[#6b7280]">
                    {s.phone || '—'}
                </span>
            ),
        },
        {
            key: 'type',
            header: 'النوع',
            cell: (s) => (
                <span className="flex flex-wrap items-center gap-1">
                    <Badge variant={TYPE_TONE[s.supplier_type ?? 'products']}>{types[s.supplier_type ?? 'products'] ?? s.supplier_type}</Badge>
                    {(s.services ?? []).slice(0, 4).map((x) => (
                        <span key={x.id} className="rounded-full bg-[#f5f3ff] px-2 py-0.5 text-[11px] text-[#6d28d9]">{x.name}</span>
                    ))}
                    {(s.services ?? []).length > 4 && <span className="text-[11px] text-[#9ca3af]">+{(s.services ?? []).length - 4}</span>}
                </span>
            ),
        },
        { key: 'contact', header: 'الشخص المسؤول', cell: (s) => s.contact || '—' },
        {
            key: 'email',
            header: 'البريد',
            cell: (s) => (
                <span dir="ltr" className="text-[#6b7280]">
                    {s.email || '—'}
                </span>
            ),
        },
        {
            key: 'orders_count',
            header: 'أوامر الشراء',
            align: 'end',
            sortable: true,
            value: (s) => s.orders_count,
            cell: (s) => <Badge variant="info">{`${number(s.orders_count)} ${t('أمر')}`}</Badge>,
        },
        {
            key: 'actions',
            header: 'إجراءات',
            align: 'end',
            cell: (s) => (
                <RowActions
                    /*
                        ولا يُرسم الحذفُ على مورّدٍ اشتُري منه.
                        سنداتُه مقيّدةٌ عليه في القاعدة فيردّ الخادمُ الحذف،
                        وأوامرُه تُفرَّغ مراجعُها صامتةً. والقاعدةُ تُقرأ من
                        الخادم (`can_delete`) لا تُشتقّ هنا من عدد الأوامر:
                        سندٌ بلا أمرٍ يمنع كذلك.
                    */
                    destroy={
                        s.can_delete === false
                            ? undefined
                            : { url: route('admin.suppliers.destroy', s.id), message: 'حذف المورّد؟' }
                    }
                    extra={[
                        ...(s.phone
                            ? [{ label: 'اتصال', icon: <Phone className="size-4" />, href: `tel:${s.phone}` }]
                            : []),
                        { label: 'تعديل', onSelect: () => setEditing(s) },
                    ]}
                />
            ),
        },
    ];

    const fields = (
        <div className="space-y-4">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field label="اسم المورّد" required error={form.errors.name}>
                    <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                </Field>
                {/* يُكتب بيدٍ حين لا يُشتقّ: الاسم اللاتينيّ يُنقل إلى العربية
                    تلقائيًّا، أمّا العربيّ فلا صورة لاتينية له تُخمَّن */}
                <Field
                    label="الاسم بالإنجليزية (اختياري)"
                    hint="يظهر عند تشغيل الواجهة بالإنجليزية"
                    error={form.errors.name_en}
                >
                    <Input
                        dir="ltr"
                        value={form.data.name_en}
                        onChange={(e) => form.setData('name_en', e.target.value)}
                    />
                </Field>
            </div>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field label="النوع" error={form.errors.supplier_type}>
                    <Select value={form.data.supplier_type} onChange={(e) => form.setData('supplier_type', e.target.value)}
                        options={Object.entries(types).map(([value, label]) => ({ value, label }))} />
                </Field>
                <Field label="الحالة" error={form.errors.status}>
                    <Select value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}
                        options={Object.entries(statuses).map(([value, label]) => ({ value, label }))} />
                </Field>
            </div>
            {form.data.supplier_type !== 'products' && (
                <Field label="الخدمات التي يقدّمها" hint="من كتالوج الخدمات — يُبحث بها ويُرشَّح" error={form.errors.service_ids}>
                    <div className="flex flex-wrap gap-2">
                        {services.filter((x) => x.active || form.data.service_ids.includes(x.id)).map((x) => {
                            const on = form.data.service_ids.includes(x.id);
                            return (
                                <button key={x.id} type="button"
                                    onClick={() => form.setData('service_ids', on ? form.data.service_ids.filter((i) => i !== x.id) : [...form.data.service_ids, x.id])}
                                    className={`rounded-full border px-3 py-1 text-[12.5px] ${on ? 'border-[#6d28d9] bg-[#f5f3ff] text-[#6d28d9]' : 'border-[var(--ui-border,#e8e8e8)] text-[#4b4b4b]'}`}>
                                    {x.name}
                                </button>
                            );
                        })}
                    </div>
                </Field>
            )}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field label="الهاتف" error={form.errors.phone}>
                    <Input dir="ltr" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} />
                </Field>
                <Field label="واتساب" hint="إن كان غير رقم الهاتف" error={form.errors.whatsapp}>
                    <Input dir="ltr" value={form.data.whatsapp} onChange={(e) => form.setData('whatsapp', e.target.value)} />
                </Field>
                <Field label="الشخص المسؤول" error={form.errors.contact_person}>
                    <Input
                        value={form.data.contact_person}
                        onChange={(e) => form.setData('contact_person', e.target.value)}
                    />
                </Field>
            </div>
            <Field label="البريد الإلكتروني" error={form.errors.email}>
                <Input
                    type="email"
                    dir="ltr"
                    value={form.data.email}
                    onChange={(e) => form.setData('email', e.target.value)}
                />
            </Field>
            <Field label="ملاحظات" error={form.errors.notes}>
                <Textarea rows={2} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
            </Field>
        </div>
    );

    return (
        <AdminLayout title="المورّدون">
            <PageHeader
                title="المورّدون"
                subtitle={t('إدارة موردي البضاعة وبيانات التواصل معهم')}
                actions={
                    <>
                        {/*
                            التصدير والاستيراد في قائمةٍ واحدة كما في العملاء.
                            زرّان ظاهران لعملٍ يُطلب مرّةً في الشهر يزاحمان
                            «مورّد جديد» وهو ما يُضغط كل يوم.
                        */}
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="outline" size="icon" aria-label={t('المزيد')}>
                                    <MoreVertical />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-60">
                                <DropdownMenuLabel>{t('تصدير')}</DropdownMenuLabel>
                                <DropdownMenuItem asChild>
                                    <a href={route('admin.suppliers.export.xlsx')}>
                                        <FileSpreadsheet className="text-[#059669]" />
                                        {t('تصدير Excel (xlsx)')}
                                    </a>
                                </DropdownMenuItem>
                                <DropdownMenuItem asChild>
                                    <a href={route('admin.suppliers.export.pdf')} target="_blank" rel="noreferrer">
                                        <FileText className="text-[#dc2626]" />
                                        {t('تصدير PDF')}
                                    </a>
                                </DropdownMenuItem>
                                <DropdownMenuItem asChild>
                                    <a href={route('admin.export.suppliers')}>
                                        <FileDown className="text-[#6b7280]" />
                                        {t('تصدير CSV')}
                                    </a>
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuLabel>{t('استيراد')}</DropdownMenuLabel>
                                <DropdownMenuItem onSelect={() => setImporting(true)}>
                                    <Upload className="text-[#6d28d9]" />
                                    {t('استيراد من ملف…')}
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                        <Button variant="outline" onClick={() => setCatalog(true)}>
                            <Tags />
                            {t('كتالوج الخدمات')}
                        </Button>
                        <Button variant="outline" asChild>
                            <SmartLink routeName="admin.purchases.index" href={route('admin.purchases.index')}>
                                <ClipboardList />
                                {t('أوامر الشراء')}
                            </SmartLink>
                        </Button>
                        <Button onClick={() => setAdding(true)}>
                            <Plus />
                            {t('مورّد جديد')}
                        </Button>
                    </>
                }
            />

            <SectionTabs tabs={CUSTOMER_TABS} current="admin.suppliers.index" />

            {errors?.service && <div className="mb-4 rounded-lg bg-[#fef2f2] px-4 py-3 text-[13px] text-[#b91c1c]">{errors.service}</div>}

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                {stats.map((s, i) => (
                    <StatCard key={s.label} stat={s} index={i} />
                ))}
            </div>

            <Card className="mb-4 p-4">
                <div className="flex flex-wrap items-end gap-3">
                    <Field label="النوع" className="min-w-[160px]">
                        <Select value={typeFilter} placeholder="الكلّ" onChange={(e) => setTypeFilter(e.target.value)}
                            options={Object.entries(types).map(([value, label]) => ({ value, label }))} />
                    </Field>
                    <Field label="الخدمة" className="min-w-[180px]">
                        <Select value={serviceFilter} placeholder="كلّ الخدمات" onChange={(e) => setServiceFilter(e.target.value)}
                            options={services.map((x) => ({ value: String(x.id), label: x.name }))} />
                    </Field>
                </div>
            </Card>

            <Card className="overflow-hidden">
                <DataTable
                    rows={visible}
                    columns={columns}
                    rowKey={(s) => s.id}
                    searchPlaceholder="ابحث باسم المورّد أو الهاتف…"
                    // القادم من البحث الموحّد يصل بالقائمة مُرشَّحةً بما بحث عنه
                    initialQuery={new URLSearchParams(window.location.search).get('q') ?? ''}
                    searchable={(s) => `${s.name} ${s.name_en ?? ''} ${s.phone} ${s.whatsapp ?? ''} ${s.email} ${s.contact} ${types[s.supplier_type ?? 'products'] ?? ''} ${(s.services ?? []).map((x) => x.name).join(' ')}`}
                    empty="أضِف أول مورّد لبدء إنشاء أوامر الشراء."
                />
            </Card>

            <Dialog open={adding || editing !== null} onOpenChange={(o) => !o && close()}>
                {/*
                    نفس قياسات نافذة «إضافة حركة مخزون» حرفيًّا: max-w-lg على
                    الحاوية، و px-5 pb-5 على النموذج. بدونهما كانت الحقول تلتصق
                    بحافّتَي النافذة وتتمدّد النافذة بلا سقف — والمحتوى كما هو.
                */}
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{t(editing ? 'تعديل بيانات المورّد' : 'إضافة مورّد جديد')}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4 px-5 pb-5">
                        {fields}
                        <div className="flex justify-end gap-2 pt-1">
                            <Button type="button" variant="ghost" onClick={close}>
                                {t('إلغاء')}
                            </Button>
                            <Button type="submit" loading={form.processing}>
                                {t(editing ? 'حفظ' : 'إضافة')}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            <CatalogDialog
                open={catalog}
                onClose={() => setCatalog(false)}
                title="كتالوج خدمات الموردين"
                items={services}
                routes={{ store: 'admin.suppliers.services.store', update: 'admin.suppliers.services.update', destroy: 'admin.suppliers.services.destroy' }}
            />

            <Dialog open={importing} onOpenChange={setImporting}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{t('استيراد الموردين من ملف')}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitImport} className="space-y-4 px-5 pb-5">
                        <Field
                            label="ملف الموردين"
                            hint="الصيغ المدعومة: CSV، XLS، XLSX، XLSM — الأعمدة: الاسم، الهاتف، البريد، مسؤول التواصل، ملاحظات. يمكنك تصدير ملف ثم تعديله وإعادة استيراده."
                            error={upload.errors.file}
                            required
                        >
                            <Input
                                type="file"
                                accept=".csv,.xls,.xlsx,.xlsm"
                                required
                                onChange={(e) => upload.setData('file', e.target.files?.[0] ?? null)}
                                className="h-auto py-2 file:me-3 file:rounded-lg file:bg-[#111] file:px-4 file:py-2 file:text-white"
                            />
                        </Field>

                        <p className="rounded-[12px] bg-[#f5f3ff] px-3 py-2.5 text-[12px] text-[#6d28d9]">
                            {t('المورّد الموجود (بنفس الهاتف، أو بالاسم إن لم يكن له هاتف) يُحدَّث بدل تكراره. وستظهر معاينة كاملة قبل التأكيد.')}
                        </p>

                        <div className="flex justify-end gap-2 pt-1">
                            <Button type="button" variant="ghost" onClick={() => setImporting(false)}>
                                {t('إلغاء')}
                            </Button>
                            <Button type="submit" loading={upload.processing}>
                                <Eye />
                                {t('معاينة الملف')}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
