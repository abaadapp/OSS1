<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PackageItem;
use App\Models\Product;
use App\Support\Activity;
use App\Support\Demo;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * بنودُ الباقة — ما تشمله الباقةُ من أصنافٍ وخدماتٍ وباقاتٍ أصغر.
 *
 * الباقةُ **حزمةٌ تجاريّة تُسعَّر وتُعرَض**: كميّاتُها كسريّة («يومان ونصف من
 * فريق»)، ولا تُخصم من رفّ، ويقرؤها عرضُ السعر حين يفكّها إلى بنودها
 * (`Quotations::expand`). وكانت أبوابُها في متحكّم «التركيب» مع المقاسات
 * والوصفة والإضافات — وتلك من بقايا المتجر خرجت من شاشة المنتج، فبقيت الباقةُ
 * وحدها هنا.
 *
 * ولا صلاحيةَ جديدة: من يملك المنتجات يملك بنودَ باقاتها.
 */
class ProductPackageController extends Controller
{
    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    /** المنتج من هذا النشاط أو لا شيء — الحارس الأوّل في كلّ باب */
    private function product(int $id): Product
    {
        return Product::where('business_id', $this->bid())->findOrFail($id);
    }

    public function storePackageItem(Request $request, int $id)
    {
        $package = $this->product($id);
        $bid = $this->bid();

        $data = $request->validate([
            'item_id' => ['required', Rule::exists('products', 'id')->where('business_id', $bid)->whereNull('deleted_at')],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit' => ['nullable', 'string', 'max:40'],
        ], [], ['item_id' => __('البند'), 'quantity' => __('الكمية')]);

        /* والباقةُ لا تحوي نفسَها: حلقةٌ تُدخل الفكَّ في تكرارٍ لا ينتهي */
        if ((int) $data['item_id'] === (int) $package->id) {
            throw ValidationException::withMessages([
                'item_id' => __('الباقة لا تحوي نفسها.'),
            ]);
        }

        /*
         * والصفُّ المكرّر يُجمع لا يُضاف — كما في الوصفة.
         *
         * وقيدُ التفرّد في القاعدة يردّه على أيّ حال؛ وهذا يجعل الردَّ
         * رسالةً مفهومة بدل خطأٍ من المحرّك.
         */
        $existing = PackageItem::where('package_id', $package->id)
            ->where('item_id', $data['item_id'])->first();

        if ($existing) {
            $existing->update(['quantity' => (float) $existing->quantity + (float) $data['quantity']]);
        } else {
            PackageItem::create($data + [
                'business_id' => $bid,
                'package_id' => $package->id,
                'sort_order' => (int) PackageItem::where('package_id', $package->id)->max('sort_order') + 1,
            ]);
        }

        Activity::log('updated', 'عدّل بنود الباقة '.$package->name, ['subject_id' => $package->id]);

        return back()->with('toast', ['msg' => __('أُضيف البند'), 'type' => 'success']);
    }

    public function updatePackageItem(Request $request, int $id, int $itemId)
    {
        $package = $this->product($id);

        $row = PackageItem::where('package_id', $package->id)->findOrFail($itemId);

        $data = $request->validate([
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit' => ['nullable', 'string', 'max:40'],
        ], [], ['quantity' => __('الكمية')]);

        $row->update($data);

        return back()->with('toast', ['msg' => __('حُدّث البند'), 'type' => 'success']);
    }

    public function destroyPackageItem(int $id, int $itemId)
    {
        $package = $this->product($id);

        PackageItem::where('package_id', $package->id)->findOrFail($itemId)->delete();

        Activity::log('updated', 'حذف بندًا من الباقة '.$package->name, ['subject_id' => $package->id]);

        return back()->with('toast', ['msg' => __('حُذف البند'), 'type' => 'success']);
    }

    /**
     * ما يعرضه تبويبُ «بنود الباقة» في شاشة المنتج.
     *
     * @return array{items: array<int, array<string, mixed>>, options: array<int, array<string, mixed>>}
     */
    public static function payload(Product $product): array
    {
        return [
            'items' => self::packageItems($product),
            'options' => self::packageOptions((int) $product->business_id, $product->id),
        ];
    }

    /**
     * ولمنتجٍ لم يُنشأ بعد: لا بنود — العضوُ يُعلَّق بمعرّفٍ لا وجود له.
     *
     * @return array{items: array<int, array<string, mixed>>, options: array<int, array<string, mixed>>}
     */
    public static function blank(int $businessId): array
    {
        return ['items' => [], 'options' => self::packageOptions($businessId, null)];
    }

    /**
     * بنودُ الباقة بأسعارها المعتمدة.
     *
     * و`sellingPrice()` لا `price`: هي ما يقرؤه عرضُ السعر عند الفكّ
     * (`Quotations::expand`). ورقمان مختلفان في الشاشتين يجعلان من يبني
     * الباقة يحسب غير ما يُعرض على العميل.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function packageItems(Product $product): array
    {
        return $product->packageItems()->with('item')->get()
            ->map(fn (PackageItem $row) => [
                'id' => $row->id,
                'item_id' => $row->item_id,
                'name' => $row->item?->name ?? '—',
                'kind' => $row->item?->kind ?? Product::ITEM,
                'quantity' => (float) $row->quantity,
                'unit' => $row->unit,
                'unit_price' => $row->item?->sellingPrice() ?? 0.0,
                'line_total' => round((float) $row->quantity * ($row->item?->sellingPrice() ?? 0.0), 3),
            ])->all();
    }

    /**
     * ما يصلح عضوًا في باقة — كلُّ الكتالوج إلّا الباقةَ نفسَها.
     *
     * وباقةٌ داخل باقةٍ مسموحة: «مؤتمر ٥٠٠» قد تحوي «حزمة التسجيل». والحلقةُ
     * (أ تحوي ب، وب تحوي أ) يمنعها الفكُّ نفسُه: `Quotations::expand` تفكّ
     * مستوًى واحدًا لا تتعمّق — فلا تكرارَ لا ينتهي.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function packageOptions(int $businessId, ?int $exclude): array
    {
        return Product::where('business_id', $businessId)
            ->when($exclude, fn ($q) => $q->where('id', '!=', $exclude))
            ->orderBy('name')->get(['id', 'name', 'sku', 'kind', 'price', 'discount'])
            ->map(fn (Product $p) => [
                'value' => $p->id,
                'label' => $p->sku ? $p->name.' — '.$p->sku : $p->name,
                'kind' => $p->kind ?? Product::ITEM,
                'price' => $p->sellingPrice(),
            ])->all();
    }
}
