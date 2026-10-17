<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Support\Demo;
use App\Support\Permissions;
use App\Support\ProjectWorkflow;
use Illuminate\Http\Request;

/**
 * نقلُ مسار المشروع إلى أيّ مرحلة — اختيارٌ يدويٌّ حرّ.
 *
 * والنقلُ في `ProjectWorkflow::moveTo`: لا يسأل عن اكتمال مرحلة، ولا يمحو
 * شيئًا، ولا يغيّر حالة المشروع. وهنا يُسأل عن الإذن ويُردّ الجواب.
 */
class ProjectWorkflowController extends Controller
{
    private function project(int|string $id): Project
    {
        abort_if(! auth()->user()?->may(Permissions::PROJECT_MANAGE), 403);

        return Project::where('business_id', (int) (auth()->user()->business_id ?? Demo::bid()))->whereKey($id)->firstOrFail();
    }

    public function move(Request $request, int|string $id)
    {
        $project = $this->project($id);
        $stage = $request->input('stage');

        if (! is_string($stage) || ! in_array($stage, ProjectWorkflow::STAGES, true)) {
            return back()->withErrors(['workflow' => __('مرحلةٌ غير معروفة في مسار المشروع.')]);
        }

        $moved = ProjectWorkflow::moveTo($project, $stage, auth()->user());

        return back()->with('toast', ['msg' => __('انتقل المشروع إلى «:stage»', ['stage' => __(ProjectWorkflow::LABELS[$moved->workflow_stage] ?? '')]), 'type' => 'success']);
    }
}
