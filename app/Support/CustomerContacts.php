<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * أشخاصُ اتصال الجهة — صفٌّ لكلّ شخص، وأساسيٌّ واحد.
 *
 * ═══ الأساسيُّ واحدٌ بقيدين ═══
 *
 * فهرسٌ جزئيٌّ فريد في القاعدة (`customer_contacts_one_primary`)، وتعيينُه هنا
 * تحت قفل صفّ العميل: يُنزع عن السابق ثمّ يُوضع على اللاحق في المعاملة نفسِها،
 * فلا تمرّ لحظةٌ بأساسيَّين ولا ضغطتان تكتبان اثنين.
 *
 * ═══ وتعطيلُ الأساسيّ يُسلّمه لغيره ═══
 *
 * من عُطّل وهو الأساسيّ يُنزع عنه، ويصير الأساسيَّ أقدمُ النشطين إضافةً
 * (`successor`) في المعاملة نفسِها وتحت القفل نفسِه. فما دام في الجهة شخصٌ
 * نشط ففيها أساسيٌّ واحد، ولا تُترك بلا أساسيٍّ فتُسأل أعمدتُها القديمة.
 *
 * ═══ والأعمدةُ القديمة سندٌ لا مصدر ═══
 *
 * `contact_person` و`contact_phone` و`contact_email` على العميل بقيت لمن لم
 * يُنقل اسمُه إلى هنا — **ولمن لا صفَّ له هنا أصلًا**. فمن يسأل «من أكلّم في
 * هذه الجهة؟» يسأل `primary()`: الأساسيُّ النشط، وإلّا أقدمُ النشطين. والجهةُ
 * التي لها صفوفٌ هنا لا تُسأل أعمدتُها ولو عُطّلوا جميعًا: الاسمُ القديم فيها
 * هو غالبًا من نُقل ثمّ عُطّل، وإعادتُه من الأعمدة تُرجع المعطَّلَ من بابٍ خلفيّ.
 *
 * ═══ والحذفُ آمن ═══
 *
 * شخصٌ اختير في عرضٍ أو مشروع لا يُحذف: يُعطَّل فيبقى اسمُه في مستنداته
 * ولا يُعرض للاختيار من جديد.
 */
final class CustomerContacts
{
    /** حقولُ النصّ وحدودُها */
    public const FIELDS = [
        'name' => 120, 'job_title' => 120, 'department' => 120,
        'phone' => 40, 'whatsapp' => 40, 'email' => 120, 'notes' => 2000,
    ];

    /** @return Collection<int, CustomerContact> */
    public static function of(Customer $customer, bool $activeOnly = false): Collection
    {
        return CustomerContact::where('business_id', $customer->business_id)->where('customer_id', $customer->id)
            ->when($activeOnly, fn ($q) => $q->where('active', true))
            ->orderByDesc('is_primary')->orderByDesc('active')->orderBy('name')->get();
    }

    /**
     * إضافةُ شخصٍ أو تعديلُه — والأوّلُ في الجهة يصير أساسيًّا وحدَه.
     *
     * @param  array<string, mixed>  $data
     */
    public static function save(Customer $customer, array $data, ?User $actor = null, ?CustomerContact $contact = null): CustomerContact
    {
        $fields = [];
        foreach (self::FIELDS as $field => $max) {
            if ($contact === null || array_key_exists($field, $data)) {
                $fields[$field] = Projects::text($data[$field] ?? null, $max);
            }
        }

        if (($contact === null || array_key_exists('name', $data)) && $fields['name'] === null) {
            throw new RuntimeException(__('اكتب اسم شخص الاتصال.'));
        }

        return DB::transaction(function () use ($customer, $data, $actor, $contact, $fields) {
            $locked = Customer::where('business_id', $customer->business_id)->lockForUpdate()->findOrFail($customer->id);

            if ($contact !== null && ((int) $contact->customer_id !== (int) $locked->id || (int) $contact->business_id !== (int) $locked->business_id)) {
                throw new RuntimeException(__('هذا الشخص ليس من أشخاص اتصال هذه الجهة.'));
            }

            /* الجديدُ يصير أساسيًّا إن لم يكن في الجهة أساسيٌّ نشط */
            $first = $contact === null && ! CustomerContact::where('customer_id', $locked->id)->where('is_primary', true)->where('active', true)->exists();

            if ($contact === null) {
                $contact = CustomerContact::create($fields + [
                    'business_id' => $locked->business_id, 'customer_id' => $locked->id,
                    'active' => true, 'is_primary' => false, 'created_by' => $actor?->id, 'updated_by' => $actor?->id,
                ]);
                Activity::log('created', 'أضاف شخص الاتصال '.$contact->name.' إلى '.$locked->name, [
                    'subject_id' => $locked->id, 'subject_type' => 'customer', 'icon' => 'user-plus',
                ]);
            } else {
                $contact->update($fields + ['updated_by' => $actor?->id]);
                Activity::log('updated', 'عدّل شخص الاتصال '.$contact->name.' في '.$locked->name, [
                    'subject_id' => $locked->id, 'subject_type' => 'customer', 'icon' => 'pencil',
                ]);
            }

            if ($first || filter_var($data['is_primary'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                self::makePrimary($locked, $contact, $actor);
            }

            return $contact->fresh();
        });
    }

    /** تعيينُ الأساسيّ — يُنزع عن غيره في المعاملة نفسِها */
    public static function setPrimary(CustomerContact $contact, ?User $actor = null): CustomerContact
    {
        return DB::transaction(function () use ($contact, $actor) {
            $customer = Customer::where('business_id', $contact->business_id)->lockForUpdate()->findOrFail($contact->customer_id);

            self::makePrimary($customer, $contact->fresh(), $actor);

            return $contact->fresh();
        });
    }

    /**
     * تعطيلُه أو إعادتُه — والمعطَّلُ لا يبقى أساسيًّا.
     *
     * والأساسيُّ المعطَّل يخلفه أقدمُ النشطين في المعاملة نفسِها، والعائدُ إلى
     * جهةٍ لا أساسيَّ نشطَ فيها يصير أساسيَّها: ما دام فيها نشطٌ ففيها أساسيٌّ واحد.
     */
    public static function setActive(CustomerContact $contact, bool $active, ?User $actor = null): CustomerContact
    {
        return DB::transaction(function () use ($contact, $active, $actor) {
            $customer = Customer::where('business_id', $contact->business_id)->lockForUpdate()->findOrFail($contact->customer_id);
            $locked = CustomerContact::where('business_id', $customer->business_id)->whereKey($contact->id)->lockForUpdate()->firstOrFail();

            if ((bool) $locked->active === $active) {
                return $locked;
            }

            $wasPrimary = (bool) $locked->is_primary;
            $locked->update(['active' => $active, 'is_primary' => $active ? $locked->is_primary : false, 'updated_by' => $actor?->id]);

            Activity::log('status', ($active ? 'أعاد تفعيل' : 'عطّل').' شخص الاتصال '.$locked->name.' في '.$customer->name, [
                'subject_id' => $customer->id, 'subject_type' => 'customer', 'icon' => $active ? 'user-check' : 'user-x',
            ]);

            if (! $active && $wasPrimary) {
                self::handOver($customer, $locked, $actor);
            } elseif ($active && ! self::hasActivePrimary($customer)) {
                self::makePrimary($customer, $locked->fresh(), $actor);
            }

            return $locked->fresh();
        });
    }

    /** حذفٌ آمن — من اختير في مستندٍ يُعطَّل ولا يُحذف */
    public static function delete(CustomerContact $contact, ?User $actor = null): void
    {
        $used = Quotation::where('customer_contact_id', $contact->id)->exists()
            || Project::where('customer_contact_id', $contact->id)->exists();

        if ($used) {
            throw new RuntimeException(__('اختير هذا الشخص في عرض سعر أو مشروع — عطّله بدل حذفه ليبقى اسمه في مستنداته.'));
        }

        DB::transaction(function () use ($contact, $actor) {
            $customer = Customer::withTrashed()->where('business_id', $contact->business_id)->lockForUpdate()->find($contact->customer_id);
            $locked = CustomerContact::where('business_id', $contact->business_id)->whereKey($contact->id)->lockForUpdate()->firstOrFail();
            $name = $locked->name;
            $wasPrimary = (bool) $locked->is_primary && (bool) $locked->active;
            $locked->delete();

            Activity::log('deleted', 'حذف شخص الاتصال '.$name.' من '.($customer?->name ?? ''), [
                'subject_id' => $locked->customer_id, 'subject_type' => 'customer', 'icon' => 'user-minus',
            ]);

            /* والأساسيُّ المحذوف يخلفه أقدمُ النشطين — كالمعطَّل */
            if ($wasPrimary && $customer !== null) {
                self::handOver($customer, $locked, $actor);
            }
        });
    }

    /**
     * «من أكلّم في هذه الجهة؟» — الأساسيُّ النشط، وإلّا أقدمُ النشطين.
     *
     * والأعمدةُ القديمة على العميل لا تُسأل إلّا لجهةٍ **لا صفَّ لها** في
     * أشخاص الاتصال: من عُطّل أشخاصُها جميعًا لا يُعاد إليها اسمٌ قديم — انظر
     * رأس الصنف.
     *
     * @return array{id: int|null, name: string|null, job_title: string|null, phone: string|null, whatsapp: string|null, email: string|null}|null
     */
    public static function primary(?Customer $customer): ?array
    {
        if ($customer === null) {
            return null;
        }

        $all = $customer->relationLoaded('contacts')
            ? $customer->contacts
            : CustomerContact::where('business_id', $customer->business_id)->where('customer_id', $customer->id)->get();

        $contact = $all->first(fn (CustomerContact $c) => $c->is_primary && $c->active)
            ?? $all->where('active', true)->sortBy('id')->first();

        if ($contact !== null) {
            return [
                'id' => $contact->id, 'name' => $contact->name, 'job_title' => $contact->job_title,
                'phone' => $contact->phone, 'whatsapp' => $contact->whatsappNumber(), 'email' => $contact->email,
            ];
        }

        /* جهةٌ لها أشخاصٌ هنا — معطَّلون كلُّهم — لا يُسأل عنها عمودٌ قديم */
        if ($all->isNotEmpty()) {
            return null;
        }

        if (blank($customer->contact_person) && blank($customer->contact_phone) && blank($customer->contact_email)) {
            return null;
        }

        return [
            'id' => null, 'name' => $customer->contact_person ?: null, 'job_title' => null,
            'phone' => $customer->contact_phone ?: null, 'whatsapp' => $customer->contact_phone ?: null, 'email' => $customer->contact_email ?: null,
        ];
    }

    /**
     * شخصٌ مختارٌ لمستند — من هذه الجهة ومن هذه الشركة ونشط، أو لا شيء.
     *
     * والمعطَّلُ يُقبل إن كان هو المختارَ من قبل (`$keep`): مستندٌ قديمٌ يُعدَّل
     * لا يفقد من اختير فيه لأنّه عُطّل بعده.
     */
    public static function pick(int $businessId, ?int $customerId, mixed $contactId, ?int $keep = null): ?CustomerContact
    {
        if ($customerId === null || $contactId === null || $contactId === '' || ! is_numeric($contactId)) {
            return null;
        }

        $contact = CustomerContact::where('business_id', $businessId)->where('customer_id', $customerId)->find((int) $contactId);

        if ($contact === null) {
            throw new RuntimeException(__('هذا الشخص ليس من أشخاص اتصال هذا العميل.'));
        }
        if (! $contact->active && (int) $contact->id !== (int) $keep) {
            throw new RuntimeException(__('شخص الاتصال هذا معطَّل — اختر غيره.'));
        }

        return $contact;
    }

    /**
     * ما يُختار منه في المستندات — النشطون، والأساسيُّ أوّلًا.
     *
     * @return list<array<string, mixed>>
     */
    public static function options(Customer $customer): array
    {
        return self::of($customer, true)->map(fn (CustomerContact $c) => self::present($c))->values()->all();
    }

    /** @return array<string, mixed> */
    public static function present(CustomerContact $c): array
    {
        return [
            'id' => $c->id, 'name' => $c->name, 'job_title' => $c->job_title, 'department' => $c->department,
            'phone' => $c->phone, 'whatsapp' => $c->whatsapp, 'email' => $c->email, 'notes' => $c->notes,
            'is_primary' => (bool) $c->is_primary, 'active' => (bool) $c->active,
        ];
    }

    /** هل للجهة صفوفٌ في أشخاص الاتصال — نشطةً أو معطَّلة؟ فإن كان لم تُسأل أعمدتُها القديمة */
    public static function hasAny(Customer $customer): bool
    {
        return $customer->relationLoaded('contacts')
            ? $customer->contacts->isNotEmpty()
            : CustomerContact::where('business_id', $customer->business_id)->where('customer_id', $customer->id)->exists();
    }

    /** خليفةُ الأساسيّ — أقدمُ النشطين إضافةً، ترتيبٌ ثابتٌ لا يتغيّر بتعديل اسم */
    private static function successor(Customer $customer, ?int $except = null): ?CustomerContact
    {
        return CustomerContact::where('business_id', $customer->business_id)->where('customer_id', $customer->id)
            ->where('active', true)->when($except !== null, fn ($q) => $q->whereKeyNot($except))
            ->orderBy('id')->first();
    }

    private static function hasActivePrimary(Customer $customer): bool
    {
        return CustomerContact::where('business_id', $customer->business_id)->where('customer_id', $customer->id)
            ->where('is_primary', true)->where('active', true)->exists();
    }

    /** يُستدعى تحت قفل صفّ العميل: الأساسيُّ خرج، فيخلفه أقدمُ النشطين إن وُجد */
    private static function handOver(Customer $customer, CustomerContact $leaving, ?User $actor): void
    {
        $next = self::successor($customer, (int) $leaving->id);

        if ($next === null) {
            return;
        }

        CustomerContact::where('customer_id', $customer->id)->whereKeyNot($next->id)->where('is_primary', true)
            ->update(['is_primary' => false]);
        $next->update(['is_primary' => true, 'updated_by' => $actor?->id]);

        Activity::log('status', 'صار '.$next->name.' شخص الاتصال الأساسي في '.$customer->name.' بدل '.$leaving->name, [
            'subject_id' => $customer->id, 'subject_type' => 'customer', 'icon' => 'star',
        ]);
    }

    private static function makePrimary(Customer $customer, CustomerContact $contact, ?User $actor): void
    {
        if (! $contact->active) {
            throw new RuntimeException(__('شخص الاتصال معطَّل — فعّله قبل أن تجعله الأساسي.'));
        }
        if ($contact->is_primary) {
            return;
        }

        CustomerContact::where('customer_id', $customer->id)->whereKeyNot($contact->id)->where('is_primary', true)
            ->update(['is_primary' => false]);
        $contact->update(['is_primary' => true, 'updated_by' => $actor?->id]);

        Activity::log('status', 'جعل '.$contact->name.' شخص الاتصال الأساسي في '.$customer->name, [
            'subject_id' => $customer->id, 'subject_type' => 'customer', 'icon' => 'star',
        ]);
    }
}
