/**
 * المنظّمون الميدانيّون — أنواعُهم وألوانُ حالاتهم.
 *
 * والأسماءُ العربيّة للقوائم المغلقة (الجنس · الحال · المصدر · حالُ التعيين)
 * تصل من الخادم في `labels` — الواجهةُ لا تكتبها ولا تخمّنها.
 */
export interface OrganizerCategory {
    id: number;
    name: string;
    active?: boolean;
    organizers?: number;
}

export interface OrganizerRow {
    id: number;
    name: string;
    phone: string | null;
    whatsapp: string | null;
    email: string | null;
    gender: 'male' | 'female';
    status: 'available' | 'unavailable' | 'suspended';
    source: string | null;
    categories: { id: number; name: string }[];
    upcoming?: number;
    joined_at?: string | null;
    notes?: string | null;
}

export type OrganizerLabels = Record<'gender' | 'status' | 'source' | 'assignment', Record<string, string>>;

export const organizerTone = (status: string): string =>
    status === 'available'
        ? 'bg-[#ecfdf5] text-[#047857]'
        : status === 'suspended'
          ? 'bg-[#fef2f2] text-[#b91c1c]'
          : 'bg-[#f4f4f5] text-[#71717a]';

/** حالُ المنظّم على الفعاليّة: معيَّن · أكّد · حضر · غاب · أُلغي */
export const assignmentTone = (status: string): string =>
    status === 'attended'
        ? 'bg-[#ecfdf5] text-[#047857]'
        : status === 'confirmed'
          ? 'bg-[#eff6ff] text-[#1d4ed8]'
          : status === 'absent'
            ? 'bg-[#fef2f2] text-[#b91c1c]'
            : status === 'cancelled'
              ? 'bg-[#f4f4f5] text-[#71717a]'
              : 'bg-[#f5f3ff] text-[#6d28d9]';

/**
 * ما يُختار منه تصنيفُ تعيينٍ قائم — تصنيفاتُ المنظّم في ملفّه وحدها.
 *
 * والخادمُ يردّ غيرَها (`OperationOrganizers::categoryOf`). وتصنيفُ التعيين
 * الحاليّ يبقى خيارًا ولو نُزع من ملفّه بعده: يُقرأ ولا يُعرض غيرُه.
 */
export function assignmentCategories(row: {
    category_id: number | null;
    category: string | null;
    categories?: { id: number; name: string }[];
}): { id: number; name: string }[] {
    const his = row.categories ?? [];
    return row.category_id && row.category && !his.some((c) => c.id === row.category_id)
        ? [...his, { id: row.category_id, name: row.category }]
        : his;
}
