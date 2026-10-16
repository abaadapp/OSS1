import { useId } from 'react';
import Field, { Select } from '@/Components/Field';
import { useTranslate } from '@/lib/i18n';

/** ملفُّ صلاحياتٍ أساسيّ كما يعرضه الخادم — `EmployeeAccess::roleOptions` */
export interface RoleOption {
    value: string;
    label: string;
    /** أيُسنده الفاعل؟ — `Permissions::mayAssignRole`. وما لا يُسنده معطّلٌ بسببه */
    assignable: boolean;
}

/**
 * «ملف الصلاحيات الأساسي» للمسمّى الوظيفي — الدورُ الذي يحمله كلُّ من يُسند إليه.
 *
 * كان يُترك فيسقط في الخادم على «موظف مبيعات» بصمت، والشاشةُ لا تسأل عنه أصلًا.
 * وليس اسمُه «الدور»: يلتبس بالمسمّى فيظنّ صاحبُ العمل أنّه يكتب الاسمَ مرّتين.
 */
export default function AccessProfileField({
    value,
    onChange,
    options,
    error,
    disabled = false,
    note,
}: {
    value: string;
    onChange: (role: string) => void;
    options: RoleOption[];
    error?: string;
    disabled?: boolean;
    /** سببُ التعطيل أو ما يجب أن يُعرف قبل الحفظ */
    note?: string | null;
}) {
    const t = useTranslate();
    const id = useId();

    return (
        <Field
            label="ملف الصلاحيات الأساسي"
            required
            htmlFor={id}
            hint="يحدد الصلاحيات الافتراضية، ويمكن تخصيص صلاحيات الموظف لاحقًا."
            error={error}
        >
            <Select
                id={id}
                value={value}
                disabled={disabled}
                onChange={(e) => onChange(e.target.value)}
                placeholder="اختر…"
                options={options.map((o) => ({
                    label: o.assignable ? o.label : `${t(o.label)} — ${t('يفتح ما لا تفتحه')}`,
                    value: o.value,
                    disabled: !o.assignable,
                }))}
            />
            {note && <p className="mt-1.5 text-[12px] text-[#b45309]">{t(note)}</p>}
        </Field>
    );
}
