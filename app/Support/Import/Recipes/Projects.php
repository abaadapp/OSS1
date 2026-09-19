<?php

namespace App\Support\Import\Recipes;

use App\Models\Project;
use App\Support\Import\Index;
use App\Support\Import\Provenance;
use App\Support\Projects as ProjectsSupport;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * المشاريعُ التاريخيّة — تدخل في **حالها** لا في دورةٍ لم تمرّ بها.
 *
 * لا عرضَ يُقبل ولا مشروعَ يُولد من قبول: يُكتب المشروعُ بمنشأ
 * `historical_import` ورمزٍ من تسلسل النظام (والرمزُ القديم أثرًا لا رمزًا)،
 * وحالُه كما كانت: مُقفَلٌ بتاريخ إقفاله، ملغًى بتاريخه وسببه، أو قائم. ولا
 * `financials_frozen` ولا تثبيتَ متطلّبات ولا موافقةَ عميل — ما لم يقع
 * لا يُخترع.
 */
final class Projects extends Recipe
{
    public function type(): string
    {
        return 'project';
    }

    public function check(array $unit): array
    {
        $r = $unit[0];
        $errors = [];
        $warnings = [];

        try {
            $this->ref('customer', $r['customer'] ?? null, __('العميل'));
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }

        if (! empty($r['starts_at']) && ! empty($r['ends_at']) && $r['ends_at'] < $r['starts_at']) {
            $errors[] = __('نهايةُ الفعاليّة قبل بدايتها');
        }
        if (($r['status'] ?? null) === Project::CLOSED && empty($r['closed_at'])) {
            $warnings[] = __('مُقفَلٌ بلا تاريخ إقفال — سيُكتب تاريخُ النهاية أو اليوم');
        }
        if (($r['status'] ?? null) === Project::CANCELLED && empty($r['cancellation_reason'])) {
            $warnings[] = __('ملغًى بلا سبب');
        }
        if (isset($r['approved_value']) && $r['approved_value'] < 0) {
            $errors[] = __('قيمةٌ سالبة');
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    public function match(array $unit): array
    {
        $r = $unit[0];
        $customerId = null;
        try {
            $customerId = (int) $this->ref('customer', $r['customer'] ?? null, __('العميل'))->id;
        } catch (RuntimeException) {
        }

        return $this->matchBy('project', [
            'external' => Index::code($r['external_id'] ?? $r['code'] ?? null),
            'code' => Index::code($r['code'] ?? null),
        ], $customerId === null ? [] : ['name_customer' => Index::name($r['name'] ?? null).'|'.$customerId]);
    }

    public function externalId(array $unit): ?string
    {
        return parent::externalId($unit) ?? (isset($unit[0]['code']) ? mb_substr((string) $unit[0]['code'], 0, 64) : null);
    }

    public function create(array $unit, int $row): Model
    {
        $r = $unit[0];
        $customer = $this->ref('customer', $r['customer'], __('العميل'));
        $status = $r['status'] ?? Project::CONFIRMED;
        $value = self::money($r['approved_value'] ?? 0);

        return Project::create([
            'business_id' => $this->ctx->businessId(),
            'customer_id' => $customer->id,
            'code' => ProjectsSupport::nextCode($this->ctx->businessId()),
            'name' => $r['name'],
            'status' => $status,
            'quotation_id' => null,
            'event_type' => $r['event_type'] ?? null,
            'location' => $r['location'] ?? null,
            'starts_at' => $r['starts_at'] ?? null,
            'ends_at' => $r['ends_at'] ?? null,
            'contact_person' => $r['contact_person'] ?? $customer->contact_person,
            'contact_phone' => $r['contact_phone'] ?? null,
            'contact_email' => $r['contact_email'] ?? null,
            'quoted_value' => $value,
            'approved_value' => $value,
            'closed_at' => $status === Project::CLOSED ? ($r['closed_at'] ?? $r['ends_at'] ?? now()->toDateString()) : null,
            'cancelled_at' => $status === Project::CANCELLED ? ($r['cancelled_at'] ?? now()->toDateString()) : null,
            'cancellation_reason' => $status === Project::CANCELLED ? ($r['cancellation_reason'] ?? __('مستورَد ملغًى')) : null,
            'notes' => $r['notes'] ?? null,
            'internal_notes' => trim(implode("\n", array_filter([
                isset($r['code']) ? __('الرمز القديم: :c', ['c' => $r['code']]) : null,
                $r['internal_notes'] ?? null,
            ]))) ?: null,
            'owner_id' => $this->ctx->actor?->id,
            'created_by' => $this->ctx->actor?->id,
            'origin' => Provenance::HISTORICAL,
        ]);
    }

    public function keys(Model $model): array
    {
        /** @var Project $model */
        return ['code' => Index::code($model->code), 'name' => Index::name($model->name), 'name_customer' => Index::name($model->name).'|'.$model->customer_id];
    }
}
