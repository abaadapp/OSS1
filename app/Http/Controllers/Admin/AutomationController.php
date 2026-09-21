<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Business;
use App\Models\InternalNotification;
use App\Models\User;
use App\Support\Automation\Audience;
use App\Support\Automation\Engine;
use App\Support\Automation\Rules;
use App\Support\Automation\Settings;
use App\Support\Automation\Triggers;
use App\Support\Communication\Subjects;
use App\Support\Communication\Templates;
use App\Support\Demo;
use App\Support\Messaging\Notifiers;
use App\Support\Pagination;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * الأتمتة — الشاشة: القواعدُ وإعداداتُها، وسجلُّ ما فعله المحرّك.
 *
 * لا حسابَ هنا: القواعدُ في `Automation\Rules`، والإعداداتُ في
 * `Automation\Settings`، والتنفيذُ في `Automation\Engine`.
 */
class AutomationController extends Controller
{
    private const PER_PAGE = [25, 50, 100];

    private function bid(): int
    {
        return (int) (auth()->user()->business_id ?? Demo::bid());
    }

    /** @return array<string, bool> */
    private function may(): array
    {
        $user = auth()->user();

        return [
            'manage' => (bool) $user?->may(Permissions::AUTOMATION_MANAGE),
            'pause' => (bool) $user?->may(Permissions::AUTOMATION_PAUSE),
        ];
    }

    private function mustManage(): void
    {
        abort_if(! auth()->user()?->may(Permissions::AUTOMATION_MANAGE), 403);
    }

    /* ─────────────────────────── القواعد ─────────────────────────── */

    public function index(): Response
    {
        $bid = $this->bid();
        Rules::ensure($bid);
        $settings = Settings::for($bid);

        $lastRuns = AutomationRun::where('business_id', $bid)
            ->selectRaw('automation_rule_id, MAX(created_at) AS last_at, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS failed', [AutomationRun::FAILED])
            ->groupBy('automation_rule_id')->get()->keyBy('automation_rule_id');

        $rules = AutomationRule::where('business_id', $bid)->orderBy('kind')->orderBy('id')->get()
            ->map(function (AutomationRule $r) use ($settings, $lastRuns) {
                $trigger = Triggers::exists($r->trigger) ? Triggers::for($r->trigger) : null;

                return [
                    'id' => $r->id,
                    'key' => $r->key,
                    'name' => $r->name,
                    'kind' => $r->kind,
                    'trigger' => $r->trigger,
                    'trigger_label' => $trigger?->label() ?? $r->trigger,
                    'subject_type' => $trigger?->subjectType(),
                    'audiences' => $trigger?->audiences() ?? [],
                    'enabled' => $r->enabled,
                    'mode' => $r->mode,
                    'effective_mode' => $settings->effectiveMode($r),
                    'channel' => $r->channel,
                    'template_key' => $r->template_key,
                    'template_name' => $r->template_key && Templates::exists($r->template_key) ? __(Templates::CATALOG[$r->template_key]['name']) : null,
                    'steps' => $r->steps(),
                    'send_time' => $r->send_time,
                    'cooldown_hours' => $r->cooldown_hours,
                    'business_hours' => $r->business_hours,
                    'language' => $r->language,
                    'recipients' => $r->recipients ?? [],
                    'retry_once' => $r->retry_once,
                    'last_run_at' => $r->last_run_at?->format('Y-m-d H:i'),
                    'last_action_at' => isset($lastRuns[$r->id]) && $lastRuns[$r->id]->last_at ? substr((string) $lastRuns[$r->id]->last_at, 0, 16) : null,
                    'failed' => (int) ($lastRuns[$r->id]->failed ?? 0),
                    'next_at' => $this->nextAt($settings, $r),
                    'templates' => $trigger?->subjectType()
                        ? collect(Templates::CATALOG)->filter(fn ($t, $k) => $t['subject'] === $trigger->subjectType() && empty($t['internal']))
                            ->map(fn ($t, $k) => ['key' => $k, 'name' => __($t['name']), 'channels' => $t['channels']])->values()->all()
                        : [],
                ];
            })->values()->all();

        return Inertia::render('Admin/Automation/Rules', [
            'rules' => $rules,
            'settings' => $settings->toArray(),
            'channels' => ['email' => Notifiers::email()->configured(), 'whatsapp' => Notifiers::whatsapp()->configured()],
            'audiences' => collect(Audience::LABELS)->map(fn ($l) => __($l))->all(),
            'users' => User::where('business_id', $bid)->where('status', 'نشط')->orderBy('name')->get(['id', 'name'])->all(),
            'timezones' => \DateTimeZone::listIdentifiers(),
            'may' => $this->may(),
        ]);
    }

    /** أقربُ تنفيذٍ متوقَّع — من صفٍّ معلَّق، أو ساعةُ الإرسال القادمة للموجز */
    private function nextAt(Settings $settings, AutomationRule $rule): ?string
    {
        $pending = AutomationRun::where('automation_rule_id', $rule->id)->where('status', AutomationRun::PENDING)->min('scheduled_for');
        if ($pending) {
            return substr((string) $pending, 0, 16);
        }

        if ($rule->trigger === 'morning_brief' && $rule->enabled) {
            $at = $settings->at($settings->now(), 0, $rule->send_time ?: '07:30');
            if ($at->lessThanOrEqualTo(now())) {
                $at->addDay();
            }

            return $at->format('Y-m-d H:i');
        }

        return null;
    }

    public function updateRule(Request $request, int|string $id)
    {
        $this->mustManage();

        $rule = AutomationRule::where('business_id', $this->bid())->whereKey($id)->firstOrFail();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'enabled' => ['sometimes', 'boolean'],
            'mode' => ['sometimes', Rule::in(AutomationRule::MODES)],
            'channel' => ['sometimes', Rule::in(AutomationRule::CHANNELS)],
            'template_key' => ['sometimes', 'nullable', 'string', 'max:80'],
            'steps' => ['sometimes', 'array', 'max:'.Rules::MAX_STEPS],
            'steps.*.offset' => ['required_with:steps', 'integer'],
            'steps.*.audience' => ['required_with:steps', 'string', 'max:30'],
            'send_time' => ['sometimes', 'nullable', 'string', 'max:5'],
            'cooldown_hours' => ['sometimes', 'integer'],
            'business_hours' => ['sometimes', 'boolean'],
            'language' => ['sometimes', Rule::in(['ar', 'en'])],
            'recipients' => ['sometimes', 'nullable', 'array', 'max:50'],
            'recipients.*' => ['integer'],
            'retry_once' => ['sometimes', 'boolean'],
        ]);

        try {
            $rule = Rules::save($rule, $data, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['rule' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('حُفظت القاعدة «:name»', ['name' => $rule->name]), 'type' => 'success']);
    }

    /* ─────────────────────────── الإعدادات ─────────────────────────── */

    public function settings(Request $request)
    {
        $this->mustManage();

        $data = $request->validate([
            'mode' => ['sometimes', Rule::in(Settings::MODES)],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'days' => ['sometimes', 'array', 'max:7'],
            'days.*' => ['integer', 'min:0', 'max:6'],
            'start' => ['sometimes', 'string', 'max:5'],
            'end' => ['sometimes', 'string', 'max:5'],
            /* حدُّ الانفجار — المرحلة 12 */
            'external_cap' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ]);

        try {
            Settings::save($this->bid(), $data, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['settings' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('حُفظت إعدادات الأتمتة'), 'type' => 'success']);
    }

    /** مفتاحُ الأمان — إيقافٌ واستئناف */
    public function pause(Request $request)
    {
        abort_if(! auth()->user()?->may(Permissions::AUTOMATION_PAUSE), 403);

        $data = $request->validate(['paused' => ['required', 'boolean']]);
        $settings = Settings::pause($this->bid(), (bool) $data['paused'], auth()->user());

        return back()->with('toast', [
            'msg' => $settings->paused ? __('أُوقفت الأتمتة — لا يخرج شيءٌ حتى تُستأنف') : __('استُؤنفت الأتمتة'),
            'type' => $settings->paused ? 'warning' : 'success',
        ]);
    }

    /** دورةٌ الآن بيد — لمن يضبط القواعد؛ نافعةٌ في وضع المراقبة ليرى ما سيحدث */
    public function evaluate()
    {
        $this->mustManage();

        $stats = Engine::tick($this->bid());

        return back()->with('toast', [
            'msg' => __('قُيّم :e · نُفّذ :x · رُوقب :o · تُخطّي :s · فشل :f', [
                'e' => $stats['evaluated'], 'x' => $stats['executed'], 'o' => $stats['observed'], 's' => $stats['skipped'], 'f' => $stats['failed'],
            ]),
            'type' => $stats['failed'] > 0 ? 'warning' : 'success',
        ]);
    }

    /* ─────────────────────────── السجلّ ─────────────────────────── */

    public function history(Request $request): Response
    {
        $bid = $this->bid();
        $per = (int) $request->integer('per_page');
        $per = in_array($per, self::PER_PAGE, true) ? $per : self::PER_PAGE[0];
        $from = $request->date('from');
        $to = $request->date('to');

        $query = AutomationRun::where('business_id', $bid)->with('rule', 'communication')
            ->when($request->integer('rule_id'), fn ($q, $id) => $q->where('automation_rule_id', $id))
            ->when($request->string('status')->toString(), fn ($q, $s) => in_array($s, AutomationRun::STATUSES, true) ? $q->where('status', $s) : $q->whereRaw('1 = 0'))
            ->when($request->string('target_type')->toString(), fn ($q, $s) => $q->where('target_type', Subjects::classOf($s) ?? '-'))
            ->when($request->string('channel')->toString(), fn ($q, $s) => $q->whereHas('rule', fn ($r) => $r->where('channel', $s)))
            ->when($from, fn ($q, $d) => $q->where('created_at', '>=', $d->startOfDay()))
            ->when($to, fn ($q, $d) => $q->where('created_at', '<=', $d->endOfDay()));

        $page = (clone $query)->orderByDesc('id')->paginate($per)->withQueryString();
        $counts = (clone $query)->reorder()->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');

        return Inertia::render('Admin/Automation/History', [
            'runs' => collect($page->items())->map(fn (AutomationRun $r) => $this->row($r))->all(),
            'pagination' => Pagination::meta($page),
            'filters' => $request->only('rule_id', 'status', 'target_type', 'channel', 'from', 'to', 'per_page'),
            'totals' => [
                'evaluated' => (int) $counts->sum(),
                'executed' => (int) ($counts[AutomationRun::EXECUTED] ?? 0),
                'observed' => (int) ($counts[AutomationRun::OBSERVED] ?? 0),
                'skipped' => (int) ($counts[AutomationRun::SKIPPED] ?? 0),
                'failed' => (int) ($counts[AutomationRun::FAILED] ?? 0),
                'pending' => (int) ($counts[AutomationRun::PENDING] ?? 0),
            ],
            'rules' => AutomationRule::where('business_id', $bid)->orderBy('name')->get(['id', 'name'])->all(),
            'statuses' => AutomationRun::STATUSES,
            'target_types' => array_keys(Subjects::TYPES),
            'may' => $this->may(),
        ]);
    }

    /** تفصيلُ تشغيلٍ — يقرؤه إنسان: المُطلِق، الشرط، القرار، الفعل، النتيجة */
    public function show(int|string $id): JsonResponse
    {
        $run = AutomationRun::where('business_id', $this->bid())->whereKey($id)->with('rule', 'communication')->firstOrFail();

        return response()->json(['run' => $this->row($run) + [
            'metadata' => collect($run->metadata ?? [])->except(['step'])->all(),
            'idempotency_key' => $run->idempotency_key,
            'notifications' => InternalNotification::where('automation_run_id', $run->id)->with('user:id,name')
                ->get()->map(fn ($n) => ['user' => $n->user?->name, 'title' => $n->title, 'read_at' => $n->read_at?->format('Y-m-d H:i')])->all(),
        ]]);
    }

    public function cancel(int|string $id)
    {
        $this->mustManage();

        $run = AutomationRun::where('business_id', $this->bid())->whereKey($id)->firstOrFail();

        try {
            Engine::cancel($run);
        } catch (RuntimeException $e) {
            return back()->withErrors(['run' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('أُلغي التشغيل المعلَّق'), 'type' => 'success']);
    }

    /** قراءةُ تنبيهٍ داخليّ — لصاحبه وحده */
    public function read(int|string $id): JsonResponse
    {
        $n = InternalNotification::where('user_id', auth()->id())->whereKey($id)->firstOrFail();
        if ($n->read_at === null) {
            $n->update(['read_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }

    /** @return array<string, mixed> */
    private function row(AutomationRun $r): array
    {
        return [
            'id' => $r->id,
            'rule_id' => $r->automation_rule_id,
            'rule' => $r->rule?->name,
            'kind' => $r->rule?->kind,
            'channel' => $r->rule?->channel,
            'trigger' => $r->trigger,
            'trigger_label' => Triggers::exists($r->trigger) ? Triggers::for($r->trigger)->label() : $r->trigger,
            'target_type' => Subjects::keyOf($r->target_type) ?? ($r->target_type === Business::class ? 'business' : $r->target_type),
            'target_id' => $r->target_id,
            'target_label' => $r->target_label,
            'target_url' => $this->targetUrl($r),
            'step' => $r->step,
            'audience' => $r->audience,
            'audience_label' => __(Audience::LABELS[$r->audience] ?? $r->audience),
            'occurrence' => $r->occurrence,
            'status' => $r->status,
            'description' => $r->metadata['description'] ?? null,
            'result' => $r->result,
            'skipped_reason' => $r->skipped_reason,
            'skipped_label' => $r->skipped_reason ? __(Engine::REASONS[$r->skipped_reason] ?? $r->skipped_reason) : null,
            'failure' => $r->failure,
            'communication_id' => $r->communication_id,
            'communication_status' => $r->communication?->status,
            'notification_count' => $r->notification_count,
            'evaluated_at' => $r->evaluated_at?->format('Y-m-d H:i'),
            'scheduled_for' => $r->scheduled_for?->format('Y-m-d H:i'),
            'executed_at' => $r->executed_at?->format('Y-m-d H:i'),
            'would' => $r->metadata['would'] ?? null,
        ];
    }

    private function targetUrl(AutomationRun $r): ?string
    {
        if (! $r->target_type || ! Triggers::exists($r->trigger)) {
            return null;
        }
        $target = Triggers::for($r->trigger)->find($this->bid(), $r->target_type, (int) $r->target_id);

        return $target === null ? null : Triggers::for($r->trigger)->url($target);
    }
}
