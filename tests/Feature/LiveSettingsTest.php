<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * كلّ إعدادٍ معروضٍ يفعل شيئًا.
 *
 * كان في الشاشتين ثلاثة وأربعون مفتاحًا تُحفظ ولا يقرؤها سطرٌ واحد: يُطفئ
 * التاجر «بطاقة» فيبقى الصندوق يقبلها، ويكتب بادئة فاتورته فتصدر INV-،
 * ويضبط عملته AED فيرى ر.ع، ويشغّل المشغّل وضع الصيانة فيبيع الناس على
 * قاعدةٍ تُهاجَر تحتهم. والمقبض الذي لا يُمسك أسوأ من غيابه لأنه يُطمئن.
 *
 * فهذا الملفّ لا يختبر أن الحفظ يحفظ — ذاك كان يمرّ وهو راضٍ — بل أن
 * المحفوظ يُقرأ. وكلّ اختبارٍ هنا يفشل إن عاد مفتاحُه ميّتًا.
 */
class LiveSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'owner@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة ورد',
            'price' => 10, 'cost' => 4, 'quantity' => 50, 'active' => true,
        ]);

    }

    private function set(string $key, string $value): void
    {
        Setting::updateOrCreate(['business_id' => $this->business->id, 'key' => $key], ['value' => $value]);
        Demo::flushCurrency();
    }

    private function platform(string $key, string $value): void
    {
        Setting::updateOrCreate(['business_id' => null, 'key' => $key], ['value' => $value]);
    }
    public function test_a_wildcard_in_the_prefix_is_refused_at_the_door(): void
    {
        // «%» في شرط LIKE تطابق كلّ فاتورة، فيقفز العدّاد إلى رقمٍ لا يخصّها
        $this->actingAs($this->owner)
            ->post(route('admin.settings.update'), ['inv_prefix' => 'IN%-'])
            ->assertSessionHasErrors('inv_prefix');
    }
    /* ------------------------------- العملة ------------------------------- */

    public function test_the_currency_setting_decides_what_the_merchant_sees(): void
    {
        /*
         * جدول العملات لا تملؤه شاشة، فكان السقوط على «ر.ع» مثبّتًا في الكود:
         * تاجرٌ في دبي يضبط AED ويرى الريال العماني في كل رقمٍ عنده.
         */
        $this->set('currency', 'AED');
        $this->actingAs($this->owner);

        $this->assertStringContainsString('د.إ', Demo::money(10));
    }

    public function test_the_decimal_places_setting_is_obeyed(): void
    {
        $this->set('currency', 'AED');
        $this->set('decimals', '0');
        $this->actingAs($this->owner);

        $this->assertStringStartsWith('10 ', Demo::money(10));
    }

    public function test_the_symbol_can_sit_before_the_amount(): void
    {
        $this->set('currency', 'USD');
        $this->set('symbol_pos', 'before');
        $this->actingAs($this->owner);

        $this->assertSame('$ 10.00', Demo::money(10));
    }

    /* ---------------------------- وضع الصيانة ---------------------------- */

    public function test_maintenance_mode_stops_the_merchant(): void
    {
        $this->platform('maintenance_mode', '1');

        $this->actingAs($this->owner)->get(route('admin.dashboard'))
            ->assertStatus(503)
            ->assertInertia(fn ($page) => $page->component('Auth/Maintenance'));
    }
    private function superAdmin(): User
    {
        return User::create([
            'business_id' => null, 'name' => 'المشغّل', 'email' => 'super@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }
}
