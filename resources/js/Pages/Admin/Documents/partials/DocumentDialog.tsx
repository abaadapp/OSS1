import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { Save } from 'lucide-react';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Input, Textarea } from '@/Components/ui/input';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { useTranslate } from '@/lib/i18n';
import type { DocumentCatalog, DocumentRow } from '@/lib/documents';

const blank = (d: DocumentRow | null) => ({
    title: d?.title ?? '', category_id: d?.category_id ? String(d.category_id) : '', document_number: d?.document_number ?? '',
    issuer: d?.issuer ?? '', issued_at: d?.issued_at ?? '', expires_at: d?.expires_at ?? '', notes: d?.notes ?? '',
    link_type: d?.link_type ?? '', link_id: d?.link_id ? String(d.link_id) : '', responsible_id: d?.responsible_id ? String(d.responsible_id) : '',
});

/**
 * إضافةُ مستندٍ أو تعديلُه — والملفُّ إن أُرفق نسخةٌ جديدة لا تمحو ما قبلها.
 *
 * يُرسَل نموذجًا بملفّ (`forceFormData`) إلى `documents.store` أو `documents.update`،
 * والخادمُ يتحقّق أنّ التصنيفَ وما يُربط به والمسؤولَ من الشركة نفسِها.
 */
export default function DocumentDialog({ open, onClose, document, catalog }: { open: boolean; onClose: () => void; document: DocumentRow | null; catalog: DocumentCatalog }) {
    const t = useTranslate();
    const [form, setForm] = useState(blank(document));
    const [file, setFile] = useState<File | null>(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (open) {
            setForm(blank(document));
            setFile(null);
        }
    }, [open, document]);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        setBusy(true);
        router.post(document ? route('admin.documents.update', document.id) : route('admin.documents.store'), { ...form, ...(file ? { file } : {}) }, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => onClose(),
            onFinish: () => setBusy(false),
        });
    };

    const links = form.link_type ? catalog.links[form.link_type] ?? [] : [];

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-2xl">
                <DialogHeader><DialogTitle>{document ? t('تعديل المستند') : t('مستند جديد')}</DialogTitle></DialogHeader>
                <form onSubmit={submit} className="space-y-4 px-5 pb-2">
                    <Field label="العنوان" required>
                        <Input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} required />
                    </Field>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="التصنيف">
                            <Select value={form.category_id} placeholder="بلا تصنيف" onChange={(e) => setForm({ ...form, category_id: e.target.value })}
                                options={catalog.categories.filter((c) => c.active || String(c.id) === form.category_id).map((c) => ({ value: String(c.id), label: c.name }))} />
                        </Field>
                        <Field label="رقم المستند">
                            <Input dir="ltr" value={form.document_number} onChange={(e) => setForm({ ...form, document_number: e.target.value })} />
                        </Field>
                        <Field label="جهة الإصدار">
                            <Input value={form.issuer} onChange={(e) => setForm({ ...form, issuer: e.target.value })} />
                        </Field>
                        <Field label="المسؤول" hint="يصله تنبيه الانتهاء">
                            <Select value={form.responsible_id} placeholder="بلا مسؤول" onChange={(e) => setForm({ ...form, responsible_id: e.target.value })}
                                options={catalog.users.map((u) => ({ value: String(u.id), label: u.name }))} />
                        </Field>
                        <Field label="تاريخ الإصدار">
                            <Input type="date" dir="ltr" value={form.issued_at} onChange={(e) => setForm({ ...form, issued_at: e.target.value })} />
                        </Field>
                        <Field label="تاريخ الانتهاء" hint="فارغًا = بلا انتهاء">
                            <Input type="date" dir="ltr" value={form.expires_at} onChange={(e) => setForm({ ...form, expires_at: e.target.value })} />
                        </Field>
                        <Field label="مرتبط بـ" hint="فارغًا = الشركة نفسها">
                            <Select value={form.link_type} placeholder="الشركة نفسها" onChange={(e) => setForm({ ...form, link_type: e.target.value, link_id: '' })}
                                options={Object.entries(catalog.link_types).map(([value, label]) => ({ value, label }))} />
                        </Field>
                        {form.link_type && (
                            <Field label="اختر" required>
                                <Select value={form.link_id} placeholder="اختر" onChange={(e) => setForm({ ...form, link_id: e.target.value })}
                                    options={links.map((l) => ({ value: String(l.id), label: l.label }))} />
                            </Field>
                        )}
                    </div>
                    <Field label={document ? 'نسخة جديدة من الملف' : 'الملف'} required={!document} hint={`${catalog.extensions.join('، ').toUpperCase()} — ${t('حتى 10 ميجابايت')}`}>
                        <Input type="file" accept={catalog.extensions.map((x) => '.' + x).join(',')} onChange={(e) => setFile(e.target.files?.[0] ?? null)} required={!document} />
                    </Field>
                    <Field label="ملاحظات">
                        <Textarea rows={2} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
                    </Field>
                    <DialogFooter className="px-0">
                        <Button type="button" variant="outline" onClick={onClose}>{t('إلغاء')}</Button>
                        <Button type="submit" loading={busy}><Save />{t('حفظ')}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
