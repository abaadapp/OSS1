<?php

namespace App\Support\Import;

use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\ImportLink;
use App\Models\Product;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Model;

/**
 * فهرسُ ما في الشركة — يُبنى مرّةً لكلّ تنفيذ، ويُسأل ألفَ مرّة.
 *
 * لا استعلامَ لكلّ صفّ: العملاءُ والمورّدون والخدماتُ والمشاريعُ تُقرأ
 * دفعةً واحدةً إلى خرائطَ بالمفاتيح المعياريّة (هاتفٌ · بريدٌ · رقمٌ
 * ضريبيّ · معرِّفٌ قديم · اسمٌ). والمستنداتُ تُقرأ بمفاتيحها (رقمٌ قديم ·
 * عميلٌ وتاريخٌ ومبلغ). وما يُنشأ أثناء الاستيراد يُضاف إلى الفهرس فورًا كي
 * يجده الصفُّ التالي.
 */
final class Index
{
    /** @var array<string, array<string, array<string, list<int>>>> النوعُ ⇽ المفتاحُ ⇽ القيمةُ ⇽ معرِّفات */
    private array $keys = [];

    /** @var array<string, array<int, Model>> */
    private array $rows = [];

    /** @var array<string, bool> */
    private array $loaded = [];

    public function __construct(private readonly int $businessId) {}

    /* ─────────────────────────── التطبيع ─────────────────────────── */

    public static function name(mixed $value): ?string
    {
        $text = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $value)));
        $text = str_replace(['أ', 'إ', 'آ', 'ة', 'ى'], ['ا', 'ا', 'ا', 'ه', 'ي'], $text);
        $text = preg_replace('/[\x{064B}-\x{0652}]/u', '', $text) ?? $text;
        $text = preg_replace('/^(شركة|مؤسسة|مكتب|the|co\.?|company)\s+/u', '', $text) ?? $text;
        $text = preg_replace('/[^\p{L}\p{N} ]+/u', '', $text) ?? $text;

        return trim($text) === '' ? null : trim($text);
    }

    public static function phone(mixed $value): ?string
    {
        return PhoneNumber::normalize(is_string($value) || is_numeric($value) ? (string) $value : null);
    }

    public static function email(mixed $value): ?string
    {
        $text = mb_strtolower(trim((string) $value));

        return $text === '' ? null : $text;
    }

    public static function code(mixed $value): ?string
    {
        $text = mb_strtoupper(trim((string) preg_replace('/\s+/u', '', (string) $value)));

        return $text === '' ? null : $text;
    }

    public static function money(mixed $value): string
    {
        return number_format((float) $value, 3, '.', '');
    }

    /* ─────────────────────────── الفهرسة ─────────────────────────── */

    /** @param  array<string, string|null>  $keys  المفتاحُ ⇽ القيمةُ المعياريّة */
    public function add(string $type, Model $row, array $keys): void
    {
        $this->rows[$type][(int) $row->getKey()] = $row;

        foreach ($keys as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $this->keys[$type][$key][$value][] = (int) $row->getKey();
        }
    }

    /** @return list<int> */
    public function find(string $type, string $key, mixed $value): array
    {
        $this->load($type);

        if ($value === null || $value === '') {
            return [];
        }

        return array_values(array_unique($this->keys[$type][$key][(string) $value] ?? []));
    }

    public function get(string $type, int $id): ?Model
    {
        $this->load($type);

        return $this->rows[$type][$id] ?? null;
    }

    /** المعرِّفُ القديم (من استيرادٍ سابق) ⇽ الكيان */
    public function byExternal(string $type, mixed $external): ?Model
    {
        $ids = $this->find($type, 'external', self::code($external));

        return $ids === [] ? null : $this->get($type, $ids[0]);
    }

    public function load(string $type): void
    {
        if (isset($this->loaded[$type])) {
            return;
        }
        $this->loaded[$type] = true;
        $this->keys[$type] = [];
        $this->rows[$type] = [];

        $externals = [];
        foreach (ImportLink::where('business_id', $this->businessId)->where('linkable_type', $this->morph($type))
            ->whereNotNull('external_id')->whereNull('rolled_back_at')->get(['linkable_id', 'external_id']) as $link) {
            $externals[(int) $link->linkable_id][] = self::code($link->external_id);
        }

        match ($type) {
            'customer' => Customer::where('business_id', $this->businessId)->orderBy('id')->lazy(500)->each(function (Customer $c) use ($externals) {
                $this->add('customer', $c, ['phone' => self::phone($c->phone), 'contact_phone' => self::phone($c->contact_phone),
                    'email' => self::email($c->email), 'contact_email' => self::email($c->contact_email),
                    'tax' => self::code($c->tax_number), 'reference' => self::code($c->customer_reference),
                    'name' => self::name($c->name), 'legal' => self::name($c->legal_name), 'name_en' => self::name($c->name_en)]);
                foreach ($externals[(int) $c->id] ?? [] as $ext) {
                    $this->keys['customer']['external'][$ext][] = (int) $c->id;
                }
            }),
            'supplier' => Supplier::where('business_id', $this->businessId)->orderBy('id')->lazy(500)->each(function (Supplier $s) use ($externals) {
                $this->add('supplier', $s, ['phone' => self::phone($s->phone), 'email' => self::email($s->email),
                    'name' => self::name($s->name), 'name_en' => self::name($s->name_en)]);
                foreach ($externals[(int) $s->id] ?? [] as $ext) {
                    $this->keys['supplier']['external'][$ext][] = (int) $s->id;
                }
            }),
            'product' => Product::where('business_id', $this->businessId)->orderBy('id')->lazy(500)->each(function (Product $p) use ($externals) {
                $this->add('product', $p, ['sku' => self::code($p->sku), 'name' => self::name($p->name), 'name_en' => self::name($p->name_en)]);
                foreach ($externals[(int) $p->id] ?? [] as $ext) {
                    $this->keys['product']['external'][$ext][] = (int) $p->id;
                }
            }),
            'project' => Project::where('business_id', $this->businessId)->orderBy('id')->lazy(500)->each(function (Project $p) use ($externals) {
                $this->add('project', $p, ['code' => self::code($p->code), 'name' => self::name($p->name),
                    'name_customer' => self::name($p->name).'|'.$p->customer_id]);
                foreach ($externals[(int) $p->id] ?? [] as $ext) {
                    $this->keys['project']['external'][$ext][] = (int) $p->id;
                }
            }),
            'quotation' => Quotation::where('business_id', $this->businessId)->orderBy('id')->lazy(500)->each(function (Quotation $q) use ($externals) {
                $this->add('quotation', $q, ['number' => self::code($q->number),
                    'customer_date_total' => $q->customer_id.'|'.optional($q->issued_at)->format('Y-m-d').'|'.self::money($q->total)]);
                foreach ($externals[(int) $q->id] ?? [] as $ext) {
                    $this->keys['quotation']['external'][$ext][] = (int) $q->id;
                }
            }),
            'invoice' => CustomerInvoice::where('business_id', $this->businessId)->orderBy('id')->lazy(500)->each(function (CustomerInvoice $i) use ($externals) {
                $this->add('invoice', $i, ['number' => self::code($i->number), 'reference' => self::code($i->external_reference),
                    'customer_date_total' => $i->customer_id.'|'.optional($i->issued_at)->format('Y-m-d').'|'.self::money($i->total),
                    'opening' => $i->is_opening_balance ? (string) $i->customer_id : null]);
                foreach ($externals[(int) $i->id] ?? [] as $ext) {
                    $this->keys['invoice']['external'][$ext][] = (int) $i->id;
                }
            }),
            'payment' => CustomerPayment::where('business_id', $this->businessId)->orderBy('id')->lazy(500)->each(function (CustomerPayment $p) use ($externals) {
                $this->add('payment', $p, ['reference' => self::code($p->external_reference),
                    'customer_date_amount' => $p->customer_id.'|'.optional($p->occurred_at)->format('Y-m-d').'|'.self::money($p->amount)]);
                foreach ($externals[(int) $p->id] ?? [] as $ext) {
                    $this->keys['payment']['external'][$ext][] = (int) $p->id;
                }
            }),
            'expense' => Expense::where('business_id', $this->businessId)->orderBy('id')->lazy(500)->each(function (Expense $e) use ($externals) {
                $this->add('expense', $e, ['reference' => self::code($e->reference),
                    'type_date_amount' => self::name($e->type).'|'.optional($e->spent_at)->format('Y-m-d').'|'.self::money($e->amount)]);
                foreach ($externals[(int) $e->id] ?? [] as $ext) {
                    $this->keys['expense']['external'][$ext][] = (int) $e->id;
                }
            }),
            'supplier_invoice' => SupplierInvoice::where('business_id', $this->businessId)->orderBy('id')->lazy(500)->each(function (SupplierInvoice $s) use ($externals) {
                $this->add('supplier_invoice', $s, ['supplier_ref' => $s->supplier_id.'|'.self::code($s->supplier_ref),
                    'opening' => $s->is_opening_balance ? (string) $s->supplier_id : null]);
                foreach ($externals[(int) $s->id] ?? [] as $ext) {
                    $this->keys['supplier_invoice']['external'][$ext][] = (int) $s->id;
                }
            }),
            default => null,
        };
    }

    /** اسمُ الصنف في `import_links` */
    public function morph(string $type): string
    {
        return match ($type) {
            'customer' => (new Customer)->getMorphClass(),
            'supplier' => (new Supplier)->getMorphClass(),
            'product' => (new Product)->getMorphClass(),
            'project' => (new Project)->getMorphClass(),
            'quotation' => (new Quotation)->getMorphClass(),
            'invoice' => (new CustomerInvoice)->getMorphClass(),
            'payment' => (new CustomerPayment)->getMorphClass(),
            'expense' => (new Expense)->getMorphClass(),
            'supplier_invoice' => (new SupplierInvoice)->getMorphClass(),
            default => $type,
        };
    }

    /** كيانٌ أُنشئ للتوّ يدخل الفهرسَ بمعرِّفه القديم — كي يجده الصفُّ التالي */
    public function remember(string $type, Model $row, array $keys, ?string $external = null): void
    {
        $this->load($type);
        $this->add($type, $row, $keys + ['external' => self::code($external)]);
    }
}
