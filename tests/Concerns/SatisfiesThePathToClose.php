<?php

namespace Tests\Concerns;

use App\Models\EventOperation;
use App\Models\Project;
use App\Models\User;
use App\Support\Operations\EventOperations;
use App\Support\ProjectBilling;
use App\Support\ProjectFinancials;
use App\Support\ScopesOfWork;

/**
 * ما يطلبه مسارُ المشروع قبل الإقفال — لاختباراتٍ موضوعُها غيرُه.
 *
 * الإقفالُ صار يسأل عن نطاقٍ وقّعه العميل، وتنفيذٍ مكتمل، وقيمةٍ فُوترت
 * كلُّها (`Projects::closeBlockers`). واختبارُ الالتزامات أو التسليم يحتاج
 * ذلك شرطًا لا موضوعًا — فيُستوفى هنا من أبوابه، وإتمامُ الفعاليّة تحضيرٌ
 * (سلسلةُ جاهزيّتها تُختبر في `tests/Feature/Operations`).
 */
trait SatisfiesThePathToClose
{
    /**
     * @param  bool  $bill  أتُصدَر الفاتورةُ النهائيّةُ بما بقي؟ — تحتاج تسليمًا مقبولًا
     */
    protected function satisfyThePathToClose(Project $project, User $actor, bool $bill = true): void
    {
        $this->actingAs($actor);

        if (! ScopesOfWork::isSigned($project->fresh())) {
            $scope = ScopesOfWork::send(ScopesOfWork::markReady(ScopesOfWork::start($project->fresh(), $actor), $actor), $actor);
            ScopesOfWork::decide($scope, 'accepted', ['name' => 'سالم الهنائي', 'consent' => 'أوافق'], [], $scope->content_hash);
        }

        if (! EventOperation::where('project_id', $project->id)->where('status', EventOperation::COMPLETED)->exists()) {
            EventOperations::create($project->fresh(), ['name' => 'تشغيل'], $actor)
                ->forceFill(['status' => EventOperation::COMPLETED, 'completed_at' => now()])->save();
        }

        $summary = ProjectFinancials::summary($project->fresh());
        if ($bill && (float) $summary['current_value'] - (float) $summary['net_invoiced'] > 0.0005) {
            ProjectBilling::createFinal($project->fresh(), ['issue' => true], $actor);
        }
    }
}
