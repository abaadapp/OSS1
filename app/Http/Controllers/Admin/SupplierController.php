<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Support\Activity;
use App\Support\BranchAccess;
use App\Support\Demo;
use App\Support\LocalName;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    /**
     * قواعدُ المورّد — وما يقدّمه نصٌّ حرٌّ يكتبه المستخدم بكلماته.
     *
     * «تصوير فوتوغرافي»، «شاشات LED»، «تأجير كراسي»: لا قائمةَ يُختار منها ولا
     * كتالوجَ يُنشأ قبله. والحالُ تسقط إلى «نشط» حين لا تُرسَل: شاشةُ أمر
     * الشراء تُضيف مورّدًا باسمه وهاتفه وحدَهما.
     *
     * والنوعُ القديم وأرقامُ الكتالوج إن وصلا من نموذجٍ قديم لا يُقرآن — انظر
     * `Supplier` لما بقي منهما.
     *
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // مكتوبًا بيدٍ يعلو على النقل الآليّ — انظر LocalName::apply
            'name_en' => ['nullable', 'string', 'max:255'],
            'service' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'whatsapp' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'status' => ['nullable', Rule::in(Supplier::STATUSES)],
        ];
    }

    /**
     * الاسمُ بلغتيه، والخدمةُ نصٌّ مشذَّب — فراغُها لا شيء.
     *
     * والنموذجُ لا يحمل الاسمَ الإنجليزيّ: من لم يُرسله لا يُمحى منه ما كان
     * (`LocalName::apply` يفرّغه لاسمٍ عربيّ لم يُكتب له مقابل).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data, Request $request): array
    {
        $data = LocalName::apply($data);
        if (! $request->has('name_en') && ($data['name_en'] ?? null) === null) {
            unset($data['name_en']);
        }
        if (array_key_exists('service', $data)) {
            $data['service'] = trim((string) $data['service']) ?: null;
        }

        return $data;
    }

    public function store(Request $request)
    {
        $data = $this->normalize($request->validate($this->rules()), $request);
        $data['status'] = $data['status'] ?? Supplier::ACTIVE;

        $supplier = DB::transaction(function () use ($data) {
            $supplier = Supplier::create(array_merge($data, ['business_id' => $this->bid()]));
            Activity::log('created', 'أضاف مورّدًا: '.$data['name'], ['subject_id' => $supplier->id, 'subject_type' => 'supplier']);

            return $supplier;
        });

        /*
         * ومن أضافه من داخل شاشةٍ أخرى يُختار له فور العودة.
         *
         * شاشةُ أمر الشراء تفتح النافذة وتعود إلى نفسها، فلا يبقى إلّا أن
         * يُقال أيُّهم. ومن لا يُختار له يعود يبحث في القائمة عن اسمٍ كتبه
         * قبل ثانية — أو يظنّ أنّ الحفظ لم يقع.
         *
         * والبابُ واحدٌ لا يُنسخ: شاشةُ الموردين تمرّ من هنا كذلك وتُهمل
         * المفتاح، فلا تتفرّق قاعدتان لإنشاء مورّد.
         */
        return back()->with('toast', ['msg' => __('تم إضافة المورّد بنجاح'), 'type' => 'success'])
            ->with('new_supplier_id', $supplier->id);
    }

    public function update(Request $request, $id)
    {
        $supplier = Supplier::where('business_id', $this->bid())->findOrFail($id);
        $data = $this->normalize($request->validate($this->rules()), $request);
        /* وما لم يُرسَل من الحال والخدمة يبقى كما كان — لا يُمحى بحفظٍ لا يعرفه */
        if (($data['status'] ?? null) === null) {
            unset($data['status']);
        }
        if (! $request->has('service')) {
            unset($data['service']);
        }

        DB::transaction(function () use ($supplier, $data) {
            $before = $supplier->status ?: Supplier::ACTIVE;
            $supplier->update($data);
            Activity::log('updated', 'عدّل المورّد: '.$supplier->name, ['subject_id' => $supplier->id, 'subject_type' => 'supplier']);
            /* والإيقافُ والإعادةُ يُقالان بأسمائهما — الإيقافُ يمنع أوامرَ الشراء الجديدة عليه */
            if (isset($data['status']) && $data['status'] !== $before) {
                Activity::log('status', ($data['status'] === Supplier::INACTIVE ? 'أوقف المورّد: ' : 'أعاد تفعيل المورّد: ').$supplier->name, [
                    'subject_id' => $supplier->id, 'subject_type' => 'supplier',
                ]);
            }
        });

        return back()->with('toast', ['msg' => __('تم تحديث بيانات المورّد'), 'type' => 'success']);
    }

    /**
     * صفحةُ المورّد — بياناتُه وما يقدّمه وأوامرُ شرائه وسنداتُه ومشاريعُه.
     *
     * والمالُ على سنداته لمن يفتح السندات (`INVOICE_VIEW`)، وروابطُ أوامر
     * الشراء لمن يفتح المشتريات — الصفحةُ تقرأ ما تقرؤه الأقسامُ نفسُها.
     */
    public function show(int|string $id): Response
    {
        $bid = $this->bid();
        $supplier = Supplier::where('business_id', $bid)->findOrFail($id);
        $user = auth()->user();
        $money = $user->may(Permissions::INVOICE_VIEW);

        // ولمن قُيّد بفروعٍ أوامرُ فروعه وسنداتُها وحدها — انظر BranchAccess
        $orders = BranchAccess::limit(PurchaseOrder::where('business_id', $bid))->where('supplier_id', $supplier->id)
            ->with('project:id,code,name')->orderByDesc('ordered_at')->orderByDesc('id')->limit(100)->get();
        $invoices = BranchAccess::limitThrough(SupplierInvoice::where('business_id', $bid), 'purchaseOrder')->where('supplier_id', $supplier->id)
            ->with('project:id,code,name')->orderByDesc('issued_at')->orderByDesc('id')->limit(100)->get();

        $projectIds = $orders->pluck('project_id')->merge($invoices->pluck('project_id'))->filter()->unique()->values();
        $projects = Project::where('business_id', $bid)->whereIn('id', $projectIds)->orderByDesc('id')
            ->get(['id', 'code', 'name', 'status'])
            ->map(fn (Project $p) => [
                'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'status' => $p->status,
                'orders' => $orders->where('project_id', $p->id)->count(),
            ])->all();

        return Inertia::render('Admin/Suppliers/Show', [
            'supplier' => [
                'id' => $supplier->id, 'name' => $supplier->name, 'name_en' => $supplier->name_en,
                'phone' => $supplier->phone, 'whatsapp' => $supplier->whatsapp, 'email' => $supplier->email,
                'contact_person' => $supplier->contact_person, 'notes' => $supplier->notes,
                'service' => $supplier->service, 'status' => $supplier->status ?: Supplier::ACTIVE,
                'outstanding' => $money ? $supplier->outstanding() : null,
            ],
            'orders' => $orders->map(fn (PurchaseOrder $o) => [
                'id' => $o->id, 'number' => $o->number, 'status' => $o->status,
                'ordered_at' => optional($o->ordered_at)->format('Y-m-d'), 'total' => round((float) $o->total, 3),
                'project' => $o->project ? ['id' => $o->project->id, 'code' => $o->project->code] : null,
            ])->values()->all(),
            'invoices' => $invoices->map(fn (SupplierInvoice $i) => [
                'id' => $i->id, 'ref' => $i->supplier_ref, 'approval_status' => $i->approval_status, 'status' => $i->status,
                'issued_at' => optional($i->issued_at)->format('Y-m-d'),
                'total' => $money ? round((float) $i->total, 3) : null,
                'outstanding' => $money ? $i->outstanding() : null,
                'project' => $i->project ? ['id' => $i->project->id, 'code' => $i->project->code] : null,
            ])->values()->all(),
            'projects' => $projects,
            'statuses' => collect(Supplier::STATUS_LABELS)->map(fn ($l) => __($l))->all(),
            'may' => ['purchases' => $user->allows('purchases'), 'money' => $money, 'projects' => $user->allows('projects')],
        ]);
    }

    /**
     * حذفُ مورّد — ما لم يكن له تاريخُ شراء.
     *
     * ═══ ما كان يقع ═══
     *
     * الحذفُ كان مطلقًا، وللمورّد في القاعدة نسبان مختلفان:
     *
     *  • **سنداتُه** مقيّدةٌ عليه (`restrictOnDelete`). فمورّدٌ له سندٌ واحد
     *    كان يردّ صفحةَ خطأٍ خمسمئة — لا رسالةً تقول لمَ. والزرُّ مرسومٌ على
     *    كلّ صفّ: بابٌ معروضٌ لا يُفتح.
     *
     *  • **أوامرُه وأوراقُ استلامه** تُفرَّغ مراجعُها (`nullOnDelete`). فتُحذف
     *    صامتةً صلةُ خمسةِ أوامرَ بمن ورّدها — يبقى الاسمُ لقطةً في
     *    `supplier_name` ولا يبقى شيءٌ يُصفّى به أو يُجمع عليه. وهذا أسوأُ من
     *    الخطأ: لا أحد يعرف أنّه وقع.
     *
     * ═══ والقاعدة ═══
     *
     * من اشتُري منه مرّةً يبقى: حذفُه يُعيد كتابةَ تاريخ الشراء. ومن أُضيف
     * خطأً ولم يُشترَ منه شيء يُحذف كما كان.
     */
    public function destroy($id)
    {
        $supplier = Supplier::where('business_id', $this->bid())->findOrFail($id);

        $orders = $supplier->purchaseOrders()->count();
        $invoices = $supplier->invoices()->count();

        if ($orders > 0 || $invoices > 0) {
            // ‏ثلاثُ رسائلَ كاملة لا واحدةٌ تُركَّب من قطع: المركَّبةُ لا تُترجَم
            $msg = match (true) {
                $orders > 0 && $invoices > 0 => __('لهذا المورّد :o أمر شراء و:i سند — لا يُحذف، وإلّا بقيت أوراقُه بلا من أصدرها.', ['o' => $orders, 'i' => $invoices]),
                $orders > 0 => __('لهذا المورّد :o أمر شراء — لا يُحذف، وإلّا بقيت أوامرُه بلا من ورّدها.', ['o' => $orders]),
                default => __('لهذا المورّد :i سند — لا يُحذف، وإلّا بقيت سنداتُه بلا من أصدرها.', ['i' => $invoices]),
            };

            return back()->with('toast', ['msg' => $msg, 'type' => 'warning']);
        }

        $name = $supplier->name;
        $supplier->delete();
        Activity::log('deleted', 'حذف المورّد: '.$name);

        return back()->with('toast', ['msg' => __('تم حذف المورّد'), 'type' => 'warning']);
    }
}
