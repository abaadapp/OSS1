<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetReservation;
use App\Models\FixedAsset;
use App\Models\OperationIncident;
use App\Support\Demo;
use App\Support\Operations\Assets;
use App\Support\Operations\Incidents;
use App\Support\Operations\Reservations;
use App\Support\Pagination;
use App\Support\Permissions;
use App\Support\Search;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * سجلُّ المعدّات — الشاشات. المرحلة 10.
 *
 * التسجيلُ والحالُ في `Assets`، والحجزُ والردُّ في `Reservations`. وما هنا
 * قراءةُ طلبٍ وسؤالُ إذنٍ وردُّ شاشة.
 */
class AssetController extends Controller
{
    private function bid(): int
    {
        return (int) (auth()->user()->business_id ?? Demo::bid());
    }

    private function find(int|string $id): Asset
    {
        return Asset::where('business_id', $this->bid())->whereKey($id)->firstOrFail();
    }

    /** @return array<string, bool> */
    private function may(): array
    {
        $user = auth()->user();

        return [
            'manage' => (bool) $user?->may(Permissions::ASSETS_MANAGE),
            'reserve' => (bool) $user?->may(Permissions::ASSETS_RESERVE),
            'checkout' => (bool) $user?->may(Permissions::ASSETS_CHECKOUT),
            'return' => (bool) $user?->may(Permissions::ASSETS_RETURN),
            'operations' => (bool) $user?->allows('operations'),
        ];
    }

    /* ─────────────────────────── القائمة ─────────────────────────── */

    public function index(Request $request): Response
    {
        $bid = $this->bid();

        $from = filled($request->string('from')->toString()) ? Carbon::parse($request->string('from')->toString()) : null;
        $until = filled($request->string('until')->toString()) ? Carbon::parse($request->string('until')->toString()) : null;
        $busy = $from !== null && $until !== null && $until->gt($from)
            ? AssetReservation::where('business_id', $bid)->whereIn('status', AssetReservation::OCCUPYING)
                ->where('reserved_from', '<', $until)->where('reserved_until', '>', $from)->pluck('asset_id')->flip()
            : null;

        $page = Asset::where('business_id', $bid)
            ->when($request->string('q')->toString(), fn ($q, $s) => $q->where(
                fn ($w) => $w->where('asset_code', Search::like(), "%{$s}%")
                    ->orWhere('name', Search::like(), "%{$s}%")
                    ->orWhere('serial_number', Search::like(), "%{$s}%")
                    ->orWhere('model', Search::like(), "%{$s}%")
                    ->orWhere('brand', Search::like(), "%{$s}%")
            ))
            ->when($request->string('status')->toString(), fn ($q, $s) => in_array($s, Asset::STATUSES, true) ? $q->where('status', $s) : $q->whereRaw('1 = 0'))
            ->when($request->string('category')->toString(), fn ($q, $c) => $q->where('category', $c))
            ->when($request->string('condition')->toString(), fn ($q, $c) => in_array($c, Asset::CONDITIONS, true) ? $q->where('condition', $c) : $q)
            ->when($request->string('location')->toString(), fn ($q, $l) => $q->where('location', Search::like(), "%{$l}%"))
            ->when($busy !== null && $request->boolean('available'), fn ($q) => $q->whereNotIn('status', Asset::OUT_OF_SERVICE)->whereNotIn('id', $busy->keys()->all()))
            ->orderBy('asset_code')->paginate(50)->withQueryString();

        $base = fn () => Asset::where('business_id', $bid);

        return Inertia::render('Admin/Assets/Index', [
            'assets' => collect($page->items())->map(fn (Asset $a) => $this->row($a) + ['free' => $busy === null ? null : (! $a->isOutOfService() && ! isset($busy[$a->id]))])->all(),
            'pagination' => Pagination::meta($page),
            'filters' => $request->only('q', 'status', 'category', 'condition', 'location', 'from', 'until', 'available'),
            'statuses' => Asset::STATUSES,
            'conditions' => Asset::CONDITIONS,
            'categories' => $this->categories($bid),
            'locations' => Asset::where('business_id', $bid)->whereNotNull('location')->select('location')->distinct()->orderBy('location')->pluck('location')->all(),
            'fixed_assets' => FixedAsset::where('business_id', $bid)->orderBy('name')->limit(300)->get(['id', 'name'])->all(),
            'totals' => [
                'all' => (int) $base()->count(),
                'available' => (int) $base()->where('status', Asset::AVAILABLE)->count(),
                'deployed' => (int) $base()->where('status', Asset::DEPLOYED)->count(),
                'out_of_service' => (int) $base()->whereIn('status', Asset::OUT_OF_SERVICE)->count(),
                'outstanding' => (int) AssetReservation::where('business_id', $bid)->where('status', AssetReservation::CHECKED_OUT)->where('reserved_until', '<', now())->count(),
            ],
            'labels' => $this->labels(),
            'may' => $this->may(),
        ]);
    }

    /** التقويم — ما يشغل كلَّ قطعةٍ خلال نافذة، مرتَّبًا بالقطعة ثمّ الوقت */
    public function calendar(Request $request): Response
    {
        $bid = $this->bid();
        $from = filled($request->string('from')->toString()) ? Carbon::parse($request->string('from')->toString())->startOfDay() : now()->startOfDay();
        $days = max(7, min(60, (int) ($request->integer('days') ?: 14)));
        $until = $from->copy()->addDays($days)->endOfDay();

        $reservations = AssetReservation::where('business_id', $bid)->whereIn('status', AssetReservation::OCCUPYING)
            ->where('reserved_from', '<', $until)->where('reserved_until', '>', $from)
            ->when($request->string('category')->toString(), fn ($q, $c) => $q->whereHas('asset', fn ($a) => $a->where('category', $c)))
            ->with('asset', 'operation:id,code,name,status', 'custodian:id,name')
            ->orderBy('reserved_from')->limit(1000)->get();

        $assets = Asset::where('business_id', $bid)
            ->when($request->string('category')->toString(), fn ($q, $c) => $q->where('category', $c))
            ->orderBy('asset_code')->limit(500)->get();

        return Inertia::render('Admin/Assets/Calendar', [
            'from' => $from->toDateString(),
            'days' => $days,
            'filters' => $request->only('from', 'days', 'category'),
            'assets' => $assets->map(fn (Asset $a) => $this->row($a))->all(),
            'reservations' => $reservations->map(fn (AssetReservation $r) => [
                'id' => $r->id, 'asset_id' => $r->asset_id, 'status' => $r->status,
                'from' => $r->reserved_from->format('Y-m-d H:i'), 'until' => $r->reserved_until->format('Y-m-d H:i'),
                'operation' => $r->operation === null ? null : ['id' => $r->operation->id, 'code' => $r->operation->code, 'name' => $r->operation->name],
                'custodian' => $r->custodian?->name,
            ])->all(),
            'categories' => $this->categories($bid),
            'labels' => $this->labels(),
            'may' => $this->may(),
        ]);
    }

    /* ─────────────────────────── البطاقة ─────────────────────────── */

    public function show(int|string $id): Response
    {
        $asset = $this->find($id);

        /* الحجزُ الحاليّ: ما خرج ولم يعد، أو ما نافذتُه تشمل الآن */
        $current = AssetReservation::where('asset_id', $asset->id)
            ->where(fn ($q) => $q->where('status', AssetReservation::CHECKED_OUT)
                ->orWhere(fn ($w) => $w->where('status', AssetReservation::RESERVED)->where('reserved_from', '<=', now())->where('reserved_until', '>=', now())))
            ->with('operation:id,code,name,status', 'custodian:id,name')->orderBy('reserved_from')->first();

        $history = AssetReservation::where('asset_id', $asset->id)
            ->with('operation:id,code,name,status', 'custodian:id,name', 'assigner:id,name')
            ->orderByDesc('reserved_from')->limit(100)->get();

        return Inertia::render('Admin/Assets/Show', [
            'asset' => $this->row($asset) + [
                'brand' => $asset->brand, 'model' => $asset->model, 'serial_number' => $asset->serial_number,
                'purchase_date' => $asset->purchase_date?->toDateString(), 'purchase_cost' => $asset->purchase_cost === null ? null : (float) $asset->purchase_cost,
                'notes' => $asset->notes, 'fixed_asset_id' => $asset->fixed_asset_id, 'fixed_asset' => $asset->fixedAsset?->name,
                'retired_at' => $asset->retired_at?->format('Y-m-d'), 'metadata' => $asset->metadata,
            ],
            'current' => $current === null ? null : $this->reservationRow($current),
            'upcoming' => Reservations::upcomingFor($asset)->map(fn (AssetReservation $r) => $this->reservationRow($r))->all(),
            'history' => $history->map(fn (AssetReservation $r) => $this->reservationRow($r))->all(),
            'incidents' => OperationIncident::where('asset_id', $asset->id)->with('operation:id,code,name')->orderByDesc('occurred_at')->limit(50)->get()
                ->map(fn (OperationIncident $i) => [
                    'id' => $i->id, 'title' => $i->title, 'severity' => $i->severity, 'status' => $i->status,
                    'occurred_at' => $i->occurred_at?->format('Y-m-d H:i'), 'operation' => $i->operation?->code, 'operation_id' => $i->event_operation_id,
                ])->all(),
            'categories' => $this->categories($this->bid()),
            'fixed_assets' => FixedAsset::where('business_id', $this->bid())->orderBy('name')->limit(300)->get(['id', 'name'])->all(),
            'labels' => $this->labels(),
            'may' => $this->may(),
        ]);
    }

    /* ─────────────────────────── الكتابة ─────────────────────────── */

    public function store(Request $request)
    {
        abort_if(! auth()->user()?->may(Permissions::ASSETS_MANAGE), 403);
        $data = $this->data($request);

        try {
            $asset = Assets::create($this->bid(), $data, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['asset' => $e->getMessage()]);
        }

        return redirect()->route('admin.assets.show', $asset->id)->with('toast', ['msg' => __('سُجّلت القطعة :code', ['code' => $asset->asset_code]), 'type' => 'success']);
    }

    public function update(Request $request, int|string $id)
    {
        abort_if(! auth()->user()?->may(Permissions::ASSETS_MANAGE), 403);
        $asset = $this->find($id);
        $data = $this->data($request);

        try {
            Assets::update($asset, $data, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['asset' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('حُفظت القطعة'), 'type' => 'success']);
    }

    public function status(Request $request, int|string $id)
    {
        abort_if(! auth()->user()?->may(Permissions::ASSETS_MANAGE), 403);
        $asset = $this->find($id);
        $data = $request->validate(['status' => ['required', 'string', 'max:20'], 'reason' => ['nullable', 'string', 'max:255']]);

        try {
            Assets::setStatus($asset, $data['status'], $data['reason'] ?? null, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['asset' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('غُيّرت حال القطعة'), 'type' => 'success']);
    }

    /** الردُّ من بطاقة القطعة — الخدمةُ نفسُها التي تنادى من شاشة الفعاليّة */
    public function giveBack(Request $request, int|string $id, int|string $rid)
    {
        abort_if(! auth()->user()?->may(Permissions::ASSETS_RETURN), 403);
        $asset = $this->find($id);
        $reservation = AssetReservation::where('asset_id', $asset->id)->whereKey($rid)->with('operation', 'requirement')->firstOrFail();
        $data = $request->validate([
            'condition' => ['required', 'string', 'max:20'],
            'missing_accessories' => ['nullable', 'string', 'max:2000'],
            'damage_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            Reservations::giveBack($reservation, $data, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['asset' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('رُدّت القطعة'), 'type' => 'success']);
    }

    /** @return array<string, mixed> */
    private function data(Request $request): array
    {
        return $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'category' => ['sometimes', 'nullable', 'string', 'max:60'],
            'brand' => ['sometimes', 'nullable', 'string', 'max:80'],
            'model' => ['sometimes', 'nullable', 'string', 'max:80'],
            'serial_number' => ['sometimes', 'nullable', 'string', 'max:120'],
            'purchase_date' => ['sometimes', 'nullable', 'date'],
            'purchase_cost' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'condition' => ['sometimes', 'nullable', 'string', 'max:20'],
            'location' => ['sometimes', 'nullable', 'string', 'max:120'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'fixed_asset_id' => ['sometimes', 'nullable', 'integer'],
        ]);
    }

    /* ─────────────────────────── الصفوف ─────────────────────────── */

    /** @return array<string, mixed> */
    private function row(Asset $a): array
    {
        return [
            'id' => $a->id, 'code' => $a->asset_code, 'name' => $a->name, 'category' => $a->category, 'category_label' => Assets::categoryLabel($a->category),
            'status' => $a->status, 'condition' => $a->condition, 'location' => $a->location, 'brand' => $a->brand, 'model' => $a->model, 'serial_number' => $a->serial_number,
            'out_of_service' => $a->isOutOfService(),
        ];
    }

    /** @return array<string, mixed> */
    private function reservationRow(AssetReservation $r): array
    {
        return [
            'id' => $r->id, 'status' => $r->status,
            'operation' => $r->operation === null ? null : ['id' => $r->operation->id, 'code' => $r->operation->code, 'name' => $r->operation->name, 'status' => $r->operation->status],
            'reserved_from' => $r->reserved_from?->format('Y-m-d H:i'), 'reserved_until' => $r->reserved_until?->format('Y-m-d H:i'),
            'checked_out_at' => $r->checked_out_at?->format('Y-m-d H:i'), 'custodian' => $r->custodian?->name, 'assigned_by' => $r->assigner?->name ?? null,
            'returned_at' => $r->returned_at?->format('Y-m-d H:i'), 'return_condition' => $r->return_condition,
            'missing_accessories' => $r->missing_accessories, 'damage_notes' => $r->damage_notes,
        ];
    }

    /** @return list<array{value: string, label: string}> */
    private function categories(int $bid): array
    {
        $used = Asset::where('business_id', $bid)->select('category')->distinct()->pluck('category')->all();
        $all = array_values(array_unique([...$used, ...Assets::SUGGESTED_CATEGORIES]));

        return array_map(fn (string $c) => ['value' => $c, 'label' => Assets::categoryLabel($c)], $all);
    }

    private function labels(): array
    {
        $t = fn (array $map) => collect($map)->map(fn ($l) => __($l))->all();

        return [
            'asset_status' => $t(Assets::STATUS_LABELS),
            'condition' => $t(Assets::CONDITION_LABELS),
            'severity' => $t(Incidents::SEVERITY_LABELS),
            'incident_status' => $t(Incidents::STATUS_LABELS),
        ];
    }
}
