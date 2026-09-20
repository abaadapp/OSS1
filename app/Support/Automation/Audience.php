<?php

namespace App\Support\Automation;

use App\Models\AssetReservation;
use App\Models\AutomationRule;
use App\Models\Communication;
use App\Models\CustomerInvoice;
use App\Models\DeliveryPackage;
use App\Models\EventOperation;
use App\Models\OperationCrewAssignment;
use App\Models\OperationIncident;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * الجمهورُ — إلى من تذهب الخطوة.
 *
 * `customer` وحدَه يخرج من النظام (بريدًا أو واتساب عبر المرحلة 6)؛ والباقي
 * موظّفون يُحلّون من الهدف نفسِه (المسؤولُ، صاحبُ المشروع، من كتب العرض،
 * من أرسل الرسالة) أو من الدور (المالية، الإدارة) أو بالاسم (قائمةُ
 * القاعدة). ولا يُحلّ أحدٌ من خارج الشركة.
 */
final class Audience
{
    public const CUSTOMER = 'customer';

    public const ASSIGNEE = 'assignee';

    public const OWNER = 'owner';

    public const CREATOR = 'creator';

    public const INITIATOR = 'initiator';

    public const FINANCE = 'finance';

    public const MANAGEMENT = 'management';

    public const CUSTOM = 'custom';

    /** قائدُ عمليات الفعاليّة — المرحلة 10 */
    public const LEAD = 'lead';

    public const ALL = [self::CUSTOMER, self::ASSIGNEE, self::OWNER, self::CREATOR, self::INITIATOR, self::FINANCE, self::MANAGEMENT, self::CUSTOM, self::LEAD];

    public const LABELS = [
        self::CUSTOMER => 'العميل',
        self::ASSIGNEE => 'المسؤول عن المهمّة',
        self::OWNER => 'صاحب المشروع',
        self::CREATOR => 'من كتب المستند',
        self::INITIATOR => 'من أرسل الرسالة',
        self::FINANCE => 'المالية',
        self::MANAGEMENT => 'الإدارة',
        self::CUSTOM => 'قائمة محدَّدة',
        self::LEAD => 'قائد عمليات الفعاليّة',
    ];

    public static function isExternal(string $audience): bool
    {
        return $audience === self::CUSTOMER;
    }

    /**
     * الموظّفون الذين يبلغهم هذا الجمهورُ عن هذا الهدف — نشطون ومن الشركة.
     *
     * @return Collection<int, User>
     */
    public static function users(int $businessId, string $audience, ?Model $target, AutomationRule $rule): Collection
    {
        $active = fn () => User::where('business_id', $businessId)->where('status', 'نشط');

        $ids = match ($audience) {
            self::ASSIGNEE => match (true) {
                $target instanceof ProjectTask => [$target->assignee_id],
                $target instanceof OperationCrewAssignment => [$target->user_id],
                $target instanceof OperationIncident => [$target->assigned_to],
                default => [],
            },
            self::LEAD => [self::operationOf($target)?->operations_lead_id],
            self::OWNER => [self::projectOf($target)?->owner_id],
            self::CREATOR => [$target->created_by ?? null],
            self::INITIATOR => $target instanceof Communication ? [$target->initiated_by] : [],
            self::CUSTOM => array_map('intval', (array) ($rule->recipients ?? [])),
            default => null,
        };

        if ($ids !== null) {
            $ids = array_values(array_filter(array_map(fn ($v) => (int) $v, $ids)));

            return $ids === [] ? collect() : $active()->whereIn('id', $ids)->get();
        }

        return match ($audience) {
            self::MANAGEMENT => $active()->whereIn('role', ['admin', 'manager'])->get(),
            self::FINANCE => $active()->get()->filter(fn (User $u) => $u->allows('finance'))->values(),
            default => collect(),
        };
    }

    /** المشروعُ الذي يتبعه الهدف — لصاحبه */
    public static function projectOf(?Model $target): ?Project
    {
        return match (true) {
            $target instanceof Project => $target,
            $target instanceof ProjectTask, $target instanceof DeliveryPackage, $target instanceof CustomerInvoice => $target->project,
            $target instanceof Quotation => Project::where('quotation_id', $target->id)->first(),
            $target instanceof EventOperation, $target instanceof AssetReservation, $target instanceof OperationCrewAssignment, $target instanceof OperationIncident => self::operationOf($target)?->project,
            default => null,
        };
    }

    /** الفعاليّةُ التي يتبعها الهدف — لقائدها (المرحلة 10) */
    public static function operationOf(?Model $target): ?EventOperation
    {
        return match (true) {
            $target instanceof EventOperation => $target,
            $target instanceof AssetReservation, $target instanceof OperationCrewAssignment, $target instanceof OperationIncident => $target->operation,
            default => null,
        };
    }

    /** الجمهورُ الذي يتلقّى «فشلت»: من يرى المشكلةَ حين يُنقَص الجمهور — الإدارة */
    public static function fallback(int $businessId): Collection
    {
        return self::users($businessId, self::MANAGEMENT, null, new AutomationRule);
    }
}
