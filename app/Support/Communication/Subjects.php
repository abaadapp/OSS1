<?php

namespace App\Support\Communication;

use App\Models\Business;
use App\Models\ChangeOrder;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\DeliveryPackage;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\ScopeOfWork;
use App\Support\ChangeOrders;
use App\Support\CustomerContacts;
use App\Support\DeliveryPackages;
use App\Support\Money;
use App\Support\PhoneNumber;
use App\Support\PublicDocument;
use App\Support\Quotations;
use App\Support\ScopesOfWork;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * ما تتكلّم عنه الرسالة — وما يُعرف عنه من هنا.
 *
 * ═══ موضعٌ واحد لستّة مستندات ═══
 *
 * العرضُ والمشروعُ والفاتورةُ وحزمةُ التسليم وأمرُ التغيير والعميل: لكلٍّ
 * عميلُه ومشروعُه (إن كان)، ومن يُراسَل فيه، وبأيّ متغيّراتٍ تُملأ قوالبُه،
 * وهل يُراسَل في حاله هذه أصلًا. ولا يعرف المتحكّم ولا القناةُ شيئًا من
 * ذلك: يسألان هنا.
 *
 * ═══ ولا يُراسَل غريب ═══
 *
 * `recipients()` تعيد ما كُتب في المستند وعميله وحدَهما — بريدَ جهة
 * الاتصال، وهاتفَ العميل، ومن أُرسل إليه قبلًا. ولا تبحث في دفتر العناوين
 * كلِّه: رسالةٌ عن فاتورة وزارةٍ لا تُعرض لها أرقامُ شركةٍ أخرى.
 */
final class Subjects
{
    /** المفتاحُ القصير في المسار والشاشة ← الصنف */
    public const TYPES = [
        'quotation' => Quotation::class,
        'project' => Project::class,
        'customer_invoice' => CustomerInvoice::class,
        'delivery_package' => DeliveryPackage::class,
        'change_order' => ChangeOrder::class,
        'customer' => Customer::class,
        /* نطاقُ العمل — رابطُ توقيعه يُرسَل بريدًا أو واتساب ويُقيَّد كغيره */
        'scope_of_work' => ScopeOfWork::class,
    ];

    /** ما يُعرَض به النوعُ في الشاشة */
    public const LABELS = [
        'quotation' => 'عرض سعر',
        'project' => 'مشروع',
        'customer_invoice' => 'فاتورة',
        'delivery_package' => 'حزمة تسليم',
        'change_order' => 'أمر تغيير',
        'customer' => 'عميل',
        'scope_of_work' => 'نطاق عمل',
    ];

    /** الصنفُ من مفتاحه — أو null لما لا يُعرف */
    public static function classOf(?string $key): ?string
    {
        return self::TYPES[$key ?? ''] ?? null;
    }

    /** المفتاحُ من الصنف (أو من اسمه المخزَّن في `subject_type`) */
    public static function keyOf(Model|string|null $subject): ?string
    {
        $class = $subject instanceof Model ? $subject->getMorphClass() : $subject;

        return $class === null ? null : (array_search($class, self::TYPES, true) ?: null);
    }

    /** الموضوعُ من نوعه ومعرّفه — داخل المتجر وحدَه */
    public static function find(int $businessId, string $key, int|string $id): ?Model
    {
        $class = self::classOf($key);

        if ($class === null) {
            return null;
        }

        /** @var class-string<Model> $class */
        return $class::where('business_id', $businessId)->whereKey($id)->first();
    }

    public static function customerIdOf(?Model $subject): ?int
    {
        return match (true) {
            $subject instanceof Customer => (int) $subject->id,
            $subject instanceof ChangeOrder, $subject instanceof ScopeOfWork => self::customerIdOf($subject->project),
            $subject === null => null,
            default => filled($subject->customer_id ?? null) ? (int) $subject->customer_id : null,
        };
    }

    public static function projectIdOf(?Model $subject): ?int
    {
        return match (true) {
            $subject instanceof Project => (int) $subject->id,
            $subject instanceof Quotation => Project::where('quotation_id', $subject->id)->value('id'),
            $subject === null, $subject instanceof Customer => null,
            default => filled($subject->project_id ?? null) ? (int) $subject->project_id : null,
        };
    }

    /** اسمُ المستند كما يُقرأ في السجلّ — «عرض سعر Q-000012» */
    public static function label(?Model $subject): ?string
    {
        return match (true) {
            $subject instanceof Quotation => __('عرض سعر :label', ['label' => Quotations::label($subject)]),
            $subject instanceof Project => __('مشروع :code — :name', ['code' => $subject->code, 'name' => $subject->name]),
            $subject instanceof CustomerInvoice => __('فاتورة :number', ['number' => $subject->number ?: '#'.$subject->id]),
            $subject instanceof DeliveryPackage => __('حزمة التسليم :label', ['label' => DeliveryPackages::label($subject)]),
            $subject instanceof ChangeOrder => __('أمر التغيير :label', ['label' => ChangeOrders::label($subject)]),
            $subject instanceof ScopeOfWork => __('نطاق العمل :label', ['label' => ScopesOfWork::label($subject)]),
            $subject instanceof Customer => (string) $subject->name,
            default => null,
        };
    }

    /** مسارُ المستند في اللوحة — لتقود شاشةُ المراسلات إليه */
    public static function adminRoute(?Model $subject): ?string
    {
        return match (true) {
            $subject instanceof Quotation => route('admin.quotations.show', $subject->id),
            $subject instanceof Project => route('admin.projects.show', $subject->id),
            $subject instanceof CustomerInvoice => route('admin.customerInvoices.show', $subject->id),
            $subject instanceof DeliveryPackage => route('admin.deliveries.show', $subject->id),
            $subject instanceof ChangeOrder => route('admin.changeOrders.show', $subject->id),
            $subject instanceof ScopeOfWork => route('admin.projects.scope.show', $subject->project_id),
            $subject instanceof Customer => route('admin.customers.show', $subject->id),
            default => null,
        };
    }

    /**
     * هل يُراسَل في حاله هذه؟ — وإلّا فلماذا.
     *
     * الرسالةُ لا تُغيّر حالًا، فلا تُرسَل عن مستندٍ ليس في حالٍ تحتملها:
     * عرضٌ لم يخرج لا يُذكَّر به، وفاتورةٌ مسدَّدة لا يُطالَب بها.
     */
    public static function assertSendable(Model $subject, string $templateKey): void
    {
        $why = match (true) {
            $subject instanceof Quotation => $subject->isDecidable() ? null : ($subject->isExpired()
                ? __('انتهت صلاحية هذا العرض — نقّحه قبل أن تذكّر به.')
                : __('لا يُرسَل إلا عرضٌ خرج وينتظر قرارًا.')),
            $subject instanceof ChangeOrder => $subject->isDecidable() ? null : __('لا يُرسَل إلا أمرٌ خرج وينتظر قرارًا.'),
            /* ورابطُ نسخةٍ حلّت محلَّها أحدث لا يُرسَل — صفحتُه تقول «قديمة» ولا تقبل توقيعًا */
            $subject instanceof ScopeOfWork => $subject->isDecidable() && ! ScopesOfWork::hasNewer($subject)
                ? null : __('لا يُرسَل إلا نطاقُ عملٍ خرج وينتظر التوقيع.'),
            $subject instanceof DeliveryPackage => $subject->isDecidable() ? null : __('لا تُرسَل إلا حزمةٌ خرجت وتنتظر قرارًا.'),
            $subject instanceof CustomerInvoice => $subject->status !== CustomerInvoice::ISSUED
                ? __('لا تُرسَل إلا فاتورةٌ صادرة.')
                : ($templateKey === 'invoice.reminder' && $subject->outstanding() <= 0 ? __('الفاتورة مسدَّدة — لا يُذكَّر بها.') : null),
            $subject instanceof Project => $subject->isOpen() ? null : __('لا يُراسَل عميلُ مشروعٍ مُقفَلٍ أو ملغى.'),
            default => __('لا يُرسَل عن هذا المستند.'),
        };

        if ($why !== null) {
            throw new RuntimeException($why);
        }
    }

    /**
     * من يُراسَل في هذا المستند — بريدًا وهاتفًا، ممّا كُتب فيه وفي عميله.
     *
     * @return array{email: list<array{value: string, label: string}>, phone: list<array{value: string, label: string}>}
     */
    public static function recipients(Model $subject): array
    {
        $customer = $subject instanceof Customer ? $subject : ($subject instanceof ChangeOrder || $subject instanceof ScopeOfWork ? $subject->project?->customer : $subject->customer);
        $emails = [];
        $phones = [];

        $email = function (?string $value, string $label) use (&$emails) {
            $v = Communications::normalizeEmail($value);
            if ($v !== null && ! isset($emails[$v])) {
                $emails[$v] = ['value' => $v, 'label' => $label];
            }
        };
        $phone = function (?string $value, string $label) use (&$phones) {
            $v = PhoneNumber::normalize($value);
            if ($v !== null && ! isset($phones[$v])) {
                $phones[$v] = ['value' => $v, 'label' => $label];
            }
        };

        /* ما في المستند نفسِه أوّلًا: هو أقربُ ما كُتب لهذه الرسالة */
        if ($subject instanceof Quotation || $subject instanceof Project) {
            $email($subject->contact_email, (string) ($subject->attention_to ?? $subject->contact_person ?? __('جهة الاتصال')));
            $phone($subject->contact_phone, (string) ($subject->attention_to ?? $subject->contact_person ?? __('جهة الاتصال')));
        }

        /* ونطاقُ العمل يحمل جهةَ اتّصاله كما كُتبت فيه — أقربُ ما يكون لمن يوقّع */
        if ($subject instanceof ScopeOfWork) {
            $who = (string) ($subject->contact_person ?: __('جهة الاتصال'));
            $email($subject->contact_email, $who);
            $phone($subject->contact_phone, $who);
            $email($subject->project?->contact_email, (string) ($subject->project?->contact_person ?: __('جهة الاتصال')));
            $phone($subject->project?->contact_phone, (string) ($subject->project?->contact_person ?: __('جهة الاتصال')));
        }

        if (isset($subject->sent_to)) {
            $email($subject->sent_to, __('أُرسل إليه سابقًا'));
        }

        if ($subject instanceof Quotation || $subject instanceof DeliveryPackage || $subject instanceof ChangeOrder || $subject instanceof ScopeOfWork) {
            $approval = $subject->approval ?? null;
            if ($approval !== null) {
                $email($approval->signer_email ?? null, (string) ($approval->signer_name ?? __('من قرّر')));
                $phone($approval->signer_phone ?? null, (string) ($approval->signer_name ?? __('من قرّر')));
            }
        }

        if ($customer !== null) {
            /*
             * وأشخاصُ اتصال الجهة — النشطون، والأساسيُّ أوّلًا (المرحلة 14).
             *
             * بعد ما في المستند نفسِه لا قبله: الأتمتةُ تراسل أوّلَ عنوان، وما
             * كُتب في العرض أقربُ إلى رسالته. ورقمُ واتساب الشخص خيارٌ مستقلّ
             * حين يختلف عن هاتفه.
             */
            foreach (CustomerContacts::of($customer, true) as $c) {
                $who = $c->name.($c->job_title ? ' — '.$c->job_title : '');
                $email($c->email, $who);
                $phone($c->phone, $who);
                $phone($c->whatsapp, $who.' — '.__('واتساب'));
            }

            /* والأعمدةُ القديمة لجهةٍ لا أشخاصَ لها هنا وحدها — وإلّا عاد منها من عُطّل (`CustomerContacts::primary`) */
            if (! CustomerContacts::hasAny($customer)) {
                $email($customer->contact_email ?? null, (string) ($customer->contact_person ?: $customer->name));
                $phone($customer->contact_phone ?? null, (string) ($customer->contact_person ?: $customer->name));
            }
            $email($customer->email, (string) $customer->name);
            $phone($customer->phone, (string) $customer->name);
        }

        return ['email' => array_values($emails), 'phone' => array_values($phones)];
    }

    /**
     * متغيّراتُ القالب من المستند — قيمٌ نصّيّة جاهزة للخانات.
     *
     * والرابطُ العامّ لا يُبنى لما لم يخرج: رمزُ مسودّةٍ يفتح للعميل ورقةً
     * لم تُرسَل إليه — انظر `PublicDocument::token`. فيُترك فارغًا.
     *
     * @return array<string, string>
     */
    public static function variables(Model $subject, array $extra = []): array
    {
        $bid = (int) $subject->business_id;
        $company = (string) (Business::find($bid)?->name ?? '');
        $currency = fn () => Money::of($bid);
        $money = fn ($v) => Money::format((float) $v, $currency());
        $date = fn ($d) => $d ? Carbon::parse($d)->format('Y-m-d') : '—';

        $vars = match (true) {
            $subject instanceof Quotation => [
                'customer_name' => (string) ($subject->customer?->name ?? $subject->customer_name ?? ''),
                'contact_name' => (string) ($subject->attention_to ?: (CustomerContacts::primary($subject->customer)['name'] ?? '')),
                'label' => Quotations::label($subject),
                'title' => (string) $subject->title,
                'total' => $money($subject->total),
                'valid_until' => $date($subject->valid_until),
                'event_date' => $date($subject->event_starts_at),
                'url' => $subject->number ? (string) PublicDocument::url($subject) : '',
            ],
            $subject instanceof ChangeOrder => [
                'customer_name' => (string) ($subject->project?->customer?->name ?? ''),
                'contact_name' => (string) ($subject->project?->contact_person ?? ''),
                'label' => ChangeOrders::label($subject),
                'title' => (string) $subject->title,
                'project' => (string) ($subject->project?->name ?? ''),
                'total_delta' => $money($subject->total_delta),
                'url' => $subject->number ? (string) PublicDocument::url($subject) : '',
            ],
            $subject instanceof ScopeOfWork => [
                'customer_name' => (string) ($subject->customer_name ?: ($subject->project?->customer?->name ?? '')),
                'contact_name' => (string) ($subject->contact_person ?: ($subject->project?->contact_person ?? '')),
                'label' => ScopesOfWork::label($subject),
                'project' => (string) ($subject->project_name ?: ($subject->project?->name ?? '')),
                'revision' => (string) $subject->revision,
                'event_date' => $date($subject->starts_at),
                'url' => (string) (ScopesOfWork::url($subject) ?? ''),
            ],
            $subject instanceof DeliveryPackage => [
                'customer_name' => (string) ($subject->customer?->name ?? ''),
                'contact_name' => (string) ($subject->project?->contact_person ?? CustomerContacts::primary($subject->customer)['name'] ?? ''),
                'label' => DeliveryPackages::label($subject),
                'title' => (string) $subject->title,
                'project' => (string) ($subject->project?->name ?? ''),
                'url' => $subject->isPubliclyVisible() ? (string) DeliveryPackages::url($subject) : '',
            ],
            $subject instanceof CustomerInvoice => [
                'customer_name' => (string) ($subject->customer?->name ?? $subject->customer_name ?? ''),
                'contact_name' => (string) ($subject->attention_to ?: (CustomerContacts::primary($subject->customer)['name'] ?? '')),
                'number' => (string) ($subject->number ?: '#'.$subject->id),
                'total' => $money($subject->total),
                'outstanding' => $money($subject->outstanding()),
                'issued_at' => $date($subject->issued_at),
                'due_at' => $date($subject->due_at),
                'url' => $subject->status === CustomerInvoice::ISSUED ? (string) PublicDocument::url($subject) : '',
            ],
            $subject instanceof Project => [
                'customer_name' => (string) ($subject->customer?->name ?? ''),
                'contact_name' => (string) ($subject->contact_person ?: (CustomerContacts::primary($subject->customer)['name'] ?? '')),
                'code' => (string) $subject->code,
                'name' => (string) $subject->name,
                'starts_at' => $date($subject->starts_at),
                'ends_at' => $date($subject->ends_at),
                'location' => (string) ($subject->location ?? '—'),
                'owner' => (string) ($subject->owner?->name ?? '—'),
            ],
            $subject instanceof Customer => [
                'customer_name' => (string) $subject->name,
                'contact_name' => (string) (CustomerContacts::primary($subject)['name'] ?? ''),
            ],
            default => [],
        };

        $vars['company'] = $company;
        $vars['note'] = (string) ($extra['note'] ?? '');

        return array_map(fn ($v) => trim((string) $v), $vars + $extra);
    }
}
