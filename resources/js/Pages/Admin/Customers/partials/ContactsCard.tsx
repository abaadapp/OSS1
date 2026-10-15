import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { Mail, MessageCircle, Pencil, Phone, Plus, Save, Star, Trash2, UserCheck, UserX } from 'lucide-react';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import Field from '@/Components/Field';
import { Input, Textarea } from '@/Components/ui/input';
import { useConfirm } from '@/Components/ConfirmDialog';
import { useTranslate } from '@/lib/i18n';
import type { CustomerContact } from '@/lib/contacts';

const BLANK = { name: '', job_title: '', department: '', phone: '', whatsapp: '', email: '', notes: '', is_primary: false };

/**
 * أشخاصُ اتصال الجهة — صفٌّ لكلّ شخص، وواحدٌ أساسيّ.
 *
 * والقاعدةُ في الخادم (`CustomerContacts`): الأساسيُّ يُنقل تحت قفل، والمعطَّلُ
 * لا يبقى أساسيًّا، ومن اختير في عرضٍ أو مشروعٍ يُعطَّل ولا يُحذف — فيقول
 * الخادمُ ذلك في رسالةٍ تظهر هنا.
 */
export default function ContactsCard({ customerId, contacts }: { customerId: number; contacts: CustomerContact[] }) {
    const t = useTranslate();
    const [ask, confirmDialog] = useConfirm();
    const { errors } = usePage().props as { errors?: Record<string, string> };
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<CustomerContact | null>(null);
    const form = useForm({ ...BLANK });

    const openAdd = () => {
        setEditing(null);
        form.setData({ ...BLANK, is_primary: contacts.length === 0 });
        form.clearErrors();
        setOpen(true);
    };
    const openEdit = (c: CustomerContact) => {
        setEditing(c);
        form.setData({
            name: c.name, job_title: c.job_title ?? '', department: c.department ?? '', phone: c.phone ?? '',
            whatsapp: c.whatsapp ?? '', email: c.email ?? '', notes: c.notes ?? '', is_primary: c.is_primary,
        });
        form.clearErrors();
        setOpen(true);
    };
    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const done = { preserveScroll: true, onSuccess: () => setOpen(false) };
        if (editing) {
            form.put(route('admin.customers.contacts.update', [customerId, editing.id]), done);
        } else {
            form.post(route('admin.customers.contacts.store', customerId), done);
        }
    };
    const act = (name: string, c: CustomerContact, data: Record<string, string | number> = {}) =>
        router.post(route(name, [customerId, c.id]), data, { preserveScroll: true });

    return (
        <Card className="p-6">
            <div className="mb-4 flex items-center justify-between gap-2">
                <h3 className="text-sm font-bold text-[#111]">{t('أشخاص الاتصال')}</h3>
                <Button size="sm" variant="outline" onClick={openAdd}>
                    <Plus />{t('إضافة شخص اتصال')}
                </Button>
            </div>

            {errors?.contact && <p className="mb-3 rounded-lg bg-[#fef2f2] px-3 py-2 text-[12.5px] text-[#b91c1c]">{errors.contact}</p>}

            {contacts.length === 0 ? (
                <p className="text-[13px] text-[#9ca3af]">{t('لا أشخاص اتصال بعد — أضف من تكلّمه في هذه الجهة.')}</p>
            ) : (
                <ul className="space-y-3">
                    {contacts.map((c) => (
                        <li key={c.id} className={`rounded-[12px] border border-[var(--ui-border,#e8e8e8)] p-3 ${c.active ? '' : 'opacity-60'}`}>
                            <div className="flex items-start justify-between gap-2">
                                <div className="min-w-0">
                                    <p className="truncate font-semibold text-[#111]">{c.name}</p>
                                    {(c.job_title || c.department) && (
                                        <p className="truncate text-[12px] text-[#6b7280]">{[c.job_title, c.department].filter(Boolean).join(' · ')}</p>
                                    )}
                                </div>
                                <div className="flex shrink-0 gap-1">
                                    {c.is_primary && <Badge variant="success"><Star className="size-3" />{t('أساسي')}</Badge>}
                                    {!c.active && <Badge variant="neutral">{t('معطَّل')}</Badge>}
                                </div>
                            </div>
                            <ul className="mt-2 space-y-1 text-[12.5px] text-[#4b4b4b]">
                                {c.phone && <li className="flex items-center gap-2"><Phone className="size-3.5 text-[#9ca3af]" /><span dir="ltr">{c.phone}</span></li>}
                                {c.whatsapp && c.whatsapp !== c.phone && (
                                    <li className="flex items-center gap-2"><MessageCircle className="size-3.5 text-[#9ca3af]" /><span dir="ltr">{c.whatsapp}</span></li>
                                )}
                                {c.email && <li className="flex items-center gap-2"><Mail className="size-3.5 text-[#9ca3af]" /><span dir="ltr" className="truncate">{c.email}</span></li>}
                            </ul>
                            <div className="mt-2 flex flex-wrap gap-1">
                                <Button variant="ghost" size="sm" onClick={() => openEdit(c)}><Pencil />{t('تعديل')}</Button>
                                {c.active && !c.is_primary && (
                                    <Button variant="ghost" size="sm" onClick={() => act('admin.customers.contacts.primary', c)}><Star />{t('تحديد كأساسي')}</Button>
                                )}
                                <Button variant="ghost" size="sm" onClick={() => act('admin.customers.contacts.active', c, { active: c.active ? 0 : 1 })}>
                                    {c.active ? <><UserX />{t('تعطيل')}</> : <><UserCheck />{t('تفعيل')}</>}
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="text-[#b91c1c]"
                                    onClick={async () => {
                                        if (! await ask({ message: 'حذف شخص الاتصال؟ إن كان مختارًا في مستند فعطّله بدل حذفه.', danger: true, action: 'حذف' })) return;
                                        router.delete(route('admin.customers.contacts.destroy', [customerId, c.id]), { preserveScroll: true });
                                    }}
                                >
                                    <Trash2 />{t('حذف')}
                                </Button>
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{t(editing ? 'تعديل شخص الاتصال' : 'شخص اتصال جديد')}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4 px-5 pb-5">
                        <Field label="الاسم" required error={form.errors.name}>
                            <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                        </Field>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label="المسمى الوظيفي" error={form.errors.job_title}>
                                <Input value={form.data.job_title} onChange={(e) => form.setData('job_title', e.target.value)} placeholder={t('مدير التسويق')} />
                            </Field>
                            <Field label="القسم" error={form.errors.department}>
                                <Input value={form.data.department} onChange={(e) => form.setData('department', e.target.value)} />
                            </Field>
                            <Field label="الهاتف" error={form.errors.phone}>
                                <Input dir="ltr" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} />
                            </Field>
                            <Field label="واتساب" hint="إن كان غير رقم الهاتف" error={form.errors.whatsapp}>
                                <Input dir="ltr" value={form.data.whatsapp} onChange={(e) => form.setData('whatsapp', e.target.value)} />
                            </Field>
                        </div>
                        <Field label="البريد الإلكتروني" error={form.errors.email}>
                            <Input type="email" dir="ltr" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                        </Field>
                        <Field label="ملاحظات" error={form.errors.notes}>
                            <Textarea rows={2} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                        </Field>
                        <label className="flex items-center gap-2 text-sm text-[#4b4b4b]">
                            <input type="checkbox" checked={form.data.is_primary} onChange={(e) => form.setData('is_primary', e.target.checked)} />
                            {t('شخص الاتصال الأساسي')}
                        </label>
                        <div className="flex justify-end gap-2 pt-1">
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>{t('إلغاء')}</Button>
                            <Button type="submit" loading={form.processing}><Save />{t('حفظ')}</Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            {confirmDialog}
        </Card>
    );
}
