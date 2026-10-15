<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PublicDocumentController;
use App\Models\Communication;
use App\Models\Project;
use App\Models\ScopeOfWork;
use App\Support\Communication\Templates;
use App\Support\CustomerContacts;
use App\Support\Demo;
use App\Support\InvoiceBranding;
use App\Support\Messaging\Notifiers;
use App\Support\Permissions;
use App\Support\ProjectWorkflow;
use App\Support\ScopesOfWork;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * نطاقُ العمل — شاشةُ إعداده ومراجعته وإرساله، داخل المشروع.
 *
 * ولا حسابَ هنا: النسخُ والبصمةُ والإرسالُ في `ScopesOfWork`، والبنودُ
 * والقيمةُ من `ProjectScope`. وما في هذا الملفّ: يقرأ الطلب، ويسأل عن
 * الإذن، ويردّ شاشة.
 */
class ScopeOfWorkController extends Controller
{
    private function bid(): int
    {
        return (int) (auth()->user()->business_id ?? Demo::bid());
    }

    /** المشروعُ من شركة الطالب — ولا رقمَ من رابطٍ يفتح مشروعَ غيره */
    private function project(int|string $id): Project
    {
        return Project::where('business_id', $this->bid())->whereKey($id)->with('customer')->firstOrFail();
    }

    /** النسخةُ من هذا المشروع وحدَه */
    private function scope(Project $project, int|string $scope): ScopeOfWork
    {
        return ScopeOfWork::where('business_id', $this->bid())->where('project_id', $project->id)->whereKey($scope)->firstOrFail();
    }

    private function manage(): void
    {
        abort_if(! auth()->user()?->may(Permissions::PROJECT_MANAGE), 403);
    }

    private function commercial(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->may(Permissions::PROJECT_BUDGET_VIEW) || $user?->may(Permissions::CHANGE_ORDER_CREATE));
    }

    public function show(int|string $id): Response
    {
        $project = $this->project($id);
        $commercial = $this->commercial();
        $user = auth()->user();
        $revisions = ScopeOfWork::where('business_id', $project->business_id)->where('project_id', $project->id)
            ->orderByDesc('revision')->get();
        $whatsapp = Notifiers::whatsapp()->configured();

        return Inertia::render('Admin/Projects/ScopeOfWork', [
            'project' => [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
                'customer' => $project->customer?->name ?? '—',
                'status' => $project->status,
                'open' => $project->isOpen(),
                'baselined' => $project->isBaselined(),
                'required' => ScopesOfWork::required($project),
            ],
            'current' => $revisions->first() === null ? null : ScopesOfWork::present($revisions->first(), $commercial),
            'revisions' => $revisions->map(fn (ScopeOfWork $s) => [
                'id' => $s->id,
                'number' => $s->number,
                'revision' => $s->revision,
                'status' => $s->status,
                'state' => $s->presentedState(),
                'sent_at' => optional($s->sent_at)->format('Y-m-d H:i'),
                'decided_at' => optional($s->decided_at)->format('Y-m-d H:i'),
                'decided_by_name' => $s->decided_by_name,
                'superseded_at' => optional($s->superseded_at)->format('Y-m-d H:i'),
                'url' => ScopesOfWork::url($s),
            ])->all(),
            /* حالُ القنوات — والنسخُ اليدويّ للرابط يبقى حين لا يُوصِل شيء */
            'channels' => [
                'email' => Notifiers::email()->configured(),
                'whatsapp' => $whatsapp,
                'whatsapp_template' => [
                    'ar' => Templates::whatsapp((int) $project->business_id, 'scope.link', 'ar') !== null,
                    'en' => Templates::whatsapp((int) $project->business_id, 'scope.link', 'en') !== null,
                ],
            ],
            /* أشخاصُ اتصال الجهة — يُختار منهم من يوقّع، وأسماؤه لقطةٌ في النسخة (المرحلة 14) */
            'contacts' => $project->customer ? CustomerContacts::options($project->customer) : [],
            'may' => [
                'manage' => (bool) $user?->may(Permissions::PROJECT_MANAGE),
                'send' => (bool) $user?->may(Permissions::MESSAGING_SEND),
                'communications' => (bool) $user?->may(Permissions::COMMUNICATION_VIEW),
                'templates' => (bool) $user?->may(Permissions::COMMUNICATION_TEMPLATES_MANAGE),
                'commercial' => $commercial,
            ],
            'channel_names' => [Communication::EMAIL, Communication::WHATSAPP],
        ]);
    }

    /** «إعداد نطاق العمل» أو «نسخة جديدة» — ومرّةً لكلّ حالة، انظر `ScopesOfWork::start` */
    public function store(int|string $id)
    {
        $this->manage();
        $project = $this->project($id);

        try {
            $scope = ScopesOfWork::start($project, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['scope' => $e->getMessage()]);
        }

        ProjectWorkflow::touch($project, ProjectWorkflow::SCOPE);

        return redirect()->route('admin.projects.scope.show', $project->id)
            ->with('toast', ['msg' => __('نطاق العمل :n مسودّة — أكمله وراجعه', ['n' => $scope->number]), 'type' => 'success']);
    }

    public function update(Request $request, int|string $id, int|string $scope)
    {
        $this->manage();
        $project = $this->project($id);
        $row = $this->scope($project, $scope);

        $rules = collect(ScopesOfWork::TEXT_FIELDS)->map(fn (int $max) => ['nullable', 'string', 'max:'.$max])->all();
        $rules['contact_email'] = ['nullable', 'email', 'max:120'];
        $rules['project_name'] = ['required', 'string', 'max:255'];
        $rules['starts_at'] = ['nullable', 'date'];
        $rules['ends_at'] = ['nullable', 'date', 'after_or_equal:starts_at'];
        $rules['show_value'] = ['nullable', 'boolean'];

        $data = $request->validate($rules, [], [
            'project_name' => __('اسم المشروع'), 'contact_email' => __('البريد الإلكتروني'),
            'starts_at' => __('تاريخ البدء'), 'ends_at' => __('تاريخ الانتهاء'),
        ]);

        /* والقيمةُ المرجعيّة لا يُظهرها للعميل من لا يراها هو */
        if (! $this->commercial()) {
            unset($data['show_value']);
        }

        try {
            ScopesOfWork::update($row, $data, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['scope' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('حُفظت مسودّة نطاق العمل'), 'type' => 'success']);
    }

    public function ready(int|string $id, int|string $scope)
    {
        $this->manage();
        $project = $this->project($id);

        try {
            ScopesOfWork::markReady($this->scope($project, $scope), auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['scope' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('نطاق العمل جاهز للإرسال'), 'type' => 'success']);
    }

    public function draft(int|string $id, int|string $scope)
    {
        $this->manage();
        $project = $this->project($id);

        try {
            ScopesOfWork::backToDraft($this->scope($project, $scope), auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['scope' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('أُعيد نطاق العمل مسودّةً'), 'type' => 'success']);
    }

    /** «إرسال للعميل للتوقيع» — يُجمَّد ويُبصَم ويُبنى رابطُه، ثمّ يُرسَل من نافذة المراسلات أو يُنسخ */
    public function send(int|string $id, int|string $scope)
    {
        $this->manage();
        $project = $this->project($id);

        try {
            $sent = ScopesOfWork::send($this->scope($project, $scope), auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['scope' => $e->getMessage()]);
        }

        ProjectWorkflow::touch($project, ProjectWorkflow::SCOPE);

        return back()->with('toast', ['msg' => __('خرج نطاق العمل :n للتوقيع — أرسل رابطه للعميل', ['n' => $sent->number]), 'type' => 'success']);
    }

    /** المعاينةُ — الصفحةُ التي سيفتحها العميل بعينها، بلا نموذج توقيع */
    public function preview(int|string $id, int|string $scope): View
    {
        $project = $this->project($id);
        $row = $this->scope($project, $scope)->loadMissing('approval');

        return InvoiceBranding::render($project->business_id, null, fn () => view('public.scope', PublicDocumentController::scopeView($project->business, $row, [
            'preview' => true,
        ])));
    }
}
