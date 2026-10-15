<?php

namespace App\Support;

use App\Models\CustomerInvoice;
use App\Models\DeliveryPackage;
use App\Models\EventOperation;
use App\Models\Project;
use App\Models\ScopeOfWork;
use App\Models\User;
use App\Support\Operations\EventOperations;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * مسارُ المشروع — سبعُ مراحل، وكلٌّ منها تُقرأ من حقيقتها.
 *
 *   عرض السعر → نطاق العمل → الدفعة المقدّمة → التنفيذ → التسليم →
 *   الفاتورة النهائيّة → الإغلاق
 *
 * ═══ المرحلةُ تتمّ بمستندها لا بزرّ ═══
 *
 * • عرض السعر: المشروعُ وُلد من عرضٍ قُبل.
 * • نطاق العمل: نسخةٌ وقّعها العميل (`ScopesOfWork`).
 * • الدفعة المقدّمة: فاتورةُ دفعةٍ صادرة، أو قرارُ «بلا دفعة» باسم صاحبه،
 *   أو فوترةُ القيمة كلِّها — والتحصيلُ يُقال منفصلًا.
 * • التنفيذ: فعاليّةٌ أُتمّت ولا فعاليّةَ قائمةٌ لم تكتمل (`EventOperation`).
 * • التسليم: `DeliveryReadiness` — حزمةٌ قَبِلها العميل ولا رفضَ معلَّق.
 * • الفاتورة النهائيّة: صافي المفوتَر بلغ القيمةَ الحاليّة (`ProjectFinancials`).
 * • الإغلاق: المشروعُ مُقفَل.
 *
 * ═══ و«أين نعمل الآن» مؤشّرٌ تحرسه الحقيقة ═══
 *
 * `workflow_stage` المرحلةُ التي يعمل عليها الفريق: لا تتقدّم إلّا بعد أن
 * تتمّ التي قبلها (`advance`)، وتعود خطوةً للمراجعة (`back`) بلا أن يُمحى
 * شيء — لا فاتورةٌ ولا توقيعٌ ولا قيد. وما يتجاوز أوّلَ مرحلةٍ لم تتمّ
 * يُقصّ عند القراءة: فاتورةُ دفعةٍ أُلغيت تُعيد المسارَ إليها وحدَه.
 *
 * ومنه تقرأ بطاقةُ القائمة وصفحةُ المشروع معًا — مصدرٌ واحدٌ لا اثنان.
 */
final class ProjectWorkflow
{
    public const QUOTE = 'quote';

    public const SCOPE = 'scope';

    public const DEPOSIT = 'deposit';

    public const EXECUTION = 'execution';

    public const DELIVERY = 'delivery';

    public const FINAL = 'final';

    public const CLOSING = 'closing';

    /** @var list<string> */
    public const STAGES = [self::QUOTE, self::SCOPE, self::DEPOSIT, self::EXECUTION, self::DELIVERY, self::FINAL, self::CLOSING];

    public const LABELS = [
        self::QUOTE => 'عرض السعر',
        self::SCOPE => 'نطاق العمل',
        self::DEPOSIT => 'الدفعة المقدمة',
        self::EXECUTION => 'التنفيذ',
        self::DELIVERY => 'التسليم',
        self::FINAL => 'الفاتورة النهائية',
        self::CLOSING => 'الإغلاق',
    ];

    /* ─────────────────────────── القراءة ─────────────────────────── */

    /**
     * مسارُ مشروعٍ واحد — بموانع إقفاله.
     *
     * @return array<string, mixed>
     */
    public static function for(Project $project): array
    {
        $facts = self::facts((int) $project->business_id, collect([$project]), true);

        return self::compose($project, $facts[(int) $project->id]);
    }

    /**
     * مساراتُ مشاريعَ كثيرة — دفعةً واحدة لا مشروعًا مشروعًا، بلا موانع الإقفال.
     *
     * @param  Collection<int, Project>  $projects
     * @return array<int, array<string, mixed>>
     */
    public static function forMany(int $businessId, Collection $projects): array
    {
        $facts = self::facts($businessId, $projects, false);

        return $projects->mapWithKeys(fn (Project $p) => [(int) $p->id => self::compose($p, $facts[(int) $p->id])])->all();
    }

    /**
     * حكمُ التنفيذ من فعاليّات المشروع — يقرؤه المسارُ وموانعُ الإقفال معًا.
     *
     * @param  Collection<int, EventOperation>  $operations
     * @return array{done: bool, pending: list<string>, completed: int, active: int}
     */
    public static function execution(Collection $operations, bool $required = true): array
    {
        $live = $operations->reject(fn (EventOperation $o) => $o->status === EventOperation::CANCELLED);
        $active = $live->filter(fn (EventOperation $o) => in_array($o->status, EventOperation::ACTIVE, true));
        $completed = $live->where('status', EventOperation::COMPLETED)->count();

        $pending = [];
        if ($live->isEmpty() && $required) {
            $pending[] = __('لا فعاليّة تشغيليّة على هذا المشروع — أنشئ فعاليّة التنفيذ وأتمّها.');
        }
        foreach ($active as $o) {
            $pending[] = __('الفعاليّة :code (:status) لم تكتمل بعد.', [
                'code' => $o->code, 'status' => __(EventOperations::statusLabel($o->status)),
            ]);
        }

        return [
            'done' => $active->isEmpty() && ($completed > 0 || ! $required),
            'pending' => $pending,
            'completed' => $completed,
            'active' => $active->count(),
        ];
    }

    /**
     * حقائقُ كلّ مرحلة لكلّ مشروع — باستعلاماتٍ مجمَّعة.
     *
     * @param  Collection<int, Project>  $projects
     * @return array<int, array<string, array<string, mixed>>>
     */
    private static function facts(int $bid, Collection $projects, bool $detailed): array
    {
        $ids = $projects->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($ids === []) {
            return [];
        }

        $money = ProjectFinancials::summaries($bid, $projects);
        $drafts = CustomerInvoice::where('business_id', $bid)->whereIn('project_id', $ids)->where('status', CustomerInvoice::DRAFT)
            ->toBase()->selectRaw('project_id, COALESCE(SUM(total), 0) AS v')->groupBy('project_id')->pluck('v', 'project_id');
        $scopes = ScopeOfWork::where('business_id', $bid)->whereIn('project_id', $ids)->orderBy('revision')
            ->get(['id', 'project_id', 'revision', 'status', 'number', 'first_viewed_at'])->groupBy('project_id');
        $deposits = CustomerInvoice::where('business_id', $bid)->whereIn('project_id', $ids)
            ->where('billing_role', ProjectBilling::DEPOSIT)->where('status', '<>', CustomerInvoice::CANCELLED)
            ->get(['id', 'project_id', 'status', 'number'])->groupBy('project_id');
        $operations = EventOperation::where('business_id', $bid)->whereIn('project_id', $ids)
            ->get(['id', 'project_id', 'status', 'code', 'name'])->groupBy('project_id');
        $packages = DeliveryPackage::where('business_id', $bid)->whereIn('project_id', $ids)->orderBy('id')->get()->groupBy('project_id');

        $out = [];

        foreach ($projects as $p) {
            $pid = (int) $p->id;
            $summary = $money[$pid];
            $required = ScopesOfWork::required($p);
            $value = round((float) $summary['current_value'], 3);
            $unbilled = round(max(0.0, $value - (float) $summary['net_invoiced']), 3);
            $draftTotal = round((float) ($drafts[$pid] ?? 0), 3);

            /* ─── نطاق العمل ─── */
            $revs = $scopes[$pid] ?? collect();
            $signed = $revs->where('status', ScopeOfWork::SIGNED)->last();
            $latest = $revs->last();
            $scopeDone = ! $required || $signed !== null;
            $scopePending = $scopeDone ? [] : [match ($latest?->status) {
                ScopeOfWork::DRAFT => __('نطاق العمل :n مسودّة — أكمله واجعله جاهزًا للإرسال.', ['n' => $latest->number]),
                ScopeOfWork::READY => __('نطاق العمل :n جاهز — أرسله للعميل للتوقيع.', ['n' => $latest->number]),
                ScopeOfWork::SENT => $latest->first_viewed_at !== null
                    ? __('فتح العميل نطاق العمل :n ولم يوقّعه بعد.', ['n' => $latest->number])
                    : __('أُرسل نطاق العمل :n ولم يفتحه العميل بعد.', ['n' => $latest->number]),
                ScopeOfWork::DECLINED => __('طلب العميل تعديل نطاق العمل :n — ابدأ نسخةً جديدة.', ['n' => $latest->number]),
                default => __('لم يُعدّ نطاق العمل بعد.'),
            }];
            $scopeNote = match (true) {
                ! $required => __('مشروعٌ مستورَد — بلا نطاق موقَّع'),
                $signed !== null && $latest !== null && $latest->id !== $signed->id && in_array($latest->status, [ScopeOfWork::DRAFT, ScopeOfWork::READY, ScopeOfWork::SENT], true) => __('نسخة :n قيد الاعتماد', ['n' => $latest->number]),
                $latest?->status === ScopeOfWork::SENT => $latest->first_viewed_at !== null ? __('فُتح') : __('أُرسل'),
                default => null,
            };

            /* ─── الدفعة المقدّمة ─── */
            $live = $deposits[$pid] ?? collect();
            $issued = $live->where('status', CustomerInvoice::ISSUED);
            $waived = $p->deposit_waived_at !== null;
            $fully = $unbilled <= 0.0005 && $value > 0;
            $depositDone = $issued->isNotEmpty() || $waived || $fully;
            $depositPending = $depositDone ? [] : [$live->where('status', CustomerInvoice::DRAFT)->isNotEmpty()
                ? __('فاتورة الدفعة المقدمة مسودّة — أصدرها.')
                : __('لم تُصدر فاتورة الدفعة المقدمة بعد.')];
            $depositNote = match (true) {
                $waived => __('بلا دفعة مقدمة'),
                $issued->isNotEmpty() => null,
                $fully => __('فُوترت القيمة كلّها'),
                default => null,
            };

            /* ─── التنفيذ ─── */
            $execution = self::execution($operations[$pid] ?? collect(), $required);

            /* ─── التسليم ─── */
            $delivery = DeliveryReadiness::of($packages[$pid] ?? collect());

            /* ─── الفاتورة النهائيّة ─── */
            $finalDone = $unbilled <= 0.0005;
            $finalPending = $finalDone ? [] : array_values(array_filter([
                __('لم تُفوتر قيمة المشروع كلّها بعد.'),
                $draftTotal > 0 ? __('على المشروع فاتورةٌ مسودّة لم تصدر.') : null,
            ]));

            /* ─── الإغلاق ─── */
            $closingPending = match (true) {
                $p->status === Project::CANCELLED => [__('المشروع ملغى.')],
                $detailed && $p->isOpen() => Projects::closeBlockers($p, $summary),
                default => [],
            };

            $out[$pid] = [
                self::QUOTE => [
                    'done' => $p->quotation_id !== null || ! $required,
                    'pending' => $p->quotation_id !== null || ! $required ? [] : [__('مشروعٌ بلا عرض سعر مقبول.')],
                    'note' => null,
                ],
                self::SCOPE => ['done' => $scopeDone, 'pending' => $scopePending, 'note' => $scopeNote],
                self::DEPOSIT => ['done' => $depositDone, 'pending' => $depositPending, 'note' => $depositNote],
                self::EXECUTION => ['done' => $execution['done'], 'pending' => $execution['pending'], 'note' => null],
                self::DELIVERY => ['done' => $delivery->ready, 'pending' => $delivery->blockers, 'note' => null],
                self::FINAL => ['done' => $finalDone, 'pending' => $finalPending, 'note' => null],
                self::CLOSING => ['done' => $p->isClosed(), 'pending' => $closingPending, 'note' => null],
            ];
        }

        return $out;
    }

    /**
     * من الحقائق إلى المسار: ما تمّ، وأين يقف الفريق، وما بعده.
     *
     * @param  array<string, array<string, mixed>>  $facts
     * @return array<string, mixed>
     */
    private static function compose(Project $project, array $facts): array
    {
        $last = count(self::STAGES) - 1;
        $done = array_map(fn (string $k) => (bool) $facts[$k]['done'], self::STAGES);
        $frontier = array_search(false, $done, true);
        $frontier = $frontier === false ? null : (int) $frontier;
        $open = $project->isOpen();

        $pointer = array_search($project->workflow_stage, self::STAGES, true);
        $cap = $frontier ?? $last;

        /*
         * ═══ والمؤشّرُ لا يبتعد عن الحقيقة أكثرَ من خطوة ═══
         *
         * لا يتجاوز أوّلَ مرحلةٍ لم تتمّ (`$cap`)، ولا يتأخّر عنها إلّا بمرحلةٍ
         * واحدة: تامّةٍ تنتظر «تم — الانتقال»، أو أُعيد إليها للمراجعة. فمشروعٌ
         * تمّ تنفيذُه وتسليمُه ومؤشّرُه على «الدفعة» لا تقول بطاقتُه «الدفعة
         * المقدمة» — تقول «التسليم» تامًّا ينتظر الانتقال إلى الفاتورة النهائيّة.
         */
        $focus = match (true) {
            $project->isClosed() => $last,
            $pointer === false => $cap,
            default => max(max(0, $cap - 1), min((int) $pointer, $cap)),
        };

        $stages = [];
        foreach (self::STAGES as $i => $key) {
            $state = match (true) {
                $done[$i] => 'done',
                $i === $focus => $project->status === Project::CANCELLED ? 'failed' : 'current',
                $i < $focus => 'skipped',
                default => 'todo',
            };

            $note = $facts[$key]['note'];
            if ($i === $focus && ! $done[$i]) {
                $note = match ($project->status) {
                    Project::CANCELLED => __('ملغى'),
                    Project::ON_HOLD => __('مؤجَّل'),
                    default => $note,
                };
            }

            $stages[] = [
                'key' => $key,
                'state' => $state,
                'done' => $done[$i],
                'focus' => $i === $focus,
                'reachable' => $frontier === null || $i <= $frontier,
                'note' => $note,
                'pending' => array_values($facts[$key]['pending']),
                /* وما يمنع الوصولَ إليها: ما لم يتمّ في أوّل مرحلةٍ مفتوحةٍ قبلها */
                'blocked_by' => $frontier !== null && $i > $frontier
                    ? array_merge([__('لم تكتمل مرحلة «:stage» بعد', ['stage' => __(self::LABELS[self::STAGES[$frontier]])])], array_values($facts[self::STAGES[$frontier]]['pending']))
                    : [],
            ];
        }

        $backBlock = self::backBlock($open, $focus, $cap, $done);

        return [
            'stages' => $stages,
            'focus' => self::STAGES[$focus],
            'frontier' => $frontier === null ? null : self::STAGES[$frontier],
            'next' => $focus < $last ? self::STAGES[$focus + 1] : null,
            'previous' => $focus > 0 ? self::STAGES[$focus - 1] : null,
            'can_advance' => $open && $done[$focus] && $focus < $last,
            'can_back' => $backBlock === null,
            'back_blocked' => $backBlock,
        ];
    }

    /**
     * أيجوز «رجوع» الآن؟ — `null` إن جاز، وإلّا سببُ المنع بنصّه.
     *
     * الرجوعُ خطوةٌ واحدةٌ من المرحلة التي يُعمل عليها إلى التامّة قبلها،
     * للمراجعة. ولا يجوز إن تمّت مرحلةٌ بعدها: الحقيقةُ تقدّمت، والمؤشّرُ
     * المُعاد يجعل البطاقةَ تقول غيرها. والمراجعةُ بلا تحريكٍ متاحةٌ دائمًا —
     * كلُّ مرحلةٍ في الشريط تُفتح تفاصيلُها.
     *
     * @param  list<bool>  $done
     */
    private static function backBlock(bool $open, int $focus, int $cap, array $done): ?string
    {
        return match (true) {
            ! $open => __('لا يتحرّك مسار مشروعٍ مُقفَل أو ملغى.'),
            $focus === 0 => __('هذه أوّل مرحلة في المسار.'),
            $focus < $cap => __('المسار على مرحلةٍ تامّة قبل التي يُعمل عليها — الرجوع خطوةٌ واحدة. لمراجعة ما قبلها افتحها من الشريط.'),
            in_array(true, array_slice($done, $focus + 1), true) => __('تمّت مرحلةٌ بعد هذه — الرجوع الآن يجعل المسار يقول غير الحقيقة. افتح المرحلة من الشريط لمراجعتها.'),
            default => null,
        };
    }

    /* ─────────────────────────── الحركة ─────────────────────────── */

    /**
     * «تم — الانتقال إلى …» — لا تتقدّم المرحلةُ إلّا وقد تمّت التي قبلها.
     *
     * والدخولُ إلى التنفيذ يبدأه: مشروعٌ مؤكَّدٌ يصير «قيد التنفيذ» من بابه
     * (`Projects::transition`) لا بكتابةٍ هنا.
     */
    public static function advance(Project $project, ?User $actor = null): Project
    {
        return DB::transaction(function () use ($project, $actor) {
            $locked = Project::where('business_id', $project->business_id)->lockForUpdate()->findOrFail($project->id);

            if (! $locked->isOpen()) {
                throw new RuntimeException(__('لا يتحرّك مسار مشروعٍ مُقفَل أو ملغى.'));
            }

            $flow = self::for($locked);
            $focus = collect($flow['stages'])->firstWhere('key', $flow['focus']);

            if (! $flow['can_advance']) {
                throw new RuntimeException($focus['done']
                    ? __('هذه آخر مرحلة في المسار.')
                    : __('لم تكتمل مرحلة «:stage» بعد — :why', [
                        'stage' => __(self::LABELS[$flow['focus']]),
                        'why' => implode(' · ', $focus['pending']),
                    ]));
            }

            $next = (string) $flow['next'];
            $locked->update(['workflow_stage' => $next]);

            if ($next === self::EXECUTION && $locked->status === Project::CONFIRMED) {
                Projects::transition($locked, Project::IN_PROGRESS, null, $actor);
            }

            Activity::log('status', 'نقل المشروع '.$locked->code.' في مساره من «'.self::LABELS[$flow['focus']].'» إلى «'.self::LABELS[$next].'»', [
                'subject_id' => $locked->id, 'subject_type' => 'project', 'icon' => 'arrow-left', 'color' => 'primary',
            ]);

            return $locked->fresh();
        });
    }

    /**
     * «رجوع إلى …» — خطوةٌ للمراجعة، لا تراجعٌ يمحو.
     *
     * لا تُلغى فاتورةٌ ولا يُنقض توقيعٌ ولا يُعكس قيد: المؤشّرُ وحدَه يعود.
     * وما يحتاج تغييرًا بعدها له بابُه — نسخةٌ جديدة من النطاق، أو أمرُ
     * تغيير، أو إلغاءٌ وإشعارُ دائنٍ لفاتورةٍ صدرت.
     */
    public static function back(Project $project, ?User $actor = null): Project
    {
        return DB::transaction(function () use ($project) {
            $locked = Project::where('business_id', $project->business_id)->lockForUpdate()->findOrFail($project->id);

            if (! $locked->isOpen()) {
                throw new RuntimeException(__('لا يتحرّك مسار مشروعٍ مُقفَل أو ملغى.'));
            }

            $flow = self::for($locked);

            if (! $flow['can_back']) {
                throw new RuntimeException((string) $flow['back_blocked']);
            }

            $previous = (string) $flow['previous'];
            $locked->update(['workflow_stage' => $previous]);

            Activity::log('status', 'أعاد المشروع '.$locked->code.' في مساره من «'.self::LABELS[$flow['focus']].'» إلى «'.self::LABELS[$previous].'» — للمراجعة، ولم يُمحَ مستندٌ ولا توقيع', [
                'subject_id' => $locked->id, 'subject_type' => 'project', 'icon' => 'arrow-right', 'color' => 'warning',
            ]);

            return $locked->fresh();
        });
    }

    /**
     * من عمل في مرحلةٍ أبعد من المؤشّر — يلحقه المؤشّرُ إليها بلا ضجيج.
     *
     * إصدارُ الدفعة من مرحلتها والمؤشّرُ على النطاق يعني أنّ الفريق هناك.
     * ولا يتجاوز أوّلَ مرحلةٍ لم تتمّ — القراءةُ تقصّه على كلّ حال.
     */
    public static function touch(Project $project, string $stage): void
    {
        $at = array_search($stage, self::STAGES, true);
        $pointer = array_search($project->workflow_stage, self::STAGES, true);

        if ($at === false || $pointer === false || $at <= $pointer) {
            return;
        }

        Project::whereKey($project->id)->update(['workflow_stage' => $stage]);
    }
}
