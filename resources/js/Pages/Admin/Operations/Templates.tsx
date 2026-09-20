import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { ClipboardList, Pencil, Plus, Trash2 } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import BackLink from '@/Components/BackLink';
import PageHeader from '@/Components/PageHeader';
import Field, { Select } from '@/Components/Field';
import { useConfirm } from '@/Components/ConfirmDialog';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input, Textarea } from '@/Components/ui/input';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { useTranslate } from '@/lib/i18n';
import { label, type Labels } from '@/lib/operations';

interface Template {
    id: number;
    name: string;
    phase: string;
    description: string | null;
    active: boolean;
    items: { id: number; title: string; required: boolean }[];
}

interface Props {
    templates: Template[];
    phases: string[];
    labels: Labels;
    may: { checklist: boolean };
}

interface Form {
    name: string;
    phase: string;
    description: string;
    active: boolean;
    items: { title: string; required: boolean }[];
}

/**
 * قوالبُ قوائم التحقّق — تُكتب مرّةً وتُستنسخ لكلّ فعاليّة.
 *
 * تعديلُ القالب لا يغيّر قائمةَ فعاليّةٍ جارية: أخذت نسختَها يومَ أُلحقت.
 * والقوالبُ الثلاثة الجاهزة (قبل · تجهيز · إقفال) تُلحق تلقائيًّا بكلّ
 * فعاليّةٍ جديدة ما دامت فعّالة.
 */
export default function Templates({ templates, phases, labels, may }: Props) {
    const t = useTranslate();
    const { errors } = usePage().props as { errors?: Record<string, string> };
    const [ask, dialog] = useConfirm();
    const blank: Form = { name: '', phase: 'custom', description: '', active: true, items: [{ title: '', required: true }] };
    const [editing, setEditing] = useState<{ id: number | null; form: Form } | null>(null);

    const submit = () => {
        if (!editing) return;
        const data = { ...editing.form, items: editing.form.items.filter((i) => i.title.trim() !== '') };
        const opts = { preserveScroll: true, onSuccess: () => setEditing(null) };
        editing.id === null
            ? router.post(route('admin.operations.templates.store'), data, opts)
            : router.put(route('admin.operations.templates.update', editing.id), data, opts);
    };

    const setItem = (i: number, patch: Partial<{ title: string; required: boolean }>) => {
        if (!editing) return;
        const items = editing.form.items.map((it, k) => (k === i ? { ...it, ...patch } : it));
        setEditing({ ...editing, form: { ...editing.form, items } });
    };

    return (
        <AdminLayout title="قوالب التحقّق">
            <BackLink routeName="admin.operations.index" href={route('admin.operations.index')} label="العمليات" />
            <PageHeader
                title="قوالب قوائم التحقّق"
                subtitle={t('تُستنسخ لكلّ فعاليّة — قبل الفعاليّة، والتجهيز، والإقفال، وما تضيفه')}
                actions={may.checklist && <Button onClick={() => setEditing({ id: null, form: blank })}><Plus />{t('قالب جديد')}</Button>}
            />

            {errors?.template && <div className="mb-4 rounded-lg bg-[#fef2f2] px-4 py-3 text-[13px] text-[#b91c1c]">{errors.template}</div>}

            <div className="grid gap-4 lg:grid-cols-2">
                {templates.length === 0 && <Card className="p-8 text-center text-[13px] text-[#9ca3af] lg:col-span-2"><ClipboardList className="mx-auto mb-2 size-6" />{t('لا قوالب بعد')}</Card>}
                {templates.map((tp) => (
                    <Card key={tp.id} className={'p-4 ' + (tp.active ? '' : 'opacity-60')}>
                        <div className="mb-2 flex items-start justify-between gap-2">
                            <div>
                                <h3 className="text-[14px] font-semibold">{tp.name}</h3>
                                <p className="text-[12px] text-[#9ca3af]">{label(labels, 'phase', tp.phase)} · {t(':n بندًا', { n: String(tp.items.length) })}{!tp.active && ` · ${t('موقوف')}`}</p>
                                {tp.description && <p className="mt-1 text-[12px] text-[#6b7280]">{tp.description}</p>}
                            </div>
                            {may.checklist && (
                                <div className="flex gap-1">
                                    <Button variant="ghost" size="sm" onClick={() => setEditing({ id: tp.id, form: { name: tp.name, phase: tp.phase, description: tp.description ?? '', active: tp.active, items: tp.items.map((i) => ({ title: i.title, required: i.required })) } })}><Pencil /></Button>
                                    <Button variant="ghost" size="sm" onClick={async () => { if (await ask({ message: t('حذف القالب «:name»؟ القوائم المستنسخة منه تبقى.', { name: tp.name }), danger: true, action: t('حذف') })) router.delete(route('admin.operations.templates.destroy', tp.id), { preserveScroll: true }); }}><Trash2 /></Button>
                                </div>
                            )}
                        </div>
                        <ol className="list-decimal space-y-0.5 ps-5 text-[13px]">
                            {tp.items.map((i) => (
                                <li key={i.id}>{i.title}{i.required && <span className="ms-1 rounded-full bg-[#fef2f2] px-1.5 text-[10px] text-[#b91c1c]">{t('إلزاميّ')}</span>}</li>
                            ))}
                        </ol>
                    </Card>
                ))}
            </div>

            <Dialog open={editing !== null} onOpenChange={(v) => !v && setEditing(null)}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{editing?.id === null ? t('قالب جديد') : t('تعديل القالب')}</DialogTitle>
                        <DialogDescription>{t('البنودُ الإلزاميّة تمنع الجاهزيّة أو الإتمام حتى تُنجَز أو تُعفى بسبب.')}</DialogDescription>
                    </DialogHeader>
                    {editing && (
                        <div className="max-h-[60vh] space-y-3 overflow-y-auto px-5 pb-5">
                            <div className="grid grid-cols-2 gap-3">
                                <Field label="الاسم" required><Input value={editing.form.name} onChange={(e) => setEditing({ ...editing, form: { ...editing.form, name: e.target.value } })} /></Field>
                                <Field label="المرحلة">
                                    <Select value={editing.form.phase} onChange={(e) => setEditing({ ...editing, form: { ...editing.form, phase: e.target.value } })} options={phases.map((ph) => ({ value: ph, label: label(labels, 'phase', ph) }))} />
                                </Field>
                            </div>
                            <Field label="الوصف"><Textarea rows={2} value={editing.form.description} onChange={(e) => setEditing({ ...editing, form: { ...editing.form, description: e.target.value } })} /></Field>
                            <label className="flex items-center gap-2 text-[13px]"><input type="checkbox" checked={editing.form.active} onChange={(e) => setEditing({ ...editing, form: { ...editing.form, active: e.target.checked } })} />{t('فعّال — يُلحق تلقائيًّا بالفعاليّات الجديدة')}</label>
                            <div className="space-y-2">
                                <div className="text-[13px] font-medium">{t('البنود')}</div>
                                {editing.form.items.map((it, i) => (
                                    <div key={i} className="flex items-center gap-2">
                                        <Input value={it.title} placeholder="بند" onChange={(e) => setItem(i, { title: e.target.value })} />
                                        <label className="flex shrink-0 items-center gap-1 text-[12px]"><input type="checkbox" checked={it.required} onChange={(e) => setItem(i, { required: e.target.checked })} />{t('إلزاميّ')}</label>
                                        <Button variant="ghost" size="sm" onClick={() => setEditing({ ...editing, form: { ...editing.form, items: editing.form.items.filter((_, k) => k !== i) } })}><Trash2 /></Button>
                                    </div>
                                ))}
                                <Button variant="outline" size="sm" onClick={() => setEditing({ ...editing, form: { ...editing.form, items: [...editing.form.items, { title: '', required: true }] } })}><Plus />{t('بند')}</Button>
                            </div>
                        </div>
                    )}
                    <DialogFooter>
                        <Button disabled={!editing || editing.form.name.trim() === ''} onClick={submit}>{t('حفظ')}</Button>
                        <Button variant="outline" onClick={() => setEditing(null)}>{t('إلغاء')}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            {dialog}
        </AdminLayout>
    );
}
