<?php

namespace App\Support\Operations;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\EventOperation;
use App\Models\OperationConsumableIssue;
use App\Models\OperationEquipmentRequirement;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Support\Activity;
use App\Support\BranchAccess;
use App\Support\Contention;
use App\Support\Ledger;
use App\Support\StockLedger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * المستهلَكاتُ تخرج من الرفّ إلى الفعاليّة وتعود منها — من باب المخزون نفسِه. المرحلة 11.
 *
 * ═══ لا محرّكَ مخزونٍ ثانيًا ═══
 *
 * الصرفُ حركةٌ سالبة عبر `StockLedger::move` (الكميّةُ ورصيدُ الفرع والحركة
 * معًا)، وصفُّ `stock_adjustments` بمشروع الفعاليّة وتكلفةِ اللحظة — وهو ما
 * تقرؤه `ProjectFinancials` تكلفةً على المشروع — وقيدٌ في الدفتر: مدين تكلفةُ
 * البضاعة المستهلَكة / دائن المخزون. والإرجاعُ عكسُ ذلك بالكميّة المُعادة
 * وبتكلفة الصرف نفسِها (لا تكلفةِ اليوم). والمستهلَك = المصروف − المُعاد.
 *
 * ═══ ولا يُعدّ مرّتين ═══
 *
 * البضاعةُ تدخل الرفَّ بسند مورّدٍ **بلا مشروع** وتُقرأ على المشروع يومَ تُصرف
 * له. ومفتاحُ التكرار على (الفعاليّة، الصنف، مفتاحُ الطلب) يردّ الضغطةَ الثانية
 * بالصفّ الأوّل. والرصيدُ لا ينزل تحت الصفر: يُفحص داخل المعاملة بعد قفل صفّ
 * الصنف.
 */
final class Consumables
{
    public const ISSUE_TYPE = 'صرف لفعاليّة';

    public const RETURN_TYPE = 'إرجاع من فعاليّة';

    public const ISSUE_REASON = 'صرف لفعاليّة';

    public const RETURN_REASON = 'إرجاع من فعاليّة';

    /**
     * الصرفُ — آمنٌ عند التكرار بمفتاح الطلب.
     *
     * @param  array{quantity: mixed, requirement_id?: mixed, branch_id?: mixed, notes?: mixed, key?: mixed}  $data
     */
    public static function issue(EventOperation $operation, Product $product, array $data, ?User $actor = null): OperationConsumableIssue
    {
        if (! $operation->isActive()) {
            throw new RuntimeException(__('لا يُصرف مستهلَكٌ لفعاليّةٍ خُتمت.'));
        }
        if ((int) $product->business_id !== (int) $operation->business_id) {
            throw new RuntimeException(__('المنتج ليس من كتالوج شركتك.'));
        }
        if (! $product->isStocked()) {
            throw new RuntimeException(__('الخدمةُ والباقةُ لا تُصرفان من الرفّ — المستهلَكُ صنفٌ مخزونٌ.'));
        }
        $quantity = (int) ($data['quantity'] ?? 0);
        if ($quantity < 1 || $quantity > 1000000) {
            throw new RuntimeException(__('كميّةُ الصرف بين 1 و1000000.'));
        }

        $key = self::key($operation, $product, $data['key'] ?? null);
        $existing = OperationConsumableIssue::where('business_id', $operation->business_id)->where('idempotency_key', $key)->first();
        if ($existing !== null) {
            return $existing; /* ضغطةٌ ثانية بالمفتاح نفسِه — لا حركةَ ثانية */
        }

        $requirement = self::requirementFor($operation, $product, $data['requirement_id'] ?? null);
        $branch = self::branch((int) $operation->business_id, $data['branch_id'] ?? null);
        $bid = (int) $operation->business_id;

        $issue = Contention::attempt(function () use ($operation, $product, $quantity, $key, $requirement, $branch, $bid, $data, $actor) {
            /* قفلُ صفّ الصنف — الفحصُ والخصمُ على رقمٍ لا يتغيّر تحتهما */
            $locked = Product::where('business_id', $bid)->lockForUpdate()->findOrFail($product->id);
            $cost = round((float) $locked->cost, 3);

            BranchStock::ensureAllocated($bid, $locked->id, (int) $locked->quantity);
            $book = $branch !== null ? BranchStock::bookOf($bid, $locked->id, $branch->id) : (int) $locked->quantity;
            if ($book < $quantity) {
                throw new RuntimeException(__('رصيد :name :n فقط — لا يُصرف أكثر منه.', ['name' => $locked->name, 'n' => $book]));
            }

            StockLedger::move($bid, $branch?->id, [$locked->id => -$quantity], self::ISSUE_TYPE, $actor?->name,
                __('صرف للفعاليّة :code', ['code' => $operation->code]));

            $adjustment = StockAdjustment::create([
                'business_id' => $bid, 'branch_id' => $branch?->id, 'product_id' => $locked->id, 'project_id' => $operation->project_id,
                'number' => StockAdjustment::nextNumber($bid), 'quantity_delta' => -$quantity, 'cost_at_time' => $cost,
                'reason' => self::ISSUE_REASON, 'notes' => __('الفعاليّة :code', ['code' => $operation->code]), 'created_by' => $actor?->id, 'adjusted_at' => now(),
            ]);

            $value = round($quantity * $cost, 3);
            if ($value > 0.0005) {
                Ledger::post($bid, __('مستهلَكات الفعاليّة :code — :name', ['code' => $operation->code, 'name' => $locked->name]), [
                    ['account' => 'cogs', 'debit' => $value, 'memo' => $locked->name],
                    ['account' => 'inventory', 'credit' => $value],
                ], now(), 'مستهلَكات فعاليّة', $branch?->id, $actor?->id, $adjustment);
            }

            $issue = OperationConsumableIssue::create([
                'business_id' => $bid, 'event_operation_id' => $operation->id, 'requirement_id' => $requirement?->id, 'product_id' => $locked->id,
                'branch_id' => $branch?->id, 'issued_quantity' => $quantity, 'returned_quantity' => 0, 'unit_cost' => $cost,
                'status' => OperationConsumableIssue::ISSUED, 'idempotency_key' => $key, 'issue_adjustment_id' => $adjustment->id,
                'issued_at' => now(), 'issued_by' => $actor?->id, 'notes' => EventOperations::text($data['notes'] ?? null, 2000),
            ]);

            if ($requirement !== null) {
                self::recount($requirement);
            }

            return $issue;
        });

        if ($issue === null) {
            /* اصطدمت بمفتاح التكرار — طلبان معًا: يُعاد الأوّل */
            return OperationConsumableIssue::where('business_id', $bid)->where('idempotency_key', $key)->firstOrFail();
        }

        Activity::log('updated', 'صرف '.$quantity.' × '.$product->name.' للفعاليّة '.$operation->code, [
            'subject_id' => $operation->id, 'subject_type' => 'event_operation', 'icon' => 'package-minus',
        ]);

        return $issue;
    }

    /**
     * الإرجاعُ — ما لم يُستهلك يعود إلى الرفّ بتكلفة صرفه. آمنٌ عند التكرار.
     *
     * @param  array{quantity: mixed, notes?: mixed}  $data
     */
    public static function giveBack(OperationConsumableIssue $issue, array $data, ?User $actor = null): OperationConsumableIssue
    {
        $quantity = (int) ($data['quantity'] ?? 0);
        if ($quantity < 0) {
            throw new RuntimeException(__('كميّة الإرجاع لا تكون سالبة.'));
        }

        return DB::transaction(function () use ($issue, $quantity, $data, $actor) {
            $locked = OperationConsumableIssue::where('business_id', $issue->business_id)->lockForUpdate()->findOrFail($issue->id);

            if ($locked->status === OperationConsumableIssue::CLOSED) {
                return $locked; /* أُقفل من قبل — الردُّ الثاني لا يكتب */
            }
            if ($quantity > (int) $locked->issued_quantity) {
                throw new RuntimeException(__('لا يُعاد أكثر ممّا صُرف (:n).', ['n' => $locked->issued_quantity]));
            }

            $bid = (int) $locked->business_id;
            $product = Product::where('business_id', $bid)->lockForUpdate()->findOrFail($locked->product_id);
            $cost = (float) $locked->unit_cost;
            $adjustment = null;

            if ($quantity > 0) {
                StockLedger::move($bid, $locked->branch_id, [$product->id => $quantity], self::RETURN_TYPE, $actor?->name,
                    __('إرجاع من الفعاليّة :code', ['code' => (string) $locked->operation?->code]));

                $adjustment = StockAdjustment::create([
                    'business_id' => $bid, 'branch_id' => $locked->branch_id, 'product_id' => $product->id, 'project_id' => $locked->operation?->project_id,
                    'number' => StockAdjustment::nextNumber($bid), 'quantity_delta' => $quantity, 'cost_at_time' => $cost,
                    'reason' => self::RETURN_REASON, 'notes' => __('الفعاليّة :code', ['code' => (string) $locked->operation?->code]), 'created_by' => $actor?->id, 'adjusted_at' => now(),
                ]);

                $value = round($quantity * $cost, 3);
                if ($value > 0.0005) {
                    Ledger::post($bid, __('إرجاع مستهلَكات الفعاليّة :code — :name', ['code' => (string) $locked->operation?->code, 'name' => $product->name]), [
                        ['account' => 'inventory', 'debit' => $value, 'memo' => $product->name],
                        ['account' => 'cogs', 'credit' => $value],
                    ], now(), 'مستهلَكات فعاليّة', $locked->branch_id, $actor?->id, $adjustment);
                }
            }

            $locked->update([
                'returned_quantity' => $quantity, 'status' => OperationConsumableIssue::CLOSED, 'return_adjustment_id' => $adjustment?->id,
                'returned_at' => now(), 'returned_by' => $actor?->id,
                'notes' => EventOperations::text($data['notes'] ?? null, 2000) ?? $locked->notes,
            ]);

            if ($locked->requirement_id !== null && $locked->requirement !== null) {
                self::recount($locked->requirement);
            }

            Activity::log('return', 'أُعيد '.$quantity.' من '.$locked->issued_quantity.' × '.$product->name.' من الفعاليّة '.(string) $locked->operation?->code.' — استُهلك '.($locked->issued_quantity - $quantity), [
                'subject_id' => $locked->event_operation_id, 'subject_type' => 'event_operation', 'icon' => 'package-plus', 'color' => 'info',
            ]);

            return $locked->fresh();
        });
    }

    /** إعادةُ عدّ المصروف والمُعاد على المتطلَّب */
    public static function recount(OperationEquipmentRequirement $requirement): void
    {
        $row = OperationConsumableIssue::where('requirement_id', $requirement->id)->toBase()
            ->selectRaw('COALESCE(SUM(issued_quantity), 0) AS i, COALESCE(SUM(returned_quantity), 0) AS r')->first();
        $issued = (int) ($row->i ?? 0);
        $returned = (int) ($row->r ?? 0);

        $requirement->update([
            'issued_quantity' => $issued, 'returned_quantity' => $returned,
            /* والمُعَدُّ من صنفِ مخزونٍ هو ما صُرف له فعلًا — لا يُكتب بيد بعد أوّل صرف */
            'assigned_quantity' => $requirement->kind === OperationEquipmentRequirement::PRODUCT ? max((int) $requirement->assigned_quantity, $issued - $returned) : $requirement->assigned_quantity,
        ]);
    }

    /**
     * ما صُرف واستُهلك وعاد للفعاليّة — بالصنف وبالتكلفة.
     *
     * @return array{rows: list<array<string, mixed>>, issued_cost: float, consumed_cost: float, returned_cost: float}
     */
    public static function summary(EventOperation $operation): array
    {
        $issues = OperationConsumableIssue::where('event_operation_id', $operation->id)->with('product:id,name,sku', 'requirement:id,description')->orderBy('id')->get();
        /*
         * والصرفُ يبقى صرفَ الفعاليّة كلِّه بتكلفته — هو تكلفةُ المشروع لا ورقةُ فرع.
         * لكنّ الإرجاعَ يُعيد الكميّة إلى فرع الصرف، فلا يُعرض زرُّه لمن قُيّد بفروعٍ
         * على صرفٍ من فرعٍ ليس من فروعه — والخادمُ يردّه أيضًا (EventOperationController).
         */
        $user = auth()->user();
        $mine = BranchAccess::restricts($user) ? BranchAccess::allowedBranchIds($user) : null;

        $rows = $issues->map(fn (OperationConsumableIssue $i) => [
            'returnable' => $mine === null || in_array((int) $i->branch_id, $mine, true),
            'id' => $i->id, 'product_id' => $i->product_id, 'product' => $i->product?->name, 'requirement' => $i->requirement?->description,
            'issued' => (int) $i->issued_quantity, 'returned' => (int) $i->returned_quantity, 'consumed' => $i->consumedQuantity(),
            'unit_cost' => (float) $i->unit_cost, 'consumed_cost' => $i->consumedCost(), 'status' => $i->status,
            'issued_at' => $i->issued_at?->format('Y-m-d H:i'), 'returned_at' => $i->returned_at?->format('Y-m-d H:i'), 'notes' => $i->notes,
        ])->values()->all();

        return [
            'rows' => $rows,
            'issued_cost' => round((float) $issues->sum(fn ($i) => $i->issued_quantity * (float) $i->unit_cost), 3),
            'consumed_cost' => round((float) $issues->sum(fn ($i) => $i->consumedCost()), 3),
            'returned_cost' => round((float) $issues->sum(fn ($i) => $i->returned_quantity * (float) $i->unit_cost), 3),
            'open' => $issues->where('status', OperationConsumableIssue::ISSUED)->count(),
        ];
    }

    /* ─────────────────────────── أدوات ─────────────────────────── */

    private static function key(EventOperation $operation, Product $product, mixed $clientKey): string
    {
        $suffix = filled($clientKey) ? mb_substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string) $clientKey) ?? '', 0, 40) : now()->format('YmdHis').'-'.random_int(1000, 9999);

        return 'op'.$operation->id.'-p'.$product->id.'-'.$suffix;
    }

    private static function requirementFor(EventOperation $operation, Product $product, mixed $id): ?OperationEquipmentRequirement
    {
        if ($id !== null && $id !== '') {
            $req = OperationEquipmentRequirement::where('event_operation_id', $operation->id)->whereKey((int) $id)->first();
            if ($req === null) {
                throw new RuntimeException(__('المتطلَّب ليس من هذه الفعاليّة.'));
            }

            return $req;
        }

        return OperationEquipmentRequirement::where('event_operation_id', $operation->id)
            ->where('kind', OperationEquipmentRequirement::PRODUCT)->where('product_id', $product->id)->orderBy('id')->first();
    }

    private static function branch(int $bid, mixed $id): ?Branch
    {
        if ($id !== null && $id !== '') {
            $branch = Branch::where('business_id', $bid)->whereKey((int) $id)->first();
            if ($branch === null) {
                throw new RuntimeException(__('الفرع ليس من شركتك.'));
            }

            return $branch;
        }

        // وبلا فرعٍ مختار: أوّلُ فروع من قُيّد بفروعٍ، لا أوّلُ فروع الشركة
        return BranchAccess::limit(Branch::where('business_id', $bid), null, 'id')->orderBy('id')->first();
    }
}
