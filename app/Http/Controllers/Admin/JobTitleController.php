<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\JobTitle;
use App\Models\User;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\Permissions;
use App\Support\Roles;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class JobTitleController extends Controller
{
    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    public function store(Request $request)
    {
        $bid = $this->bid();
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('job_titles', 'name')->where(fn ($q) => $q->where('business_id', $bid)),
            ],
            /*
             * ═══ ملفُّ الصلاحيات الأساسيّ يُختار — لا يُفترض ═══
             *
             * كان اختياريًّا ويسقط على `sales` حين يُترك، والشاشةُ لا تسأل عنه
             * أصلًا. فمن أضاف «مشرف الفعالية» حصل على وظيفةٍ تفتح عروضَ الأسعار
             * والعملاءَ وتكتب أوامرَ التغيير — بلا أن يعرف. والدورُ هو ما يحمله
             * كلُّ موظّفٍ يُسند إليه المسمّى (`users.role`)، فيُقال صراحةً.
             *
             * ومن أدوار الموظّفين الخمسة وحدها: `admin` صاحبُ النشاط لا وظيفة.
             */
            'role' => ['required', 'string', Rule::in(Roles::STAFF)],
            'description' => ['nullable', 'string', 'max:255'],
        ], [
            'name.unique' => __('هذه الوظيفة موجودة مسبقًا.'),
            'role.required' => __('اختر ملف الصلاحيات الأساسي لهذه الوظيفة.'),
        ], ['name' => __('اسم الوظيفة'), 'role' => __('ملف الصلاحيات الأساسي')]);

        $this->refuseRoleBeyondMine($data['role']);

        JobTitle::create([
            'business_id' => $bid,
            'name' => $data['name'],
            'role' => $data['role'],
            'description' => $data['description'] ?? null,
        ]);
        Activity::log('created', 'أضاف وظيفة: ' . $data['name']);

        return back()->with('toast', ['msg' => __('تم إضافة الوظيفة'), 'type' => 'success']);
    }

    /**
     * تعديل وظيفة.
     *
     * والفخّ هنا أن الموظفين مرتبطون بالوظيفة **بالاسم لا بالمعرّف**
     * (users.job_title نصّ). فتغييرُ الاسم وحده يترك موظفين معلَّقين على
     * وظيفة لا وجود لها: لا تظهر في قائمة التعديل، وأوّل حفظٍ لبياناتهم
     * يُرفض بـ«الوظيفة المحددة غير موجودة». ولذلك يُنقل الاسم إليهم.
     *
     * والدورُ يُغيَّر لوظيفةٍ لا يحملها أحد، ولا يُمسّ دورُ حامليها عند تغيير
     * الاسم. وغيابُه من الطلب يعني «أبقِه» — فوظيفةُ صاحب النشاط (`admin`) لا
     * تُرسل دورًا وتبقى على حالها.
     */
    public function update(Request $request, $id)
    {
        $bid = $this->bid();
        $title = JobTitle::where('business_id', $bid)->findOrFail($id);

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('job_titles', 'name')
                    ->where(fn ($q) => $q->where('business_id', $bid))
                    ->ignore($title->id),
            ],
            'role' => ['nullable', 'string', Rule::in(Roles::STAFF)],
            'description' => ['nullable', 'string', 'max:255'],
        ], [
            'name.unique' => __('هذه الوظيفة موجودة مسبقًا.'),
        ], ['name' => __('اسم الوظيفة'), 'role' => __('ملف الصلاحيات الأساسي')]);

        $oldName = $title->name;
        $data['role'] = ($data['role'] ?? null) ?: $title->role;

        if ($data['role'] !== $title->role) {
            $this->refuseRoleBeyondMine($data['role']);

            /*
             * ═══ ولا يتغيّر ملفُّ وظيفةٍ يحملها موظّفون ═══
             *
             * دورُ الموظّف مكتوبٌ في صفّه، والوظيفةُ تُسنده عند حفظ ملفّه. فلو
             * تغيّر دورُ الوظيفة لبقي حاملوها على القديم — ثمّ ينتقل كلٌّ منهم إلى
             * الجديد عند أوّل حفظٍ لملفّه ولو كان لتصحيح هاتف: ترقيةٌ أو تنزيلٌ لا
             * يطلبه أحدٌ في لحظته. فيُنشأ مسمًّى جديد ويُنقل إليه من يُقصد.
             */
            $holders = User::where('business_id', $bid)->where('job_title', $title->name)->count();
            if ($holders > 0) {
                throw ValidationException::withMessages(['role' => __('يحمل هذه الوظيفة :n موظف — لا يتغيّر ملف صلاحياتها. أنشئ وظيفة جديدة وانقل إليها من تريد.', ['n' => $holders])]);
            }
        }

        $title->update($data);

        // الاسم وحده هو ما يربط الموظف بوظيفته، فهو وحده ما يُنقل إليه
        $affected = User::where('business_id', $bid)->where('job_title', $oldName)
            ->update(['job_title' => $data['name']]);

        Activity::log('updated', "عدّل الوظيفة: {$oldName} → {$data['name']} (تأثّر {$affected} موظفًا)", ['subject_id' => $title->id]);

        $msg = __('تم تحديث الوظيفة');
        if ($affected > 0) {
            $msg .= ' · ' . __('حُدِّث :n موظفًا يحملها', ['n' => $affected]);
        }

        return back()->with('toast', ['msg' => $msg, 'type' => 'success']);
    }

    /**
     * ولا تُنشأ وظيفةٌ تحمل ما لا يملكه منشئها.
     *
     * هي القاعدةُ التي يقيس بها إسنادُ الوظيفة لموظّف (`Permissions::mayAssignRole`)
     * — فوظيفةٌ لا يستطيع صاحبُها أن يُسندها لأحدٍ لا تُكتب له أصلًا، ولا تبقى
     * في قائمته معطّلةً بسببٍ لم يعرفه وقت إنشائها.
     */
    private function refuseRoleBeyondMine(string $role): void
    {
        if (! Permissions::mayAssignRole(auth()->user(), $role)) {
            throw ValidationException::withMessages(['role' => __('ملف «:role» يفتح ما لا تفتحه — لا تُنشأ وظيفة بصلاحياتٍ لا تملكها.', ['role' => Roles::label($role)])]);
        }
    }

    public function destroy($id)
    {
        $title = JobTitle::where('business_id', $this->bid())->findOrFail($id);

        // لا تُحذف وظيفة مستخدَمة — وإلا بقي موظفون بوظيفة لا وجود لها
        $used = User::where('business_id', $this->bid())->where('job_title', $title->name)->count();
        if ($used > 0) {
            return back()->with('toast', [
                'msg' => __('لا يمكن حذف «:name» لأنها مستخدمة لدى :count موظف. غيّر وظيفتهم أولًا.', ['name' => $title->name, 'count' => $used]),
                'type' => 'danger',
            ]);
        }

        Activity::log('deleted', 'حذف الوظيفة: ' . $title->name, ['subject_id' => $title->id]);
        $title->delete();

        return back()->with('toast', ['msg' => __('تم حذف الوظيفة'), 'type' => 'warning']);
    }
}
