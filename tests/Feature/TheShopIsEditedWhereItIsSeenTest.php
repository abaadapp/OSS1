<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Website;
use App\Support\Website\Blueprints;
use App\Support\Website\Builder;
use App\Support\Website\Sections;
use App\Support\Website\Templates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المتجر يُعدَّل حيث يُرى — لا في شاشةٍ إلى جانبه.
 *
 * ═══ ما كان ═══
 *
 * أربعُ شاشاتٍ لعملٍ واحد: معالجٌ من ثلاث خطوات لإنشائه، ومحرّرٌ فيه قائمةُ
 * أقسامٍ ومعاينةٌ صغيرة بجانبها، وشاشةُ تصميمٍ مستقلّة بمعاينتها الثانية،
 * وصفحاتٌ في ثالثة. ومن أراد تجربة لونٍ وهو يكتب نصَّ واجهته يغادر المحرّر
 * ويعود.
 *
 * ═══ وما يحرسه هذا الملفّ ═══
 *
 * ثلاثةُ عقودٍ يُبنى عليها الشكلُ الجديد، وكلُّها تنكسر صامتةً:
 *
 * ١) **المحرّر يحمل تصميمَه.** حمولتُه فيها القوالبُ ورموزُها وخياراتُها،
 *    فلو سقطت لَعُرضت لوحةُ تصميمٍ فارغة بلا خطأ في الطرفية.
 * ٢) **بابُ التصميم القديم يقود إلى اللوحة الجديدة.** روابطُ محفوظةٌ
 *    وتبويبٌ يشيران إليه، و404 عليه فقدانٌ لا نقل.
 * ٣) **المنتجُ مصدرُه واحد.** ما يُحفظ في قسم المنتجات وصفُ عرضٍ لا بضاعة:
 *    اسمٌ أو سعرٌ يُنسخ في الموقع يعني كتالوجين يفترقان في أوّل تعديل.
 */
class TheShopIsEditedWhereItIsSeenTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'ورود مسقط', 'type' => 'محل ورود', 'status' => 'نشط',
            'phone' => '96890000000', 'city' => 'مسقط',
        ]);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    private function props(string $url): array
    {
        return $this->get($url)->assertOk()->viewData('page')['props'];
    }

    private function build(string $template = 'modern'): Website
    {
        return Builder::create($this->business, Blueprints::STORE, $template, $this->owner->id);
    }
    /* ================== ٥ · القالبُ هيئةٌ لا لونٌ وحده ================== */

    /**
     * وأربعةُ قوالبَ لا يفرّقها اللون وحده.
     *
     * قالبان لونُهما مختلفٌ وكلُّ ما عداهما واحد يُقرآن قالبًا واحدًا بلونين،
     * ومن يُعرض عليه أربعةٌ منها يظنّ أنّه يختار لونًا فيختار أيَّها كان.
     *
     * وهذا يقيس ما يُزرع في **المحتوى** (`presets`): ارتفاعُ الواجهة
     * ومحاذاتُها وعددُ الأعمدة. أمّا بنيةُ الرسم — ترويسةٌ وواجهةٌ وبطاقةٌ
     * وشبكة — فرموزٌ يحكمها القالب ويقيسها
     * `ATemplateIsAStructureNotAPaletteTest`.
     */
    public function test_the_four_templates_differ_in_shape_not_only_in_colour(): void
    {
        $shape = function (string $template): array {
            $business = Business::create([
                'name' => 'متجر '.$template, 'type' => 'عام', 'status' => 'نشط',
            ]);

            /* وبضاعةٌ في كلٍّ منها: القسمُ الذي لا يجد ما يعرضه لا يُبنى أصلًا */
            Product::create([
                'business_id' => $business->id, 'name' => 'صنف', 'price' => 1,
                'active' => true, 'quantity' => 3,
            ]);

            $site = Builder::create($business, Blueprints::STORE, $template);
            $hero = $site->homePage()->sections()->where('type', 'hero')->firstOrFail();

            return [
                'height' => $hero->data['height'],
                'align' => $hero->data['align'],
                'columns' => $site->homePage()->sections()
                    ->where('type', 'featured_products')->firstOrFail()->data['columns'],
            ];
        };

        $shapes = array_map($shape, array_combine(Templates::FEATURED, Templates::FEATURED));

        $this->assertCount(4, array_unique(array_map('json_encode', $shapes)), 'قالبان بهيئةٍ واحدة');
        $this->assertSame('large', $shapes['atelier']['height']);
        $this->assertSame('small', $shapes['mono']['height']);
        $this->assertSame('2', $shapes['mono']['columns']);
        $this->assertSame('4', $shapes['souq']['columns']);

        /*
         * وشكلُ الترويسة لا يُزرع في المحتوى بعد اليوم: القالب يحكمه برمزه.
         *
         * ولو زُرع لبقيت ترويسةُ القالب الأوّل على موقعٍ بدّل قالبه — وهو
         * أوّلُ ما يُنتظر أن يتبدّل حين يُبدَّل القالب.
         */
        foreach (Templates::FEATURED as $key) {
            $business = Business::create(['name' => 'ترويسة '.$key, 'type' => 'عام', 'status' => 'نشط']);
            $site = Builder::create($business, Blueprints::STORE, $key);

            $this->assertSame('auto', $site->slot('header')->data['preset'], $key);
        }
    }

    /**
     * وهيئةُ القالب لا تخترع مقبضًا.
     *
     * `presets` تختار من مقابضَ يعرفها وصفُ القسم، فلو رُفع حقلٌ منه غدًا
     * سقط ذكرُه بلا أن يُكتب في القاعدة ما لا يقرؤه أحد.
     */
    public function test_a_template_cannot_invent_a_field(): void
    {
        $out = Templates::apply('bold', 'hero', ['height' => 'small', 'title' => 'أهلًا']);

        $this->assertSame(['height' => 'large', 'title' => 'أهلًا'], $out);

        $this->assertSame(
            ['title' => 'أهلًا'],
            Templates::apply('bold', 'hero', ['title' => 'أهلًا']),
            'كُتب مفتاحٌ لا يعرفه وصفُ القسم',
        );
    }
}
