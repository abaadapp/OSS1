<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use App\Models\Website;
use App\Support\Permissions;
use App\Support\Website\Blueprints;
use App\Support\Website\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * تشغيلُ الموقع ليس ضبطَه — ولا يُمنحان بمفتاحٍ واحد.
 *
 * ═══ ما كان ═══
 *
 * قسمُ `website` واحدٌ يفتح كلَّ شيء. فمن مُنح «الموقع الإلكتروني» ليتفقّد
 * منتجاتِه الظاهرة كلَّ صباح مُنح معه: تبديلَ قالب الموقع، وحذفَ صفحاته،
 * وتحويلَ نطاقه، ونشرَ ما في المسوّدة على زبائنه.
 *
 * وأثقلُها النطاق: حرفٌ يُبدَّل فيه يُطفئ المتجر على كلّ من يفتح رابطه، ولا
 * يكتشفه صاحبُه إلّا من زبونٍ يتّصل. وهذه صلاحيةٌ لا تُعطى لمن وظيفتُه أن
 * يكتب كمّيةً في خانة.
 *
 * ═══ ما صار ═══
 *
 * القسمُ للتشغيل — لوحةُ الموقع ومنتجاتُه — وفعلُ `website.configure` للضبط.
 * ويرثه `admin` و`manager` فلا يفقد أحدٌ اليومَ بابًا كان يفتحه أمس، ويُمنح
 * لمن سواهما باسمه.
 *
 * والحراسةُ في المسار لا في الشاشة: إخفاءُ زرٍّ ليس حراسة، والعنوانُ يُكتب
 * بالید.
 */
class RunningTheSiteIsNotConfiguringItTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'ورود مسقط', 'type' => 'محل ورود', 'status' => 'نشط',
        ]);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        Builder::create($this->business, Blueprints::STORE, 'modern', $this->owner->id);
    }

    /** موظّفٌ مُنح التشغيل وحده — بصلاحياتٍ مكتوبةٍ بيدها لا موروثةٍ بدور */
    private function operator(array $permissions): User
    {
        return User::create([
            'business_id' => $this->business->id, 'name' => 'موظّف',
            'email' => 'staff'.count($permissions).rand(1, 9999).'@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'staff', 'status' => 'نشط',
            'permissions' => $permissions,
        ]);
    }
    /* ==================== والضبطُ مغلقٌ دونه ==================== */

    /** @return array<int, array{0: string}> */
    public static function configScreens(): array
    {
        return [
            ['admin.website.site'],
            ['admin.website.design'],
            ['admin.website.pages'],
            ['admin.website.shop'],
            ['admin.website.seo'],
            ['admin.website.domain'],
            ['admin.website.editor'],
        ];
    }

    #[DataProvider('configScreens')]
    public function test_an_operator_cannot_reach_a_configuration_screen(string $name): void
    {
        $this->actingAs($this->operator(['website']));

        $this->get(route($name))->assertForbidden();
    }
}
