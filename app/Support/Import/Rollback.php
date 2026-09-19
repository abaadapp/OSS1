<?php

namespace App\Support\Import;

use App\Models\ChangeOrder;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceItem;
use App\Models\CustomerPayment;
use App\Models\DeliveryPackage;
use App\Models\Expense;
use App\Models\ImportDataset;
use App\Models\ImportLink;
use App\Models\ImportRun;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectItem;
use App\Models\ProjectTask;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\Activity;
use App\Support\Books;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Permissions;
use App\Support\SupplierInvoices;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * التراجعُ عن استيراد — حيث يكون آمنًا، وبتقريرٍ حيث لا يكون (§25).
 *
 * ═══ ما أُنشئ ولم يُستعمل يُزال · وما استُعمل يبقى ═══
 *
 * عميلٌ مستورَد كُتبت عليه فاتورةٌ بعد الاستيراد لا يُحذف: يُقال «مرتبطٌ»
 * ويبقى. والمستندُ الماليُّ لا يُحذف أبدًا: يُلغى من بابه (`cancel`) فيُعكس
 * قيدُه ويبقى مقروءًا موسومًا. وما سُدّد منه لا يُلغى — يُقال «غيرُ قابل».
 *
 * ═══ والأهليّةُ قبل التنفيذ ═══
 *
 * `eligibility()` تقول لكلّ أثرٍ: يُزال · يُلغى · مرتبطٌ فيبقى · غيرُ قابل —
 * والشاشةُ تعرضها قبل أن يُضغط الزرّ. وما رُبط (لا أُنشئ) يُفكّ أثرُه فقط.
 */
final class Rollback
{
    public const REMOVE = 'remove';

    public const REVERSE = 'reverse';

    public const IN_USE = 'in_use';

    public const NOT_REVERSIBLE = 'not_reversible';

    public const UNLINK = 'unlink';

    /**
     * @return array{eligible: bool, counts: array<string, int>, items: list<array<string, mixed>>}
     */
    public static function eligibility(ImportDataset $dataset): array
    {
        $links = ImportLink::where('dataset_id', $dataset->id)->whereNull('rolled_back_at')->orderBy('row_number')->get();
        $items = [];
        $counts = [self::REMOVE => 0, self::REVERSE => 0, self::IN_USE => 0, self::NOT_REVERSIBLE => 0, self::UNLINK => 0];

        foreach ($links as $link) {
            $verdict = $link->action === ImportLink::LINKED ? self::UNLINK : self::verdict($link);
            $counts[$verdict]++;
            if (count($items) < 500) {
                $items[] = ['row' => $link->row_number, 'source_ref' => $link->source_ref, 'type' => class_basename($link->linkable_type),
                    'id' => $link->linkable_id, 'verdict' => $verdict];
            }
        }

        return [
            'eligible' => $links->isNotEmpty() && $counts[self::NOT_REVERSIBLE] === 0,
            'reversible' => $counts[self::REMOVE] + $counts[self::REVERSE],
            'counts' => $counts,
            'items' => $items,
        ];
    }

    public static function execute(ImportDataset $dataset, string $reason, ?User $actor = null): ImportRun
    {
        if (! ($actor?->may(Permissions::IMPORT_ROLLBACK) ?? false)) {
            throw new RuntimeException(__('التراجعُ عن استيراد يحتاج صلاحيّة التراجع.'));
        }
        if (! $dataset->isImported()) {
            throw new RuntimeException(__('لا تراجعَ إلّا عن مجموعةٍ استُوردت.'));
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException(__('اكتب سبب التراجع.'));
        }

        Activity::log('status', 'طلب التراجع عن استيراد المجموعة '.$dataset->code.' — '.$reason, [
            'subject_id' => $dataset->id, 'subject_type' => 'import_dataset', 'color' => 'warning',
        ]);

        $run = ImportRun::create([
            'business_id' => $dataset->business_id, 'dataset_id' => $dataset->id, 'kind' => ImportRun::ROLLBACK,
            'status' => ImportRun::RUNNING, 'checksum' => $dataset->checksum, 'mapping_version' => $dataset->mapping_version,
            'strategy' => $dataset->strategy, 'user_id' => $actor?->id, 'started_at' => now(),
        ]);

        $counts = ['removed' => 0, 'reversed' => 0, 'unlinked' => 0, 'kept' => 0, 'failed' => 0];
        $findings = [];

        foreach (ImportLink::where('dataset_id', $dataset->id)->whereNull('rolled_back_at')->orderByDesc('row_number')->lazy(200) as $link) {
            $verdict = $link->action === ImportLink::LINKED ? self::UNLINK : self::verdict($link);

            try {
                DB::transaction(function () use ($link, $verdict, $reason, $actor, &$counts) {
                    match ($verdict) {
                        self::UNLINK => $counts['unlinked']++,
                        self::REMOVE => self::remove($link) && $counts['removed']++,
                        self::REVERSE => self::reverse($link, $reason, $actor) && $counts['reversed']++,
                        default => $counts['kept']++,
                    };

                    if (in_array($verdict, [self::UNLINK, self::REMOVE, self::REVERSE], true)) {
                        $link->update(['rolled_back_at' => now()]);
                    }
                });
            } catch (Throwable $e) {
                $counts['failed']++;
                if (count($findings) < 200) {
                    $findings[] = ['row' => $link->row_number, 'messages' => [mb_substr($e->getMessage(), 0, 200)]];
                }
            }
        }

        $remaining = ImportLink::where('dataset_id', $dataset->id)->whereNull('rolled_back_at')->count();
        $status = $counts['failed'] > 0 ? ImportRun::PARTIAL : ImportRun::SUCCESS;

        $run->update(['status' => $status, 'finished_at' => now(), 'totals' => $counts, 'findings' => $findings]);

        $dataset->update([
            'rollback' => ['at' => now()->toDateTimeString(), 'by' => $actor?->name, 'reason' => mb_substr($reason, 0, 255),
                'run_id' => $run->id, 'counts' => $counts, 'remaining' => $remaining],
            'status' => $remaining === 0 ? ImportDataset::CANCELLED : $dataset->status,
        ]);

        Activity::log('status', 'نُفّذ التراجع عن المجموعة '.$dataset->code.' — أُزيل '.$counts['removed'].' · أُلغي '.$counts['reversed'].' · فُكّ '.$counts['unlinked'].' · بقي '.$counts['kept'].' · فشل '.$counts['failed'], [
            'subject_id' => $dataset->id, 'subject_type' => 'import_dataset', 'icon' => 'undo', 'color' => $counts['failed'] > 0 ? 'danger' : 'warning',
        ]);

        return $run->fresh();
    }

    /* ─────────────────────────── الحكم ─────────────────────────── */

    private static function verdict(ImportLink $link): string
    {
        $model = self::model($link);

        if ($model === null) {
            return self::REMOVE; /* زال من قبل — لا شيءَ يُفعل سوى وسم الأثر */
        }

        return match (true) {
            $model instanceof Customer => self::used($model) ? self::IN_USE : self::REMOVE,
            $model instanceof Supplier => self::used($model) ? self::IN_USE : self::REMOVE,
            $model instanceof Product => self::used($model) ? self::IN_USE : self::REMOVE,
            $model instanceof Project => self::used($model) ? self::IN_USE : self::REMOVE,
            $model instanceof Quotation => Project::where('quotation_id', $model->id)->exists() ? self::IN_USE : self::REMOVE,
            $model instanceof CustomerInvoice => $model->status === CustomerInvoice::CANCELLED ? self::REMOVE
                : ($model->paidTotal() > 0.0005 ? self::NOT_REVERSIBLE : self::REVERSE),
            $model instanceof CustomerPayment => $model->cancelled_at !== null ? self::REMOVE : self::REVERSE,
            $model instanceof Expense => self::REVERSE,
            $model instanceof SupplierInvoice => (float) $model->paid > 0.0005 ? self::NOT_REVERSIBLE
                : ($model->approval_status === SupplierInvoices::APPROVED ? self::REVERSE : self::REMOVE),
            default => self::NOT_REVERSIBLE,
        };
    }

    /** أاستُعمل هذا الكيانُ المرجعيُّ في شيءٍ بعد استيراده؟ */
    private static function used(Model $model): bool
    {
        return match (true) {
            $model instanceof Customer => CustomerInvoice::where('customer_id', $model->id)->exists()
                || Quotation::where('customer_id', $model->id)->exists()
                || Project::where('customer_id', $model->id)->exists()
                || CustomerPayment::where('customer_id', $model->id)->exists()
                || DeliveryPackage::where('customer_id', $model->id)->exists(),
            $model instanceof Supplier => SupplierInvoice::where('supplier_id', $model->id)->exists()
                || PurchaseOrder::where('supplier_id', $model->id)->exists(),
            $model instanceof Product => QuotationItem::where('product_id', $model->id)->exists()
                || CustomerInvoiceItem::where('product_id', $model->id)->exists()
                || ProjectItem::where('product_id', $model->id)->exists(),
            $model instanceof Project => CustomerInvoice::where('project_id', $model->id)->exists()
                || Expense::where('project_id', $model->id)->exists()
                || PurchaseOrder::where('project_id', $model->id)->exists()
                || SupplierInvoice::where('project_id', $model->id)->exists()
                || ChangeOrder::where('project_id', $model->id)->exists()
                || DeliveryPackage::where('project_id', $model->id)->exists()
                || ProjectTask::where('project_id', $model->id)->exists(),
            default => true,
        };
    }

    private static function remove(ImportLink $link): bool
    {
        $model = self::model($link);

        if ($model === null) {
            return true;
        }
        if ($model instanceof Quotation) {
            $model->items()->delete();
        }
        if ($model instanceof SupplierInvoice && $model->approval_status !== SupplierInvoices::APPROVED) {
            $model->delete();

            return true;
        }
        if ($model instanceof CustomerInvoice || $model instanceof CustomerPayment) {
            /* ملغًى أصلًا — يبقى مقروءًا؛ الأثرُ وحدَه يُوسم */
            return true;
        }

        $model instanceof Project ? $model->items()->delete() : null;
        $model->forceDelete();

        return true;
    }

    private static function reverse(ImportLink $link, string $reason, ?User $actor): bool
    {
        $model = self::model($link);

        if ($model === null) {
            return true;
        }

        $why = __('تراجعٌ عن استيراد :ref — :reason', ['ref' => $link->source_ref, 'reason' => $reason]);

        match (true) {
            $model instanceof CustomerInvoice => CustomerInvoices::cancel($model, $why, $actor?->id),
            $model instanceof CustomerPayment => CustomerPayments::cancel($model, $why, $actor?->id),
            $model instanceof SupplierInvoice => SupplierInvoices::cancel($model, $why, $actor),
            $model instanceof Expense => self::reverseExpense($model, $why, $actor),
            default => throw new RuntimeException(__('لا طريقةَ لعكس هذا الكيان')),
        };

        return true;
    }

    private static function reverseExpense(Expense $expense, string $why, ?User $actor): void
    {
        if ($expense->isPaid()) {
            $expense->transaction()->delete();
            Books::unpostExpense($expense, $actor?->id, $why);
        }
        $expense->delete();
    }

    private static function model(ImportLink $link): ?Model
    {
        $class = Relation::getMorphedModel($link->linkable_type) ?? $link->linkable_type;

        if (! class_exists($class)) {
            return null;
        }

        $query = $class::query();
        if (method_exists($class, 'withTrashed')) {
            $query = $class::withTrashed();
        }

        return $query->where('business_id', $link->business_id)->find($link->linkable_id);
    }
}
