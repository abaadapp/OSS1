import { useEffect, useMemo, useState } from 'react';
import { Check, ChevronDown, ChevronUp, ShieldAlert, TriangleAlert } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { cn } from '@/lib/utils';
import { useTranslate } from '@/lib/i18n';

/** مفتاحُ صلاحيةٍ كما يصفه الخادم — `EmployeeAccess::catalog` */
export interface PermissionItem {
    key: string;
    label: string;
    kind: 'section' | 'action';
    risk: 'normal' | 'sensitive';
    hint: string | null;
    /** الأقسامُ التي يُستعمل الفعلُ من شاشاتها — أيٌّ منها يكفي */
    parents: string[];
}

export interface PermissionGroup {
    key: string;
    label: string;
    sensitive: boolean;
    items: PermissionItem[];
}

/** ما يحمله ملفُّ صلاحياتٍ أساسيّ — `Permissions::roleGrants` */
export interface RoleProfile {
    label: string;
    grants: string[];
}

export type PermissionMode = 'inherited' | 'custom';

interface Props {
    catalog: PermissionGroup[];
    /** ملفُّ المسمّى المختار — أو `null` قبل اختياره */
    profile: RoleProfile | null;
    /** اسمُ المسمّى المختار — يُقال في المعاينة */
    titleName: string | null;
    /** أهو ملفُّ صاحب النشاط؟ يحمل كلَّ شيء فيُحذَّر منه */
    ownerProfile?: boolean;
    mode: PermissionMode;
    selected: string[];
    onModeChange: (mode: PermissionMode) => void;
    onSelectedChange: (keys: string[]) => void;
    /** ما يملك الفاعلُ منحَه — `Permissions::grantable`. وسواه معطّلٌ بسببه */
    grantable: string[];
    error?: string;
}

/**
 * صلاحياتُ موظّفٍ واحد — «صلاحيات الوظيفة» أو «تخصيص».
 *
 * `users.permissions = null` يعني «يتبع الوظيفة»، والقائمةُ تعني ما فيها وحده
 * (`User::allows`/`may`). وكانت الشاشةُ ترسم ثلاثًا وتسعين خانةً دفعةً واحدة
 * وتحفظ كلَّ موظّفٍ قائمةً — فصار الوضعان اختيارًا ظاهرًا، والمعاينةُ من ملفّ
 * الوظيفة نفسِه، والتخصيصُ يبدأ ممّا يملكه الموظّف لا من صفحةٍ بيضاء.
 */
export default function EmployeePermissions({
    catalog,
    profile,
    titleName,
    ownerProfile = false,
    mode,
    selected,
    onModeChange,
    onSelectedChange,
    grantable,
    error,
}: Props) {
    const t = useTranslate();
    const [advanced, setAdvanced] = useState(false);
    const [confirming, setConfirming] = useState(false);

    const labels = useMemo(
        () => Object.fromEntries(catalog.flatMap((g) => g.items.map((i) => [i.key, i.label]))),
        [catalog],
    );
    const grants = profile?.grants ?? [];

    /*
     * «مرتبطة بعمله»: ما يحمله ملفُّ وظيفته، وما كان مختارًا له حين فُتح التخصيص.
     *
     * وما اختير يبقى ظاهرًا ولو لم يكن في ملفّه — صلاحيةٌ يملكها موظّفٌ قديم لا
     * تُدفن في «المتقدّمة» فيظنّها صاحبُ العمل غير موجودة. وتُثبَّت عند الفتح لا
     * مع كلّ نقرة: مربّعٌ يُؤشَّر في «المتقدّمة» يبقى في مكانه ولا يقفز إلى أعلى
     * الصفحة من تحت إصبع صاحبه.
     */
    const [pinned, setPinned] = useState<string[]>(selected);
    useEffect(() => {
        if (mode === 'custom') setPinned(selected);
        // يُثبَّت عند الدخول إلى التخصيص وحده
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [mode]);
    const related = useMemo(() => new Set([...grants, ...pinned]), [grants, pinned]);
    // وما اختير في «المتقدّمة» يُعدّ على زرّها — فلا يختفي مختارٌ حين تُطوى
    const chosenAdvanced = selected.filter((k) => !related.has(k)).length;
    // والقسمُ الذي يُفعَّل من تنبيه فعله يظهر بين ما يرتبط بعمله
    const enable = (key: string) => {
        setPinned((list) => [...list, key]);
        onSelectedChange([...selected, key]);
    };

    const split = (pick: (i: PermissionItem) => boolean) =>
        catalog
            .map((g) => ({ ...g, items: g.items.filter(pick) }))
            .filter((g) => g.items.length > 0);

    const toggle = (key: string) =>
        onSelectedChange(selected.includes(key) ? selected.filter((k) => k !== key) : [...selected, key]);

    const choose = (next: PermissionMode) => {
        if (next === mode) return;
        // والرجوعُ يمحو التخصيص — فيُسأل قبل أن يقع
        if (next === 'inherited' && selected.length > 0) {
            setConfirming(true);
            return;
        }
        onModeChange(next);
    };

    return (
        <div className="space-y-4">
            <div role="radiogroup" aria-label={t('طريقة الصلاحيات')} className="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
                {(
                    [
                        ['inherited', 'صلاحيات الوظيفة الافتراضية', 'يأخذ ما يحمله ملف وظيفته، ويتغيّر معه.'],
                        ['custom', 'تخصيص صلاحيات هذا الموظف', 'قائمة صريحة لهذا الموظف وحده.'],
                    ] as const
                ).map(([value, label, hint]) => (
                    <label
                        key={value}
                        className={cn(
                            'flex cursor-pointer items-start gap-2.5 rounded-[12px] border p-3 transition-colors',
                            mode === value ? 'border-[#111] bg-[#fafafa]' : 'border-[var(--ui-border,#e8e8e8)] hover:bg-[#f7f7f5]',
                        )}
                    >
                        <input
                            type="radio"
                            name="permission-mode"
                            value={value}
                            checked={mode === value}
                            onChange={() => choose(value)}
                            className="mt-1 size-4 accent-[#111]"
                        />
                        <span className="min-w-0">
                            <span className="block text-sm font-semibold text-[#111]">{t(label)}</span>
                            <span className="mt-0.5 block text-[12px] text-[#6b7280]">{t(hint)}</span>
                        </span>
                    </label>
                ))}
            </div>

            {mode === 'inherited' ? (
                <InheritedPreview
                    catalog={catalog}
                    profile={profile}
                    titleName={titleName}
                    ownerProfile={ownerProfile}
                />
            ) : (
                <div className="space-y-4">
                    <p className="text-[12px] text-[#6b7280]">
                        {t('تبدأ ممّا يملكه الآن — وما تختاره هنا هو كلّ ما يفتحه، لا يُضاف إليه شيءٌ من وظيفته.')}
                    </p>

                    <GroupList
                        title="صلاحيات مرتبطة بعمله"
                        groups={split((i) => i.risk === 'normal' && related.has(i.key))}
                        selected={selected}
                        grantable={grantable}
                        labels={labels}
                        onToggle={toggle}
                        onAdd={enable}
                    />

                    <GroupList
                        title="صلاحيات حساسة"
                        sensitive
                        groups={split((i) => i.risk === 'sensitive' && related.has(i.key))}
                        selected={selected}
                        grantable={grantable}
                        labels={labels}
                        onToggle={toggle}
                        onAdd={enable}
                    />

                    {split((i) => !related.has(i.key)).length > 0 && (
                        <div className="rounded-[12px] border border-dashed border-[var(--ui-border,#e8e8e8)]">
                            <button
                                type="button"
                                aria-expanded={advanced}
                                onClick={() => setAdvanced((v) => !v)}
                                className="flex w-full items-center justify-between gap-2 px-4 py-3 text-sm font-medium text-[#374151]"
                            >
                                <span>
                                    {t(advanced ? 'إخفاء الصلاحيات المتقدمة' : 'إظهار الصلاحيات المتقدمة')}
                                    {chosenAdvanced > 0 && (
                                        <span className="ms-2 text-[12px] font-normal text-[#b45309]">
                                            {t(':n مختارة', { n: chosenAdvanced })}
                                        </span>
                                    )}
                                </span>
                                {advanced ? <ChevronUp className="size-4" /> : <ChevronDown className="size-4" />}
                            </button>
                            {advanced && (
                                <div className="space-y-4 border-t border-[var(--ui-border,#e8e8e8)] p-4" data-testid="advanced-permissions">
                                    <GroupList
                                        groups={split((i) => i.risk === 'normal' && !related.has(i.key))}
                                        selected={selected}
                                        grantable={grantable}
                                        labels={labels}
                                        onToggle={toggle}
                                        onAdd={enable}
                                    />
                                    <GroupList
                                        title="صلاحيات حساسة أخرى"
                                        sensitive
                                        groups={split((i) => i.risk === 'sensitive' && !related.has(i.key))}
                                        selected={selected}
                                        grantable={grantable}
                                        labels={labels}
                                        onToggle={toggle}
                                        onAdd={enable}
                                    />
                                </div>
                            )}
                        </div>
                    )}
                </div>
            )}

            {error && <p className="text-[12px] text-[#b91c1c]">{error}</p>}

            <Dialog open={confirming} onOpenChange={setConfirming}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('الرجوع إلى صلاحيات الوظيفة')}</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-4 px-5 pb-5">
                        <p className="text-sm text-[#4b4b4b]">
                            {t('سيتم حذف التخصيصات وسيعود الموظف إلى صلاحيات وظيفته.')}
                        </p>
                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="ghost" onClick={() => setConfirming(false)}>
                                {t('إلغاء')}
                            </Button>
                            <Button
                                type="button"
                                onClick={() => {
                                    setConfirming(false);
                                    onModeChange('inherited');
                                }}
                            >
                                {t('نعم، أعده إلى صلاحيات وظيفته')}
                            </Button>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
        </div>
    );
}

/** معاينةُ ما يحمله ملفُّ الوظيفة — مجموعاتٍ، والحسّاسُ وحده */
function InheritedPreview({
    catalog,
    profile,
    titleName,
    ownerProfile,
}: {
    catalog: PermissionGroup[];
    profile: RoleProfile | null;
    titleName: string | null;
    ownerProfile: boolean;
}) {
    const t = useTranslate();

    if (!profile) {
        return (
            <p className="rounded-[12px] border border-dashed border-[var(--ui-border,#e8e8e8)] p-4 text-center text-[13px] text-[#9ca3af]">
                {t('اختر المسمى الوظيفي لتظهر صلاحياته.')}
            </p>
        );
    }

    const groups = catalog
        .map((g) => ({ ...g, items: g.items.filter((i) => profile.grants.includes(i.key)) }))
        .filter((g) => g.items.length > 0);

    return (
        <div className="space-y-3" data-testid="inherited-preview">
            <p className="text-[13px] text-[#374151]">
                {t('صلاحيات وظيفة «:title» — ملف «:profile»:', { title: titleName ?? '', profile: profile.label })}
            </p>
            {ownerProfile && (
                <p className="flex items-start gap-2 rounded-[12px] bg-[#fffbeb] px-3 py-2.5 text-[12px] text-[#b45309]">
                    <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                    {t('هذه الوظيفة تحمل صلاحيات صاحب النشاط كاملة.')}
                </p>
            )}
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                {groups.map((g) => (
                    <div
                        key={g.key}
                        className={cn(
                            'rounded-[12px] border p-3',
                            g.sensitive ? 'border-[#fde68a] bg-[#fffbeb]' : 'border-[var(--ui-border,#e8e8e8)]',
                        )}
                    >
                        <p className="mb-2 flex items-center gap-1.5 text-[12px] font-semibold text-[#374151]">
                            {g.sensitive && <ShieldAlert className="size-3.5 text-[#b45309]" />}
                            {g.label}
                        </p>
                        <ul className="space-y-1">
                            {g.items.map((i) => (
                                <li key={i.key} className="flex items-start gap-1.5 text-[13px] text-[#4b4b4b]">
                                    <Check className="mt-0.5 size-3.5 shrink-0 text-[#047857]" />
                                    <span>{i.label}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                ))}
            </div>
            <p className="text-[12px] text-[#9ca3af]">
                {t('يحدد الصلاحيات الافتراضية، ويمكن تخصيص صلاحيات الموظف لاحقًا.')}
            </p>
        </div>
    );
}

/** مجموعاتُ مربّعات — وما لا يُمنح معطّلٌ وسببُه مكتوبٌ تحته */
function GroupList({
    title,
    sensitive = false,
    groups,
    selected,
    grantable,
    labels,
    onToggle,
    onAdd,
}: {
    title?: string;
    sensitive?: boolean;
    groups: PermissionGroup[];
    selected: string[];
    grantable: string[];
    labels: Record<string, string>;
    onToggle: (key: string) => void;
    onAdd: (key: string) => void;
}) {
    const t = useTranslate();

    if (groups.length === 0) return null;

    return (
        <section
            aria-label={title ? t(title) : undefined}
            className={cn(sensitive && 'rounded-[12px] border border-[#fde68a] bg-[#fffbeb] p-4')}
        >
            {title && (
                <h4 className="mb-1 flex items-center gap-1.5 text-[13px] font-semibold text-[#111]">
                    {sensitive && <ShieldAlert className="size-4 text-[#b45309]" />}
                    {t(title)}
                </h4>
            )}
            {sensitive && (
                <p className="mb-3 text-[12px] text-[#92400e]">
                    {t('تُخرج مالًا أو تعتمد التزامًا أو تتجاوز قرارًا — امنحها لمن يملك القرار.')}
                </p>
            )}
            <div className="space-y-4">
                {groups.map((g) => (
                    <div key={g.key}>
                        {!g.sensitive && <p className="mb-2 text-[12px] font-semibold text-[#6b7280]">{g.label}</p>}
                        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            {g.items.map((i) => {
                                const allowed = grantable.includes(i.key);
                                const on = selected.includes(i.key);
                                // فعلٌ بلا قسمه: يُمنح ولا يُوصل إليه — يُقال ويُقترح القسم
                                const missing =
                                    on && i.kind === 'action' && i.parents.length > 0 && !i.parents.some((p) => selected.includes(p))
                                        ? i.parents
                                        : [];

                                return (
                                    <div key={i.key} className={cn('rounded-[10px] px-1 py-0.5', !allowed && 'opacity-70')}>
                                        <label className={cn('flex items-start gap-2.5', allowed ? 'cursor-pointer' : 'cursor-not-allowed')}>
                                            <input
                                                type="checkbox"
                                                checked={on}
                                                disabled={!allowed}
                                                onChange={() => onToggle(i.key)}
                                                className="mt-0.5 size-4 shrink-0 rounded border-[#d1d5db] accent-[#111]"
                                            />
                                            <span className="min-w-0">
                                                <span className="block text-sm text-[#374151]">{i.label}</span>
                                                {i.hint && <span className="block text-[12px] text-[#6b7280]">{i.hint}</span>}
                                                {!allowed && (
                                                    <span className="block text-[12px] text-[#b45309]">
                                                        {t('لا تملكها — ولا تُمنح ما لا تملك.')}
                                                    </span>
                                                )}
                                            </span>
                                        </label>
                                        {missing.length > 0 && (
                                            <p className="mt-1 flex flex-wrap items-center gap-2 ps-6 text-[12px] text-[#b45309]">
                                                <span>
                                                    {t('يتطلب الوصول إلى قسم :section.', {
                                                        section: missing.map((p) => labels[p] ?? p).join(t(' أو ')),
                                                    })}
                                                </span>
                                                {grantable.includes(missing[0]) && (
                                                    <button
                                                        type="button"
                                                        onClick={() => onAdd(missing[0])}
                                                        className="font-semibold underline underline-offset-2"
                                                    >
                                                        {t('فعّل :section', { section: labels[missing[0]] ?? missing[0] })}
                                                    </button>
                                                )}
                                            </p>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                ))}
            </div>
        </section>
    );
}
