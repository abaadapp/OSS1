import { useMemo } from 'react';
import { usePage } from '@inertiajs/react';
import { PackageCheck } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { INVENTORY_TABS } from '@/Components/SectionTabs';
import ExportMenu from '@/Components/ExportMenu';
import SmartLink from '@/Components/SmartLink';
import DataTable, { type Column, type Filter } from '@/Components/DataTable';
import { Badge } from '@/Components/ui/badge';
import { Card } from '@/Components/ui/card';
import { money, number } from '@/lib/format';
import useLiveStock from '@/hooks/useLiveStock';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { Branch } from '@/types';
import type { InventoryItem } from '@/types/models';
import ForecastPanel, { type InventoryForecastPanel } from './partials/ForecastPanel';

interface Props {
    inventory: InventoryItem[];
    branches: Branch[];
    currentBranchId: number | null;
    /** حالة المخزون القادمة في الرابط (?stock=) — يرسلها تنبيه النظرة العامة */
    stockFilter: string | null;
    /** توقّعُ المخزون للمشاريع القادمة — قراءةٌ لا خصم */
    forecast?: InventoryForecastPanel;
    /** سنداتُ استلامٍ تنتظر الاعتماد — كميّتُها لا تدخل الرفّ قبله */
    pendingReceipts?: { id: number; number: string; supplier: string | null; order: string | null; received_at: string | null }[];
}

export default function InventoryIndex() {
    const { inventory: serverInventory, stockFilter, forecast, pendingReceipts = [], context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const currency = context!.currency;

    /* الجدول كان مجمّدًا على لقطة لحظة الفتح بينما بطاقات اللوحة تتحدّث.
       الحقل هنا اسمه status لا stock_status، فيُترجَم ذهابًا وإيابًا.

       والـuseMemo ليس تحسينًا: useLiveStock يمسح لقطته كلما تغيّر مرجع
       المصفوفة (ليَغلب دائمًا ما جاء من الخادم مع كل تنقّل). مصفوفةٌ جديدة
       مع كل تصيير تعني مسحًا دائمًا فلا يظهر أي تحديث أبدًا. */
    const mapped = useMemo(
        () => serverInventory.map((i) => ({ ...i, stock_status: i.status })),
        [serverInventory],
    );
    const { products: live, updatedAt } = useLiveStock(route('admin.products.stockFeed'), mapped);

    // القيمة تتبع الكمية: تركُها كما جاءت من الخادم يعني صفًّا يقول
    // «الكمية 2» و«القيمة 240» في آن واحد
    const inventory = live.map((i) => ({
        ...i,
        status: i.stock_status,
        value: Math.round(i.cost * i.qty * 1000) / 1000,
    }));

    const totalValue = inventory.reduce((sum, item) => sum + item.value, 0);

    const columns: Column<InventoryItem>[] = [
        {
            key: 'name',
            header: 'المنتج',
            sortable: true,
            value: (i) => i.name,
            cell: (i) => (
                <span>
                    <span className="block font-medium">{i.name}</span>
                    <span className="block text-[11px] text-[#9ca3af]">{i.sku}</span>
                </span>
            ),
        },
        {
            key: 'qty',
            header: 'الكمية',
            align: 'end',
            sortable: true,
            value: (i) => i.qty,
            cell: (i) => <span className="tabular-nums">{number(i.qty)}</span>,
        },
        {
            key: 'min',
            header: 'حد التنبيه',
            align: 'end',
            cell: (i) => <span className="tabular-nums text-[#9ca3af]">{number(i.min)}</span>,
        },
        { key: 'status', header: 'الحالة', cell: (i) => <Badge status={i.status} /> },
        {
            key: 'cost',
            header: 'التكلفة',
            align: 'end',
            sortable: true,
            value: (i) => i.cost,
            cell: (i) => <span className="tabular-nums">{money(i.cost, currency)}</span>,
        },
        {
            key: 'value',
            header: 'قيمة المخزون',
            align: 'end',
            sortable: true,
            value: (i) => i.value,
            cell: (i) => <span className="tabular-nums font-medium">{money(i.value, currency)}</span>,
        },
    ];

    const filters: Filter<InventoryItem>[] = [
        {
            label: 'كل الحالات',
            asTabs: true,
            options: [
                { label: 'متوفر', value: 'متوفر' },
                { label: 'منخفض', value: 'منخفض' },
                // القيمة هي ما يُرجعه Product::statusFor حرفيًا — كانت «نفد»
                // فلا تطابق «نفد المخزون» أبدًا، فيُرجع الخيار قائمة فارغة دائمًا
                { label: 'نفد', value: 'نفد المخزون' },
            ],
            match: (i, value) => i.status === value,
            initial: stockFilter ?? undefined,
        },
    ];

    return (
        <AdminLayout title="المخزون">
            <PageHeader
                title="المخزون"
                subtitle={t(':n صنف · القيمة الإجمالية :total', {
                    n: number(inventory.length),
                    total: money(totalValue, currency),
                })}
                actions={
                    <>
                        <ExportMenu
                            xlsx={route('admin.inventory.xlsx')}
                            pdf={route('admin.inventory.exportPdf')}
                            csv={route('admin.export.inventory')}
                        />
                    </>
                }
            />

            <SectionTabs tabs={INVENTORY_TABS} current="admin.inventory.index" />

            {forecast && <ForecastPanel data={forecast} />}

            {/*
                وما ينتظر الاعتماد من سندات الاستلام — قائمتُها خرجت من القسم،
                والاعتمادُ باقٍ في صفحة كلّ سند: من هنا يُفتح.
            */}
            {pendingReceipts.length > 0 && (
                <Card className="mb-6 overflow-hidden border-[#fde68a]" data-testid="pending-receipts">
                    <div className="flex items-start gap-2 border-b border-[#fef3c7] bg-[#fffbeb] px-5 py-3">
                        <PackageCheck className="mt-0.5 size-4 shrink-0 text-[#b45309]" />
                        <div>
                            <h3 className="text-sm font-bold text-[#92400e]">
                                {t('سندات استلام بانتظار الاعتماد')} <span className="tabular-nums">({number(pendingReceipts.length)})</span>
                            </h3>
                            <p className="text-[12px] text-[#a16207]">{t('لا تدخل الكمية المخزون قبل اعتماد سندها — افتح السند لاعتماده أو رفضه.')}</p>
                        </div>
                    </div>
                    <ul className="divide-y divide-[#f4f4f5]">
                        {pendingReceipts.map((r) => (
                            <li key={r.id} className="flex flex-wrap items-center justify-between gap-2 px-5 py-2 text-[13px]">
                                <SmartLink
                                    routeName="admin.inventory.receipts.show"
                                    href={route('admin.inventory.receipts.show', r.id)}
                                    className="font-mono text-[12px] text-[#1d4ed8] hover:underline"
                                >
                                    {r.number}
                                </SmartLink>
                                <span className="min-w-0 flex-1 truncate text-[#4b4b4b]">
                                    {r.supplier ?? '—'}
                                    {r.order && <span className="ms-2 font-mono text-[11px] text-[#9ca3af]">{r.order}</span>}
                                </span>
                                <span className="text-[12px] text-[#6b7280]" dir="ltr">{r.received_at ?? '—'}</span>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            {updatedAt && (
                <p className="mb-3 text-[12px] text-[#9ca3af]">
                    {t('الكميات محدّثة حتى')} <span dir="ltr">{updatedAt}</span>
                </p>
            )}

            <Card className="overflow-hidden">
                <DataTable
                    rows={inventory}
                    columns={columns}
                    rowKey={(i) => i.id}
                    searchPlaceholder="ابحث بالاسم أو SKU…"
                    searchable={(i) => `${i.name} ${i.sku}`}
                    filters={filters}
                    empty="لا توجد أصناف في المخزون بعد"
                />
            </Card>
        </AdminLayout>
    );
}
