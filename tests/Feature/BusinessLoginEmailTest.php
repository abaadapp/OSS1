<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use App\Support\MerchantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * عمود البريد في شاشة الشركات.
 *
 * للشركة عنوانا بريد: واحدٌ للتواصل يُكتب عند التسجيل، وآخرُ يدخل به التاجر
 * ويُبنى على نطاقنا. وكان العمود يعرض الأوّل بلا وصف — فيبدّل المشغّل حساب
 * الدخول ثم يعود إلى الجدول فيرى العنوان القديم ويظنّ أن التعديل لم يقع.
 */
class BusinessLoginEmailTest extends TestCase
{
    use RefreshDatabase;

    private User $platform;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platform = User::create([
            'name' => 'مدير المنصة',
            'email' => 'admin@eventplusapp.om',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
            'status' => 'نشط',
        ]);

        $this->business = Business::create([
            'name' => 'متجري',
            'status' => 'نشط',
            // بريد التواصل — يُكتب عند التسجيل ولا يدخل به أحد
            'email' => 'contact@gmail.com',
        ]);

        MerchantAccount::create($this->business, 'shop', 'secret-pass');
    }
    public function test_the_column_shows_the_address_the_merchant_logs_in_with(): void
    {
        $row = $this->firstRow();

        $this->assertSame('shop@eventplusapp.om', $row['email']);
    }

    public function test_the_contact_address_is_kept_and_not_confused_with_it(): void
    {
        // لم يُحذف، بل فُصل: عنوانان لمعنيين، ولكلٍّ اسمه
        $row = $this->firstRow();

        $this->assertSame('contact@gmail.com', $row['contactEmail']);
    }
}
