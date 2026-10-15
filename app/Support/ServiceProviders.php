<?php

namespace App\Support;

use App\Models\ServiceCategory;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * المورّدُ مقدّمَ خدمة — نوعُه وخدماتُه وكتالوجُها.
 *
 * لا عالمَ ماليًّا ثانيًا: أوامرُ الشراء وسنداتُ المورّد وذممُه هي نفسُها لمورّد
 * المنتجات ولمقدّم الخدمة. وما يزيد هنا شيئان: نوعُ المورّد (`Supplier::TYPES`)،
 * وما يقدّمه من خدماتٍ من كتالوجٍ تديره الشركة ويُبحث به — «مقدّم خدمة ← تصوير».
 */
final class ServiceProviders
{
    public const TYPE_LABELS = [
        Supplier::PRODUCTS => 'مورد منتجات',
        Supplier::SERVICES => 'مقدم خدمات',
        Supplier::BOTH => 'منتجات وخدمات',
    ];

    public const STATUS_LABELS = ['active' => 'نشط', 'inactive' => 'متوقف'];

    /** خدماتٌ تبدأ بها الشركة — تُعدَّل وتُعطَّل ويُزاد عليها */
    public const DEFAULT_SERVICES = [
        'صوتيات', 'إضاءة', 'شاشات', 'تصوير', 'فيديو', 'Drone', 'ديكور', 'ضيافة',
        'أمن', 'طباعة', 'نقل', 'ترجمة', 'تقديم', 'DJ', 'أثاث', 'خيام',
    ];

    public static function ensureCategories(int $businessId): void
    {
        if (ServiceCategory::where('business_id', $businessId)->exists()) {
            return;
        }

        foreach (self::DEFAULT_SERVICES as $i => $name) {
            ServiceCategory::firstOrCreate(['business_id' => $businessId, 'name' => $name], ['sort_order' => $i + 1, 'active' => true]);
        }
    }

    /**
     * خدماتُ المورّد — وما أُضيف منها يُقيَّد في السجلّ باسمه.
     *
     * @param  list<mixed>  $ids
     */
    public static function syncServices(Supplier $supplier, array $ids, ?User $actor = null): void
    {
        $valid = ServiceCategory::where('business_id', $supplier->business_id)
            ->whereIn('id', array_map('intval', $ids))->pluck('name', 'id');

        $before = DB::table('service_category_supplier')->where('supplier_id', $supplier->id)->pluck('service_category_id')->all();
        $supplier->serviceCategories()->sync($valid->keys()->mapWithKeys(fn ($id) => [$id => ['business_id' => $supplier->business_id]])->all());

        $added = $valid->except($before)->values()->all();
        $removed = ServiceCategory::whereIn('id', array_diff($before, $valid->keys()->all()))->pluck('name')->all();

        if ($added !== []) {
            Activity::log('updated', 'أضاف إلى خدمات المورد '.$supplier->name.': '.implode('، ', $added), [
                'business_id' => $supplier->business_id, 'subject_id' => $supplier->id, 'subject_type' => 'supplier', 'icon' => 'tag',
            ]);
        }
        if ($removed !== []) {
            Activity::log('updated', 'أزال من خدمات المورد '.$supplier->name.': '.implode('، ', $removed), [
                'business_id' => $supplier->business_id, 'subject_id' => $supplier->id, 'subject_type' => 'supplier', 'icon' => 'tag',
            ]);
        }
    }

    public static function saveCategory(int $businessId, string $name, bool $active = true, ?ServiceCategory $category = null): ServiceCategory
    {
        $name = Projects::text($name, 80) ?? throw new RuntimeException(__('اكتب اسم الخدمة.'));

        $taken = ServiceCategory::where('business_id', $businessId)->where('name', $name)
            ->when($category, fn ($q) => $q->whereKeyNot($category->id))->exists();
        if ($taken) {
            throw new RuntimeException(__('هذه الخدمة موجودة في الكتالوج.'));
        }

        if ($category === null) {
            $category = ServiceCategory::create([
                'business_id' => $businessId, 'name' => $name, 'active' => $active,
                'sort_order' => (int) ServiceCategory::where('business_id', $businessId)->max('sort_order') + 1,
            ]);
            Activity::log('created', 'أضاف الخدمة «'.$name.'» إلى كتالوج خدمات الموردين', ['business_id' => $businessId, 'subject_type' => 'supplier']);
        } else {
            $category->update(['name' => $name, 'active' => $active]);
            Activity::log('updated', 'عدّل الخدمة «'.$name.'» في كتالوج خدمات الموردين', ['business_id' => $businessId, 'subject_type' => 'supplier']);
        }

        return $category->fresh();
    }

    /** حذفُ خدمةٍ لا يقدّمها أحد — وما يقدّمه مورّدٌ يُعطَّل */
    public static function deleteCategory(ServiceCategory $category): void
    {
        if (DB::table('service_category_supplier')->where('service_category_id', $category->id)->exists()) {
            throw new RuntimeException(__('يقدّم هذه الخدمة مورد أو أكثر — عطّلها بدل حذفها.'));
        }

        $category->delete();
    }

    /** @return list<array{id: int, name: string, active: bool}> */
    public static function catalog(int $businessId): array
    {
        return ServiceCategory::where('business_id', $businessId)->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'active'])
            ->map(fn (ServiceCategory $c) => ['id' => $c->id, 'name' => $c->name, 'active' => (bool) $c->active])->all();
    }
}
