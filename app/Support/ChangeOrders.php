<?php

namespace App\Support;

use App\Models\Business;
use App\Models\ChangeOrder;
use App\Models\ChangeOrderItem;
use App\Models\DocumentApproval;
use App\Models\PolicyException;
use App\Models\Project;
use App\Models\ProjectItem;
use App\Models\ProjectRequirement;
use App\Models\User;
use App\Support\Document\Snapshot;
use App\Support\Messaging\MessageIntent;
use App\Support\Messaging\Notifiers;
use App\Support\Policy\Registry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * أوامرُ التغيير: كتابتُها، وتسعيرُها، وإرسالُها، وقبولُها، **وتطبيقُها**.
 *
 * ═══ العرضُ المقبول لا يُمسّ ═══
 *
 * كلُّ ما يتغيّر بعد القبول يمرّ من هنا فرقًا موقَّعًا على ما اعتُمد. ولا
 * `Ledger` ولا فاتورةَ ولا إشعارَ دائن: أمرُ التغيير يغيّر **النطاق**،
 * والمالُ يتبعه من أبوابه — انظر §5.2.
 *
 * ═══ والتسعيرُ بمحرّك العرض نفسِه ═══
 *
 * بندٌ جديد يُسعَّر بـ`Quotations::price`: السعرُ من الكتالوج في الخادم،
 * والخصمُ والزيادةُ بالأذون نفسِها (`quotation.discount` · `overridePrice` ·
 * `approve`). ونقصٌ على بندٍ قائم لا يُسعَّر: يُرَدّ بما دُفع به.
 *
 * ═══ والتطبيقُ بابٌ واحد ═══
 *
 * `apply` تُنادى مرّةً داخل معاملة القبول تحت قفل، ولا يُنادى غيرُها ليكتب
 * في كميّةِ بندِ مشروع. وأمرٌ قُبل لا يُقبل ثانيةً — فلا يُطبَّق ثانيةً.
 */
final class ChangeOrders
{
    private const EPSILON = 0.0005;

    /* ─────────────────────────── التسعير ─────────────────────────── */

    /**
     * البنودُ مسعَّرةً — الجديدُ بمحرّك العرض، وتعديلُ القائم بسعر بنده.
     *
     * ═══ بندٌ قائمٌ لا يُعاد تسعيرُه ═══
     *
     * زيادةٌ أو نقصٌ على بندٍ اعتمده العميل تُحسب بسعره وضريبته **كما
     * اعتُمدا** — وما يرسله الطلب من `unit_price` أو ضريبةٍ يُطرح ولا يُقرأ.
     * فالتطبيقُ يزيد كميّةَ البند نفسِه، وبندٌ واحد بسعرين يجعل مجموعَ
     * بنوده يقول غيرَ ما يقوله مجموعُ فروق أوامره. ومن أراد سعرًا آخر
     * كتب **بندًا جديدًا** يُسعَّر بمحرّك العرض بأذونه.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{items: list<array<string, mixed>>, needsApproval: bool, subtotal: float, discount: float, tax: float, total: float}
     */
    public static function price(Project $project, array $lines, ?User $actor = null): array
    {
        $bid = (int) $project->business_id;
        $current = $project->items()->get()->keyBy('id');

        $additions = [];
        $adjustments = [];

        foreach ($lines as $line) {
            $qty = round((float) ($line['quantity'] ?? 0), 3);
            $itemId = isset($line['project_item_id']) && $line['project_item_id'] !== '' && $line['project_item_id'] !== null
                ? (int) $line['project_item_id'] : null;

            if (abs($qty) <= self::EPSILON) {
                throw new RuntimeException(__('بندٌ بكميّة صفر لا يغيّر شيئًا.'));
            }

            /* ومعرّفُ بندٍ من مشروعٍ آخر يُردّ هنا لا في الشاشة */
            $item = $itemId === null ? null : $current->get($itemId);

            if ($itemId !== null && $item === null) {
                throw new RuntimeException(__('هذا البند ليس من بنود المشروع.'));
            }

            if ($qty < 0 && $item === null) {
                throw new RuntimeException(__('النقصُ يكون على بندٍ قائم — اختر البند.'));
            }

            if ($item !== null) {
                if ($qty < 0 && abs($qty) > (float) $item->quantity + self::EPSILON) {
                    throw new RuntimeException(__('لا يُنقَص بند «:item» أكثر من كميّته المعتمَدة (:qty).', [
                        'item' => $item->description, 'qty' => (float) $item->quantity,
                    ]));
                }

                $adjustments[] = self::adjustmentRow($item, $qty, Projects::text($line['notes'] ?? null, 2000));

                continue;
            }

            $additions[] = [
                'product_id' => $line['product_id'] ?? null,
                'description' => $line['description'] ?? null,
                'unit' => $line['unit'] ?? null,
                'quantity' => $qty,
                'unit_price' => $line['unit_price'] ?? 0,
                'discount_reason' => $line['discount_reason'] ?? null,
                'notes' => $line['notes'] ?? null,
            ];
        }

        $priced = $additions === []
            ? ['lines' => [], 'needsApproval' => false]
            : Quotations::price($bid, $additions, $actor);
        $totals = $additions === []
            ? ['items' => [], 'subtotal' => 0.0, 'discount' => 0.0, 'tax' => 0.0, 'total' => 0.0]
            : Quotations::totals($bid, $priced['lines']);

        $items = [];

        foreach ($totals['items'] as $row) {
            $row['project_item_id'] = null;
            $items[] = $row;
        }

        $subtotal = (float) $totals['subtotal'];
        $tax = (float) $totals['tax'];
        $total = (float) $totals['total'];

        foreach ($adjustments as $row) {
            $subtotal += (float) $row['quantity'] * (float) $row['unit_price'];
            $tax += (float) $row['tax_amount'];
            $total += (float) $row['line_total'];
            $items[] = $row;
        }

        foreach ($items as $i => $row) {
            $items[$i]['sort_order'] = $i;
        }

        return [
            'items' => $items,
            'needsApproval' => (bool) $priced['needsApproval'],
            'subtotal' => round($subtotal, 3),
            'discount' => round((float) $totals['discount'], 3),
            'tax' => round($tax, 3),
            'total' => round($total, 3),
        ];
    }

    /**
     * سطرُ تعديلٍ على بندٍ قائم — زيادةً أو نقصًا — بسعر البند وضريبته، وبلا خصم.
     *
     * @return array<string, mixed>
     */
    private static function adjustmentRow(ProjectItem $item, float $qty, ?string $notes): array
    {
        $unit = round((float) $item->unit_price, 3);
        $rate = round((float) $item->tax_rate, 2);
        $net = round($qty * $unit, 3);
        $taxAmount = round($net * $rate / 100, 3);

        return [
            'project_item_id' => $item->id,
            'product_id' => $item->product_id,
            'description' => (string) $item->description,
            'unit' => $item->unit,
            'quantity' => $qty,
            'list_price' => $unit,
            'unit_price' => $unit,
            'discount' => 0.0,
            'discount_reason' => null,
            'discount_requested_by' => null,
            'discount_approved_by' => null,
            'discount_approved_at' => null,
            'tax_rate' => $rate,
            'tax_amount' => $taxAmount,
            'line_total' => round($net + $taxAmount, 3),
            'notes' => $notes,
        ];
    }

    /* ─────────────────────────── الكتابة ─────────────────────────── */

    /**
     * كتابةُ أمرٍ — مسودّةً، أو ينتظر اعتمادًا إن تجاوز خصمٌ حدَّه أو خُولفت سياسة.
     *
     * @param  array<string, mixed>  $data  `title` · `reason` · `requested_by_party` · `requested_by_name` · `new_starts_at` · `new_ends_at` · `valid_until`
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array{add?: list<array<string, mixed>>, remove?: list<int>}  $requirements
     * @param  array<int, array<string, mixed>>  $exceptions
     */
    public static function create(Project $project, array $data, array $lines, array $requirements = [], ?User $actor = null, array $exceptions = []): ChangeOrder
    {
        if (! $project->isOpen()) {
            throw new RuntimeException(__('لا يُكتب أمرُ تغيير إلا على مشروعٍ قائم.'));
        }

        $priced = self::price($project, $lines, $actor);
        $removals = self::removalTargets($project, $requirements['remove'] ?? [], null);
        $adds = self::additionRows($project, $requirements['add'] ?? []);
        $attributes = self::attributes($project, $data, $priced);

        if ($priced['items'] === [] && $adds === [] && $removals->isEmpty()
            && $attributes['new_starts_at'] === null && $attributes['new_ends_at'] === null) {
            throw new RuntimeException(__('أمرُ تغييرٍ بلا تغيير: أضف بندًا أو متطلّبًا أو موعدًا.'));
        }

        $shaped = self::shape($project, $attributes, $priced['items']);
        $settled = self::settle($shaped, $exceptions, $actor);

        return DB::transaction(function () use ($project, $attributes, $priced, $adds, $removals, $settled, $actor) {
            /* والتسلسلُ تحت قفل صفّ المشروع: أمران يُكتبان معًا لا يأخذان الرقمَ نفسَه */
            Project::whereKey($project->id)->lockForUpdate()->first();

            $order = ChangeOrder::create($attributes + [
                'sequence' => (int) ChangeOrder::where('project_id', $project->id)->max('sequence') + 1,
                'status' => $priced['needsApproval'] || $settled['pending']
                    ? ChangeOrder::PENDING_APPROVAL : ChangeOrder::DRAFT,
                'created_by' => $actor?->id,
            ]);

            self::writeItems($order, $priced['items']);
            self::writeRequirements($order, $adds, $removals, $actor);
            self::writeExceptions($order, $settled['rows']);

            Activity::log('created', 'كتب أمر تغيير على المشروع '.$project->code.' — '.$order->title, [
                'subject_id' => $order->id, 'subject_type' => 'change_order',
            ]);

            return $order->fresh('items');
        });
    }

    /**
     * تعديلُ ما لم يخرج — وما خرج لا يُعدَّل.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array{add?: list<array<string, mixed>>, remove?: list<int>}  $requirements
     * @param  array<int, array<string, mixed>>  $exceptions
     */
    public static function update(ChangeOrder $order, array $data, array $lines, array $requirements = [], ?User $actor = null, array $exceptions = []): ChangeOrder
    {
        if ($order->isFrozen()) {
            throw new RuntimeException(__('هذا الأمر خرج إلى العميل — لا يُعدَّل.'));
        }

        $project = $order->project;
        $priced = self::price($project, $lines, $actor);
        $removals = self::removalTargets($project, $requirements['remove'] ?? [], $order);
        $adds = self::additionRows($project, $requirements['add'] ?? []);
        $attributes = self::attributes($project, $data, $priced);

        if ($priced['items'] === [] && $adds === [] && $removals->isEmpty()
            && $attributes['new_starts_at'] === null && $attributes['new_ends_at'] === null) {
            throw new RuntimeException(__('أمرُ تغييرٍ بلا تغيير: أضف بندًا أو متطلّبًا أو موعدًا.'));
        }

        $shaped = self::shape($project, $attributes, $priced['items']);
        $settled = self::settle($shaped, $exceptions, $actor);

        return DB::transaction(function () use ($order, $project, $attributes, $priced, $adds, $removals, $settled, $actor) {
            /* وتحت القفل يُعاد السؤال: إرسالٌ سبق هذا التعديل بلحظةٍ جمّد الأمر */
            $locked = ChangeOrder::where('business_id', $order->business_id)
                ->lockForUpdate()->findOrFail($order->id);

            if ($locked->isFrozen()) {
                throw new RuntimeException(__('هذا الأمر خرج إلى العميل — لا يُعدَّل.'));
            }

            if (! Project::whereKey($project->id)->lockForUpdate()->firstOrFail()->isOpen()) {
                throw new RuntimeException(__('لا يُكتب أمرُ تغيير إلا على مشروعٍ قائم.'));
            }

            $order->update($attributes + [
                'status' => $priced['needsApproval'] || $settled['pending']
                    ? ChangeOrder::PENDING_APPROVAL : ChangeOrder::DRAFT,
                /* وتعديلٌ يمحو اعتمادًا سابقًا — وقع على ورقةٍ لم تعد قائمة */
                'approved_by' => null,
                'approved_at' => null,
            ]);

            $order->items()->delete();
            self::writeItems($order, $priced['items']);

            /* ومقترَحاتُ مسودّةٍ تُعاد كتابتُها: لم تصر دليلًا بعد */
            $order->addedRequirements()->delete();
            ProjectRequirement::where('removed_by_change_order_id', $order->id)->update(['removed_by_change_order_id' => null]);
            self::writeRequirements($order, $adds, $removals, $actor);

            $order->policyExceptions()->delete();
            self::writeExceptions($order, $settled['rows']);

            Activity::log('updated', 'عدّل أمر التغيير '.self::label($order).' على المشروع '.$project->code, [
                'subject_id' => $order->id, 'subject_type' => 'change_order',
            ]);

            return $order->fresh('items');
        });
    }

    /**
     * أعمدةُ الأمر كما تُكتب — من موضعٍ واحد للحفظ والفحص.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $priced
     * @return array<string, mixed>
     */
    private static function attributes(Project $project, array $data, array $priced): array
    {
        $party = in_array($data['requested_by_party'] ?? null, ChangeOrder::PARTIES, true)
            ? $data['requested_by_party'] : ChangeOrder::BY_CUSTOMER;

        $starts = Projects::date($data['new_starts_at'] ?? null);
        $ends = Projects::date($data['new_ends_at'] ?? null);

        /* وموعدٌ هو الموعدُ القائمُ نفسُه ليس تغييرًا */
        if ($starts !== null && $project->starts_at?->toDateString() === $starts && ($ends === null || $project->ends_at?->toDateString() === $ends)) {
            $starts = null;
            $ends = null;
        }

        /*
         * والموعدُ الفعليُّ بعد الأمر هو ما يُفحص — لا ما كُتب في الطلب وحده:
         * نهايةٌ جديدة بلا بداية تُقاس على بداية المشروع القائمة، وبدايةٌ جديدة
         * بلا نهاية تُقاس على نهايته. فلا يُوقَّع على موعدٍ يكذب ثمّ يُمحى بصمت.
         */
        self::assertDatesConsistent($project, $starts, $ends);

        return [
            'business_id' => $project->business_id,
            'project_id' => $project->id,
            'title' => Projects::text($data['title'] ?? null, 255) ?? __('أمر تغيير'),
            'reason' => Projects::text($data['reason'] ?? null, 5000),
            'requested_by_party' => $party,
            'requested_by_name' => Projects::text($data['requested_by_name'] ?? null, 120),
            'new_starts_at' => $starts,
            'new_ends_at' => $ends,
            'valid_until' => Projects::date($data['valid_until'] ?? null),
            'subtotal_delta' => $priced['subtotal'],
            'discount_delta' => $priced['discount'],
            'tax_delta' => $priced['tax'],
            'total_delta' => $priced['total'],
        ];
    }

    /** الموعدُ كما سيصير: ما جاء في الأمر، وما لم يجئ فمن المشروع — ولا نهايةَ قبل بدايةٍ */
    private static function assertDatesConsistent(Project $project, ?string $starts, ?string $ends): void
    {
        if ($starts === null && $ends === null) {
            return;
        }

        $effectiveStart = $starts ?? $project->starts_at?->toDateString();
        $effectiveEnd = $ends ?? $project->ends_at?->toDateString();

        if ($effectiveStart !== null && $effectiveEnd !== null && $effectiveEnd < $effectiveStart) {
            throw new RuntimeException(__('نهايةُ الفعاليّة قبل بدايتها.'));
        }
    }

    /**
     * أمرٌ على هيئته **بلا حفظ** — لفحص السياسات قبل الكتابة.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $items
     */
    private static function shape(Project $project, array $attributes, array $items): ChangeOrder
    {
        $order = new ChangeOrder($attributes + ['status' => ChangeOrder::DRAFT]);
        $order->setRelation('project', $project);
        $order->setRelation('items', collect($items)->map(fn (array $i) => new ChangeOrderItem($i)));

        return $order;
    }

    /** @param  list<array<string, mixed>>  $items */
    private static function writeItems(ChangeOrder $order, array $items): void
    {
        foreach ($items as $item) {
            ChangeOrderItem::create($item + ['change_order_id' => $order->id]);
        }
    }

    /**
     * ما يُقترح إضافتُه — صفوفٌ جاهزة.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function additionRows(Project $project, array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $title = Projects::text($row['title'] ?? null, 255);

            if ($title === null) {
                continue;
            }

            $out[] = [
                'business_id' => $project->business_id,
                'project_id' => $project->id,
                'project_item_id' => Projects::itemIdOf($project, $row['project_item_id'] ?? null),
                'title' => $title,
                'details' => Projects::text($row['details'] ?? null, 5000),
            ];
        }

        return $out;
    }

    /**
     * ما يُقترح إزالتُه — متطلّباتٌ قائمةٌ من هذا المشروع لم يحجزها أمرٌ آخر.
     *
     * @param  list<int|string>  $ids
     * @return Collection<int, ProjectRequirement>
     */
    private static function removalTargets(Project $project, array $ids, ?ChangeOrder $self)
    {
        $ids = collect($ids)->map(fn ($id) => (int) $id)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $rows = ProjectRequirement::where('project_id', $project->id)->active()->whereKey($ids->all())->get();

        if ($rows->count() !== $ids->count()) {
            throw new RuntimeException(__('متطلَّبٌ ليس من النطاق القائم لهذا المشروع.'));
        }

        foreach ($rows as $row) {
            if ($row->removed_by_change_order_id !== null && $row->removed_by_change_order_id !== $self?->id) {
                throw new RuntimeException(__('المتطلَّب «:title» محجوزٌ لأمر تغييرٍ آخر لم يُقرَّر بعد.', ['title' => $row->title]));
            }
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $adds
     * @param  Collection<int, ProjectRequirement>  $removals
     */
    private static function writeRequirements(ChangeOrder $order, array $adds, $removals, ?User $actor): void
    {
        $sort = (int) (ProjectRequirement::where('project_id', $order->project_id)->max('sort_order') ?? 0);

        foreach ($adds as $row) {
            ProjectRequirement::create($row + [
                'change_order_id' => $order->id,
                'sort_order' => ++$sort,
                'created_by' => $actor?->id,
            ]);
        }

        if ($removals->isNotEmpty()) {
            ProjectRequirement::whereKey($removals->pluck('id')->all())
                ->update(['removed_by_change_order_id' => $order->id]);
        }
    }

    /* ─────────────────────────── السياسات ─────────────────────────── */

    /**
     * ما خالفه الأمرُ من سياسات — وهل لكلّ مخالفةٍ سببٌ مكتوب. كـ`Quotations::settle`.
     *
     * @param  array<int, array<string, mixed>>  $requested
     * @return array{rows: list<array<string, mixed>>, pending: bool}
     */
    private static function settle(ChangeOrder $shaped, array $requested, ?User $actor): array
    {
        $findings = CompanyPolicies::findings((int) $shaped->business_id, $shaped);

        if ($findings === []) {
            return ['rows' => [], 'pending' => false];
        }

        $byKey = collect($requested)->keyBy(fn ($row) => (string) ($row['key'] ?? ''));
        $mayOverride = (bool) $actor?->may(Permissions::POLICY_OVERRIDE);
        $rows = [];
        $pending = false;

        foreach ($findings as $finding) {
            $input = $byKey->get($finding->key(), []);
            $reason = Projects::text($input['reason'] ?? null, 500);

            if ($reason === null) {
                throw new RuntimeException(__(':finding اكتب سبب الاستثناء ليُحفظ الأمر.', ['finding' => $finding->message]));
            }

            $rows[] = Quotations::exceptionRow($finding, $reason, $input['surcharge_amount'] ?? null, $actor, $mayOverride);
            $pending = $pending || ($finding->requiresApproval && ! $mayOverride);
        }

        return ['rows' => $rows, 'pending' => $pending];
    }

    /** @param  list<array<string, mixed>>  $rows */
    private static function writeExceptions(ChangeOrder $order, array $rows): void
    {
        foreach ($rows as $row) {
            $exception = $order->policyExceptions()->create($row + ['business_id' => $order->business_id]);

            Activity::log('status', 'تجاوز سياسة «'.Registry::label($row['policy_key']).'» في أمر التغيير '
                .self::label($order).' — '.$row['reason']
                .($exception->approved_at ? ' · اعتُمد' : ' · ينتظر اعتمادًا'), [
                    'subject_id' => $order->id, 'subject_type' => 'change_order',
                    'icon' => 'shield-alert', 'color' => 'warning',
                ]);
        }
    }

    /** ما يخالفه أمرٌ محفوظ الآن — للشاشة وللإرسال */
    public static function findings(ChangeOrder $order): array
    {
        $order->loadMissing('items', 'project');

        return CompanyPolicies::findings((int) $order->business_id, $order);
    }

    /* ─────────────────────────── الدورة ─────────────────────────── */

    /** اعتمادُ ما تجاوز الحدّ — بصلاحيّتين كما في العرض */
    public static function approve(ChangeOrder $order, ?User $actor = null): ChangeOrder
    {
        if ($order->status !== ChangeOrder::PENDING_APPROVAL) {
            throw new RuntimeException(__('هذا الأمر لا ينتظر اعتمادًا.'));
        }

        $mayDiscount = (bool) $actor?->may(Permissions::QUOTATION_APPROVE);
        $mayPolicy = (bool) $actor?->may(Permissions::POLICY_OVERRIDE);

        if (! $mayDiscount && ! $mayPolicy) {
            throw new RuntimeException(__('لا تملك اعتماد هذا الأمر.'));
        }

        return DB::transaction(function () use ($order, $actor, $mayDiscount, $mayPolicy) {
            $approvedSomething = false;

            if ($mayDiscount) {
                $count = $order->items()->whereNull('discount_approved_by')->whereNotNull('discount_requested_by')
                    ->update(['discount_approved_by' => $actor?->id, 'discount_approved_at' => now()]);

                if ($count > 0) {
                    $approvedSomething = true;
                    Activity::log('status', 'اعتمد خصم أمر التغيير '.self::label($order), [
                        'subject_id' => $order->id, 'subject_type' => 'change_order',
                    ]);
                }
            }

            if ($mayPolicy) {
                $count = $order->policyExceptions()->pending()
                    ->update(['approved_by' => $actor?->id, 'approved_at' => now()]);

                if ($count > 0) {
                    $approvedSomething = true;
                    Activity::log('status', 'اعتمد استثناء السياسة في أمر التغيير '.self::label($order), [
                        'subject_id' => $order->id, 'subject_type' => 'change_order', 'icon' => 'shield-check',
                    ]);
                }
            }

            $stillPending = $order->items()->whereNull('discount_approved_by')->whereNotNull('discount_requested_by')->exists()
                || $order->policyExceptions()->pending()->exists();

            if (! $approvedSomething && $stillPending) {
                throw new RuntimeException(__('ما ينتظره هذا الأمر يعتمده غيرك.'));
            }

            $order->update($stillPending ? [] : [
                'status' => ChangeOrder::DRAFT,
                'approved_by' => $actor?->id,
                'approved_at' => now(),
            ]);

            return $order->fresh('items');
        });
    }

    /**
     * الإرسال — يُقطع الرقمُ وتُختم الورقة وتُبصَم وتُجمَّد. تحت قفل، ومرّةً.
     */
    public static function send(ChangeOrder $order, ?User $actor = null): ChangeOrder
    {
        return DB::transaction(function () use ($order, $actor) {
            $locked = ChangeOrder::where('business_id', $order->business_id)
                ->lockForUpdate()->findOrFail($order->id);

            if ($locked->status === ChangeOrder::SENT) {
                return $locked;
            }
            if ($locked->status === ChangeOrder::PENDING_APPROVAL) {
                throw new RuntimeException(__('هذا الأمر ينتظر اعتمادًا — لا يخرج قبله.'));
            }
            if ($locked->status !== ChangeOrder::DRAFT) {
                throw new RuntimeException(__('لا يُرسَل إلا أمرٌ في المسودّة.'));
            }
            if ($locked->isEmpty()) {
                throw new RuntimeException(__('أمرُ تغييرٍ بلا تغيير: أضف بندًا أو متطلّبًا أو موعدًا.'));
            }

            $project = $locked->project;

            if (! $project->isOpen()) {
                throw new RuntimeException(__('لا يُرسَل أمرُ تغيير على مشروعٍ مُقفَل.'));
            }

            $locked->setRelation('items', $locked->items()->get());
            $exceptions = $locked->policyExceptions()->get();

            if ($exceptions->contains(fn (PolicyException $e) => ! $e->isApproved())) {
                throw new RuntimeException(__('هذا الأمر ينتظر اعتماد استثناءٍ من سياسة — لا يخرج قبله.'));
            }

            foreach (CompanyPolicies::findings((int) $locked->business_id, $locked) as $finding) {
                if (! $exceptions->contains('policy_key', $finding->key())) {
                    throw new RuntimeException(__('هذا الأمر يخالف سياسة «:policy» ولم يُسجَّل له استثناء — احفظه مرّةً أخرى مع السبب.', [
                        'policy' => Registry::label($finding->key()),
                    ]));
                }
            }

            $locked->policy_snapshot = CompanyPolicies::snapshot((int) $locked->business_id, $locked);

            $locked->update([
                'number' => $locked->number ?: self::number($project, (int) $locked->sequence),
                'status' => ChangeOrder::SENT,
                'sent_at' => now(),
                'sent_by' => $actor?->id,
                Snapshot::COLUMN => Snapshot::capture((int) $locked->business_id),
                'policy_snapshot' => $locked->policy_snapshot,
                'content_hash' => self::contentHash($locked),
            ]);

            /* أمرُ تغييرٍ أُرسل ← من · متى · بأيّ بصمة · على أيّ نطاق (§24) */
            Activity::log('status', 'أرسل أمر التغيير '.self::label($locked).' بفرق '.$locked->total_delta, [
                'subject_id' => $locked->id, 'subject_type' => 'change_order',
            ]);

            return $locked->fresh('items');
        });
    }

    /** «EP-2026-014/CO-02» */
    public static function number(Project $project, int $sequence): string
    {
        return $project->code.'/CO-'.str_pad((string) $sequence, 2, '0', STR_PAD_LEFT);
    }

    /**
     * قرارُ العميل **من رابطه** — دليلٌ يُكتب، وقبولٌ يُطبَّق في اللحظة نفسِها.
     *
     * @param  array<string, mixed>  $signer
     * @param  array<string, mixed>  $meta
     */
    public static function decide(ChangeOrder $order, string $decision, array $signer, array $meta = []): ChangeOrder
    {
        $accepted = $decision === DocumentApproval::ACCEPTED;

        if (! $accepted && $decision !== DocumentApproval::DECLINED) {
            throw new RuntimeException(__('قرارٌ غير معروف.'));
        }

        $name = Projects::text($signer['name'] ?? null, 120);

        if ($name === null) {
            throw new RuntimeException(__('اكتب اسمك ليُسجَّل القرار باسمه.'));
        }

        $declineReason = Projects::text($signer['decline_reason'] ?? null, 500);

        if (! $accepted && $declineReason === null) {
            throw new RuntimeException(__('اكتب سبب الاعتذار — يساعدنا على تحسين ما نعرضه.'));
        }

        return DB::transaction(function () use ($order, $accepted, $name, $signer, $declineReason, $meta) {
            $locked = ChangeOrder::where('business_id', $order->business_id)
                ->lockForUpdate()->findOrFail($order->id);

            if (! $locked->isDecidable()) {
                throw new RuntimeException($locked->isExpired()
                    ? __('انتهت صلاحية هذا الأمر — تواصل مع الشركة لتجديده.')
                    : __('هذا الأمر لا يقبل قرارًا الآن.'));
            }

            self::assertProjectAccepts($locked);

            $locked->setRelation('items', $locked->items()->get());
            self::assertUntouched($locked);

            try {
                $approval = DocumentApproval::create([
                    'business_id' => $locked->business_id,
                    'approvable_type' => $locked->getMorphClass(),
                    'approvable_id' => $locked->id,
                    'provider' => DocumentApproval::INTERNAL,
                    'decision' => $accepted ? DocumentApproval::ACCEPTED : DocumentApproval::DECLINED,
                    'content_hash' => $locked->content_hash,
                    'accepted_snapshot' => self::hashPayload($locked),
                    'signer_name' => $name,
                    'signer_title' => Projects::text($signer['title'] ?? null, 120),
                    'signer_email' => Projects::text($signer['email'] ?? null, 120),
                    'signer_phone' => Projects::text($signer['phone'] ?? null, 40),
                    'consent_text' => $accepted ? Projects::text($signer['consent'] ?? null, 1000) : null,
                    'decline_reason' => $accepted ? null : $declineReason,
                    'decided_at' => now(),
                    'ip' => Projects::text($meta['ip'] ?? null, 45),
                    'user_agent' => Projects::text($meta['user_agent'] ?? null, 255),
                ]);
            } catch (QueryException) {
                throw new RuntimeException(__('قُرّر في هذا الأمر قبل لحظات.'));
            }

            $locked->update([
                'status' => $accepted ? ChangeOrder::ACCEPTED : ChangeOrder::DECLINED,
                'decided_at' => $approval->decided_at,
                'decided_by' => null,
                'decided_by_name' => $name,
                'decline_reason' => $accepted ? null : $declineReason,
            ]);

            Activity::log('status', ($accepted ? 'قبل العميل أمر التغيير ' : 'اعتذر العميل عن أمر التغيير ')
                .self::label($locked).' إلكترونيًّا — '.$name, [
                    'business_id' => $locked->business_id,
                    'subject_id' => $locked->id, 'subject_type' => 'change_order',
                    'icon' => $accepted ? 'file-check' : 'file-x',
                    'color' => $accepted ? 'success' : 'danger',
                ]);

            if ($accepted) {
                self::apply($locked);
            } else {
                self::release($locked);
            }

            return $locked->fresh();
        });
    }

    /**
     * تسجيلُ قرار العميل نيابةً — مكالمةٌ أو بريد. وقبولٌ يُطبَّق كذلك.
     *
     * @param  array<string, mixed>  $data
     */
    public static function recordDecision(ChangeOrder $order, string $decision, array $data = [], ?User $actor = null): ChangeOrder
    {
        $accepted = $decision === ChangeOrder::ACCEPTED;

        if (! $accepted && $decision !== ChangeOrder::DECLINED) {
            throw new RuntimeException(__('قرارٌ غير معروف.'));
        }

        $reason = Projects::text($data['decline_reason'] ?? null, 500);

        if (! $accepted && $reason === null) {
            throw new RuntimeException(__('اكتب سبب الرفض.'));
        }

        return DB::transaction(function () use ($order, $accepted, $reason, $data, $actor) {
            $locked = ChangeOrder::where('business_id', $order->business_id)
                ->lockForUpdate()->findOrFail($order->id);

            if ($locked->status !== ChangeOrder::SENT) {
                throw new RuntimeException(__('لا يُسجَّل قرارٌ إلا على أمرٍ أُرسل.'));
            }

            /*
             * والقبولُ نيابةً يُفحص كما يُفحص من الرابط: الصفُّ الآن ببصمة
             * الإرسال وعلى الأساس نفسِه. فلا يُسجَّل باسم العميل قبولُ ورقةٍ
             * غيرِ التي رآها — لا من يده ولا من يد موظّف.
             */
            if ($accepted) {
                self::assertProjectAccepts($locked);
                $locked->setRelation('items', $locked->items()->get());
                self::assertUntouched($locked);
            }

            $locked->update([
                'status' => $accepted ? ChangeOrder::ACCEPTED : ChangeOrder::DECLINED,
                'decided_at' => now(),
                'decided_by' => $actor?->id,
                'decided_by_name' => Projects::text($data['decided_by_name'] ?? null, 120),
                'decline_reason' => $accepted ? null : $reason,
            ]);

            Activity::log('status', ($accepted ? 'سجّل قبول العميل لأمر التغيير ' : 'سجّل رفض العميل لأمر التغيير ')
                .self::label($locked), [
                    'subject_id' => $locked->id, 'subject_type' => 'change_order',
                ]);

            if ($accepted) {
                self::apply($locked);
            } else {
                self::release($locked);
            }

            return $locked->fresh();
        });
    }

    /**
     * ═══ التطبيق — البابُ الواحد ═══
     *
     * تُنادى داخل معاملة القبول وحدها، على صفٍّ مقفولٍ انتقل للتوّ إلى
     * `accepted`. فأمرٌ قُبل من قبل لا يمرّ من `isDecidable` ولا من فحص
     * `SENT` — ولا يصل إلى هنا ثانيةً.
     *
     * والمتطلّباتُ لا تُكتب هنا: «قائمٌ» يُشتقّ من حال الأمر
     * (`ProjectRequirement::scopeActive`)، فقبولُه يُحييها ويُزيل ما وسمه بلا
     * كتابةٍ ثانية — وهو ما يجعل التطبيقَ لا يُكرَّر بطبيعته.
     */
    private static function apply(ChangeOrder $order): void
    {
        $project = Project::whereKey($order->project_id)->lockForUpdate()->firstOrFail();

        /* والبابُ الواحد يسأل عن المشروع بنفسه: مُقفَلٌ أو ملغًى لا يتغيّر نطاقُه — ولو وقّع العميل */
        if (! $project->isOpen()) {
            throw new RuntimeException(__('المشروع :code :state — لا يُطبَّق عليه أمرُ تغيير.', [
                'code' => $project->code, 'state' => $project->isClosed() ? __('مُقفَل') : __('ملغى'),
            ]));
        }

        $changed = [];

        foreach ($order->items as $line) {
            $delta = round((float) $line->quantity, 3);

            if ($line->project_item_id !== null) {
                $item = ProjectItem::where('project_id', $project->id)->lockForUpdate()->findOrFail($line->project_item_id);
                $quantity = round((float) $item->quantity + $delta, 3);

                if ($quantity < -self::EPSILON) {
                    throw new RuntimeException(__('لا يُنقَص بند «:item» أكثر من كميّته المعتمَدة (:qty).', [
                        'item' => $item->description, 'qty' => (float) $item->quantity,
                    ]));
                }

                $item->update([
                    'quantity' => max(0.0, $quantity),
                    'line_total' => ProjectItem::lineTotal(max(0.0, $quantity), (float) $item->unit_price, (float) $item->tax_rate),
                ]);
                $changed[] = $item->description.' '.($delta > 0 ? '+' : '').$delta;

                continue;
            }

            ProjectItem::create([
                'business_id' => $project->business_id,
                'project_id' => $project->id,
                'product_id' => $line->product_id,
                'quotation_item_id' => null,
                'change_order_id' => $order->id,
                'change_order_item_id' => $line->id,
                'description' => $line->description,
                'unit' => $line->unit,
                'quantity' => $delta,
                'unit_price' => (float) $line->unit_price,
                'tax_rate' => (float) $line->tax_rate,
                'line_total' => ProjectItem::lineTotal($delta, (float) $line->unit_price, (float) $line->tax_rate),
                'status' => ProjectItem::PENDING,
                'sort_order' => (int) ($project->items()->max('sort_order') ?? 0) + 1,
            ]);
            $changed[] = $line->description.' (جديد) '.$delta;
        }

        if ($order->hasDates()) {
            $from = optional($project->starts_at)->format('Y-m-d');
            $starts = $order->new_starts_at?->toDateString() ?? $project->starts_at?->toDateString();
            $ends = $order->new_ends_at?->toDateString() ?? $project->ends_at?->toDateString();

            /* وما وُقّع عليه فُحص عند كتابته — فإن كذب الموعدُ هنا فقد كُتب من غير بابه */
            if ($starts !== null && $ends !== null && $ends < $starts) {
                throw new RuntimeException(__('نهايةُ الفعاليّة قبل بدايتها.'));
            }

            $project->update(['starts_at' => $starts, 'ends_at' => $ends]);
            $changed[] = 'الموعد '.$from.' → '.$starts;
        }

        $added = $order->addedRequirements()->count();
        $removed = $order->removedRequirements()->count();

        if ($added > 0 || $removed > 0) {
            $changed[] = 'متطلّبات +'.$added.' −'.$removed;
        }

        /* أمرُ تغييرٍ طُبّق ← ما تغيّرت كميّاتُه · ما أُضيف وأُزيل · الموعدُ الجديد (§24) */
        Activity::log('updated', 'طُبّق أمر التغيير '.self::label($order).' على المشروع '.$project->code.' — '.implode(' · ', $changed), [
            'business_id' => $order->business_id,
            'subject_id' => $project->id, 'subject_type' => 'project',
            'icon' => 'git-merge', 'color' => 'success',
        ]);
    }

    /** أيقبل مشروعُ هذا الأمر تغييرًا على نطاقه الآن؟ — مُقفَلٌ أو ملغًى لا */
    private static function assertProjectAccepts(ChangeOrder $order): void
    {
        $project = Project::whereKey($order->project_id)->lockForUpdate()->first();

        if ($project === null || ! $project->isOpen()) {
            throw new RuntimeException(__('مشروع هذا الأمر لم يعد قائمًا — لا يُقبل أمرُ تغييرٍ عليه. تواصل مع الشركة.'));
        }
    }

    /** الصفُّ الآن ببصمة الإرسال وعلى الأساس نفسِه — وإلّا لم يُقبل بهذه الصورة */
    private static function assertUntouched(ChangeOrder $order): void
    {
        if (! Fingerprint::matches(self::hashPayload($order), $order->content_hash)) {
            throw new RuntimeException(__('تغيّر محتوى هذا الأمر بعد إرساله — لا يُقبل بهذه الصورة. تواصل مع الشركة.'));
        }
    }

    /** أمرٌ رُفض أو أُلغي يُفرج عمّا حجزه من متطلّباتٍ للإزالة — فيحجزها أمرٌ آخر */
    private static function release(ChangeOrder $order): void
    {
        ProjectRequirement::where('removed_by_change_order_id', $order->id)->update(['removed_by_change_order_id' => null]);
    }

    /**
     * الإلغاء — تحت قفل الصفّ وعلى حاله **الآن** لا حالِه حين فُتحت الشاشة.
     *
     * قبولٌ من الرابط سبق هذا الإلغاء بلحظة: نموذجٌ قديم يقرأ «مُرسَل» ثمّ
     * يكتب «ملغى» فوق أمرٍ طُبّق على النطاق. فيُقرأ الصفُّ مقفولًا ويُحكم عليه.
     */
    public static function cancel(ChangeOrder $order, string $reason, ?User $actor = null): ChangeOrder
    {
        return DB::transaction(function () use ($order, $reason, $actor) {
            $locked = ChangeOrder::where('business_id', $order->business_id)
                ->lockForUpdate()->findOrFail($order->id);

            if ($locked->status === ChangeOrder::ACCEPTED) {
                throw new RuntimeException(__('لا يُلغى أمرٌ قَبِله العميل — اكتب أمرًا معاكسًا.'));
            }
            if ($locked->status === ChangeOrder::CANCELLED) {
                return $locked;
            }

            $locked->update([
                'status' => ChangeOrder::CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $actor?->id,
                'cancellation_reason' => Projects::text($reason, 255),
            ]);

            self::release($locked);

            Activity::log('deleted', 'ألغى أمر التغيير '.self::label($locked).' — '.$reason, [
                'business_id' => $locked->business_id,
                'subject_id' => $locked->id, 'subject_type' => 'change_order',
            ]);

            return $locked->fresh();
        });
    }

    /* ─────────────────────────── الإيصال ─────────────────────────── */

    /** إرسالُ الرابط بالبريد — إيصالٌ لا تحويلُ حال، كما في العرض */
    public static function email(ChangeOrder $order, string $to, ?string $note, ?User $actor = null): void
    {
        if (! $order->isDecidable()) {
            throw new RuntimeException(__('لا يُرسَل بالبريد إلا أمرٌ خرج وينتظر قرارًا.'));
        }

        $notifier = Notifiers::email();

        if (! $notifier->configured()) {
            throw new RuntimeException(__('إرسالُ البريد غير مضبوط على الخادم — انسخ الرابط وأرسله بنفسك.'));
        }

        $business = Business::find($order->business_id);
        $reminder = $order->sent_to !== null;
        $label = self::label($order);

        $result = $notifier->send(new MessageIntent(
            to: $to,
            subject: ($reminder ? __('تذكير: ') : '').__('أمر تغيير :label — :company', [
                'label' => $label, 'company' => $business?->name ?? '',
            ]),
            paragraphs: array_values(array_filter([
                __('تحية طيبة،'),
                __('نرفق أمر التغيير :label على مشروع «:project» — :title.', [
                    'label' => $label, 'project' => (string) $order->project?->name, 'title' => (string) $order->title,
                ]),
                Projects::text($note, 2000),
                __('يمكنكم قراءة الأمر كاملًا وقبوله إلكترونيًا من الرابط أدناه.'),
            ], fn ($p) => filled($p))),
            url: PublicDocument::url($order),
            action: __('فتح أمر التغيير'),
            templateKey: $reminder ? 'change_order.reminder' : 'change_order.link',
            senderName: $business?->name,
            replyTo: filled($actor?->email) ? $actor->email : (filled($business?->email) ? $business->email : null),
            footer: $business?->name,
        ));

        if (! $result->sent) {
            throw new RuntimeException((string) $result->reason);
        }

        if (! $reminder) {
            $order->forceFill(['sent_to' => Projects::text($to, 120)])->saveQuietly();
        }

        Activity::log('updated', ($reminder ? 'أرسل تذكيرًا بأمر التغيير ' : 'أرسل أمر التغيير ').$label.' بالبريد إلى '.$to, [
            'subject_id' => $order->id, 'subject_type' => 'change_order', 'icon' => 'mail',
        ]);
    }

    /** خبرُ القرار إلى الشركة — أفضلُ جهدٍ لا شرط */
    public static function notifyDecision(ChangeOrder $order, DocumentApproval $approval): void
    {
        $notifier = Notifiers::email();
        $business = Business::find($order->business_id);

        if (! $notifier->configured() || ! Mailer::deliverable($business)) {
            return;
        }

        $label = self::label($order);
        $accepted = $approval->isAccepted();

        $notifier->send(new MessageIntent(
            to: (string) $business->email,
            subject: $accepted
                ? __('قبل العميل أمر التغيير :label', ['label' => $label])
                : __('اعتذر العميل عن أمر التغيير :label', ['label' => $label]),
            paragraphs: array_values(array_filter([
                $approval->signer_name.(filled($approval->signer_title) ? ' ('.$approval->signer_title.')' : ''),
                $accepted
                    ? __('قبل الأمر إلكترونيًا في :at — وطُبّق على المشروع.', ['at' => $approval->decided_at?->format('Y-m-d H:i')])
                    : __('اعتذر عن الأمر في :at — السبب: :reason', [
                        'at' => $approval->decided_at?->format('Y-m-d H:i'), 'reason' => (string) $approval->decline_reason,
                    ]),
            ])),
            url: route('admin.changeOrders.show', $order->id),
            action: __('فتح الأمر في النظام'),
            templateKey: $accepted ? 'change_order.accepted' : 'change_order.declined',
            senderName: $business->name,
        ));
    }

    public static function markViewed(ChangeOrder $order): void
    {
        $order->forceFill([
            'first_viewed_at' => $order->first_viewed_at ?? now(),
            'last_viewed_at' => now(),
            'view_count' => (int) $order->view_count + 1,
        ])->saveQuietly();
    }

    /* ─────────────────────────── البصمة ─────────────────────────── */

    public static function contentHash(ChangeOrder $order): string
    {
        return Fingerprint::of(self::hashPayload($order));
    }

    /**
     * الحمولةُ التي تُبصَم — وفيها **الأساس**: بصمةُ العرض المقبول وأرقامُ
     * الأوامر المقبولة قبله. فالدليلُ يقول على أيّ نطاقٍ وقع هذا التغيير.
     *
     * @return array<string, mixed>
     */
    public static function hashPayload(ChangeOrder $order): array
    {
        $order->loadMissing('project.quotation');
        $n3 = fn ($v) => number_format((float) $v, 3, '.', '');

        return [
            'base' => [
                'q' => (string) $order->project?->quotation?->content_hash,
                'co' => ChangeOrder::where('project_id', $order->project_id)->accepted()
                    ->when($order->exists, fn ($q) => $q->whereKeyNot($order->id))
                    ->where('sequence', '<', (int) $order->sequence)
                    ->orderBy('sequence')->pluck('number')->map(fn ($n) => (string) $n)->all(),
            ],
            'head' => [
                (string) $order->title,
                (string) $order->reason,
                (string) $order->requested_by_party,
                (string) $order->requested_by_name,
                (string) $order->new_starts_at?->toDateString(),
                (string) $order->new_ends_at?->toDateString(),
                (string) $order->valid_until?->toDateString(),
            ],
            'items' => $order->items->map(fn (ChangeOrderItem $i) => [
                'i' => $i->project_item_id,
                'p' => $i->product_id,
                'd' => (string) $i->description,
                'u' => (string) $i->unit,
                'q' => $n3($i->quantity),
                'l' => $n3($i->list_price),
                'n' => $n3($i->unit_price),
                'x' => $n3($i->discount),
                'r' => number_format((float) $i->tax_rate, 2, '.', ''),
                't' => $n3($i->line_total),
            ])->values()->all(),
            'totals' => [
                $n3($order->subtotal_delta), $n3($order->discount_delta),
                $n3($order->tax_delta), $n3($order->total_delta),
            ],
            'requirements' => [
                'add' => $order->addedRequirements()->get()->map(fn ($r) => [(string) $r->title, (string) $r->details, $r->project_item_id])->all(),
                'remove' => $order->removedRequirements()->get()->map(fn ($r) => [$r->id, (string) $r->title, (string) $r->details])->all(),
            ],
            'policies' => collect($order->policy_snapshot ?? [])->map(fn (array $p) => [
                'k' => (string) ($p['key'] ?? ''),
                'v' => (int) ($p['version'] ?? 0),
                'a' => (string) ($p['text_ar'] ?? ''),
                'e' => (string) ($p['text_en'] ?? ''),
            ])->values()->all(),
        ];
    }

    /** اسمُ الأمر في السجلّ — رقمُه، أو «مسودّة» إن لم يُرقَّم */
    public static function label(ChangeOrder $order): string
    {
        return $order->number ?: __('مسودة أمر تغيير').' #'.$order->sequence;
    }
}
