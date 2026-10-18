import { useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { AlertTriangle, ClipboardList, PackageCheck, Paperclip, Plus, Printer, Trash2, Truck, Upload } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { PURCHASE_TABS } from '@/Components/SectionTabs';
import StatCard, { type Stat } from '@/Components/StatCard';
import DataTable, { type Column } from '@/Components/DataTable';
import ExportMenu from '@/Components/ExportMenu';
import ListDateRange from '@/Components/ListDateRange';
import Tabs from '@/Components/Tabs';
import { exportLinks } from '@/lib/exportLink';
import SmartLink from '@/Components/SmartLink';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { receiptMode } from '@/lib/receipts';
import type { PageProps } from '@/types';
import { Input } from '@/Components/ui/input';
import type { PurchaseOrder } from '@/types/models';

interface ReorderRow {
    name: string;
    sku: string;
    qty: number;
    alert: number;
    suggested: number;
    cost: number;
}

interface Props {
    orders: PurchaseOrder[];
    stats: Stat[];
    reorder: ReorderRow[];
    /**
     * البحثُ في الرابط (`q`) — يصل من إشعار الاستلام أو يُكتب في الحقل، ويقرؤه
     * الخادمُ للقائمة ولملفّ تصديرها معًا.
     */
    q: string | null;
    /** الحالُ والمدّةُ والمورّدُ في الرابط — يقرؤها الخادمُ للقائمة ولملفّ تصديرها معًا */
    filters?: { status?: string; from?: string; to?: string; supplier_id?: string };
}

const STATUSES = ['مسودة', 'مُرسل', 'مستلم جزئيًا', 'مستلم', 'ملغي'];

export default function PurchasesIndex() {
    const { orders, stats, reorder, q, filters, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const currency = context!.currency;
    const m = (v: number) => money(v, currency);

    const [confirm, setConfirm] = useState<{ order: PurchaseOrder; kind: 'receive' | 'delete' } | null>(null);

    /*
     * الكمية المستلمة لكل بند — تبدأ بما بقي منه.
     *
     * البداية بالمتبقّي لا بصفر: الشحنة الكاملة هي الحال الغالب، فمن وصلته
     * كاملةً يضغط «تأكيد» بلا كتابة، ومن نقصت شحنته يصحّح السطر الناقص وحده.
     */
    const [taking, setTaking] = useState<Record<number, string>>({});

    const openReceive = (order: PurchaseOrder) => {
        setTaking(Object.fromEntries(order.items.map((i) => [i.id, String(i.remaining)])));
        setConfirm({ order, kind: 'receive' });
    };

    const act = () => {
        if (!confirm) return;
        const { order, kind } = confirm;
        const done = { preserveScroll: true, onFinish: () => setConfirm(null) };

        if (kind === 'receive') {
            router.post(
                route('admin.purchases.receive', order.id),
                {
                    items: order.items.map((i) => ({
                        id: i.id,
                        // حقلٌ فُرّغ يعني «لم يصل منه شيء» لا «وصل كلّه»
                        quantity: Number(taking[i.id] ?? 0) || 0,
                    })),
                },
                done,
            );
        } else {
            router.delete(route('admin.purchases.destroy', order.id), done);
        }
    };

    const takingTotal = confirm?.order.items.reduce((sum, i) => sum + (Number(taking[i.id]) || 0), 0) ?? 0;
    /* الخدمةُ تُؤكَّد ولا تُستلم — والمستندُ واحد (`lib/receipts`) */
    const modeOf = (o: PurchaseOrder) => receiptMode(o.items.map((i) => i.line_kind));
    const confirmMode = confirm ? modeOf(confirm.order) : 'goods';
    const partial =
        confirm?.order.items.some((i) => (Number(taking[i.id]) || 0) < i.remaining) ?? false;

    const uploadReceipt = (order: PurchaseOrder, file: File) => {
        router.post(route('admin.purchases.receipt', order.id), { receipt: file }, {
            forceFormData: true,
            preserveScroll: true,
        });
    };

    const columns: Column<PurchaseOrder>[] = [
        {
            key: 'number',
            header: 'الرقم',
            /*
                والرقمُ بابٌ إلى الأمر.
                كان نصًّا لا يُضغط، فمن أراد أن يعرف ما بقي من أمرٍ «مستلم
                جزئيًا» لم يجد إلى تفصيله سبيلًا من هنا.
            */
            cell: (o) => (
                <SmartLink
                    routeName="admin.purchases.show"
                    href={route('admin.purchases.show', o.id)}
                    className="font-mono text-[#6d28d9] hover:underline"
                >
                    {o.number}
                </SmartLink>
            ),
        },
        { key: 'branch', header: 'الفرع', cell: (o) => o.branch || '—' },
        { key: 'supplier', header: 'المورّد', cell: (o) => o.supplier || '—' },
        {
            key: 'items_count',
            header: 'الأصناف',
            align: 'end',
            cell: (o) => <span className="tabular-nums">{number(o.items_count)}</span>,
        },
        {
            key: 'total',
            header: 'الإجمالي',
            align: 'end',
            cell: (o) => <span className="tabular-nums font-semibold">{m(o.total)}</span>,
        },
        { key: 'status', header: 'الحالة', cell: (o) => <Badge status={o.status}>{t(o.status)}</Badge> },
        {
            key: 'receipt',
            header: 'إيصال الدفع',
            cell: (o) => <ReceiptCell order={o} onUpload={(f) => uploadReceipt(o, f)} />,
        },
        { key: 'ordered', header: 'تاريخ الطلب', cell: (o) => <span dir="ltr" className="text-[#6b7280]">{o.ordered}</span> },
        {
            key: 'actions',
            header: 'إجراء',
            align: 'end',
            cell: (o) => (
                <div className="flex items-center justify-end gap-1.5">
                    {o.status !== 'مستلم' && o.status !== 'ملغي' && (
                        <Button
                            variant="success"
                            size="sm"
                            className="rounded-full"
                            onClick={() => openReceive(o)}
                        >
                            <PackageCheck />
                            {modeOf(o) === 'services' ? t('تأكيد التنفيذ') : t('استلام')}
                        </Button>
                    )}
                    {/* الأمر يخرج على ورق: مورّدٌ يقرأ ما طُلب منه لا مكالمةً تُنسى */}
                    <Button variant="ghost" size="icon-sm" aria-label={t('طباعة أمر الشراء')} asChild>
                        <a href={route('admin.purchases.pdf', o.id)} target="_blank" rel="noreferrer">
                            <Printer />
                        </a>
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        aria-label={t('حذف')}
                        className="text-[#b91c1c]"
                        onClick={() => setConfirm({ order: o, kind: 'delete' })}
                    >
                        <Trash2 />
                    </Button>
                </div>
            ),
        },
    ];

    /*
     * والحالُ في الرابط لا في المتصفّح: كانت تبويبًا يُرشّح ما وصل، فلا يعرفها
     * ملفُّ التصدير. والآن يقرؤها الخادمُ للقائمة وللملفّ معًا.
     */
    const status = filters?.status ?? '';

    /*
     * والبحثُ في الرابط أيضًا (`q`): كان يُرشّح في المتصفّح وحده، فيرى التاجرُ
     * ثلاثةَ أوامر ويصدّر فيخرج الملفُّ بالأوامر كلّها. الجدولُ ما زال يرشّح فورًا،
     * وبعد توقّفٍ قصير يُكتب النصُّ في الرابط ويُعاد ما يطابقه من الخادم — فيقرؤه
     * زرُّ «تصدير» كما يقرأ الحالَ والمدّة.
     */
    const typed = useRef(q ?? '');
    /*
     * وما كُتب الآن يصل إلى زرّ «تصدير» فورًا لا بعد التوقّف: من كتب وضغط «تصدير»
     * قبل أن يُكتب البحثُ في الرابط ينتظر ملفًّا بما كتب — وحقلٌ مُسح لا يبقى بحثُه.
     */
    const [typedQ, setTypedQ] = useState(q ?? '');
    const searchTimer = useRef<number | undefined>(undefined);
    // آخرُ المرشّحات لا ما كانت عليه حين بدأ المؤقّت — فلا يُعيد البحثُ المتأخّرُ حالًا تغيّرت
    const shown = useRef({ status, filters });
    shown.current = { status, filters };
    const visit = (patch: Record<string, string | null | undefined>) => {
        const query: Record<string, string> = {};
        const { status: now, filters: f } = shown.current;
        const merged = { q: typed.current.trim(), status: now, from: f?.from, to: f?.to, supplier_id: f?.supplier_id, ...patch };
        for (const [k, v] of Object.entries(merged)) {
            if (v) query[k] = v;
        }
        window.clearTimeout(searchTimer.current);
        router.get(route('admin.purchases.orders'), query, { preserveState: true, preserveScroll: true, replace: true });
    };
    const goStatus = (next: string) => visit({ status: next || null });
    const search = (value: string) => {
        typed.current = value;
        setTypedQ(value);
        window.clearTimeout(searchTimer.current);
        searchTimer.current = window.setTimeout(() => visit({}), 400);
    };

    return (
        <AdminLayout title="أوامر الشراء">
            <PageHeader
                title="أوامر الشراء"
                subtitle={t('طلبات التزويد من المورّدين واستلام البضاعة')}
                actions={
                    <>
                        {/* بالبحث والحال والمدّة المعروضة — والقيمةُ التزامٌ لا مصروفٌ محقَّق */}
                        <ExportMenu {...exportLinks('admin.purchases.orders.export')} params={{ q: typedQ.trim() }} />
                        <Button variant="outline" asChild>
                            <SmartLink routeName="admin.suppliers.index" href={route('admin.suppliers.index')}>
                                <Truck />
                                {t('المورّدون')}
                            </SmartLink>
                        </Button>
                        <Button asChild>
                            <SmartLink routeName="admin.purchases.create" href={route('admin.purchases.create')}>
                                <Plus />
                                {t('أمر شراء جديد')}
                            </SmartLink>
                        </Button>
                    </>
                }
            />

            <SectionTabs tabs={PURCHASE_TABS} current="admin.purchases.orders" />

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-4">
                {stats.map((s, i) => (
                    <StatCard key={s.label} stat={s} index={i} />
                ))}
            </div>

            {reorder.length > 0 && (
                <Card className="mb-6 overflow-hidden border-[#fde68a]">
                    <div className="flex flex-wrap items-center gap-2 border-b border-[#fef3c7] bg-[#fffbeb]/60 px-5 py-4">
                        <AlertTriangle className="size-5 text-[#d97706]" />
                        <h3 className="font-bold text-[#111]">
                            {t('اقتراح إعادة الطلب')} — {number(reorder.length)} {t('منتج وصل حدّ التنبيه')}
                        </h3>
                        <SmartLink
                            routeName="admin.purchases.create"
                            href={`${route('admin.purchases.create')}?from=reorder`}
                            className="ms-auto text-sm font-medium text-[#6d28d9] hover:underline"
                        >
                            {t('إنشاء أمر شراء لها')} ←
                        </SmartLink>
                    </div>

                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                {['المنتج', 'SKU', 'المتبقّي', 'حد التنبيه', 'الكمية المقترحة', 'التكلفة التقديرية'].map(
                                    (h) => (
                                        <TableHead key={h}>{t(h)}</TableHead>
                                    ),
                                )}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {reorder.map((r) => (
                                <TableRow key={r.sku}>
                                    <TableCell className="font-medium text-[#111]">{r.name}</TableCell>
                                    <TableCell className="font-mono text-[#6b7280]">{r.sku}</TableCell>
                                    <TableCell>
                                        <span className={cn('font-semibold', r.qty === 0 ? 'text-[#b91c1c]' : 'text-[#d97706]')}>
                                            {number(r.qty)}
                                        </span>
                                    </TableCell>
                                    <TableCell className="text-[#6b7280]">{number(r.alert)}</TableCell>
                                    <TableCell className="font-semibold text-[#6d28d9]">
                                        {number(r.suggested)} {t('وحدة')}
                                    </TableCell>
                                    <TableCell className="tabular-nums">{m(r.suggested * r.cost)}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Card>
            )}

            {/* «لا أوامر بعد» لمن لم يكتب أمرًا — لا لمن رشّح فلم يجد: له مرشّحاتُه ليغيّرها */}
            {orders.length === 0 && !status && !filters?.from && !filters?.to && !filters?.supplier_id && !q ? (
                <Card className="px-5 py-16 text-center">
                    <ClipboardList className="mx-auto size-8 text-[#d1d5db]" />
                    <p className="mt-3 font-medium text-[#111]">{t('لا توجد أوامر شراء بعد')}</p>
                    <p className="mt-1 text-[13px] text-[#9ca3af]">
                        {t('أنشئ أول أمر شراء لتزويد مخزونك من المورّدين.')}
                    </p>
                    <Button className="mt-5" asChild>
                        <SmartLink routeName="admin.purchases.create" href={route('admin.purchases.create')}>
                            <Plus />
                            {t('أمر شراء جديد')}
                        </SmartLink>
                    </Button>
                </Card>
            ) : (
                <Card className="overflow-hidden">
                    <div className="border-b border-[var(--ui-border,#e8e8e8)] px-5 pt-5">
                        <h3 className="font-bold text-[#111]">{t('أوامر الشراء')}</h3>
                    </div>
                    <div className="px-5 pt-3">
                        <Tabs
                            current={status}
                            onChange={goStatus}
                            tabs={[{ key: '', label: 'كل الحالات' }, ...STATUSES.map((x) => ({ key: x, label: x }))]}
                        />
                        <ListDateRange
                            routeName="admin.purchases.orders"
                            params={{ status: status || null, q: typedQ.trim() || null, supplier_id: filters?.supplier_id ?? null }}
                            from={filters?.from}
                            to={filters?.to}
                            label="تاريخ الأمر"
                        />
                    </div>
                    <DataTable
                        rows={orders}
                        columns={columns}
                        rowKey={(o) => o.id}
                        searchPlaceholder="ابحث بالرقم أو المورّد…"
                        searchable={(o) => `${o.number} ${o.supplier} ${o.branch ?? ''}`}
                        initialQuery={q ?? undefined}
                        onQueryChange={search}
                        empty="لا توجد أوامر شراء بعد"
                    />
                </Card>
            )}

            <Dialog open={confirm !== null} onOpenChange={(v) => !v && setConfirm(null)}>
                <DialogContent className={cn(confirm?.kind === 'receive' ? 'sm:max-w-xl' : 'max-w-sm')}>
                    <DialogHeader>
                        <DialogTitle>
                            {confirm?.kind !== 'receive'
                                ? t('تأكيد الحذف')
                                : confirmMode === 'services'
                                  ? t('تأكيد تنفيذ الخدمة')
                                  : confirmMode === 'mixed'
                                    ? t('تأكيد الاستلام والتنفيذ')
                                    : t('تأكيد الاستلام')}
                        </DialogTitle>
                    </DialogHeader>

                    {confirm?.kind === 'receive' ? (
                        <div className="px-5 pb-5">
                            {/*
                                الكمية تُكتب لكل صنف لا تُفترض.

                                كان الزرّ يستلم الأمر كاملًا مهما وصل: مورّدٌ يشحن
                                ثمانين من مئة يُسجَّل مئةً — فيزيد المخزون عشرين لم
                                تصل، ويُحسب متوسّط التكلفة على مئةٍ دُفع ثمن ثمانين
                                منها.
                            */}
                            <p className="mb-3 text-sm text-[#4b4b4b]">
                                {t('اكتب ما وصلك من كل صنف — والباقي يبقى مفتوحًا لدفعةٍ قادمة.')}
                            </p>

                            <Table>
                                <TableHeader>
                                    <TableRow className="hover:bg-transparent">
                                        <TableHead>{t('الصنف')}</TableHead>
                                        <TableHead className="text-end">{t('المطلوب')}</TableHead>
                                        <TableHead className="text-end">{t('استُلم')}</TableHead>
                                        <TableHead className="text-end">{t('يُستلم الآن')}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {confirm.order.items.map((i) => (
                                        <TableRow key={i.id}>
                                            <TableCell className="font-medium text-[#111]">
                                                {i.name}
                                                {i.line_kind === 'service' && (
                                                    <span className="ms-2 rounded bg-[#eff6ff] px-1.5 py-0.5 text-[11px] text-[#2563eb]">
                                                        {t('خدمة — لا تدخل المخزون')}
                                                    </span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-end tabular-nums text-[#6b7280]">
                                                {number(i.quantity)}
                                            </TableCell>
                                            <TableCell className="text-end tabular-nums text-[#6b7280]">
                                                {number(i.received)}
                                            </TableCell>
                                            <TableCell className="text-end">
                                                <Input
                                                    type="number"
                                                    min={0}
                                                    max={i.remaining}
                                                    inputMode="numeric"
                                                    value={taking[i.id] ?? ''}
                                                    onChange={(e) =>
                                                        setTaking((v) => ({ ...v, [i.id]: e.target.value }))
                                                    }
                                                    className="h-9 w-24 text-end tabular-nums"
                                                    aria-label={`${t('يُستلم الآن')} — ${i.name}`}
                                                />
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>

                            <div className="mt-4 flex items-center justify-between gap-3">
                                <p className="text-[13px] text-[#6b7280]">
                                    {partial
                                        ? t('استلامٌ جزئيّ — يبقى الأمر مفتوحًا')
                                        : t('استلامٌ كامل — يُقفل الأمر')}
                                </p>
                                <div className="flex justify-end gap-2">
                                    <Button variant="outline" onClick={() => setConfirm(null)}>
                                        {t('إلغاء')}
                                    </Button>
                                    <Button variant="success" onClick={act} disabled={takingTotal <= 0}>
                                        {confirmMode === 'services' ? t('تأكيد التنفيذ') : t('استلام')}
                                    </Button>
                                </div>
                            </div>
                        </div>
                    ) : (
                        <div className="px-5 pb-5">
                            <p className="text-sm text-[#4b4b4b]">{t('حذف أمر الشراء؟')}</p>
                            <div className="mt-5 flex justify-end gap-2">
                                <Button variant="outline" onClick={() => setConfirm(null)}>
                                    {t('إلغاء')}
                                </Button>
                                <Button variant="danger" onClick={act}>
                                    {t('حذف')}
                                </Button>
                            </div>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}


/**
 * خانةُ إيصال الدفع — ثلاثُ حالاتٍ لا حالتان.
 *
 * إيصالٌ يُقرأ، وإيصالٌ موجودٌ لا يُقرأ، ولا إيصال. والثانيةُ كانت تُقرأ
 * كالثالثة: المتحكّم يُفرّغ الرابط لمن لا يملك فتحَ المرفقات، فتُرسم فوق
 * الورقة الموجودة زرُّ «رفع» — ورفعُ بديلٍ يحذف الأوّل من القرص. فيصير من
 * لا يُؤتمن على قراءتها قادرًا على محوها، ولا يعلم أنّه محا شيئًا.
 *
 * وأُفرد مكوّنًا ليُختبر في متصفّح: الصفحةُ كلُّها لا تُركَّب في jsdom بلا
 * أن يُصطنع نصفُ النظام حولها — انظر tests/js.
 */
export function ReceiptCell({ order, onUpload }: { order: PurchaseOrder; onUpload: (file: File) => void }) {
    const t = useTranslate();
    const input = useRef<HTMLInputElement | null>(null);

    if (order.receipt) {
        return (
            <a
                href={order.receipt}
                target="_blank"
                rel="noreferrer"
                title={order.receipt_name ?? ''}
                className="inline-flex items-center gap-1.5 text-sm text-[#4b4b4b] hover:underline"
            >
                <Paperclip className="size-4 text-[#9ca3af]" />
                {t('عرض')}
            </a>
        );
    }

    // ‏موجودٌ ولا يُقرأ: يُقال إنّه هناك، ولا يُعرض بابٌ يمحوه
    if (order.has_receipt) {
        return (
            <span className="inline-flex items-center gap-1.5 text-sm text-[#9ca3af]">
                <Paperclip className="size-4" />
                {t('مرفوع')}
            </span>
        );
    }

    return (
        <>
            <button
                type="button"
                onClick={() => input.current?.click()}
                className="inline-flex items-center gap-1.5 text-sm text-[#6b7280] hover:text-[#111]"
            >
                <Upload className="size-4" />
                {t('رفع')}
            </button>
            <input
                ref={input}
                type="file"
                hidden
                aria-label={t('إيصال الدفع')}
                accept=".jpg,.jpeg,.png,.pdf,.webp,.heic"
                onChange={(e) => {
                    const f = e.target.files?.[0];
                    if (f) onUpload(f);
                }}
            />
        </>
    );
}
