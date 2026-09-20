<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ManagementDecision;
use App\Models\ManagementReview;
use App\Models\User;
use App\Support\Demo;
use App\Support\Intelligence\ManagementReviews;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/** المراجعاتُ الإداريّة — الشاشة. المرحلة 11 */
class ManagementReviewController extends Controller
{
    private function bid(): int
    {
        return (int) (auth()->user()->business_id ?? Demo::bid());
    }

    private function find(int|string $id): ManagementReview
    {
        return ManagementReview::where('business_id', $this->bid())->whereKey($id)->firstOrFail();
    }

    private function gate(): void
    {
        abort_if(! auth()->user()?->may(Permissions::MANAGEMENT_REVIEWS_MANAGE), 403);
    }

    public function index(): Response
    {
        $bid = $this->bid();

        return Inertia::render('Admin/Management/Reviews', [
            'reviews' => ManagementReview::where('business_id', $bid)->with('preparer:id,name')->withCount('decisions')->orderByDesc('period_start')->limit(60)->get()
                ->map(fn (ManagementReview $r) => $this->row($r))->all(),
            'may' => ['manage' => (bool) auth()->user()?->may(Permissions::MANAGEMENT_REVIEWS_MANAGE), 'financial' => (bool) auth()->user()?->may(Permissions::ANALYTICS_FINANCIAL)],
        ]);
    }

    public function show(int|string $id): Response
    {
        $review = $this->find($id);
        $review->load('preparer:id,name', 'decisions.owner:id,name');
        $financial = (bool) auth()->user()?->may(Permissions::ANALYTICS_FINANCIAL);

        return Inertia::render('Admin/Management/ReviewShow', [
            'review' => $this->row($review) + [
                'notes' => $review->notes,
                'sections' => $review->sections ?? [],
                /* واللقطةُ الماليّة لمن يقرأ المال — وغيرُه يرى الهيكلَ والتشغيل */
                'snapshot' => $financial ? $review->snapshot : ($review->snapshot === null ? null : ['captured_at' => $review->snapshot['captured_at'] ?? null, 'period' => $review->snapshot['period'] ?? null,
                    'operations' => $review->snapshot['operations'] ?? null, 'funnel' => ['acceptance_rate' => $review->snapshot['funnel']['acceptance_rate'] ?? null]]),
                'decisions' => $review->decisions->map(fn (ManagementDecision $d) => [
                    'id' => $d->id, 'decision' => $d->decision, 'owner' => $d->owner?->name, 'owner_id' => $d->owner_id, 'due_at' => $d->due_at?->toDateString(),
                    'status' => $d->status, 'status_label' => __(ManagementReviews::DECISION_LABELS[$d->status] ?? $d->status), 'notes' => $d->notes,
                ])->all(),
            ],
            'users' => User::where('business_id', $this->bid())->where('status', 'نشط')->orderBy('name')->get(['id', 'name'])->all(),
            'section_keys' => ['financial', 'budget', 'forecast', 'cash', 'projects', 'sales', 'operations', 'risks', 'notes'],
            'may' => ['manage' => (bool) auth()->user()?->may(Permissions::MANAGEMENT_REVIEWS_MANAGE), 'financial' => $financial],
        ]);
    }

    public function store(Request $request)
    {
        $this->gate();
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:160'], 'period_start' => ['required', 'date'], 'period_end' => ['required', 'date'], 'notes' => ['nullable', 'string', 'max:20000'],
        ]);

        try {
            $review = ManagementReviews::create($this->bid(), $data, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['review' => $e->getMessage()]);
        }

        return redirect()->route('admin.management.reviews.show', $review->id)->with('toast', ['msg' => __('أُنشئت المراجعة ولُقطت أرقامُها'), 'type' => 'success']);
    }

    public function update(Request $request, int|string $id)
    {
        $this->gate();
        $data = $request->validate(['title' => ['sometimes', 'nullable', 'string', 'max:160'], 'notes' => ['sometimes', 'nullable', 'string', 'max:20000'], 'sections' => ['sometimes', 'array']]);

        $review = $this->find($id); /* خارج المحاولة — غيابُه 404 لا رسالةٌ حمراء */

        return $this->attempt(fn () => ManagementReviews::update($review, $data, auth()->user()), __('حُفظت المراجعة'));
    }

    public function refresh(int|string $id)
    {
        $this->gate();

        $review = $this->find($id);

        return $this->attempt(fn () => ManagementReviews::refreshSnapshot($review, auth()->user()), __('أُعيد التقاط الأرقام'));
    }

    public function review(int|string $id)
    {
        $this->gate();

        $review = $this->find($id);

        return $this->attempt(fn () => ManagementReviews::markReviewed($review, auth()->user()), __('رُوجعت — واللقطةُ مجمَّدة'));
    }

    public function close(int|string $id)
    {
        $this->gate();

        $review = $this->find($id);

        return $this->attempt(fn () => ManagementReviews::close($review, auth()->user()), __('أُقفلت المراجعة'));
    }

    public function storeDecision(Request $request, int|string $id)
    {
        $this->gate();
        $data = $request->validate(['decision' => ['required', 'string', 'max:255'], 'owner_id' => ['nullable', 'integer'], 'due_at' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:2000']]);

        $review = $this->find($id);

        return $this->attempt(fn () => ManagementReviews::decide($review, $data, auth()->user()), __('سُجّل القرار'));
    }

    public function decisionStatus(Request $request, int|string $id, int|string $did)
    {
        $this->gate();
        $review = $this->find($id);
        $decision = ManagementDecision::where('management_review_id', $review->id)->whereKey($did)->firstOrFail();
        $data = $request->validate(['status' => ['required', 'string', 'max:20']]);

        return $this->attempt(fn () => ManagementReviews::setDecisionStatus($decision, $data['status'], auth()->user()), null);
    }

    /** @return array<string, mixed> */
    private function row(ManagementReview $r): array
    {
        return [
            'id' => $r->id, 'title' => $r->title, 'period_start' => $r->period_start->toDateString(), 'period_end' => $r->period_end->toDateString(),
            'status' => $r->status, 'status_label' => __(ManagementReviews::STATUS_LABELS[$r->status] ?? $r->status), 'prepared_by' => $r->preparer?->name,
            'reviewed_at' => $r->reviewed_at?->format('Y-m-d H:i'), 'closed_at' => $r->closed_at?->format('Y-m-d H:i'),
            'decisions_count' => $r->decisions_count ?? $r->decisions()->count(), 'url' => route('admin.management.reviews.show', $r->id),
        ];
    }

    private function attempt(callable $fn, ?string $message)
    {
        try {
            $fn();
        } catch (RuntimeException $e) {
            return back()->withErrors(['review' => $e->getMessage()]);
        }

        return $message === null ? back() : back()->with('toast', ['msg' => $message, 'type' => 'success']);
    }
}
