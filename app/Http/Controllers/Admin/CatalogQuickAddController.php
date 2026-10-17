<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Support\Demo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * تصنيفٌ يُنشأ من جانب حقله في نموذج المنتج.
 *
 * التصنيفاتُ لم يكن لها بابُ إنشاءٍ في النظام إطلاقًا: تأتي من تهيئة نوع
 * النشاط (BusinessTypes) أو من استيراد ملفٍّ فيه أسماؤها. فمن أراد تصنيفًا
 * جديدًا وهو يُدخل صنفًا أو خدمةً لم يكن أمامه إلّا أن يتركه بلا تصنيف.
 *
 * والبابُ يردّ JSON لا صفحة: يُنادى من جانب حقلٍ في نموذجٍ نصفُه مملوء،
 * وإعادةُ تحميل الصفحة كانت ستمحو ما كُتب ولم يُحفظ بعد. وكان معه بابُ
 * «الإضافات» (تغليفٌ وبطاقةٌ مع البيعة) — من بقايا المتجر، خرج مع تركيب المنتج.
 *
 * ولا صلاحيةَ جديدة: تحت `/admin/products/` فيقيسه حارس المسار بصلاحية
 * «المنتجات» — ومن يضيف منتجًا يضيف تصنيفه.
 */
class CatalogQuickAddController extends Controller
{
    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    /**
     * قسمٌ جديد — والاسم فريدٌ في النشاط لا في النظام.
     *
     * قسمان بالاسم نفسه يجعلان المنتجات تتوزّع بينهما بلا قاعدة، ويصير
     * تقرير «المبيعات حسب القسم» يعرض «ورود» مرّتين برقمين.
     */
    public function storeCategory(Request $request): JsonResponse
    {
        $bid = $this->bid();

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('categories', 'name')->where('business_id', $bid),
            ],
            'name_en' => ['nullable', 'string', 'max:100'],
        ], [
            'name.unique' => __('يوجد قسمٌ بهذا الاسم.'),
        ]);

        $category = Category::create(\App\Support\Lexicon::fill($data) + ['business_id' => $bid]);

        \App\Support\Activity::log('created', 'أضاف قسم «'.$category->name.'»', ['subject_id' => $category->id]);

        return response()->json([
            'ok' => true,
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
                'name_en' => $category->name_en,
            ],
        ]);
    }
}
