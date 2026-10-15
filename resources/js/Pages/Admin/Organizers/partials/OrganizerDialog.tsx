import { useEffect } from 'react';
import { useForm } from '@inertiajs/react';
import { Save } from 'lucide-react';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Input, Textarea } from '@/Components/ui/input';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { useTranslate } from '@/lib/i18n';
import type { OrganizerCategory, OrganizerLabels, OrganizerRow } from '@/lib/organizers';

/**
 * إضافةُ منظّمٍ أو تعديلُه — الجنسُ والحالُ والمصدرُ من قوائمَ مغلقة، والتصنيفاتُ كثيرة.
 */
export default function OrganizerDialog({
    open,
    onClose,
    organizer,
    categories,
    labels,
}: {
    open: boolean;
    onClose: () => void;
    organizer: OrganizerRow | null;
    categories: OrganizerCategory[];
    labels: OrganizerLabels;
}) {
    const t = useTranslate();
    const form = useForm({
        name: '', phone: '', whatsapp: '', email: '', gender: '', status: 'available', source: '', joined_at: '', notes: '',
        category_ids: [] as number[],
    });

    useEffect(() => {
        if (!open) return;
        form.clearErrors();
        form.setData({
            name: organizer?.name ?? '', phone: organizer?.phone ?? '', whatsapp: organizer?.whatsapp ?? '', email: organizer?.email ?? '',
            gender: organizer?.gender ?? '', status: organizer?.status ?? 'available', source: organizer?.source ?? '',
            joined_at: organizer?.joined_at ?? '', notes: organizer?.notes ?? '',
            category_ids: organizer?.categories.map((c) => c.id) ?? [],
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, organizer?.id]);

    const toggle = (id: number) =>
        form.setData('category_ids', form.data.category_ids.includes(id) ? form.data.category_ids.filter((x) => x !== id) : [...form.data.category_ids, id]);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const done = { preserveScroll: true, onSuccess: onClose };
        if (organizer) {
            form.put(route('admin.organizers.update', organizer.id), done);
        } else {
            form.post(route('admin.organizers.store'), done);
        }
    };

    const options = (group: keyof OrganizerLabels) => Object.entries(labels[group]).map(([value, label]) => ({ value, label }));

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-xl">
                <DialogHeader>
                    <DialogTitle>{t(organizer ? 'تعديل المنظّم' : 'منظّم جديد')}</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4 px-5 pb-5">
                    <Field label="الاسم" required error={form.errors.name}>
                        <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                    </Field>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="الجنس" required error={form.errors.gender}>
                            <Select value={form.data.gender} placeholder="اختر" onChange={(e) => form.setData('gender', e.target.value)} options={options('gender')} />
                        </Field>
                        <Field label="الحالة" error={form.errors.status}>
                            <Select value={form.data.status} onChange={(e) => form.setData('status', e.target.value)} options={options('status')} />
                        </Field>
                        <Field label="الهاتف" error={form.errors.phone}>
                            <Input dir="ltr" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} />
                        </Field>
                        <Field label="واتساب" hint="إن كان غير رقم الهاتف" error={form.errors.whatsapp}>
                            <Input dir="ltr" value={form.data.whatsapp} onChange={(e) => form.setData('whatsapp', e.target.value)} />
                        </Field>
                        <Field label="البريد الإلكتروني" error={form.errors.email}>
                            <Input type="email" dir="ltr" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                        </Field>
                        <Field label="النوع" error={form.errors.source}>
                            <Select value={form.data.source} placeholder="غير محدَّد" onChange={(e) => form.setData('source', e.target.value)} options={options('source')} />
                        </Field>
                        <Field label="تاريخ الانضمام" error={form.errors.joined_at}>
                            <Input type="date" value={form.data.joined_at} onChange={(e) => form.setData('joined_at', e.target.value)} />
                        </Field>
                    </div>
                    <Field label="التصنيفات" hint="يمكن أن يحمل المنظّم أكثر من تصنيف" error={form.errors.category_ids}>
                        <div className="flex flex-wrap gap-2">
                            {categories.filter((c) => c.active !== false || form.data.category_ids.includes(c.id)).map((c) => (
                                <button
                                    key={c.id}
                                    type="button"
                                    onClick={() => toggle(c.id)}
                                    className={`rounded-full border px-3 py-1 text-[12.5px] ${form.data.category_ids.includes(c.id) ? 'border-[#6d28d9] bg-[#f5f3ff] text-[#6d28d9]' : 'border-[var(--ui-border,#e8e8e8)] text-[#4b4b4b]'}`}
                                >
                                    {c.name}
                                </button>
                            ))}
                        </div>
                    </Field>
                    <Field label="ملاحظات" error={form.errors.notes}>
                        <Textarea rows={2} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                    </Field>
                    <div className="flex justify-end gap-2 pt-1">
                        <Button type="button" variant="outline" onClick={onClose}>{t('إلغاء')}</Button>
                        <Button type="submit" loading={form.processing}><Save />{t('حفظ')}</Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
