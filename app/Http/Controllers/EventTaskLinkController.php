<?php

namespace App\Http\Controllers;

use App\Models\EventOperation;
use App\Models\ProjectTask;
use App\Models\User;
use App\Support\Activity;
use App\Support\Operations\EventTasks;
use App\Support\Projects;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use RuntimeException;

/**
 * «مهامي في الفعاليّة» — ما يفتحه زرُّ «عرض المهام» في رسالة واتساب.
 *
 * ═══ صفحةٌ حيّة لا نسخة ═══
 *
 * تقرأ `ProjectTask` لحظةَ فتحها: ما أُنجز من شاشة المشروع يظهر هنا منجزًا،
 * ورسالةُ واتساب التي فتحتها لا تحتاج أن تتغيّر.
 *
 * ═══ والرمزُ يفتح مهامَّ صاحبه في فعاليّته وحدها ═══
 *
 * لا حساب للدخول: الرمزُ (٤٠ حرفًا لا يُخمَّن، يُبحث ببصمته، وله انتهاء) هو
 * الإذن. و«تمّت» تقبل مهمّةً هي من هذه الفعاليّة **ولهذا الموظّف** ومن شركته —
 * فرقمُ مهمّةٍ يُبدَّل في العنوان يُردّ بـ٤٠٤ ولا يمسّ مهمّةَ غيره. والكتابةُ من
 * بابٍ واحد (`Projects::setTaskStatus`) هو نفسُه الذي تمرّ به شاشةُ المشروع.
 *
 * والصفحةُ Blade قائمةٌ بذاتها كأخواتها في `public/`: تُفتح في متصفّح واتساب
 * على شبكةٍ ضعيفة، وتعمل بلا JavaScript.
 */
class EventTaskLinkController extends Controller
{
    public function show(string $token): Response
    {
        $link = EventTasks::resolve($token);

        if ($link === null) {
            return response()->view('public.event-tasks-missing', [], 404);
        }

        $link->forceFill(['last_used_at' => now()])->saveQuietly();
        $operation = $link->operation;
        $tasks = EventTasks::of($operation, (int) $link->user_id);

        return response()->view('public.event-tasks', [
            'token' => $token,
            'employee' => $link->user,
            'operation' => $operation,
            'project' => $operation->project,
            'lead' => $operation->operations_lead_id ? User::where('business_id', $operation->business_id)->find($operation->operations_lead_id) : null,
            'company' => $operation->business?->name,
            'tasks' => $tasks,
            'open' => $tasks->where('status', ProjectTask::OPEN)->count(),
            'writable' => $this->writable($operation),
            'timezone' => $operation->timezone ?: config('app.timezone'),
        ]);
    }

    public function done(string $token, int|string $task): RedirectResponse
    {
        return $this->set($token, $task, ProjectTask::DONE);
    }

    public function reopen(string $token, int|string $task): RedirectResponse
    {
        return $this->set($token, $task, ProjectTask::OPEN);
    }

    private function set(string $token, int|string $taskId, string $status): RedirectResponse
    {
        $link = EventTasks::resolve($token);
        abort_if($link === null, 404);

        /* المهمّةُ من هذه الفعاليّة ولهذا الموظّف ومن شركته — وإلّا فلا وجود لها هنا */
        $task = ProjectTask::where('business_id', $link->business_id)->where('event_operation_id', $link->event_operation_id)
            ->where('assignee_id', $link->user_id)->whereKey($taskId)->first();
        abort_if($task === null, 404);

        if (! $this->writable($link->operation)) {
            return redirect()->route('event-tasks.show', $token)->with('event_tasks_error', __('الفعاليّة ملغاة — لا تُحدَّث مهامّها.'));
        }

        try {
            Activity::as($link->user->name.' — '.__('من رابط المهام'), fn () => Projects::setTaskStatus(
                $task, $status, $link->user, $status === ProjectTask::DONE ? 'من رابط المهام على واتساب' : 'أعادها صاحبُها من رابط المهام',
            ));
        } catch (RuntimeException $e) {
            return redirect()->route('event-tasks.show', $token)->with('event_tasks_error', $e->getMessage());
        }

        return redirect()->route('event-tasks.show', $token)
            ->with('event_tasks_ok', $status === ProjectTask::DONE ? __('سُجّلت «:title» منجزة', ['title' => $task->title]) : __('أُعيد فتح «:title»', ['title' => $task->title]));
    }

    private function writable(EventOperation $operation): bool
    {
        return $operation->status !== EventOperation::CANCELLED;
    }
}
