import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { useTranslate } from '@/lib/i18n';

/**
 * تصنيفاتٌ تُدار من الشاشة — إضافةٌ وتعطيلٌ وحذفُ ما لم يُستعمل.
 *
 * شاشةٌ واحدة لكتالوجين: تصنيفاتُ المنظّمين وخدماتُ الموردين — يختلف الباب
 * (`routes`) ولا يختلف الشكل.
 */
export default function CatalogDialog({
    open,
    onClose,
    title,
    items,
    routes,
    canEdit = true,
}: {
    open: boolean;
    onClose: () => void;
    title: string;
    items: { id: number; name: string; active?: boolean; organizers?: number }[];
    routes: { store: string; update: string; destroy: string };
    canEdit?: boolean;
}) {
    const t = useTranslate();
    const [name, setName] = useState('');

    const add = (e: React.FormEvent) => {
        e.preventDefault();
        if (!name.trim()) return;
        router.post(route(routes.store), { name }, { preserveScroll: true, onSuccess: () => setName('') });
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>{t(title)}</DialogTitle>
                </DialogHeader>
                <div className="space-y-3 px-5 pb-5">
                    {canEdit && (
                        <form onSubmit={add} className="flex gap-2">
                            <Input value={name} onChange={(e) => setName(e.target.value)} placeholder={t('اسم جديد')} />
                            <Button type="submit" size="sm"><Plus />{t('إضافة')}</Button>
                        </form>
                    )}
                    <ul className="max-h-[50vh] divide-y divide-[#f4f4f5] overflow-y-auto rounded-lg border border-[var(--ui-border,#e8e8e8)]">
                        {items.map((c) => (
                            <li key={c.id} className="flex items-center justify-between gap-2 px-3 py-2 text-[13px]">
                                <span className={c.active === false ? 'text-[#9ca3af] line-through' : 'text-[#111]'}>{c.name}</span>
                                {canEdit && (
                                    <span className="flex gap-1">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => router.put(route(routes.update, c.id), { name: c.name, active: c.active === false ? 1 : 0 }, { preserveScroll: true })}
                                        >
                                            {t(c.active === false ? 'تفعيل' : 'تعطيل')}
                                        </Button>
                                        <Button variant="ghost" size="sm" className="text-[#b91c1c]" aria-label={t('حذف')}
                                            onClick={() => router.delete(route(routes.destroy, c.id), { preserveScroll: true })}>
                                            <Trash2 />
                                        </Button>
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                </div>
            </DialogContent>
        </Dialog>
    );
}
