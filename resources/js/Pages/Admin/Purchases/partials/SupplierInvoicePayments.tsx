import { useEffect, useId, useState } from 'react';
import { router } from '@inertiajs/react';
import { Paperclip, Wallet } from 'lucide-react';
import Field, { Select } from '@/Components/Field';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';

/** دفعةٌ على سند المورّد — من قيدها في الدفتر، وصفُّها إن كان (`SupplierInvoiceController::show`) */
export interface SupplierPayment {
    id: number | null;
    number: string;
    at: string | null;
    amount: number;
    from: 'cash' | 'bank' | null;
    by: string | null;
    has_receipt: boolean;
    receipt_name: string | null;
    /** رابطُ الإيصال — لمن يملك فتح المرفقات وحده */
    receipt: string | null;
    /** قيدٌ سبق جدولَ الدفعات: بلا صفٍّ ولا إيصال */
    legacy: boolean;
}

/** صيغُ الإيصال وحدُّه — سياسةُ المرفقات الماليّة نفسُها في الخادم */
export const RECEIPT_EXTENSIONS = ['jpg', 'jpeg', 'png', 'pdf', 'webp', 'heic'];
export const RECEIPT_MAX_BYTES = 10 * 1024 * 1024;

const SOURCE_LABELS: Record<string, string> = { bank: 'بنك', cash: 'نقد' };

/** مفتاحُ نموذج السداد — ضغطةٌ ثانية أو إعادةُ إرسالٍ بالمفتاح نفسِه لا تدفع مرّتين */
const newKey = (): string =>
    typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
        ? crypto.randomUUID()
        : `k-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;

/** فحصُ الإيصال قبل الإرسال — والخادمُ يفحص ثانيةً */
export function receiptProblem(file: File | null): string | null {
    if (!file) return null;
    const ext = file.name.split('.').pop()?.toLowerCase() ?? '';
    if (!RECEIPT_EXTENSIONS.includes(ext)) return 'صيغة الإيصال غير مدعومة — المقبول: PDF أو صورة (JPG، PNG، WEBP، HEIC).';
    if (file.size > RECEIPT_MAX_BYTES) return 'حجم الإيصال أكبر من 10 ميجابايت.';
    return null;
}

/**
 * «الدفعات» — كلُّ دفعةٍ سطرٌ مستقلّ بقيدها ومصدرها وإيصالها.
 *
 * والسدادُ الجزئيّ يُقال صريحًا فوقها: كم دُفع من كم، وكم بقي. وإيصالٌ لا يُفتح
 * لمن لا يملك المرفقات يُقال إنّه موجود ولا يُبنى له رابط، ودفعةٌ بلا إيصال تقول
 * ذلك خفيفًا — لا زرٌّ وهميّ.
 */
export function SupplierInvoicePaymentsCard({ payments, total, paid, outstanding, m }: {
    payments: SupplierPayment[];
    total: number;
    paid: number;
    outstanding: number;
    m: (v: number) => string;
}) {
    const t = useTranslate();
    if (payments.length === 0) return null;
    const partial = paid > 0.0005 && outstanding > 0.0005;

    return (
        <Card className="p-4 sm:p-5">
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 className="font-bold text-[#111]">{t('الدفعات')}</h2>
                <div className="flex flex-wrap items-center gap-2 text-[12.5px] text-[#6b7280]">
                    <span>{t('المدفوع :paid من :total', { paid: m(paid), total: m(total) })}</span>
                    {partial ? (
                        <Badge variant="warning">{t('سداد جزئي — المتبقّي :v', { v: m(outstanding) })}</Badge>
                    ) : outstanding <= 0.0005 ? (
                        <Badge variant="success">{t('مسدَّد بالكامل')}</Badge>
                    ) : null}
                </div>
            </div>
            <div className="overflow-x-auto">
                <table className="w-full min-w-[620px] text-[13px]">
                    <thead className="text-[12px] text-[#71717a]">
                        <tr>
                            <th className="p-2 text-start">{t('تاريخ السداد')}</th>
                            <th className="p-2 text-end">{t('المبلغ')}</th>
                            <th className="p-2 text-start">{t('المصدر')}</th>
                            <th className="p-2 text-start">{t('سجّلها')}</th>
                            <th className="p-2 text-start">{t('رقم القيد')}</th>
                            <th className="p-2 text-start">{t('الإيصال')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {payments.map((p) => (
                            <tr key={p.number} data-testid="supplier-payment" className="border-t border-[var(--ui-border,#e8e8e8)]">
                                <td className="p-2" dir="ltr">{p.at ?? '—'}</td>
                                <td className="p-2 text-end font-semibold tabular-nums">{m(p.amount)}</td>
                                <td className="p-2">{p.from ? t(SOURCE_LABELS[p.from]) : '—'}</td>
                                <td className="p-2 text-[#6b7280]">{p.by ?? '—'}</td>
                                <td className="p-2 font-mono text-[12px] text-[#6b7280]">{p.number}</td>
                                <td className="p-2">
                                    {p.receipt ? (
                                        <Button variant="outline" size="sm" asChild>
                                            <a href={p.receipt} target="_blank" rel="noreferrer" title={p.receipt_name ?? undefined}>
                                                <Paperclip />
                                                {t('عرض الإيصال')}
                                            </a>
                                        </Button>
                                    ) : p.has_receipt ? (
                                        <span className="text-[12px] text-[#9ca3af]">{t('إيصال مرفق')}</span>
                                    ) : (
                                        <span className="text-[12px] text-[#c0c4cc]">{t('لا يوجد إيصال')}</span>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </Card>
    );
}

/**
 * «تسجيل دفعة» — المبلغُ وتاريخُ السداد ومصدرُه وإيصالُه الاختياريّ.
 *
 * يُرسَل إلى `purchases.invoices.pay` نموذجًا بملفّ (`forceFormData`) حين يُرفق إيصال،
 * ومعه مفتاحٌ يتجدّد مع كلّ فتحٍ للنافذة — فضغطتان لا تدفعان مرّتين. والخادمُ هو من
 * يحكم على المستحقّ والاعتماد تحت قفل السند.
 */
export function SupplierPaymentDialog({ open, onClose, invoice, today, m, defaultAmount = '' }: {
    open: boolean;
    onClose: () => void;
    invoice: { id: number; reference: string; paid: number; outstanding: number } | null;
    today: string;
    m: (v: number) => string;
    defaultAmount?: string;
}) {
    const t = useTranslate();
    const [form, setForm] = useState({ amount: defaultAmount, paid_at: today, from: 'cash' });
    const [receipt, setReceipt] = useState<File | null>(null);
    const [key, setKey] = useState(newKey);
    const [errors, setErrors] = useState<Record<string, string>>({});
    /* كلُّ حقلٍ بتسميته — قارئُ الشاشة ينطق «المبلغ» لا «حقلُ نصّ» */
    const amountId = useId();
    const dateId = useId();
    const fromId = useId();
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (open) {
            setForm({ amount: defaultAmount, paid_at: today, from: 'cash' });
            setReceipt(null);
            setErrors({});
            setKey(newKey());
        }
    }, [open, defaultAmount, today]);

    const problem = receiptProblem(receipt);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!invoice || problem) return;
        setBusy(true);
        router.post(
            route('admin.purchases.invoices.pay', invoice.id),
            { ...form, request_key: key, ...(receipt ? { receipt } : {}) },
            {
                forceFormData: receipt !== null,
                preserveScroll: true,
                onSuccess: () => onClose(),
                onError: (errs) => setErrors(errs as Record<string, string>),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {t('تسجيل دفعة')} — {invoice?.reference}
                    </DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4 px-5 pb-5">
                    <div className="rounded-[12px] bg-[#fafafa] p-3 text-[13px] text-[#6b7280]">
                        <p className="flex justify-between">
                            <span>{t('المستحقّ')}</span>
                            <span className="font-semibold tabular-nums text-[#111]">{m(invoice?.outstanding ?? 0)}</span>
                        </p>
                        {(invoice?.paid ?? 0) > 0 && (
                            <p className="mt-1 flex justify-between text-[12px] text-[#9ca3af]">
                                <span>{t('سُدّد سابقًا')}</span>
                                <span className="tabular-nums">{m(invoice?.paid ?? 0)}</span>
                            </p>
                        )}
                    </div>

                    <Field label="المبلغ" required error={errors.amount} htmlFor={amountId}>
                        <Input id={amountId} type="number" step="0.001" min="0.001" max={invoice?.outstanding} dir="ltr" required
                            value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} />
                    </Field>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="تاريخ السداد" required error={errors.paid_at} htmlFor={dateId}>
                            <Input id={dateId} type="date" dir="ltr" required value={form.paid_at} onChange={(e) => setForm({ ...form, paid_at: e.target.value })} />
                        </Field>
                        <Field label="المصدر" required error={errors.from} htmlFor={fromId}>
                            <Select id={fromId} value={form.from} onChange={(e) => setForm({ ...form, from: e.target.value })}
                                options={[{ value: 'bank', label: 'بنك' }, { value: 'cash', label: 'نقد' }]} />
                        </Field>
                    </div>

                    <Field label="إيصال السداد (اختياري)" hint="PDF أو صورة — حتى 10 ميجابايت" error={problem ? t(problem) : errors.receipt}>
                        <Input type="file" accept={RECEIPT_EXTENSIONS.map((x) => '.' + x).join(',')} aria-label={t('إيصال السداد')}
                            onChange={(e) => setReceipt(e.target.files?.[0] ?? null)} />
                    </Field>
                    {receipt && !problem && (
                        <p className="-mt-2 flex items-center gap-1.5 text-[12px] text-[#6b7280]" data-testid="receipt-name">
                            <Paperclip className="size-3.5" />
                            <span className="truncate" dir="auto">{receipt.name}</span>
                        </p>
                    )}

                    <div className="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="ghost" onClick={onClose}>{t('إلغاء')}</Button>
                        <Button type="submit" loading={busy} disabled={problem !== null}>
                            <Wallet />
                            {t('تسجيل الدفعة')}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
