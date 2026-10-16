/**
 * مستنداتُ الشركة الداخليّة — أنواعُها وألوانُ حالها.
 *
 * والحالُ تصل من الخادم محسوبةً من تاريخ الانتهاء (`DocumentRecord::status`)،
 * والأسماءُ العربيّة للحال والربط في `statuses` و`link_types` — الواجهةُ لا تخمّنها.
 */
export interface DocumentRow {
    id: number;
    title: string;
    category_id: number | null;
    category: string | null;
    document_number: string | null;
    issuer: string | null;
    issued_at: string | null;
    expires_at: string | null;
    days_left: number | null;
    status: 'active' | 'expiring' | 'expired' | 'archived';
    notes: string | null;
    link_type: string | null;
    link_id: number | null;
    link_label: string | null;
    responsible_id: number | null;
    responsible: string | null;
    file_name: string | null;
    file_size: number | null;
    file_url: string | null;
    archived: boolean;
}

export interface DocumentCatalog {
    categories: { id: number; name: string; active: boolean; documents: number }[];
    statuses: Record<string, string>;
    link_types: Record<string, string>;
    users: { id: number; name: string }[];
    extensions: string[];
    links: Record<string, { id: number; label: string }[]>;
}

export const documentTone = (status: string): string =>
    status === 'expired'
        ? 'bg-[#fef2f2] text-[#b91c1c]'
        : status === 'expiring'
          ? 'bg-[#fffbeb] text-[#b45309]'
          : status === 'archived'
            ? 'bg-[#f4f4f5] text-[#71717a]'
            : 'bg-[#ecfdf5] text-[#047857]';
