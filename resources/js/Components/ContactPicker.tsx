import { Select } from '@/Components/Field';
import { useTranslate } from '@/lib/i18n';
import { contactLabel, type CustomerContact } from '@/lib/contacts';

/**
 * اختيارُ شخص اتصالٍ من أشخاص الجهة — يملأ حقولَ المستند ولا يحلّ محلَّها.
 *
 * الحقولُ النصّيّة (موجَّهٌ إلى · الهاتف · البريد) تبقى تُكتب بيد؛ والاختيارُ
 * يملؤها ويُرسل معرّفَ الشخص مرجعًا. والخادمُ يتحقّق أنّه من هذه الجهة.
 */
export default function ContactPicker({
    contacts,
    value,
    onPick,
}: {
    contacts: CustomerContact[];
    value: string;
    onPick: (contact: CustomerContact | null) => void;
}) {
    const t = useTranslate();

    if (contacts.length === 0) {
        return null;
    }

    return (
        <Select
            value={value}
            onChange={(e) => onPick(contacts.find((c) => String(c.id) === e.target.value) ?? null)}
            options={contacts.map((c) => ({
                value: String(c.id),
                label: contactLabel(c) + (c.is_primary ? ` · ${t('أساسي')}` : ''),
            }))}
            placeholder="اختر من أشخاص الاتصال"
        />
    );
}
