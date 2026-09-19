<?php

namespace App\Support\Import;

use App\Models\Product;
use App\Models\Quotation;
use App\Support\PurchaseOrders;

/**
 * القائمةُ المسموحة — ما يُربط إليه عمودٌ من ملفٍّ، ولا شيءَ سواه.
 *
 * ═══ لا `table.column` ═══
 *
 * الملفُّ لا يعرف الجداول. يعرف «اسم العميل» و«الهاتف» و«قيمة الفاتورة»،
 * وهذه القائمة تقول لكلّ صنفٍ ما الحقولُ التي تُقبل، وما نوعُ كلّ حقل،
 * وما هو إلزاميّ. والكتابةُ نفسُها تقع في `Recipes` عبر خدمات المجال —
 * فالحقلُ هنا **مدخلٌ** للوصفة لا عمودٌ في جدول.
 *
 * ═══ والمرجعُ ═══
 *
 * حقلٌ نوعُه `ref` يشير إلى كيانٍ قائم (عميلٌ · مورّد · مشروع · فاتورة):
 * قيمتُه اسمٌ أو رقمٌ أو مرجعٌ قديم، وحلُّه إلى صفٍّ عملُ `Duplicates`.
 */
final class Fields
{
    public const CATEGORIES = [
        'customers', 'contacts', 'suppliers', 'services', 'projects', 'quotations',
        'customer_invoices', 'payments', 'expenses', 'supplier_invoices', 'opening_balances',
    ];

    /** الأصنافُ التي تكتب في الدفتر — تحتاج `import.financial` واستراتيجيّةً */
    public const FINANCIAL = ['customer_invoices', 'payments', 'expenses', 'supplier_invoices', 'opening_balances'];

    /** الأصنافُ التي تُجمَّع صفوفُها في مستندٍ واحد برقمه */
    public const GROUPED = ['quotations', 'customer_invoices'];

    /** حقلُ التجميع لكلّ صنفٍ مجمَّع */
    public const GROUP_KEY = ['quotations' => 'number', 'customer_invoices' => 'number'];

    /**
     * @return array<string, array<string, mixed>> الحقلُ ⇽ وصفُه
     */
    public static function for(string $category): array
    {
        return match ($category) {
            'customers' => [
                'name' => self::f('string', true, 'اسم العميل', 255),
                'name_en' => self::f('string', false, 'الاسم بالإنجليزية', 255),
                'legal_name' => self::f('string', false, 'الاسم القانوني', 255),
                'customer_type' => self::f('enum', false, 'نوع العميل', values: ['فرد', 'شركة', 'جهة حكومية']),
                'phone' => self::f('phone', false, 'الهاتف'),
                'email' => self::f('email', false, 'البريد'),
                'tax_number' => self::f('string', false, 'الرقم الضريبي', 50),
                'commercial_registration' => self::f('string', false, 'السجل التجاري', 50),
                'contact_person' => self::f('string', false, 'جهة الاتصال', 120),
                'contact_email' => self::f('email', false, 'بريد جهة الاتصال'),
                'contact_phone' => self::f('phone', false, 'هاتف جهة الاتصال'),
                'address' => self::f('text', false, 'العنوان', 500),
                'billing_address' => self::f('text', false, 'عنوان الفوترة', 500),
                'department' => self::f('string', false, 'القسم', 120),
                'customer_reference' => self::f('string', false, 'مرجع العميل', 120),
                'payment_terms_days' => self::f('integer', false, 'مدة السداد (أيام)'),
                'notes' => self::f('text', false, 'ملاحظات', 5000),
                'external_id' => self::f('string', false, 'المعرّف القديم', 64),
            ],
            'contacts' => [
                'customer' => self::f('ref', true, 'العميل', ref: 'customer'),
                'contact_person' => self::f('string', true, 'جهة الاتصال', 120),
                'contact_email' => self::f('email', false, 'بريد جهة الاتصال'),
                'contact_phone' => self::f('phone', false, 'هاتف جهة الاتصال'),
                'department' => self::f('string', false, 'القسم', 120),
            ],
            'suppliers' => [
                'name' => self::f('string', true, 'اسم المورّد', 255),
                'name_en' => self::f('string', false, 'الاسم بالإنجليزية', 255),
                'phone' => self::f('phone', false, 'الهاتف'),
                'email' => self::f('email', false, 'البريد'),
                'contact_person' => self::f('string', false, 'جهة الاتصال', 120),
                'notes' => self::f('text', false, 'ملاحظات', 5000),
                'external_id' => self::f('string', false, 'المعرّف القديم', 64),
            ],
            'services' => [
                'name' => self::f('string', true, 'اسم الخدمة', 255),
                'name_en' => self::f('string', false, 'الاسم بالإنجليزية', 255),
                'kind' => self::f('enum', false, 'النوع', values: Product::KINDS),
                'sku' => self::f('string', false, 'الرمز', 64),
                'price' => self::f('decimal', false, 'السعر'),
                'cost' => self::f('decimal', false, 'التكلفة'),
                'tax' => self::f('decimal', false, 'نسبة الضريبة'),
                'description' => self::f('text', false, 'الوصف', 5000),
                'active' => self::f('boolean', false, 'نشط'),
                'external_id' => self::f('string', false, 'المعرّف القديم', 64),
            ],
            'projects' => [
                'code' => self::f('string', false, 'رمز المشروع القديم', 40),
                'name' => self::f('string', true, 'اسم المشروع', 255),
                'customer' => self::f('ref', true, 'العميل', ref: 'customer'),
                'status' => self::f('enum', false, 'الحالة', values: ['confirmed', 'in_progress', 'on_hold', 'closed', 'cancelled']),
                'event_type' => self::f('string', false, 'نوع الفعالية', 60),
                'location' => self::f('string', false, 'الموقع', 255),
                'starts_at' => self::f('date', false, 'تاريخ البداية'),
                'ends_at' => self::f('date', false, 'تاريخ النهاية'),
                'contact_person' => self::f('string', false, 'جهة الاتصال', 120),
                'contact_phone' => self::f('phone', false, 'هاتف جهة الاتصال'),
                'contact_email' => self::f('email', false, 'بريد جهة الاتصال'),
                'approved_value' => self::f('decimal', false, 'القيمة المعتمَدة'),
                'closed_at' => self::f('date', false, 'تاريخ الإقفال'),
                'cancelled_at' => self::f('date', false, 'تاريخ الإلغاء'),
                'cancellation_reason' => self::f('string', false, 'سبب الإلغاء', 255),
                'notes' => self::f('text', false, 'ملاحظات', 20000),
                'internal_notes' => self::f('text', false, 'ملاحظات داخلية', 20000),
                'external_id' => self::f('string', false, 'المعرّف القديم', 64),
            ],
            'quotations' => [
                'number' => self::f('string', true, 'رقم العرض القديم', 40),
                'customer' => self::f('ref', true, 'العميل', ref: 'customer'),
                'title' => self::f('string', true, 'عنوان العرض', 255),
                'status' => self::f('enum', true, 'الحالة', values: [Quotation::SENT, Quotation::ACCEPTED, Quotation::REJECTED, Quotation::CANCELLED]),
                'issued_at' => self::f('date', true, 'تاريخ الإصدار'),
                'valid_until' => self::f('date', false, 'صالح حتى'),
                'event_starts_at' => self::f('date', false, 'موعد الفعالية'),
                'event_location' => self::f('string', false, 'موقع الفعالية', 255),
                'description' => self::f('string', false, 'وصف البند', 255),
                'quantity' => self::f('decimal', false, 'الكمية'),
                'unit_price' => self::f('decimal', false, 'سعر الوحدة'),
                'tax_rate' => self::f('decimal', false, 'نسبة الضريبة'),
                'total' => self::f('decimal', false, 'الإجمالي (للمطابقة)'),
                'decided_at' => self::f('date', false, 'تاريخ القرار'),
                'decided_by_name' => self::f('string', false, 'من قرّر', 120),
                'notes' => self::f('text', false, 'ملاحظات', 5000),
                'external_id' => self::f('string', false, 'المعرّف القديم', 64),
            ],
            'customer_invoices' => [
                'number' => self::f('string', true, 'رقم الفاتورة القديم', 40),
                'customer' => self::f('ref', true, 'العميل', ref: 'customer'),
                'issued_at' => self::f('date', true, 'تاريخ الإصدار'),
                'due_at' => self::f('date', false, 'تاريخ الاستحقاق'),
                'status' => self::f('enum', false, 'الحالة', values: ['issued', 'cancelled']),
                'description' => self::f('string', false, 'وصف البند', 255),
                'quantity' => self::f('decimal', false, 'الكمية'),
                'unit_price' => self::f('decimal', false, 'سعر الوحدة'),
                'tax_rate' => self::f('decimal', false, 'نسبة الضريبة'),
                'net' => self::f('decimal', false, 'الصافي قبل الضريبة'),
                'tax' => self::f('decimal', false, 'الضريبة'),
                'total' => self::f('decimal', false, 'الإجمالي'),
                'project' => self::f('ref', false, 'المشروع', ref: 'project'),
                'po_number' => self::f('string', false, 'رقم أمر الشراء', 60),
                'contract_number' => self::f('string', false, 'رقم العقد', 60),
                'department' => self::f('string', false, 'القسم', 120),
                'notes' => self::f('text', false, 'ملاحظات', 5000),
                'external_id' => self::f('string', false, 'المعرّف القديم', 64),
            ],
            'payments' => [
                'number' => self::f('string', false, 'رقم السند القديم', 40),
                'customer' => self::f('ref', true, 'العميل', ref: 'customer'),
                'amount' => self::f('decimal', true, 'المبلغ'),
                /* ولا شيكَ تاريخيّ: شيكٌ قُبض قديمًا وصُرف ليس «تحت التحصيل» — يُستورد تحويلًا مع ملاحظة */
                'method' => self::f('enum', false, 'الوسيلة', values: ['نقدي', 'بطاقة', 'تحويل']),
                'occurred_at' => self::f('date', true, 'تاريخ التحصيل'),
                'invoice' => self::f('ref', false, 'الفاتورة', ref: 'invoice'),
                'notes' => self::f('text', false, 'ملاحظات', 5000),
                'external_id' => self::f('string', false, 'المعرّف القديم', 64),
            ],
            'expenses' => [
                'type' => self::f('string', true, 'نوع المصروف', 255),
                'description' => self::f('string', false, 'الوصف', 255),
                'amount' => self::f('decimal', true, 'المبلغ'),
                'method' => self::f('enum', false, 'الوسيلة', values: PurchaseOrders::METHODS),
                'spent_at' => self::f('date', true, 'التاريخ'),
                'due_date' => self::f('date', false, 'تاريخ الاستحقاق'),
                'status' => self::f('enum', false, 'الحالة', values: ['paid', 'unpaid']),
                'project' => self::f('ref', false, 'المشروع', ref: 'project'),
                'external_id' => self::f('string', false, 'المعرّف القديم', 64),
            ],
            'supplier_invoices' => [
                'supplier' => self::f('ref', true, 'المورّد', ref: 'supplier'),
                'supplier_ref' => self::f('string', true, 'رقم سند المورّد', 60),
                'issued_at' => self::f('date', true, 'تاريخ السند'),
                'due_at' => self::f('date', false, 'تاريخ الاستحقاق'),
                'subtotal' => self::f('decimal', true, 'الصافي قبل الضريبة'),
                'tax' => self::f('decimal', false, 'الضريبة'),
                'total' => self::f('decimal', false, 'الإجمالي (للمطابقة)'),
                'paid' => self::f('decimal', false, 'المسدَّد'),
                'status' => self::f('enum', false, 'الحالة', values: ['approved', 'pending']),
                'project' => self::f('ref', false, 'المشروع', ref: 'project'),
                'notes' => self::f('text', false, 'ملاحظات', 5000),
                'external_id' => self::f('string', false, 'المعرّف القديم', 64),
            ],
            'opening_balances' => [
                'party_type' => self::f('enum', true, 'نوع الطرف', values: ['customer', 'supplier']),
                'party' => self::f('ref', true, 'الطرف', ref: 'party'),
                'amount' => self::f('decimal', true, 'الرصيد المستحقّ'),
                'as_of' => self::f('date', false, 'حتى تاريخ'),
                'reference' => self::f('string', false, 'المرجع', 120),
                'notes' => self::f('text', false, 'ملاحظات', 2000),
                'external_id' => self::f('string', false, 'المعرّف القديم', 64),
            ],
            default => [],
        };
    }

    /** أسماءُ الحقول المقبولة لصنفٍ — للتحقّق من الخريطة */
    public static function keys(string $category): array
    {
        return array_keys(self::for($category));
    }

    /** الإلزاميُّ من حقول الصنف */
    public static function required(string $category): array
    {
        return array_keys(array_filter(self::for($category), fn ($f) => $f['required']));
    }

    public static function isFinancial(string $category): bool
    {
        return in_array($category, self::FINANCIAL, true);
    }

    public static function label(string $category): string
    {
        return __(match ($category) {
            'customers' => 'العملاء',
            'contacts' => 'جهات الاتصال',
            'suppliers' => 'المورّدون',
            'services' => 'الخدمات والمنتجات',
            'projects' => 'المشاريع والفعاليات',
            'quotations' => 'عروض الأسعار',
            'customer_invoices' => 'فواتير العملاء',
            'payments' => 'تحصيلات العملاء',
            'expenses' => 'المصروفات',
            'supplier_invoices' => 'سندات الموردين',
            'opening_balances' => 'الأرصدة الافتتاحية',
            default => $category,
        });
    }

    /** @return array<string, mixed> */
    private static function f(string $type, bool $required, string $label, ?int $max = null, ?array $values = null, ?string $ref = null): array
    {
        return ['type' => $type, 'required' => $required, 'label' => $label, 'max' => $max, 'values' => $values, 'ref' => $ref];
    }
}
