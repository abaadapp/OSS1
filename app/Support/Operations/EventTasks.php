<?php

namespace App\Support\Operations;

use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\EventOperation;
use App\Models\EventTaskDispatch;
use App\Models\EventTaskLink;
use App\Models\InternalNotification;
use App\Models\ProjectTask;
use App\Models\User;
use App\Support\Activity;
use App\Support\Communication\Communications;
use App\Support\Contention;
use App\Support\Messaging\MessageIntent;
use App\Support\Messaging\Notifiers;
use App\Support\PhoneNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * مهامُّ الفعاليّة إلى أصحابها — رسالةٌ واحدة لكلّ موظّف، تقرأ المهامَّ لحظتَها.
 *
 * ═══ المهمّةُ مصدرُ الحقيقة، والرسالةُ إشعار ═══
 *
 * لا جدولَ مهامٍ ثانٍ ولا نسخةٌ من المهام في الرسالة: `ProjectTask` بعمودها
 * `event_operation_id` هي هي. والرسالةُ تقول «لديك ٥ مهام» وتفتح صفحةً حيّة
 * (`/t/{token}`) تقرأ القاعدةَ الآن — فما أُنجز يظهر منجزًا، ولا تُعدَّل رسالةٌ
 * خرجت. والتذكيرُ القادم يحسب المفتوحَ وحده.
 *
 * ═══ رسالةٌ لكلّ (موظّف · فعاليّة) لا لكلّ مهمّة ═══
 *
 * عشرُ مهامٍ لموظّفٍ في فعاليّةٍ رسالةٌ واحدة. ومنعُ التكرار مفتاحٌ فريد في
 * `event_task_dispatches`: (الشركة · الفعاليّة · الموظّف · المُطلِق · الخطوة) —
 * عاملان يصلان معًا يكتب أحدُهما. وفوقه مفتاحُ تشغيل الأتمتة نفسُه.
 *
 * ═══ وواتساب لا يُفشل شيئًا ═══
 *
 * قناةٌ مطفأة أو قالبٌ غير مضبوط أو موظّفٌ بلا رقم: تُقيَّد المراسلةُ فاشلةً
 * (حين تبلغ القناة)، ويُنبَّه الموظّفُ داخل النظام بالرابط، ويُنبَّه قائدُ
 * العمليّات ليرسله بيده — ولا تُمسّ المهام.
 *
 * ═══ والرسالةُ عن الفعاليّة لا عن العميل ═══
 *
 * موضوعُ المراسلة الفعاليّةُ نفسُها، فلا تُنسب إلى عميل المشروع (`customer_id`
 * يبقى فارغًا) ولا تُلصق محادثةُ الموظّف على واتساب بالعميل.
 */
final class EventTasks
{
    public const ASSIGNED = 'event_tasks_assigned';

    public const REMINDER = 'event_tasks_reminder';

    /** مفاتيحُ القوالب في `Templates::CATALOG` — واسمُ قالب Meta يُضبط في «المراسلات ‹ القوالب» */
    public const TEMPLATES = [self::ASSIGNED => 'event.tasks_assigned', self::REMINDER => 'event.tasks_reminder'];

    /** يبقى الرابطُ صالحًا أيّامًا بعد نهاية الفعاليّة — لتسجيل ما أُنجز متأخّرًا */
    public const LINK_GRACE_DAYS = 7;

    /* ─────────────────────────── القراءة ─────────────────────────── */

    /** @return Collection<int, ProjectTask> مهامُّ موظّفٍ في فعاليّة — المفتوحةُ أوّلًا */
    public static function of(EventOperation $operation, int $userId): Collection
    {
        return ProjectTask::where('business_id', $operation->business_id)
            ->where('event_operation_id', $operation->id)->where('assignee_id', $userId)
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [ProjectTask::OPEN])
            ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')->orderBy('due_at')->orderBy('id')
            ->get();
    }

    /** @return list<int> من له مهامٌّ (مفتوحةٌ إن طُلب) في الفعاليّة */
    public static function assigneeIds(EventOperation $operation, bool $openOnly = true): array
    {
        return ProjectTask::where('business_id', $operation->business_id)
            ->where('event_operation_id', $operation->id)->whereNotNull('assignee_id')
            ->when($openOnly, fn ($q) => $q->where('status', ProjectTask::OPEN))
            ->distinct()->orderBy('assignee_id')->pluck('assignee_id')->map(fn ($id) => (int) $id)->all();
    }

    /* ─────────────────────────── النشر ─────────────────────────── */

    /**
     * «نشر المهام على الفريق» — مرساةُ رسالة التعيين في الأتمتة.
     *
     * لا يُرسل من هنا: الأتمتةُ (`event_tasks_assigned`) تُرسل في دورتها القادمة
     * بقاعدتها ووضعها. ويُنشأ رابطُ كلّ موظّفٍ الآن — فيُنسخ ولو لم تُرسَل رسالة.
     *
     * وإعادةُ النشر لا تُعيد رسالةً لمن وصلته (مفتاحُ الموظّف)، ومفتاحُ تشغيل
     * الأتمتة يوميّ: إعادةُ نشرٍ في اليوم نفسِه لا تفتح تشغيلًا ثانيًا — من أُضيف
     * بعد نشر اليوم يُنسخ رابطُه أو يصله التذكير، وإعادةُ النشر في يومٍ تالٍ تصله.
     */
    public static function publish(EventOperation $operation, ?User $actor = null): EventOperation
    {
        if (! $operation->isActive()) {
            throw new RuntimeException(__('لا تُنشر مهامُّ فعاليّةٍ خُتمت.'));
        }

        $assignees = self::assigneeIds($operation);
        if ($assignees === []) {
            throw new RuntimeException(__('لا مهامَّ مفتوحةً معيَّنةً لموظّفين على هذه الفعاليّة بعد.'));
        }

        $operation->update(['tasks_published_at' => now(), 'tasks_published_by' => $actor?->id]);

        foreach (User::where('business_id', $operation->business_id)->whereIn('id', $assignees)->get() as $user) {
            self::link($operation, $user);
        }

        Activity::log('updated', 'نشر مهامّ الفعاليّة '.$operation->code.' على '.count($assignees).' موظّفين', [
            'business_id' => (int) $operation->business_id, 'subject_id' => $operation->id, 'subject_type' => 'event_operation', 'icon' => 'send',
        ]);

        return $operation->fresh();
    }

    /* ─────────────────────────── الرابط ─────────────────────────── */

    /**
     * رابطُ الموظّف إلى مهامّه في الفعاليّة — يُنشأ مرّةً ويُعاد هو نفسُه.
     *
     * والرمزُ نفسُه في كلّ رسالة: تذكيرٌ لا يُعطّل زرَّ رسالةٍ سبقته. ويُمدَّد
     * انتهاؤه إن تأخّرت الفعاليّة، ويُجدَّد إن انتهى أو أُلغي.
     */
    public static function link(EventOperation $operation, User $user): string
    {
        return route('event-tasks.show', self::token($operation, $user));
    }

    /** الرمزُ وحدَه — خانةُ زرّ قالب Meta ذي الرابط */
    public static function token(EventOperation $operation, User $user): string
    {
        self::assertSameCompany($operation, $user);

        $expires = self::expiryFor($operation);
        $link = EventTaskLink::where('event_operation_id', $operation->id)->where('user_id', $user->id)->first();

        if ($link !== null && $link->isUsable()) {
            if ($link->expires_at->lt($expires)) {
                $link->update(['expires_at' => $expires]);
            }

            return (string) $link->token;
        }

        return self::issue($operation, $user, $link, $expires);
    }

    /** تجديدُ الرابط بيد — القديمُ لا يفتح بعده */
    public static function rotate(EventOperation $operation, User $user, ?User $actor = null): string
    {
        self::assertSameCompany($operation, $user);
        $link = EventTaskLink::where('event_operation_id', $operation->id)->where('user_id', $user->id)->first();
        $token = self::issue($operation, $user, $link, self::expiryFor($operation));

        Activity::log('updated', 'جدّد رابط مهامّ '.$user->name.' في الفعاليّة '.$operation->code.' — القديمُ لا يفتح', [
            'business_id' => (int) $operation->business_id, 'subject_id' => $operation->id, 'subject_type' => 'event_operation', 'icon' => 'refresh-cw',
        ]);

        return route('event-tasks.show', $token);
    }

    /** الرابطُ من رمزه — صالحًا وحده، ولا يُبحث بالرمز بل ببصمته */
    public static function resolve(string $token): ?EventTaskLink
    {
        if (preg_match('/^[A-Za-z0-9]{40}$/', $token) !== 1) {
            return null;
        }

        $link = EventTaskLink::where('token_hash', hash('sha256', $token))->with('operation.project', 'user')->first();

        if ($link === null || ! $link->isUsable() || $link->operation === null || $link->user === null
            || (int) $link->operation->business_id !== (int) $link->business_id || (int) $link->user->business_id !== (int) $link->business_id) {
            return null;
        }

        return $link;
    }

    private static function issue(EventOperation $operation, User $user, ?EventTaskLink $link, Carbon $expires): string
    {
        $token = Str::random(40);
        $attributes = ['token_hash' => hash('sha256', $token), 'token' => $token, 'expires_at' => $expires, 'revoked_at' => null];

        if ($link !== null) {
            $link->update($attributes);
        } else {
            EventTaskLink::create($attributes + [
                'business_id' => $operation->business_id, 'event_operation_id' => $operation->id, 'user_id' => $user->id,
            ]);
        }

        return $token;
    }

    private static function expiryFor(EventOperation $operation): Carbon
    {
        $end = $operation->windowEnd() ?? now();

        return ($end->greaterThan(now()) ? $end->copy() : now())->addDays(self::LINK_GRACE_DAYS);
    }

    private static function assertSameCompany(EventOperation $operation, User $user): void
    {
        if ((int) $operation->business_id !== (int) $user->business_id) {
            throw new RuntimeException(__('هذا الموظّف ليس من شركتك.'));
        }
    }

    /* ─────────────────────────── الإرسال ─────────────────────────── */

    /**
     * ملخّصُ مهام كلّ موظّف — من الأتمتة (`handle`) لا من الشاشة.
     *
     * @param  Collection<int, User>  $users
     * @return array{result: string, notification_count: int}
     */
    public static function dispatchAll(EventOperation $operation, Collection $users, string $trigger, string $step, AutomationRule $rule, ?AutomationRun $run = null): array
    {
        /*
         * وبلغة القاعدة لا بلغة الخادم: الأتمتةُ تدور من سطر الأوامر بلغته
         * الافتراضيّة، والتنبيهُ وسببُ الفشل يقرؤهما فريقٌ يقرأ لغةَ القاعدة.
         */
        $previous = app()->getLocale();
        app()->setLocale(in_array($rule->language, ['ar', 'en'], true) ? $rule->language : 'ar');

        try {
            return self::dispatchEach($operation, $users, $trigger, $step, $rule, $run);
        } finally {
            app()->setLocale($previous);
        }
    }

    /**
     * @param  Collection<int, User>  $users
     * @return array{result: string, notification_count: int}
     */
    private static function dispatchEach(EventOperation $operation, Collection $users, string $trigger, string $step, AutomationRule $rule, ?AutomationRun $run): array
    {
        $counts = [EventTaskDispatch::SENT => 0, EventTaskDispatch::NOTIFIED => 0, EventTaskDispatch::FAILED => 0, EventTaskDispatch::NOTHING_OPEN => 0, 'repeated' => 0];
        $failed = [];
        $notified = 0;

        foreach ($users as $user) {
            $dispatch = self::dispatch($operation, $user, $trigger, $step, $rule, $run);

            if ($dispatch === null) {
                $counts['repeated']++;

                continue;
            }

            $counts[$dispatch->status]++;
            if ($dispatch->status === EventTaskDispatch::FAILED) {
                $failed[] = $user->name;
            }
            if (in_array($dispatch->status, [EventTaskDispatch::FAILED, EventTaskDispatch::NOTIFIED], true)) {
                $notified++;
            }
        }

        /* ومن فشلت رسالتُه يعرفه قائدُ العمليّات — ليرسل رابطَه بيده */
        if ($failed !== [] && ($lead = self::lead($operation)) !== null) {
            self::notify($lead, $operation, 'event_tasks_failed',
                __('تعذّر إرسال مهامّ الفعاليّة :code عبر واتساب إلى :names — انسخ روابطهم من تبويب المهام', [
                    'code' => $operation->code, 'names' => implode('، ', $failed),
                ]), route('admin.operations.show', ['id' => $operation->id, 'tab' => 'tasks']), $run);
            $notified++;
        }

        return [
            'result' => __('واتساب: :sent · داخل النظام: :notified · فشل: :failed · بلا مفتوح: :none · سبق: :repeated', [
                'sent' => $counts[EventTaskDispatch::SENT], 'notified' => $counts[EventTaskDispatch::NOTIFIED],
                'failed' => $counts[EventTaskDispatch::FAILED], 'none' => $counts[EventTaskDispatch::NOTHING_OPEN], 'repeated' => $counts['repeated'],
            ]),
            'notification_count' => $notified,
        ];
    }

    /**
     * رسالةُ موظّفٍ واحد — أو null إن سبق مثلُها (المفتاحُ الفريد).
     *
     * الصفُّ يُكتب **قبل** الإرسال: إرسالٌ مرّةً على الأكثر — تكرارُ رسالةٍ
     * إلى موظّف أسوأ من فواتها، والفواتُ يُرى في تبويب المهام ويُرسل رابطُه بيد.
     */
    public static function dispatch(EventOperation $operation, User $user, string $trigger, string $step, AutomationRule $rule, ?AutomationRun $run = null): ?EventTaskDispatch
    {
        $channel = $rule->channel === 'whatsapp' ? 'whatsapp' : 'internal';

        /** @var EventTaskDispatch|null $dispatch */
        $dispatch = Contention::attempt(fn () => EventTaskDispatch::create([
            'business_id' => $operation->business_id, 'event_operation_id' => $operation->id, 'user_id' => $user->id,
            'trigger' => $trigger, 'step' => mb_substr($step, 0, 40), 'channel' => $channel,
            'status' => EventTaskDispatch::FAILED, 'failure' => __('بدأ الإرسال ولم يكتمل'), 'automation_run_id' => $run?->id,
        ]));

        if ($dispatch === null) {
            return null;
        }

        $open = self::of($operation, (int) $user->id)->where('status', ProjectTask::OPEN)->count();

        if ($open === 0) {
            $dispatch->update(['status' => EventTaskDispatch::NOTHING_OPEN, 'failure' => null]);

            return $dispatch;
        }

        $url = self::link($operation, $user);
        $title = $trigger === self::REMINDER
            ? __('تذكير: لديك :n مهام مفتوحة في فعاليّة :name', ['n' => $open, 'name' => $operation->name])
            : __('لديك :n مهام في فعاليّة :name', ['n' => $open, 'name' => $operation->name]);

        if ($channel === 'internal') {
            self::notify($user, $operation, $trigger, $title, $url, $run);
            $dispatch->update(['status' => EventTaskDispatch::NOTIFIED, 'open_count' => $open, 'failure' => null, 'sent_at' => now()]);
            self::logDispatch($operation, $user, $open, __('تنبيه داخلي'));

            return $dispatch;
        }

        [$sent, $reason, $communicationId] = self::sendWhatsApp($operation, $user, $trigger, $rule, $open, $url, $title);

        if ($sent) {
            $dispatch->update(['status' => EventTaskDispatch::SENT, 'open_count' => $open, 'failure' => null,
                'communication_id' => $communicationId, 'sent_at' => now()]);
            self::logDispatch($operation, $user, $open, __('واتساب'));

            return $dispatch;
        }

        /* لم تخرج — يُنبَّه الموظّفُ داخل النظام بالرابط، والمهامُّ كما هي */
        self::notify($user, $operation, $trigger, $title, $url, $run);
        $dispatch->update(['status' => EventTaskDispatch::FAILED, 'open_count' => $open,
            'failure' => mb_substr((string) $reason, 0, 500), 'communication_id' => $communicationId]);
        self::logDispatch($operation, $user, $open, __('فشل واتساب — :reason', ['reason' => $reason]));

        return $dispatch;
    }

    /**
     * واتساب — عبر الطبقة نفسِها التي تُرسل للعملاء، وبقالبٍ يُضبط اسمُه عند Meta.
     *
     * @return array{0: bool, 1: ?string, 2: ?int}
     */
    private static function sendWhatsApp(EventOperation $operation, User $user, string $trigger, AutomationRule $rule, int $open, string $url, string $title): array
    {
        $phone = PhoneNumber::normalize($user->phone);
        if ($phone === null) {
            return [false, __('لا رقم هاتف صالحًا للموظّف'), null];
        }

        $intent = new MessageIntent(
            to: $phone,
            subject: $title,
            paragraphs: [$title],
            url: $url,
            action: __('عرض المهام'),
            templateKey: filled($rule->template_key) ? (string) $rule->template_key : self::TEMPLATES[$trigger],
            about: $operation,
            customerId: null,
            businessId: (int) $operation->business_id,
            variables: self::variables($operation, $user, $open, $url),
            language: $rule->language ?: 'ar',
            recipientName: $user->name,
        );

        $result = Notifiers::for('whatsapp')->send($intent);

        return [$result->sent, $result->sent ? null : $result->reason, Communications::last()?->id];
    }

    /** @return array<string, string> خاناتُ القالب بالاسم — تُرتَّب في «المراسلات ‹ القوالب» */
    public static function variables(EventOperation $operation, User $user, int $open, string $url): array
    {
        $start = $operation->event_starts_at?->copy()->setTimezone($operation->timezone ?: config('app.timezone'));
        $token = (string) (parse_url($url, PHP_URL_PATH) ? basename((string) parse_url($url, PHP_URL_PATH)) : '');

        return [
            'employee_name' => (string) $user->name,
            'event_name' => (string) $operation->name,
            'event_date' => $start?->format('Y-m-d') ?? '—',
            'event_time' => $start?->format('H:i') ?? '—',
            'event_location' => (string) ($operation->venue_name ?: '—'),
            'open_tasks_count' => (string) $open,
            'tasks_url' => $url,
            'tasks_token' => $token,
            'company' => (string) ($operation->business?->name ?? ''),
        ];
    }

    /* ─────────────────────────── بعد الإنجاز ─────────────────────────── */

    /**
     * أنجز الموظّفُ آخرَ مهامّه في الفعاليّة — يعرف قائدُ العمليّات (لا العميل).
     *
     * تُنادى من `Projects::setTaskStatus` وحدَه، بعد الانتقال إلى «تمّت».
     */
    public static function afterDone(ProjectTask $task): void
    {
        if ($task->event_operation_id === null || $task->assignee_id === null) {
            return;
        }

        $operation = EventOperation::where('business_id', $task->business_id)->find($task->event_operation_id);
        if ($operation === null) {
            return;
        }

        $remaining = ProjectTask::where('business_id', $task->business_id)->where('event_operation_id', $operation->id)
            ->where('assignee_id', $task->assignee_id)->where('status', ProjectTask::OPEN)->exists();
        $lead = self::lead($operation);

        if ($remaining || $lead === null || (int) $lead->id === (int) $task->assignee_id) {
            return;
        }

        $who = User::where('business_id', $task->business_id)->find($task->assignee_id)?->name ?? __('موظّف');
        self::notify($lead, $operation, 'event_tasks_completed',
            __(':name أكمل جميع مهامه في فعالية :event', ['name' => $who, 'event' => $operation->name]),
            route('admin.operations.show', ['id' => $operation->id, 'tab' => 'tasks']));
    }

    /* ─────────────────────────── أدوات ─────────────────────────── */

    private static function lead(EventOperation $operation): ?User
    {
        return $operation->operations_lead_id
            ? User::where('business_id', $operation->business_id)->where('status', 'نشط')->find($operation->operations_lead_id)
            : null;
    }

    private static function notify(User $user, EventOperation $operation, string $kind, string $title, string $url, ?AutomationRun $run = null): void
    {
        InternalNotification::create([
            'business_id' => $operation->business_id, 'user_id' => $user->id, 'kind' => mb_substr($kind, 0, 40),
            'title' => mb_substr($title, 0, 255), 'url' => $url,
            'subject_type' => $operation->getMorphClass(), 'subject_id' => $operation->id, 'automation_run_id' => $run?->id,
        ]);
    }

    private static function logDispatch(EventOperation $operation, User $user, int $open, string $how): void
    {
        Activity::log('updated', 'أرسل مهامّ الفعاليّة '.$operation->code.' إلى '.$user->name.' ('.$open.' مفتوحة) — '.$how, [
            'business_id' => (int) $operation->business_id, 'subject_id' => $operation->id, 'subject_type' => 'event_operation', 'icon' => 'send',
        ]);
    }
}
