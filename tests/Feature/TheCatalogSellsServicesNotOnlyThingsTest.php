<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\PackageItem;
use App\Models\Product;
use App\Models\User;
use App\Support\Quotations;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الكتالوجُ يبيع خدماتٍ وباقاتٍ لا أصنافًا وحدها.
 *
 * ═══ ما كان ═══
 *
 * `products` كان صنفًا ماديًّا لا غير: كميّةٌ وحدُّ تنبيهٍ وباركود. وEvent
 * Plus تبيع «تشغيل نظام تسجيل» و«فريقًا ميدانيًّا ليومين» و«باقة مؤتمر ٥٠٠
 * شخص» — ولا واحدٌ منها يُعدّ على رفّ.
 *
 * ═══ وما يحرسه هذا الملفّ ═══
 *
 * أنّ النوع **عمودٌ لا نموذجٌ ثانٍ**، وأنّ ما لا رفَّ له يُعرف بذلك، وأنّ
 * الباقةَ تُفَكّ بأسعار الكتالوج لا بأسعارٍ تُخترع — وأنّ ذلك كلَّه معزولٌ
 * بين الشركات.
 */
class TheCatalogSellsServicesNotOnlyThingsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'افنت بلس', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);

        User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'owner@hadath.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function product(array $over = []): Product
    {
        return Product::create(array_merge([
            'business_id' => $this->business->id,
            'name' => 'بطاقة تعريف',
            'price' => 1.500,
            'cost' => 0.400,
            'quantity' => 1000,
        ], $over));
    }

    /** وصفٌّ قديمٌ لا يتغيّر: الافتراضُ يقول ما كان عليه بلا هجرة بيانات */
    public function test_a_product_written_before_the_column_is_an_item(): void
    {
        $product = $this->product();

        $this->assertSame(Product::ITEM, $product->fresh()->kind);
        $this->assertTrue($product->isStocked());
    }

    public function test_a_service_has_no_shelf(): void
    {
        $this->product();
        $service = $this->product(['name' => 'تشغيل نظام تسجيل', 'kind' => Product::SERVICE, 'price' => 450]);
        $package = $this->product(['name' => 'باقة مؤتمر', 'kind' => Product::PACKAGE, 'price' => 2500]);

        $this->assertFalse($service->isStocked());
        $this->assertFalse($package->isStocked());

        /* و«ما يُتابَع على الرفّ» يستثنيهما — فلا يُرفع لهما تنبيهُ نفاد */
        $stocked = Product::where('business_id', $this->business->id)->stocked()->pluck('name');

        $this->assertSame(['بطاقة تعريف'], $stocked->all());
    }

    /**
     * ونوعٌ لا نعرفه لا يردّ الجدولَ كلَّه.
     *
     * `scopeOfKind` تُبنى من معاملٍ في الرابط. وقيمةٌ مخترعة تُرجع لا شيء
     * لا كلَّ شيء — وهي القاعدةُ نفسُها في `CustomerInvoice::scopePaymentState`.
     */
    public function test_an_unknown_kind_returns_nothing_not_everything(): void
    {
        $this->product();
        $this->product(['name' => 'خدمة', 'kind' => Product::SERVICE]);

        $this->assertSame(1, Product::where('business_id', $this->business->id)->ofKind('service')->count());
        $this->assertSame(0, Product::where('business_id', $this->business->id)->ofKind('نوعٌ مخترَع')->count());
    }

    /* ─────────────────────────── الباقات ─────────────────────────── */

    public function test_a_package_lists_what_it_contains(): void
    {
        $badge = $this->product(['name' => 'بطاقة', 'price' => 1.500]);
        $staff = $this->product(['name' => 'فريق ميداني', 'kind' => Product::SERVICE, 'price' => 80]);

        $package = $this->product(['name' => 'باقة مؤتمر ٥٠٠', 'kind' => Product::PACKAGE, 'price' => 1000]);

        PackageItem::create(['business_id' => $this->business->id, 'package_id' => $package->id,
            'item_id' => $badge->id, 'quantity' => 500, 'unit' => 'بطاقة', 'sort_order' => 1]);
        PackageItem::create(['business_id' => $this->business->id, 'package_id' => $package->id,
            'item_id' => $staff->id, 'quantity' => 2, 'unit' => 'يوم', 'sort_order' => 2]);

        $this->assertSame(2, $package->packageItems()->count());
        $this->assertSame('بطاقة', $package->packageItems->first()->unit);
    }

    /**
     * والفكُّ بأسعار الكتالوج — لا بسعر الباقة موزَّعًا على بنودها.
     *
     * توزيعُ سعر الباقة يُخرج أرقامًا كسريّةً لا يعرف أحدٌ من أين جاءت، ولا
     * يستطيع من يقرأ العرضَ أن يقول للعميل «هذا سعرُ البطاقة عندنا».
     */
    public function test_a_package_expands_at_catalogue_prices(): void
    {
        $badge = $this->product(['name' => 'بطاقة', 'price' => 1.500]);
        /* وخصمُ الصنف يُطبَّق: `sellingPrice` هي مصدرُ السعر لا `price` */
        $staff = $this->product(['name' => 'فريق ميداني', 'kind' => Product::SERVICE,
            'price' => 100, 'discount' => 20]);

        $package = $this->product(['name' => 'باقة', 'kind' => Product::PACKAGE, 'price' => 1000]);

        PackageItem::create(['business_id' => $this->business->id, 'package_id' => $package->id,
            'item_id' => $badge->id, 'quantity' => 500, 'sort_order' => 1]);
        PackageItem::create(['business_id' => $this->business->id, 'package_id' => $package->id,
            'item_id' => $staff->id, 'quantity' => 2, 'unit' => 'يوم', 'sort_order' => 2]);

        $lines = Quotations::expand($package->fresh());

        $this->assertCount(2, $lines);
        $this->assertSame(1.5, $lines[0]['unit_price']);
        $this->assertSame(500.0, $lines[0]['quantity']);
        $this->assertSame(80.0, $lines[1]['unit_price'], 'سعرُ العضو بعد خصم صنفه');
        $this->assertSame('يوم', $lines[1]['unit']);
    }

    /** وما ليس باقةً لا يُفَكّ — فلا تُخترع له بنودٌ لا يملكها */
    public function test_only_a_package_expands(): void
    {
        $service = $this->product(['kind' => Product::SERVICE]);

        $this->assertSame([], Quotations::expand($service));
    }

    /**
     * والعضوُ لا يتكرّر في الباقة.
     *
     * كميّتان لشيءٍ واحدٍ لا يُعرف أيّهما المعتبَرة — وأوّلُ من يقرأ الباقةَ
     * ليُسعّرها يجمعهما فيبيع ضعفَ ما وعد.
     */
    public function test_a_member_appears_once_in_a_package(): void
    {
        $badge = $this->product();
        $package = $this->product(['name' => 'باقة', 'kind' => Product::PACKAGE]);

        PackageItem::create(['business_id' => $this->business->id, 'package_id' => $package->id,
            'item_id' => $badge->id, 'quantity' => 100]);

        $this->expectException(QueryException::class);

        PackageItem::create(['business_id' => $this->business->id, 'package_id' => $package->id,
            'item_id' => $badge->id, 'quantity' => 50]);
    }

    /* ───────────────────── الشاشةُ والمسارات ───────────────────── */

    /**
     * والنوعُ يُكتب من شاشة المنتج — لا من القاعدة بيد.
     *
     * وما لا رفَّ له تُصفَّر كميّتُه في الخادم مهما أُرسل: شاشةٌ تُخفي
     * الحقلَ ليست حارسًا، وخدمةٌ بكميّة «٥٠» تظهر في الجرد وفي تنبيه النفاد
     * وفي تقرير المخزون — ثلاثةُ مواضعَ تقول عن شيءٍ لا رفَّ له إنّه ينفد.
     */
    public function test_a_service_is_written_from_the_screen_without_a_shelf(): void
    {
        $owner = User::where('business_id', $this->business->id)->first();

        $this->actingAs($owner)->post(route('admin.products.store'), [
            'name' => 'تشغيل نظام تسجيل',
            'kind' => Product::SERVICE,
            'price' => 450,
            /* وما يرسله من يفتح أدوات المتصفّح — أو نموذجٌ قديم */
            'quantity' => 50,
            'alert_qty' => 10,
        ])->assertRedirect();

        $product = Product::where('business_id', $this->business->id)
            ->where('name', 'تشغيل نظام تسجيل')->firstOrFail();

        $this->assertSame(Product::SERVICE, $product->kind);
        $this->assertSame(0, (int) $product->quantity);
        $this->assertSame(0, (int) $product->alert_qty);
    }

    /** ونوعٌ مخترَع يُردّ عند الباب — القائمةُ مغلقة */
    public function test_an_invented_kind_is_refused(): void
    {
        $owner = User::where('business_id', $this->business->id)->first();

        $this->actingAs($owner)->post(route('admin.products.store'), [
            'name' => 'شيء', 'kind' => 'اشتراك', 'price' => 10,
        ])->assertSessionHasErrors('kind');
    }

    /** وبنودُ الباقة تُدار من شاشتها — وتُقيَّد بالشركة */
    public function test_package_items_are_managed_from_the_product_screen(): void
    {
        $owner = User::where('business_id', $this->business->id)->first();

        $package = $this->product(['name' => 'باقة مؤتمر', 'kind' => Product::PACKAGE, 'price' => 1000]);
        $badge = $this->product(['name' => 'بطاقة', 'price' => 1.500]);

        $this->actingAs($owner)->post(route('admin.products.package.store', $package->id), [
            'item_id' => $badge->id, 'quantity' => 500, 'unit' => 'بطاقة',
        ])->assertRedirect();

        $this->assertSame(1, $package->packageItems()->count());

        /* والمكرّرُ يُجمع لا يُضاف: كميّتان لشيءٍ واحد لا يُعرف أيّهما */
        $this->actingAs($owner)->post(route('admin.products.package.store', $package->id), [
            'item_id' => $badge->id, 'quantity' => 100,
        ])->assertRedirect();

        $this->assertSame(1, $package->packageItems()->count());
        $this->assertSame('600.000', $package->packageItems()->first()->quantity);

        /* والباقةُ لا تحوي نفسَها */
        $this->actingAs($owner)->post(route('admin.products.package.store', $package->id), [
            'item_id' => $package->id, 'quantity' => 1,
        ])->assertSessionHasErrors('item_id');

        /* وبندُ شركةٍ أخرى يُردّ */
        $other = Business::create(['name' => 'أخرى', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = Product::create(['business_id' => $other->id, 'name' => 'بندهم', 'price' => 5]);

        $this->actingAs($owner)->post(route('admin.products.package.store', $package->id), [
            'item_id' => $theirs->id, 'quantity' => 1,
        ])->assertSessionHasErrors('item_id');

        /* والحذفُ يمضي */
        $row = $package->packageItems()->first();
        $this->actingAs($owner)
            ->delete(route('admin.products.package.destroy', [$package->id, $row->id]))
            ->assertRedirect();

        $this->assertSame(0, $package->packageItems()->count());
    }

    /** وباقةُ شركةٍ لا تُعدَّل من شركةٍ أخرى */
    public function test_a_package_is_not_edited_across_companies(): void
    {
        $package = $this->product(['name' => 'باقة', 'kind' => Product::PACKAGE]);
        $badge = $this->product(['name' => 'بطاقة']);

        $other = Business::create(['name' => 'أخرى', 'type' => 'عام', 'status' => 'نشط']);
        JobTitle::create(['business_id' => $other->id, 'name' => 'مدير', 'role' => 'admin']);
        $intruder = User::create([
            'business_id' => $other->id, 'name' => 'غريب', 'email' => 'x@other.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($intruder)->post(route('admin.products.package.store', $package->id), [
            'item_id' => $badge->id, 'quantity' => 1,
        ])->assertNotFound();

        $this->assertSame(0, $package->packageItems()->count());
    }

    /**
     * وباقةُ شركةٍ لا تُفَكّ إلى أصناف شركةٍ أخرى.
     *
     * والعزلُ ليس في هذه الدالّة وحدها: الباقةُ تُقرأ من شركتها في
     * `QuotationController::catalog`، والسعرُ يُقرأ من شركة العرض في
     * `Quotations::price`. وهذا الحارسُ على الطبقة التي تُفَكّ بها.
     */
    public function test_a_package_belongs_to_its_company(): void
    {
        $other = Business::create(['name' => 'شركة أخرى', 'type' => 'عام', 'status' => 'نشط']);

        $mine = $this->product(['name' => 'بطاقتي', 'price' => 1]);
        $theirs = Product::create(['business_id' => $other->id, 'name' => 'بطاقتهم', 'price' => 99]);

        $package = $this->product(['name' => 'باقة', 'kind' => Product::PACKAGE]);

        PackageItem::create(['business_id' => $this->business->id, 'package_id' => $package->id,
            'item_id' => $mine->id, 'quantity' => 10]);

        $lines = Quotations::expand($package->fresh());

        $this->assertCount(1, $lines);
        $this->assertSame($mine->id, $lines[0]['product_id']);
        $this->assertNotSame($theirs->id, $lines[0]['product_id']);

        /* وباقتُهم لا تحمل بنودي */
        $theirPackage = Product::create(['business_id' => $other->id, 'name' => 'باقتهم',
            'kind' => Product::PACKAGE, 'price' => 100]);

        $this->assertSame([], Quotations::expand($theirPackage));
    }
}
