<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EventOperation;
use App\Models\OperationOrganizer;
use App\Models\Organizer;
use App\Models\OrganizerCategory;
use App\Support\Demo;
use App\Support\Operations\OperationOrganizers;
use App\Support\Organizers;
use App\Support\Permissions;
use App\Support\Search;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * قسمُ «المنظّمون» — السجلُّ وتصنيفاتُه.
 *
 * القراءةُ لمن فُتح له القسم، والكتابةُ فعلٌ بالاسم (`organizers.manage`).
 * وتعيينُهم على الفعاليّة من شاشة الفعاليّة (`EventOperationController`).
 */
class OrganizerController extends Controller
{
    private function bid(): int
    {
        return (int) (auth()->user()->business_id ?? Demo::bid());
    }

    private function find(int|string $id): Organizer
    {
        return Organizer::where('business_id', $this->bid())->whereKey($id)->firstOrFail();
    }

    private function gate(): void
    {
        abort_if(! auth()->user()?->may(Permissions::ORGANIZERS_MANAGE), 403);
    }

    /** @return array<string, array<string, string>> */
    private function labels(): array
    {
        $t = fn (array $map) => collect($map)->map(fn ($l) => __($l))->all();

        return [
            'gender' => $t(Organizers::GENDER_LABELS),
            'status' => $t(Organizers::STATUS_LABELS),
            'source' => $t(Organizers::SOURCE_LABELS),
            'assignment' => $t(OperationOrganizers::STATUS_LABELS),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function categories(): array
    {
        return OrganizerCategory::where('business_id', $this->bid())->withCount('organizers')
            ->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn (OrganizerCategory $c) => ['id' => $c->id, 'name' => $c->name, 'active' => (bool) $c->active, 'organizers' => $c->organizers_count])
            ->all();
    }

    public function index(Request $request): Response
    {
        $bid = $this->bid();
        Organizers::ensureCategories($bid);

        $filters = [
            'q' => Search::term($request),
            'gender' => in_array($request->query('gender'), Organizer::GENDERS, true) ? $request->query('gender') : null,
            'status' => in_array($request->query('status'), Organizer::STATUSES, true) ? $request->query('status') : null,
            'category' => $request->integer('category') ?: null,
        ];

        $like = Search::like();
        $organizers = Organizer::where('business_id', $bid)
            ->when($filters['q'], fn ($q, $s) => $q->where(fn ($w) => $w->where('name', $like, "%{$s}%")
                ->orWhere('phone', $like, "%{$s}%")->orWhere('whatsapp', $like, "%{$s}%")->orWhere('email', $like, "%{$s}%")))
            ->when($filters['gender'], fn ($q, $g) => $q->where('gender', $g))
            ->when($filters['status'], fn ($q, $st) => $q->where('status', $st))
            ->when($filters['category'], fn ($q, $c) => $q->whereHas('categories', fn ($w) => $w->where('organizer_categories.id', $c)))
            ->with('categories:id,name')
            ->withCount(['assignments as upcoming_count' => fn ($q) => $q->whereIn('status', OperationOrganizer::EXPECTED)
                ->whereHas('operation', fn ($w) => $w->whereIn('status', EventOperation::ACTIVE))])
            ->orderBy('name')->limit(500)->get();

        $all = Organizer::where('business_id', $bid);

        return Inertia::render('Admin/Organizers/Index', [
            'organizers' => $organizers->map(fn (Organizer $o) => $this->row($o) + ['upcoming' => (int) $o->upcoming_count])->all(),
            'stats' => [
                'total' => (clone $all)->count(),
                'available' => (clone $all)->where('status', Organizer::AVAILABLE)->count(),
                'male' => (clone $all)->where('gender', Organizer::MALE)->count(),
                'female' => (clone $all)->where('gender', Organizer::FEMALE)->count(),
            ],
            'filters' => $filters,
            'categories' => $this->categories(),
            'labels' => $this->labels(),
            'may' => ['manage' => auth()->user()->may(Permissions::ORGANIZERS_MANAGE)],
        ]);
    }

    public function show(int|string $id): Response
    {
        $organizer = $this->find($id)->load('categories:id,name');
        $assignments = OperationOrganizer::where('organizer_id', $organizer->id)
            ->with(['operation:id,code,name,status,event_starts_at,venue_name,project_id', 'operation.project:id,code,name', 'category:id,name'])
            ->orderByDesc('starts_at')->get();

        $row = fn (OperationOrganizer $a) => [
            'id' => $a->id,
            'operation' => $a->operation ? [
                'id' => $a->operation->id, 'code' => $a->operation->code, 'name' => $a->operation->name, 'status' => $a->operation->status,
                'venue' => $a->operation->venue_name, 'project' => $a->operation->project?->code,
            ] : null,
            'category' => $a->category?->name,
            'starts_at' => optional($a->starts_at)->format('Y-m-d H:i'),
            'ends_at' => optional($a->ends_at)->format('Y-m-d H:i'),
            'status' => $a->status,
            'notes' => $a->notes,
        ];

        $upcoming = $assignments->filter(fn (OperationOrganizer $a) => $a->isExpected() && $a->operation?->isActive())
            ->sortBy('starts_at')->values();

        return Inertia::render('Admin/Organizers/Show', [
            'organizer' => $this->row($organizer) + ['joined_at' => optional($organizer->joined_at)->format('Y-m-d'), 'notes' => $organizer->notes],
            'upcoming' => $upcoming->map($row)->all(),
            'history' => $assignments->reject(fn ($a) => $upcoming->contains('id', $a->id))->map($row)->values()->all(),
            'categories' => $this->categories(),
            'labels' => $this->labels(),
            'may' => ['manage' => auth()->user()->may(Permissions::ORGANIZERS_MANAGE), 'operations' => auth()->user()->allows('operations')],
        ]);
    }

    public function store(Request $request)
    {
        $this->gate();
        $data = $request->validate(Organizers::rules($this->bid()), [], $this->names());

        try {
            $organizer = Organizers::create($this->bid(), $data, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['organizer' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('أُضيف المنظّم :name', ['name' => $organizer->name]), 'type' => 'success']);
    }

    public function update(Request $request, int|string $id)
    {
        $this->gate();
        $organizer = $this->find($id);
        $data = $request->validate(Organizers::rules($this->bid()), [], $this->names());

        return $this->attempt(fn () => Organizers::update($organizer, $data, auth()->user()), __('حُفظت بيانات المنظّم'));
    }

    public function status(Request $request, int|string $id)
    {
        $this->gate();
        $organizer = $this->find($id);
        $data = $request->validate(['status' => ['required', Rule::in(Organizer::STATUSES)]]);

        return $this->attempt(fn () => Organizers::setStatus($organizer, $data['status'], auth()->user()), __('تغيّر حال المنظّم'));
    }

    public function destroy(int|string $id)
    {
        $this->gate();
        $organizer = $this->find($id);

        try {
            Organizers::delete($organizer, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['organizer' => $e->getMessage()]);
        }

        return redirect()->route('admin.organizers.index')->with('toast', ['msg' => __('حُذف المنظّم'), 'type' => 'warning']);
    }

    /* ─────────────────────────── التصنيفات ─────────────────────────── */

    public function storeCategory(Request $request)
    {
        $this->gate();
        $data = $request->validate(['name' => ['required', 'string', 'max:80']], [], ['name' => __('اسم التصنيف')]);

        return $this->attempt(fn () => Organizers::saveCategory($this->bid(), $data['name'], true, null, auth()->user()), __('أُضيف التصنيف'));
    }

    public function updateCategory(Request $request, int|string $id)
    {
        $this->gate();
        $category = OrganizerCategory::where('business_id', $this->bid())->findOrFail($id);
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'active' => ['nullable', 'boolean']], [], ['name' => __('اسم التصنيف')]);

        return $this->attempt(fn () => Organizers::saveCategory($this->bid(), $data['name'], $request->boolean('active', true), $category, auth()->user()), __('حُفظ التصنيف'));
    }

    public function destroyCategory(int|string $id)
    {
        $this->gate();
        $category = OrganizerCategory::where('business_id', $this->bid())->findOrFail($id);

        return $this->attempt(fn () => Organizers::deleteCategory($category), __('حُذف التصنيف'));
    }

    /** @return array<string, mixed> */
    private function row(Organizer $o): array
    {
        return [
            'id' => $o->id, 'name' => $o->name, 'phone' => $o->phone, 'whatsapp' => $o->whatsapp, 'email' => $o->email,
            'gender' => $o->gender, 'status' => $o->status, 'source' => $o->source,
            'categories' => $o->categories->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values()->all(),
        ];
    }

    private function names(): array
    {
        return ['name' => __('الاسم'), 'gender' => __('الجنس'), 'email' => __('البريد الإلكتروني'), 'status' => __('الحالة')];
    }

    private function attempt(callable $fn, string $message)
    {
        try {
            $fn();
        } catch (RuntimeException $e) {
            return back()->withErrors(['organizer' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => $message, 'type' => 'success']);
    }
}
