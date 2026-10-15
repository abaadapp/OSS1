<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Support\Demo;
use App\Support\Permissions;
use App\Support\ProjectWorkflow;
use RuntimeException;

/**
 * التنقّلُ في مسار المشروع — «تم — الانتقال» و«رجوع».
 *
 * والحركةُ في `ProjectWorkflow`: لا تتقدّم قبل أن تتمّ المرحلة، ولا تعود
 * بمحو شيء. وهنا يُسأل عن الإذن ويُردّ الجواب.
 */
class ProjectWorkflowController extends Controller
{
    private function project(int|string $id): Project
    {
        abort_if(! auth()->user()?->may(Permissions::PROJECT_MANAGE), 403);

        return Project::where('business_id', (int) (auth()->user()->business_id ?? Demo::bid()))->whereKey($id)->firstOrFail();
    }

    public function advance(int|string $id)
    {
        $project = $this->project($id);

        try {
            $moved = ProjectWorkflow::advance($project, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['workflow' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('انتقل المشروع إلى «:stage»', ['stage' => __(ProjectWorkflow::LABELS[$moved->workflow_stage] ?? '')]), 'type' => 'success']);
    }

    public function back(int|string $id)
    {
        $project = $this->project($id);

        try {
            $moved = ProjectWorkflow::back($project, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['workflow' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('عاد المشروع إلى «:stage» — لم يُمحَ شيء', ['stage' => __(ProjectWorkflow::LABELS[$moved->workflow_stage] ?? '')]), 'type' => 'success']);
    }
}
