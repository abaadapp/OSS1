<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CompanyPolicy;
use App\Models\Customer;
use App\Models\Product;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\Policy\Registry;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * سياساتُ الشركة — الشاشةُ التي يضبط منها المديرُ النصَّ والمعاملات.
 *
 * ولا يكتب شرطًا: المفتاحُ من `Policy\Registry::KEYS` المغلقة، والمعاملاتُ
 * بأسمائها من القائمة نفسِها. فما يصل القاعدةَ رقمٌ ونصّ، والمنطقُ في
 * الكود. انظر §18.
 *
 * ═══ والنسخةُ تُرفع مع كلّ تعديل ═══
 *
 * العرضُ يختم النسخةَ التي قرأها العميل. فسياسةٌ عُدّل نصُّها بعد أن خرج
 * عرضٌ بها تحمل رقمًا جديدًا، ويبقى العرضُ يشير إلى القديم — «أيَّ نصٍّ
 * قَبِل العميل» يُجاب بلا التباس.
 */
class PolicyController extends Controller
{
    private function bid(): int
    {
        return (int) (auth()->user()->business_id ?? Demo::bid());
    }

    public function index(): Response
    {
        $bid = $this->bid();

        $policies = CompanyPolicy::where('business_id', $bid)->orderBy('id')->get();

        return Inertia::render('Admin/Settings/Policies', [
            'policies' => $policies->map(fn (CompanyPolicy $p) => [
                'id' => $p->id,
                'key' => $p->key,
                'label' => Registry::label($p->key),
                'scope_type' => $p->scope_type,
                'scope_id' => $p->scope_id,
                'scope_label' => $this->scopeLabel($bid, $p),
                'params' => $p->params ?? [],
                'text_ar' => $p->text_ar,
                'text_en' => $p->text_en,
                'active' => (bool) $p->active,
                'in_force' => $p->isInForce(),
                'effective_from' => optional($p->effective_from)->format('Y-m-d'),
                'version' => (int) $p->version,
            ])->all(),
            'catalog' => Registry::catalog(),
            /* وأهدافُ النطاق تُرسَل مختصرةً: اسمٌ ومعرّف، لا صفوفٌ كاملة */
            'customers' => Customer::where('business_id', $bid)->orderBy('name')->limit(500)
                ->get(['id', 'name'])->map(fn ($c) => ['value' => $c->id, 'label' => $c->name])->all(),
            'products' => Product::where('business_id', $bid)->orderBy('name')->limit(500)
                ->get(['id', 'name'])->map(fn ($p) => ['value' => $p->id, 'label' => $p->name])->all(),
            'categories' => Category::where('business_id', $bid)->orderBy('name')->limit(200)
                ->get(['id', 'name'])->map(fn ($c) => ['value' => $c->id, 'label' => $c->name])->all(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $policy = CompanyPolicy::create($data + [
            'business_id' => $this->bid(),
            'created_by' => auth()->id(),
            'version' => 1,
        ]);

        Activity::log('settings', 'أضاف سياسة «'.Registry::label($policy->key).'»', [
            'subject_id' => $policy->id, 'subject_type' => 'company_policy',
        ]);

        return back()->with('toast', ['msg' => __('أُضيفت السياسة'), 'type' => 'success']);
    }

    public function update(Request $request, int|string $id)
    {
        $policy = CompanyPolicy::where('business_id', $this->bid())->findOrFail($id);

        /* التفعيلُ والتعطيلُ وحدَه لا يرفع النسخة: النصُّ لم يتغيّر */
        if ($request->has('active') && ! $request->has('text_ar')) {
            $policy->update(['active' => $request->boolean('active')]);

            Activity::log('settings', ($policy->active ? 'فعّل' : 'عطّل').' سياسة «'.Registry::label($policy->key).'»', [
                'subject_id' => $policy->id, 'subject_type' => 'company_policy',
            ]);

            return back();
        }

        $data = $this->validated($request);

        /*
         * والنسخةُ تُرفع إن تغيّر ما يقرؤه العميل أو ما يُفحص به: النصّان
         * والمعاملات. وتبديلُ النطاق أو تاريخِ السريان لا يغيّر ما قُبل.
         */
        $changed = $data['text_ar'] !== $policy->text_ar
            || ($data['text_en'] ?? null) !== $policy->text_en
            || ($data['params'] ?? []) != ($policy->params ?? []);

        $policy->update($data + ['version' => $changed ? $policy->version + 1 : $policy->version]);

        Activity::log('settings', 'عدّل سياسة «'.Registry::label($policy->key).'»'.($changed ? ' — النسخة '.$policy->version : ''), [
            'subject_id' => $policy->id, 'subject_type' => 'company_policy',
        ]);

        return back()->with('toast', ['msg' => __('حُدّثت السياسة'), 'type' => 'success']);
    }

    public function destroy(int|string $id)
    {
        $policy = CompanyPolicy::where('business_id', $this->bid())->findOrFail($id);
        $policy->delete();

        Activity::log('deleted', 'حذف سياسة «'.Registry::label($policy->key).'»', [
            'subject_id' => $policy->id, 'subject_type' => 'company_policy',
        ]);

        return back()->with('toast', ['msg' => __('حُذفت السياسة — وما خُتم في العروض يبقى'), 'type' => 'warning']);
    }

    /**
     * القواعدُ من القائمة المغلقة: مفتاحٌ معروف، ومعاملاتُه بأسمائها وأنواعها.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $bid = $this->bid();
        $key = (string) $request->input('key');

        $rules = [
            'key' => ['required', Rule::in(Registry::keys())],
            'scope_type' => ['nullable', Rule::in(CompanyPolicy::SCOPES)],
            /* ونطاقٌ سُمّي نوعُه بلا هدفٍ سياسةٌ لا تنطبق على أحد — فيُطلب الهدف */
            'scope_id' => ['nullable', 'integer', 'required_if:scope_type,customer,product,category'],
            'text_ar' => ['required', 'string', 'max:5000'],
            'text_en' => ['nullable', 'string', 'max:5000'],
            'active' => ['nullable', 'boolean'],
            'effective_from' => ['nullable', 'date'],
            'params' => ['nullable', 'array'],
        ];

        /*
         * والمعاملاتُ بأسمائها من القائمة — وما ليس فيها يُطرح لا يُحفظ:
         * `params.anything` لا يبلغ القاعدة.
         */
        foreach (Registry::params($key) as $name => $type) {
            $rules["params.$name"] = $type === Registry::INTEGER
                ? ['nullable', 'integer', 'min:0', 'max:365']
                : ['nullable', 'numeric', 'min:0', 'max:100'];
        }

        /* وهدفُ النطاق من الشركة نفسِها — لا معرّفٌ يُخمَّن */
        $rules['scope_id'][] = match ($request->input('scope_type')) {
            CompanyPolicy::SCOPE_CUSTOMER => Rule::exists('customers', 'id')->where('business_id', $bid)->whereNull('deleted_at'),
            CompanyPolicy::SCOPE_PRODUCT => Rule::exists('products', 'id')->where('business_id', $bid)->whereNull('deleted_at'),
            CompanyPolicy::SCOPE_CATEGORY => Rule::exists('categories', 'id')->where('business_id', $bid),
            default => 'prohibited',
        };

        $data = $request->validate($rules, [
            'scope_id.required_if' => __('اختر هدف النطاق.'),
        ], [
            'key' => __('السياسة'), 'text_ar' => __('النص العربي'), 'params.days' => __('الأيام'),
        ]);

        $params = [];

        foreach (Registry::params($key) as $name => $type) {
            $value = $data['params'][$name] ?? null;

            if ($value !== null && $value !== '') {
                $params[$name] = $type === Registry::INTEGER ? (int) $value : round((float) $value, 2);
            }
        }

        return [
            'key' => $key,
            'scope_type' => $data['scope_type'] ?? null,
            'scope_id' => isset($data['scope_type']) && $data['scope_type'] !== null ? ($data['scope_id'] ?? null) : null,
            'params' => $params,
            'text_ar' => trim($data['text_ar']),
            'text_en' => filled($data['text_en'] ?? null) ? trim($data['text_en']) : null,
            'active' => $request->has('active') ? $request->boolean('active') : true,
            'effective_from' => $data['effective_from'] ?? null,
        ];
    }

    private function scopeLabel(int $bid, CompanyPolicy $p): ?string
    {
        if ($p->scope_type === null || $p->scope_id === null) {
            return null;
        }

        $name = match ($p->scope_type) {
            CompanyPolicy::SCOPE_CUSTOMER => Customer::where('business_id', $bid)->whereKey($p->scope_id)->value('name'),
            CompanyPolicy::SCOPE_PRODUCT => Product::where('business_id', $bid)->whereKey($p->scope_id)->value('name'),
            CompanyPolicy::SCOPE_CATEGORY => Category::where('business_id', $bid)->whereKey($p->scope_id)->value('name'),
            default => null,
        };

        return $name ?? __('هدفٌ لم يعد موجودًا');
    }
}
