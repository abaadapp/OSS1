<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Support\Demo;
use App\Support\Permissions;
use App\Support\ProjectBilling;
use App\Support\ProjectWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * فوترةُ المشروع من مساره — الدفعةُ المقدّمة والفاتورةُ النهائيّة.
 *
 * والمبلغُ لا يُقرأ من الطلب: ما يُرسَل نسبةٌ أو مبلغٌ ثابتٌ يكتبه
 * المستخدم، و`ProjectBilling` يحسب الفاتورةَ منه ومن القيمة الحاليّة،
 * ويرفض ما يتجاوز الباقي. والفاتورةُ النهائيّة لا تقبل رقمًا أصلًا.
 */
class ProjectBillingController extends Controller
{
    private function bid(): int
    {
        return (int) (auth()->user()->business_id ?? Demo::bid());
    }

    private function project(int|string $id): Project
    {
        return Project::where('business_id', $this->bid())->whereKey($id)->with('customer')->firstOrFail();
    }

    /** الفوترةُ لمن يفتح فواتيرَ العملاء (قسمُها «المالية») — والإصدارُ فعلٌ يُسأل عنه في `ProjectBilling` */
    private function mayInvoice(): void
    {
        abort_if(! auth()->user()?->allows('finance'), 403);
    }

    /** حسابُ الدفعة قبل إنشائها — للشاشة، من الحساب نفسِه الذي يُنشئها */
    public function preview(Request $request, int|string $id): JsonResponse
    {
        $this->mayInvoice();
        $project = $this->project($id);
        $method = (string) $request->query('method', ProjectBilling::PERCENT);
        $input = $method === ProjectBilling::PERCENT ? $request->query('percent') : $request->query('amount');

        try {
            return response()->json(['quote' => ProjectBilling::quoteDeposit($project, $method, $input), 'error' => null]);
        } catch (RuntimeException $e) {
            return response()->json(['quote' => null, 'error' => $e->getMessage()]);
        }
    }

    public function deposit(Request $request, int|string $id)
    {
        $this->mayInvoice();
        $project = $this->project($id);

        $data = $request->validate([
            'method' => ['required', Rule::in([ProjectBilling::PERCENT, ProjectBilling::FIXED])],
            'percent' => ['nullable', 'required_if:method,'.ProjectBilling::PERCENT, 'numeric', 'gt:0', 'max:100'],
            'amount' => ['nullable', 'required_if:method,'.ProjectBilling::FIXED, 'numeric', 'gt:0'],
            'issue' => ['nullable', 'boolean'],
            'additional' => ['nullable', 'boolean'],
            'request_key' => ['nullable', 'string', 'max:64'],
            'due_at' => ['nullable', 'date'],
        ], [], ['percent' => __('النسبة'), 'amount' => __('المبلغ'), 'due_at' => __('تاريخ الاستحقاق')]);

        try {
            $invoice = ProjectBilling::createDeposit($project, $data, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['billing' => $e->getMessage()]);
        }

        ProjectWorkflow::touch($project, ProjectWorkflow::DEPOSIT);

        return back()->with('toast', [
            'msg' => $invoice->number
                ? __('صدرت فاتورة الدفعة المقدمة :n', ['n' => $invoice->number])
                : __('حُفظت فاتورة الدفعة المقدمة مسودّةً'),
            'type' => 'success',
        ]);
    }

    public function waive(Request $request, int|string $id)
    {
        abort_if(! auth()->user()?->may(Permissions::PROJECT_MANAGE), 403);
        $project = $this->project($id);

        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], [], ['reason' => __('السبب')]);

        try {
            ProjectBilling::waiveDeposit($project, $data['reason'], auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['billing' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('سُجّل أن المشروع بلا دفعة مقدمة'), 'type' => 'success']);
    }

    /** الفاتورةُ النهائيّة — والمبلغُ من الخادم وحدَه، فلا حقلَ له في الطلب */
    public function final(Request $request, int|string $id)
    {
        $this->mayInvoice();
        $project = $this->project($id);

        $data = $request->validate([
            'issue' => ['nullable', 'boolean'],
            'request_key' => ['nullable', 'string', 'max:64'],
            'due_at' => ['nullable', 'date'],
        ], [], ['due_at' => __('تاريخ الاستحقاق')]);

        try {
            $invoice = ProjectBilling::createFinal($project, $data, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['billing' => $e->getMessage()]);
        }

        ProjectWorkflow::touch($project, ProjectWorkflow::FINAL);

        return back()->with('toast', [
            'msg' => $invoice->number
                ? __('صدرت الفاتورة النهائية :n', ['n' => $invoice->number])
                : __('حُفظت الفاتورة النهائية مسودّةً'),
            'type' => 'success',
        ]);
    }
}
