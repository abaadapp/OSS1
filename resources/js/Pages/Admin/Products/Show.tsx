import { usePage } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import StatCard, { type Stat } from '@/Components/StatCard';
import DeleteButton from '@/Components/DeleteButton';
import SmartLink from '@/Components/SmartLink';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import type { Movement, Product } from '@/types/models';

interface Props {
    product: Product;
    stats: Stat[];
    movements: Movement[];
    description: string;
}

const KIND_LABEL: Record<string, string> = { item: 'صنف', service: 'خدمة', package: 'باقة' };

/**
 * صفحةُ الصنف أو الخدمة أو الباقة — بلا صور: خرجت من تجربة المنتج.
 */
export default function ProductShow() {
    const { product, stats, movements, description, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const currency = context!.currency;
    const stocked = (product.kind ?? 'item') === 'item';

    const facts = [
        { label: 'نوع البند', value: t(KIND_LABEL[product.kind ?? 'item'] ?? 'صنف') },
        { label: 'الكود / SKU', value: product.sku || '—' },
        { label: 'سعر التكلفة', value: money(product.cost, currency) },
        // وحدُّ التنبيه لما له رفّ — الخدمةُ والباقةُ لا تنفدان
        ...(stocked ? [{ label: 'حد التنبيه', value: `${number(product.alert)} ${t('قطعة')}` }] : []),
    ];

    return (
        <AdminLayout title="تفاصيل المنتج">
            <PageHeader
                title={product.label ?? product.name}
                subtitle={`${product.cat} · ${product.sku}`}
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <SmartLink routeName="admin.products.edit" href={route('admin.products.edit', product.id)}>
                                <Pencil />
                                {t('تعديل')}
                            </SmartLink>
                        </Button>
                        {/* الحذف يمرّ بنافذة تأكيد ثم يعيد التوجيه إلى القائمة */}
                        <DeleteButton
                            url={route('admin.products.destroy', product.id)}
                            message="حذف هذا المنتج نهائيًا؟"
                        />
                    </>
                }
            />

            <div className="mb-6">
                <Card className="p-6">
                    <div className="mb-3 flex flex-wrap items-center gap-2">
                        {stocked && <Badge status={product.stock_status}>{t(product.stock_status)}</Badge>}
                        <Badge variant={product.active ? 'success' : 'neutral'}>
                            {product.active ? t('مفعّل') : t('غير مفعّل')}
                        </Badge>
                        {product.discount > 0 && (
                            <Badge variant="primary">
                                {t('خصم')} {number(product.discount)}%
                            </Badge>
                        )}
                    </div>

                    <p className="text-sm font-medium text-[#6d28d9]">{product.cat}</p>
                    <h2 className="mt-1 text-[24px] font-bold text-[#111]">{product.label ?? product.name}</h2>

                    <div className="mt-3 flex items-baseline gap-2">
                        <span className="text-[30px] font-bold tabular-nums text-[#111]">
                            {money(product.price, currency)}
                        </span>
                        <span className="text-sm text-[#9ca3af]">
                            {t('شامل ضريبة')} {number(product.tax)}%
                        </span>
                    </div>

                    {description
                        ? <p className="mt-4 whitespace-pre-line text-sm leading-relaxed text-[#4b4b4b]">{description}</p>
                        : <p className="mt-4 text-sm text-[#9ca3af]">{t('لا وصف بعد')}</p>}

                    <dl className="mt-6 grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                        {facts.map((f) => (
                            <div key={f.label}>
                                <dt className="text-[#9ca3af]">{t(f.label)}</dt>
                                <dd className="mt-0.5 font-medium text-[#111]">{f.value}</dd>
                            </div>
                        ))}
                    </dl>
                </Card>
            </div>

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {stats.map((s, i) => (
                    <StatCard key={s.label} stat={s} index={i} />
                ))}
            </div>

            <div>
                <div className="mb-3 flex items-center justify-between">
                    <h3 className="font-bold text-[#111]">{t('حركة المخزون')}</h3>
                </div>
                <Card className="overflow-hidden">
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                {['نوع الحركة', 'الكمية', 'الموظف', 'التاريخ'].map((h) => (
                                    <TableHead key={h}>{t(h)}</TableHead>
                                ))}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {movements.length === 0 ? (
                                <TableEmpty colSpan={4}>{t('لا توجد حركات لهذا المنتج بعد')}</TableEmpty>
                            ) : (
                                movements.map((mv, i) => (
                                    <TableRow key={i}>
                                        <TableCell className="font-medium text-[#111]">{t(mv.type)}</TableCell>
                                        <TableCell>
                                            <span
                                                className={cn(
                                                    'font-medium tabular-nums',
                                                    String(mv.qty).startsWith('+')
                                                        ? 'text-[#047857]'
                                                        : 'text-[#b91c1c]',
                                                )}
                                            >
                                                {mv.qty}
                                            </span>
                                        </TableCell>
                                        <TableCell className="text-[#4b4b4b]">{mv.employee}</TableCell>
                                        <TableCell className="text-[#6b7280]">{mv.date}</TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </Card>
            </div>
        </AdminLayout>
    );
}
