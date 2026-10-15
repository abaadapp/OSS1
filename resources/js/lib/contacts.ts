/**
 * شخصُ اتصالٍ في جهة — كما يرسله `CustomerContacts::present`.
 *
 * صفٌّ لكلّ شخص، وواحدٌ أساسيّ. والمعطَّلُ لا يُعرض للاختيار في المستندات.
 */
export interface CustomerContact {
    id: number;
    name: string;
    job_title: string | null;
    department: string | null;
    phone: string | null;
    whatsapp: string | null;
    email: string | null;
    notes: string | null;
    is_primary: boolean;
    active: boolean;
}

/** «محمد أحمد — مدير التسويق» */
export function contactLabel(c: Pick<CustomerContact, 'name' | 'job_title'>): string {
    return c.job_title ? `${c.name} — ${c.job_title}` : c.name;
}
