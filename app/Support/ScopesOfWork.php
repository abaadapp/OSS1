<?php

namespace App\Support;

use App\Models\Business;
use App\Models\ChangeOrder;
use App\Models\DocumentApproval;
use App\Models\Project;
use App\Models\ScopeOfWork;
use App\Models\User;
use App\Support\Document\Snapshot;
use App\Support\Import\Provenance;
use App\Support\Messaging\MessageIntent;
use App\Support\Messaging\Notifiers;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * نطاقُ العمل — من المسودّة إلى توقيع العميل، ونسخةٌ جديدةٌ لكلّ تعديل.
 *
 * ═══ لا منطقَ تجاريًّا ثانيًا ═══
 *
 * البنودُ والكميّاتُ والمتطلّباتُ والقيمةُ والموعدُ تُقرأ من `ProjectScope`
 * (العرضُ المقبول + أوامرُ التغيير المقبولة) وتُحفظ لقطةً في المستند. وما
 * يُكتب هنا بيدٍ هو ما لا يقوله العرض: وصفُ التنفيذ، وما يشمله وما لا
 * يشمله، ومسؤوليّاتُ الطرفين، والشروطُ الخاصّة.
 *
 * ═══ ودليلُ التوقيع كدليل العرض ═══
 *
 * يُبصَم عند الإرسال (`Fingerprint`)، ويُوقَّع من رابطٍ عامّ (`PublicDocument`)
 * فيُكتب `DocumentApproval` باللقطة والموقّع والعنوان والمتصفّح — تحت قفل،
 * ومرّةً واحدة بقيد الجدول. ولا يُوقَّع إلّا ما بصمتُه الآن هي بصمتُه يومَ
 * خرج، والبصمةُ التي قرأها العميلُ في صفحته.
 *
 * ═══ وما وُقّع لا يُكتب فوقه ═══
 *
 * التعديلُ نسخةٌ جديدة: تبدأ مسودّةً، وتحلّ محلَّ الموقَّعة حين تُوقَّع هي.
 * وما يتغيّر في البنود والقيمة بابُه أمرُ التغيير — يوقّعه العميلُ بدليله
 * هو، ولا يحتاج نسخةً من النطاق.
 */
final class ScopesOfWork
{
    /** حقولُ النصّ الحرّ وحدودُها — ما يكتبه من يُعدّ النطاق */
    public const TEXT_FIELDS = [
        'customer_name' => 255,
        'contact_person' => 120,
        'contact_phone' => 40,
        'contact_email' => 120,
        'project_name' => 255,
        'event_type' => 60,
        'location' => 255,
        'schedule_notes' => 500,
        'description' => 20000,
        'inclusions' => 10000,
        'exclusions' => 10000,
        'company_responsibilities' => 10000,
        'client_responsibilities' => 10000,
        'terms' => 10000,
    ];

    /* ─────────────────────────── القراءة ─────────────────────────── */

    /**
     * أيُطلب من هذا المشروع نطاقٌ موقَّع؟
     *
     * المشروعُ المستورَد من التاريخ (المرحلة 9) جرى قبل هذا النظام: اتّفاقُه
     * كان على ورقٍ لا رابطَ له، ولا يُطالَب الآن بتوقيعٍ على ما مضى.
     */
    public static function required(Project $project): bool
    {
        return ($project->origin ?? 'native') !== Provenance::HISTORICAL;
    }

    public static function latest(Project $project): ?ScopeOfWork
    {
        return ScopeOfWork::where('business_id', $project->business_id)
            ->where('project_id', $project->id)->orderByDesc('revision')->first();
    }

    /** النسخةُ الموقَّعة السارية — واحدةٌ أو لا شيء */
    public static function signed(Project $project): ?ScopeOfWork
    {
        return ScopeOfWork::where('business_id', $project->business_id)
            ->where('project_id', $project->id)->where('status', ScopeOfWork::SIGNED)
            ->orderByDesc('revision')->first();
    }

    /** أتمّت مرحلةُ النطاق؟ — نسخةٌ موقَّعة، أو مشروعٌ لا يُطلب منه ذلك */
    public static function isSigned(Project $project): bool
    {
        return ! self::required($project) || self::signed($project) !== null;
    }

    /* ─────────────────────────── الكتابة ─────────────────────────── */

    /**
     * نسخةٌ جديدة — الأولى من المشروع، أو تعديلٌ لما سبقها.
     *
     * ومرّةً لكلّ حالة: مسودّةٌ أو جاهزةٌ قائمةٌ تُردّ هي ولا تُكتب ثانية
     * (ضغطتان على «إعداد نطاق العمل» نسخةٌ واحدة). ونسخةٌ خرجت ولم تُوقَّع
     * تحلّ محلَّها الجديدةُ فورًا — لا يُترك للعميل رابطان يوقّع أيَّهما شاء.
     * وأمّا الموقَّعة فتبقى ساريةً حتى تُوقَّع التي بعدها.
     */
    public static function start(Project $project, ?User $actor = null): ScopeOfWork
    {
        return DB::transaction(function () use ($project, $actor) {
            $locked = Project::where('business_id', $project->business_id)
                ->lockForUpdate()->findOrFail($project->id);

            if (! $locked->isOpen()) {
                throw new RuntimeException(__('لا يُكتب نطاق عملٍ لمشروعٍ مُقفَل أو ملغى.'));
            }

            /*
             * والنسخةُ الأخيرة تُقرأ تحت قفلٍ هي أيضًا — لا قفلِ المشروع وحده.
             *
             * توقيعُ العميل يُقفل صفَّ النسخة لا صفَّ المشروع (`decide`). فلو
             * قُرئت هنا بلا قفلٍ «مُرسَلةً» والعميلُ يوقّعها في اللحظة نفسِها،
             * لكتب ما بعدها «حلّت محلَّها» فوق توقيعٍ تمّ: نسخةٌ موقّعةٌ بدليلها
             * تُطوى صامتةً. والقفلُ يجعل أحدَهما ينتظر الآخر ثمّ يقرأ ما كتبه.
             */
            $latest = ScopeOfWork::where('business_id', $locked->business_id)->where('project_id', $locked->id)
                ->orderByDesc('revision')->lockForUpdate()->first();

            if ($latest !== null && in_array($latest->status, [ScopeOfWork::DRAFT, ScopeOfWork::READY], true)) {
                return $latest;
            }

            $revision = ($latest?->revision ?? 0) + 1;
            $fresh = self::defaults($locked);

            /* والتعديلُ يرث ما كُتب بيدٍ في سابقه، ويقرأ المشروعَ والموعدَ من جديد */
            $carried = $latest === null ? [] : collect(['customer_name', 'contact_person', 'contact_phone', 'contact_email',
                'schedule_notes', 'description', 'inclusions', 'exclusions', 'company_responsibilities',
                'client_responsibilities', 'terms', 'show_value'])
                ->mapWithKeys(fn ($k) => [$k => $latest->{$k}])->reject(fn ($v) => $v === null)->all();

            $scope = ScopeOfWork::create(array_merge($fresh, $carried, [
                'business_id' => $locked->business_id,
                'project_id' => $locked->id,
                'quotation_id' => $locked->quotation_id,
                'supersedes_id' => $latest?->id,
                'revision' => $revision,
                'number' => self::number($locked, $revision),
                'status' => ScopeOfWork::DRAFT,
                'scope_snapshot' => self::snapshotOf($locked),
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
            ]));

            /* وبشرطٍ في القاعدة كذلك: لا يُطوى إلّا ما زال مُرسَلًا لم يُقرَّر فيه */
            if ($latest !== null && $latest->status === ScopeOfWork::SENT
                && ScopeOfWork::whereKey($latest->id)->where('status', ScopeOfWork::SENT)
                    ->update(['status' => ScopeOfWork::SUPERSEDED, 'superseded_at' => now()]) === 1) {

                Activity::log('status', 'حلّ نطاق العمل '.$scope->number.' محلَّ '.$latest->number.' قبل أن يُوقَّع — رابطُ السابق لا يقبل توقيعًا', [
                    'subject_id' => $locked->id, 'subject_type' => 'project', 'icon' => 'file-x', 'color' => 'warning',
                ]);
            }

            Activity::log('created', ($revision === 1 ? 'أنشأ نطاق العمل ' : 'بدأ نسخةً جديدةً من نطاق العمل ').$scope->number.' للمشروع '.$locked->code, [
                'subject_id' => $locked->id, 'subject_type' => 'project', 'icon' => 'file-text',
            ]);

            return $scope;
        });
    }

    /**
     * حفظُ المسودّة — والبنودُ تُقرأ من النطاق الحاليّ مع كلّ حفظ.
     *
     * ولا تُحفظ غيرُ المسودّة: الجاهزةُ تُعاد مسودّةً أوّلًا، وما خرج لا يُمسّ.
     *
     * @param  array<string, mixed>  $data
     */
    public static function update(ScopeOfWork $scope, array $data, ?User $actor = null): ScopeOfWork
    {
        return DB::transaction(function () use ($scope, $data, $actor) {
            $locked = ScopeOfWork::where('business_id', $scope->business_id)->lockForUpdate()->findOrFail($scope->id);

            if (! $locked->isEditable()) {
                throw new RuntimeException($locked->status === ScopeOfWork::READY
                    ? __('هذا النطاق جاهزٌ للإرسال — أعده مسودّةً لتعدّله.')
                    : __('خرج هذا النطاق إلى العميل — التعديلُ نسخةٌ جديدة.'));
            }

            $project = $locked->project;
            $fields = [];

            foreach (self::TEXT_FIELDS as $field => $max) {
                if (array_key_exists($field, $data)) {
                    $fields[$field] = Projects::text($data[$field], $max);
                }
            }

            if (array_key_exists('project_name', $fields) && $fields['project_name'] === null) {
                $fields['project_name'] = (string) $project->name;
            }

            foreach (['starts_at', 'ends_at'] as $date) {
                if (array_key_exists($date, $data)) {
                    $fields[$date] = Projects::date($data[$date]);
                }
            }

            if (array_key_exists('show_value', $data)) {
                $fields['show_value'] = filter_var($data['show_value'], FILTER_VALIDATE_BOOLEAN);
            }

            $locked->update($fields + [
                'scope_snapshot' => self::snapshotOf($project),
                'updated_by' => $actor?->id,
            ]);

            Activity::log('updated', 'عدّل مسودّة نطاق العمل '.$locked->number, [
                'subject_id' => $project->id, 'subject_type' => 'project', 'icon' => 'pencil',
            ]);

            return $locked->fresh();
        });
    }

    /**
     * «جاهز للإرسال» — رُوجع داخليًّا، ويُجمَّد النصُّ حتى يخرج أو يُعاد.
     */
    public static function markReady(ScopeOfWork $scope, ?User $actor = null): ScopeOfWork
    {
        return DB::transaction(function () use ($scope, $actor) {
            $locked = ScopeOfWork::where('business_id', $scope->business_id)->lockForUpdate()->findOrFail($scope->id);

            if ($locked->status === ScopeOfWork::READY) {
                return $locked;
            }
            if ($locked->status !== ScopeOfWork::DRAFT) {
                throw new RuntimeException(__('لا يُجهَّز للإرسال إلا نطاقٌ في المسودّة.'));
            }

            $snapshot = self::snapshotOf($locked->project);

            if (($snapshot['items'] ?? []) === [] && trim((string) $locked->description) === '') {
                throw new RuntimeException(__('نطاقٌ بلا بنودٍ ولا وصف — اكتب وصف نطاق التنفيذ أولًا.'));
            }

            $locked->update([
                'status' => ScopeOfWork::READY,
                'scope_snapshot' => $snapshot,
                'ready_at' => now(),
                'ready_by' => $actor?->id,
            ]);

            Activity::log('status', 'جهّز نطاق العمل '.$locked->number.' للإرسال', [
                'subject_id' => $locked->project_id, 'subject_type' => 'project', 'icon' => 'file-check',
            ]);

            return $locked->fresh();
        });
    }

    /** إعادةُ الجاهز مسودّةً — قبل أن يخرج وحده */
    public static function backToDraft(ScopeOfWork $scope, ?User $actor = null): ScopeOfWork
    {
        return DB::transaction(function () use ($scope) {
            $locked = ScopeOfWork::where('business_id', $scope->business_id)->lockForUpdate()->findOrFail($scope->id);

            if ($locked->status === ScopeOfWork::DRAFT) {
                return $locked;
            }
            if ($locked->status !== ScopeOfWork::READY) {
                throw new RuntimeException(__('خرج هذا النطاق إلى العميل — التعديلُ نسخةٌ جديدة.'));
            }

            $locked->update(['status' => ScopeOfWork::DRAFT, 'ready_at' => null, 'ready_by' => null]);

            Activity::log('status', 'أعاد نطاق العمل '.$locked->number.' مسودّةً للتعديل', [
                'subject_id' => $locked->project_id, 'subject_type' => 'project', 'icon' => 'pencil',
            ]);

            return $locked->fresh();
        });
    }

    /**
     * «إرسال للعميل للتوقيع» — يُجمَّد ويُبصَم ويُبنى رابطُه العامّ. تحت قفل، ومرّةً.
     *
     * ═══ والنطاقُ الحاليّ يُسأل لحظةَ الخروج ═══
     *
     * ما رُوجع هو ما يخرج: إن قُبل أمرُ تغييرٍ بين المراجعة والإرسال افترقت
     * اللقطةُ عن `ProjectScope`، فيُردّ الإرسال حتى يُراجَع من جديد — لا
     * يُحدَّث وحده فيخرج ما لم يقرأه أحد.
     *
     * ═══ والمتطلّباتُ تُثبَّت معه ═══
     *
     * ما يوقّعه العميلُ لا يُعدَّل بعده إلّا بأمر تغيير — فإن لم تُثبَّت
     * متطلّباتُ المشروع بعدُ ثُبّتت هنا من بابها (`Projects::baseline`).
     */
    public static function send(ScopeOfWork $scope, ?User $actor = null): ScopeOfWork
    {
        return DB::transaction(function () use ($scope, $actor) {
            $locked = ScopeOfWork::where('business_id', $scope->business_id)->lockForUpdate()->findOrFail($scope->id);

            if ($locked->status === ScopeOfWork::SENT) {
                return $locked;
            }
            if ($locked->status !== ScopeOfWork::READY) {
                throw new RuntimeException($locked->status === ScopeOfWork::DRAFT
                    ? __('راجِع المسودّة واجعلها «جاهزة للإرسال» أولًا.')
                    : __('لا يُرسَل إلا نطاقٌ جاهزٌ للإرسال.'));
            }

            $project = Project::where('business_id', $locked->business_id)->findOrFail($locked->project_id);

            if (! $project->isOpen()) {
                throw new RuntimeException(__('لا يُرسَل نطاق عملٍ لمشروعٍ مُقفَل أو ملغى.'));
            }

            if (self::hasNewer($locked)) {
                throw new RuntimeException(__('هذه نسخةٌ قديمة من نطاق العمل — حلّت محلَّها نسخةٌ أحدث.'));
            }

            if (self::stale($locked)) {
                throw new RuntimeException(__('تغيّر النطاق المعتمد منذ مراجعة هذه النسخة — أعدها مسودّةً واحفظها ثمّ راجعها قبل الإرسال.'));
            }

            if (! $project->isBaselined()) {
                Projects::baseline($project, $actor);
            }

            $locked->update([
                'status' => ScopeOfWork::SENT,
                'sent_at' => now(),
                'sent_by' => $actor?->id,
                Snapshot::COLUMN => Snapshot::capture((int) $locked->business_id),
                'content_hash' => self::contentHash($locked),
            ]);

            PublicDocument::token($locked);

            /* نطاقٌ أُرسل ← من · متى · بأيّ بصمة (§24) */
            Activity::log('status', 'أرسل نطاق العمل '.$locked->number.' للعميل للتوقيع — البصمة '.substr((string) $locked->content_hash, 0, 12), [
                'subject_id' => $project->id, 'subject_type' => 'project', 'icon' => 'send', 'color' => 'primary',
            ]);

            return $locked->fresh();
        });
    }

    /**
     * توقيعُ العميل من رابطه — موافقةٌ أو طلبُ تعديل. دليلٌ يُكتب مرّةً.
     *
     * ═══ ما يُردّ قبل أن يُكتب شيء ═══
     *
     * نسخةٌ حلّت محلَّها أحدث، أو قُرّر فيها، أو لم تخرج — لا تُوقَّع. وبصمةٌ
     * أرسلتها صفحةُ العميل غيرُ بصمة المستند الآن (فتح الصفحةَ ثمّ خرجت نسخةٌ
     * أحدث)، أو لقطةٌ لا تطابق بصمتَها (عُدّل الصفُّ بيدٍ بعد الإرسال) — لا
     * تُوقَّع. والقفلُ يُسلسل ضغطتين، وقيدُ `document_approvals` يردّ ما مرّ.
     *
     * @param  array<string, mixed>  $signer  `name` · `title` · `email` · `phone` · `consent` · `decline_reason`
     * @param  array<string, mixed>  $meta  `ip` · `user_agent`
     * @param  string|null  $hash  البصمةُ كما قرأها العميلُ في صفحته
     */
    public static function decide(ScopeOfWork $scope, string $decision, array $signer, array $meta = [], ?string $hash = null): ScopeOfWork
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
            throw new RuntimeException(__('اكتب ما يحتاج تعديلًا في نطاق العمل.'));
        }

        return DB::transaction(function () use ($scope, $accepted, $name, $signer, $declineReason, $meta, $hash) {
            $locked = ScopeOfWork::where('business_id', $scope->business_id)->lockForUpdate()->findOrFail($scope->id);

            if ($locked->status === ScopeOfWork::SIGNED) {
                throw new RuntimeException(__('وُقّع نطاق العمل هذا من قبل.'));
            }
            if ($locked->status === ScopeOfWork::SUPERSEDED || self::hasNewer($locked)) {
                throw new RuntimeException(__('هذه نسخةٌ قديمة من نطاق العمل — حلّت محلَّها نسخةٌ أحدث.'));
            }
            if (! $locked->isDecidable()) {
                throw new RuntimeException(__('هذا المستند لا يقبل قرارًا الآن.'));
            }

            $project = Project::where('business_id', $locked->business_id)->findOrFail($locked->project_id);

            if (! $project->isOpen()) {
                throw new RuntimeException(__('هذا المشروع مُقفَل أو ملغى — لا يُوقَّع نطاقُه.'));
            }

            if ($hash !== null && ! hash_equals((string) $locked->content_hash, $hash)) {
                throw new RuntimeException(__('تغيّر هذا المستند منذ فتحتَه — حدّث الصفحة واقرأه من جديد قبل التوقيع.'));
            }

            $payload = self::hashPayload($locked);

            if (! Fingerprint::matches($payload, $locked->content_hash)) {
                throw new RuntimeException(__('تغيّر محتوى نطاق العمل بعد إرساله — لا يُوقَّع بهذه الصورة. تواصل مع الشركة.'));
            }

            try {
                $approval = DocumentApproval::create([
                    'business_id' => $locked->business_id,
                    'approvable_type' => $locked->getMorphClass(),
                    'approvable_id' => $locked->id,
                    'provider' => DocumentApproval::INTERNAL,
                    'decision' => $accepted ? DocumentApproval::ACCEPTED : DocumentApproval::DECLINED,
                    'content_hash' => $locked->content_hash,
                    'accepted_snapshot' => $payload,
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
                throw new RuntimeException(__('قُرّر في هذا المستند قبل لحظات.'));
            }

            $locked->update([
                'status' => $accepted ? ScopeOfWork::SIGNED : ScopeOfWork::DECLINED,
                'decided_at' => $approval->decided_at,
                'decided_by_name' => $name,
                'decline_reason' => $accepted ? null : $declineReason,
            ]);

            /* والموقَّعةُ السابقةُ تُطوى — تبقى بدليلها مقروءةً، والساريةُ واحدة */
            if ($accepted) {
                ScopeOfWork::where('project_id', $locked->project_id)->whereKeyNot($locked->id)
                    ->where('status', ScopeOfWork::SIGNED)
                    ->update(['status' => ScopeOfWork::SUPERSEDED, 'superseded_at' => now()]);
            }

            /* قرارُ عميل ← من الرابط: الاسم · البريد · IP · الوقت · البصمة (§24) */
            Activity::as(__('العميل: :name', ['name' => $name]), fn () => Activity::log('status', ($accepted ? 'وقّع العميل نطاق العمل ' : 'طلب العميل تعديل نطاق العمل ')
                .$locked->number.' إلكترونيًّا — '.$name
                .(filled($approval->signer_email) ? ' <'.$approval->signer_email.'>' : '')
                .($accepted ? '' : ' — '.$declineReason), [
                    'business_id' => $locked->business_id,
                    'subject_id' => $locked->project_id, 'subject_type' => 'project',
                    'icon' => $accepted ? 'file-check' : 'file-x',
                    'color' => $accepted ? 'success' : 'warning',
                ]));

            return $locked->fresh();
        });
    }

    /**
     * فتحه العميل — يُختم أوّلُ فتحٍ مرّةً ويُقيَّد في الأثر، وما بعده عدٌّ.
     *
     * و«أوّلُ فتحٍ» يُكتب بشرطٍ في القاعدة لا بقراءةٍ قبلها: فتحان في اللحظة
     * نفسِها يكتبان واقعةً واحدة.
     */
    public static function markViewed(ScopeOfWork $scope): void
    {
        $first = ScopeOfWork::whereKey($scope->id)->whereNull('first_viewed_at')
            ->where('status', ScopeOfWork::SENT)->update(['first_viewed_at' => now()]);

        ScopeOfWork::whereKey($scope->id)->update([
            'last_viewed_at' => now(),
            'view_count' => DB::raw('view_count + 1'),
        ]);

        if ($first === 1) {
            Activity::as(__('العميل'), fn () => Activity::log('status', 'فتح العميل رابط نطاق العمل '.$scope->number.' أوّل مرّة', [
                'business_id' => $scope->business_id,
                'subject_id' => $scope->project_id, 'subject_type' => 'project', 'icon' => 'eye',
            ]));
        }
    }

    /** خبرُ القرار إلى الشركة — أفضلُ جهدٍ لا شرط، كما في العرض وأمر التغيير */
    public static function notifyDecision(ScopeOfWork $scope, DocumentApproval $approval): void
    {
        $notifier = Notifiers::email();
        $business = Business::find($scope->business_id);

        if (! $notifier->configured() || ! Mailer::deliverable($business)) {
            return;
        }

        $accepted = $approval->isAccepted();

        $notifier->send(new MessageIntent(
            to: (string) $business->email,
            subject: $accepted
                ? __('وقّع العميل نطاق العمل :label', ['label' => self::label($scope)])
                : __('طلب العميل تعديل نطاق العمل :label', ['label' => self::label($scope)]),
            paragraphs: array_values(array_filter([
                $approval->signer_name.(filled($approval->signer_title) ? ' ('.$approval->signer_title.')' : ''),
                $accepted
                    ? __('وقّع نطاق العمل إلكترونيًا في :at.', ['at' => $approval->decided_at?->format('Y-m-d H:i')])
                    : __('طلب التعديل في :at — :reason', [
                        'at' => $approval->decided_at?->format('Y-m-d H:i'), 'reason' => (string) $approval->decline_reason,
                    ]),
            ])),
            url: route('admin.projects.scope.show', $scope->project_id),
            action: __('فتح نطاق العمل في النظام'),
            templateKey: $accepted ? 'scope.signed' : 'scope.declined',
            senderName: $business->name,
            about: $scope,
            businessId: (int) $scope->business_id,
        ));
    }

    /* ─────────────────────────── اللقطة والبصمة ─────────────────────────── */

    /**
     * النطاقُ الحاليّ كما يقوله `ProjectScope` — لقطةٌ تُحفظ في المستند.
     *
     * والأرقامُ مصوغةٌ نصوصًا بمنازلها: لقطةٌ تُقرأ من JSON فتعود `5` بدل
     * `5.000` تغيّر البصمةَ وما تغيّر شيء.
     *
     * @return array<string, mixed>
     */
    public static function snapshotOf(Project $project): array
    {
        $current = ProjectScope::current($project);
        $quotation = $project->quotation;
        $n3 = fn ($v) => number_format((float) $v, 3, '.', '');

        return [
            'quotation' => $quotation === null ? null : [
                'label' => Quotations::label($quotation),
                'hash' => (string) $quotation->content_hash,
                'decided_at' => (string) optional($quotation->decided_at)->format('Y-m-d H:i'),
            ],
            'change_orders' => ChangeOrder::where('project_id', $project->id)->accepted()
                ->orderBy('sequence')->pluck('number')->map(fn ($n) => (string) $n)->values()->all(),
            'items' => collect($current['items'])
                ->filter(fn (array $i) => (float) $i['quantity'] > 0.0005)
                ->map(fn (array $i) => [
                    'description' => (string) $i['description'],
                    'unit' => (string) ($i['unit'] ?? ''),
                    'quantity' => $n3($i['quantity']),
                    'unit_price' => $n3($i['unit_price']),
                    'tax_rate' => number_format((float) $i['tax_rate'], 2, '.', ''),
                    'line_total' => $n3($i['line_total']),
                ])->values()->all(),
            'requirements' => collect($current['requirements'])
                ->map(fn (array $r) => ['title' => (string) $r['title'], 'details' => (string) ($r['details'] ?? '')])
                ->values()->all(),
            'starts_at' => (string) ($current['starts_at'] ?? ''),
            'ends_at' => (string) ($current['ends_at'] ?? ''),
            'value' => $n3($current['current_value']),
        ];
    }

    /** أافترقت لقطةُ المسودّة عن النطاق الحاليّ؟ — أمرُ تغييرٍ قُبل بعدها */
    public static function stale(ScopeOfWork $scope): bool
    {
        $project = $scope->relationLoaded('project') ? $scope->project : $scope->project()->first();

        return Fingerprint::of((array) $scope->scope_snapshot) !== Fingerprint::of(self::snapshotOf($project));
    }

    public static function contentHash(ScopeOfWork $scope): string
    {
        return Fingerprint::of(self::hashPayload($scope));
    }

    /**
     * الحمولةُ التي تُبصَم — ما يراه العميلُ بعينه، ولا شيءَ سواه.
     *
     * والأسعارُ والقيمةُ فيها حين تُعرض عليه وحدَها: بصمةٌ تشمل رقمًا لم
     * يره الموقّعُ تشهد على ما لم يُقرأ. وهي نفسُها `accepted_snapshot`
     * في الدليل — فمن شكّ بعد سنةٍ أعاد الحسابَ عليها.
     *
     * @return array<string, mixed>
     */
    public static function hashPayload(ScopeOfWork $scope): array
    {
        $snap = (array) ($scope->scope_snapshot ?? []);
        $show = (bool) $scope->show_value;
        $s = fn ($v) => (string) ($v ?? '');

        return [
            'doc' => [$s($scope->number), (int) $scope->revision, $s($snap['quotation']['hash'] ?? null)],
            'client' => [$s($scope->customer_name), $s($scope->contact_person), $s($scope->contact_phone), $s($scope->contact_email)],
            'event' => [
                $s($scope->project_name), $s($scope->event_type), $s($scope->location),
                $s($scope->starts_at?->toDateString()), $s($scope->ends_at?->toDateString()), $s($scope->schedule_notes),
            ],
            'text' => [
                $s($scope->description), $s($scope->inclusions), $s($scope->exclusions),
                $s($scope->company_responsibilities), $s($scope->client_responsibilities), $s($scope->terms),
            ],
            'scope' => [
                'quotation' => $s($snap['quotation']['label'] ?? null),
                'change_orders' => array_values((array) ($snap['change_orders'] ?? [])),
                'items' => array_map(fn (array $i) => $show ? $i : array_diff_key($i, array_flip(['unit_price', 'tax_rate', 'line_total'])),
                    array_values((array) ($snap['items'] ?? []))),
                'requirements' => array_values((array) ($snap['requirements'] ?? [])),
                'value' => $show ? $s($snap['value'] ?? null) : '',
            ],
            'show_value' => $show,
        ];
    }

    /* ─────────────────────────── أدوات ─────────────────────────── */

    /** «EP-2026-014/SOW-R1» */
    public static function number(Project $project, int $revision): string
    {
        return $project->code.'/SOW-R'.$revision;
    }

    public static function label(ScopeOfWork $scope): string
    {
        return (string) $scope->number;
    }

    /** رابطُ العميل — لما خرج وحدَه؛ ورابطُ مسودّةٍ يفتح للعميل ما لم يُرسَل إليه */
    public static function url(ScopeOfWork $scope): ?string
    {
        return $scope->isPubliclyVisible() ? PublicDocument::url($scope) : null;
    }

    /** أحلّت محلَّها نسخةٌ أحدث؟ */
    public static function hasNewer(ScopeOfWork $scope): bool
    {
        return ScopeOfWork::where('project_id', $scope->project_id)->where('revision', '>', $scope->revision)->exists();
    }

    /**
     * نصُّ الإقرار — بحرفه، يُعرض في صفحة العميل ويُحفظ مع توقيعه.
     */
    public static function consent(string $company): string
    {
        return __('أقرّ بأنني مخوّل باعتماد نطاق العمل هذا نيابة عن الجهة المذكورة فيه، وأنني قرأت البنود والمتطلّبات وما يشمله العمل وما لا يشمله ومسؤوليات الطرفين، وأوافق على أن تنفّذ :company العمل وفقه.', [
            'company' => $company,
        ]);
    }

    /**
     * ما يُعرض من النسخة في اللوحة — والمالُ لمن يملكه.
     *
     * @return array<string, mixed>
     */
    public static function present(ScopeOfWork $scope, bool $commercial): array
    {
        $scope->loadMissing('approval', 'sender:id,name');
        $snap = (array) ($scope->scope_snapshot ?? []);

        if (! $commercial) {
            $snap['items'] = array_map(fn (array $i) => array_replace($i, ['unit_price' => null, 'line_total' => null]), (array) ($snap['items'] ?? []));
            $snap['value'] = null;
        }

        $approval = $scope->approval;

        return [
            'id' => $scope->id,
            'number' => $scope->number,
            'revision' => $scope->revision,
            'status' => $scope->status,
            'state' => $scope->presentedState(),
            'editable' => $scope->isEditable(),
            'customer_name' => $scope->customer_name,
            'contact_person' => $scope->contact_person,
            'contact_phone' => $scope->contact_phone,
            'contact_email' => $scope->contact_email,
            'project_name' => $scope->project_name,
            'event_type' => $scope->event_type,
            'location' => $scope->location,
            'starts_at' => optional($scope->starts_at)->format('Y-m-d'),
            'ends_at' => optional($scope->ends_at)->format('Y-m-d'),
            'schedule_notes' => $scope->schedule_notes,
            'description' => $scope->description,
            'inclusions' => $scope->inclusions,
            'exclusions' => $scope->exclusions,
            'company_responsibilities' => $scope->company_responsibilities,
            'client_responsibilities' => $scope->client_responsibilities,
            'terms' => $scope->terms,
            'show_value' => (bool) $scope->show_value,
            'snapshot' => $snap,
            'content_hash' => $scope->content_hash,
            'ready_at' => optional($scope->ready_at)->format('Y-m-d H:i'),
            'sent_at' => optional($scope->sent_at)->format('Y-m-d H:i'),
            'sent_by' => $scope->sender?->name,
            'first_viewed_at' => optional($scope->first_viewed_at)->format('Y-m-d H:i'),
            'last_viewed_at' => optional($scope->last_viewed_at)->format('Y-m-d H:i'),
            'view_count' => (int) $scope->view_count,
            'decided_at' => optional($scope->decided_at)->format('Y-m-d H:i'),
            'decided_by_name' => $scope->decided_by_name,
            'decline_reason' => $scope->decline_reason,
            'superseded_at' => optional($scope->superseded_at)->format('Y-m-d H:i'),
            'url' => self::url($scope),
            /* والمسودّةُ تُسأل: أما زالت بنودُها هي النطاقَ الحاليّ؟ */
            'stale' => in_array($scope->status, [ScopeOfWork::DRAFT, ScopeOfWork::READY], true) && self::stale($scope),
            'approval' => $approval === null ? null : [
                'decision' => $approval->decision,
                'signer_name' => $approval->signer_name,
                'signer_title' => $approval->signer_title,
                'signer_email' => $approval->signer_email,
                'signer_phone' => $approval->signer_phone,
                'decided_at' => optional($approval->decided_at)->format('Y-m-d H:i'),
                'ip' => $approval->ip,
                'user_agent' => $approval->user_agent,
                'consent_text' => $approval->consent_text,
                'decline_reason' => $approval->decline_reason,
                'content_hash' => $approval->content_hash,
                /* والبرهانُ يُعاد فحصُه عند كلّ قراءة — دليلٌ لا شهادة */
                'verified' => $approval->verify(),
            ],
        ];
    }

    /**
     * ما يُكتب في المسودّة الأولى — من المشروع والعرض الذي بدأ منه.
     *
     * @return array<string, mixed>
     */
    private static function defaults(Project $project): array
    {
        $project->loadMissing('customer', 'quotation');
        $customer = $project->customer;
        $quotation = $project->quotation;

        return [
            'customer_name' => Projects::text($customer?->legal_name ?: $customer?->name, 255),
            'contact_person' => Projects::text($project->contact_person, 120),
            'contact_phone' => Projects::text($project->contact_phone, 40),
            'contact_email' => Projects::text($project->contact_email, 120),
            'project_name' => (string) $project->name,
            'event_type' => Projects::text($project->event_type, 60),
            'location' => Projects::text($project->location, 255),
            'starts_at' => $project->starts_at?->toDateString(),
            'ends_at' => $project->ends_at?->toDateString(),
            'description' => Projects::text($quotation?->scope ?: $project->notes, 20000),
            'terms' => Projects::text($quotation?->terms, 10000),
        ];
    }
}
