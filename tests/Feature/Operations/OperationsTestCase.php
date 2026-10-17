<?php

namespace Tests\Feature\Operations;

use App\Models\Asset;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\EventOperation;
use App\Models\JobTitle;
use App\Models\Project;
use App\Models\User;
use App\Support\Operations\Assets;
use App\Support\Operations\EventOperations;
use App\Support\Permissions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * الأرضيّةُ المشتركة لاختبارات المرحلة 10 — شركةٌ ومشروعٌ قائم وأربعةُ أدوار.
 *
 * المالكُ يملك كلَّ شيء؛ والمديرُ يدير ويعيّن ولا يتجاوز الجاهزيّة؛ والمنفّذُ
 * الميدانيّ مُنح بالاسم «العمليات» ونقلَ الحال والحوادثَ والتسليمَ والردّ —
 * كما تُمنح في الواقع لمن يقف في الموقع؛ وأمينُ المخزن يفتح «المعدّات» بدوره
 * ويسجّل ويحجز. والبائعُ لا يفتح شيئًا من هذا.
 */
abstract class OperationsTestCase extends TestCase
{
    protected Business $business;

    protected Business $other;

    protected User $owner;

    protected User $manager;

    protected User $field;

    protected User $storekeeper;

    protected User $seller;

    protected User $stranger;

    protected Customer $customer;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Carbon::setTestNow(Carbon::parse('2026-09-20 10:00:00'));

        $this->business = Business::create(['name' => 'افنت بلس', 'type' => 'عام', 'status' => 'نشط', 'email' => 'hello@hadath.om']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);
        Currency::create(['business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true]);

        $make = fn (string $name, string $email, string $role, ?array $permissions = null) => User::create([
            'business_id' => $this->business->id, 'name' => $name, 'email' => $email, 'password' => bcrypt('password'),
            'role' => $role, 'status' => 'نشط', 'permissions' => $permissions, 'phone' => '9'.random_int(1000000, 9999999),
        ]);

        $this->owner = $make('المالك', 'owner@hadath.om', 'admin');
        $this->manager = $make('المدير', 'manager@hadath.om', 'manager');
        $this->field = $make('المنفّذ', 'field@hadath.om', 'delivery', [
            'dashboard', 'projects', 'operations',
            Permissions::OPERATIONS_STATUS, Permissions::OPERATIONS_INCIDENTS, Permissions::ASSETS_CHECKOUT, Permissions::ASSETS_RETURN,
        ]);
        $this->storekeeper = $make('أمين المخزن', 'store@hadath.om', 'inventory');
        $this->seller = $make('البائع', 'sales@hadath.om', 'sales');

        $this->other = Business::create(['name' => 'شركة أخرى', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->other->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->other->id, 'name' => 'مدير', 'role' => 'admin']);
        $this->stranger = User::create(['business_id' => $this->other->id, 'name' => 'غريب', 'email' => 'owner@other.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط']);

        $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'وزارة الطاقة', 'phone' => '99887766']);
        $this->project = $this->project('EP-2026-001');
    }

    protected function project(string $code, ?int $businessId = null, ?int $customerId = null): Project
    {
        return Project::create([
            'business_id' => $businessId ?? $this->business->id,
            'customer_id' => $customerId ?? $this->customer->id,
            'code' => $code, 'name' => 'مؤتمر الطاقة '.$code, 'status' => Project::CONFIRMED,
            'starts_at' => '2026-10-01', 'ends_at' => '2026-10-02', 'location' => 'قاعة عُمان',
            'contact_person' => 'سعيد', 'contact_phone' => '91234567', 'owner_id' => $this->manager->id, 'approved_value' => 10000,
        ]);
    }

    /** فعاليّةٌ بموعدٍ ومكانٍ وقائد — جاهزةٌ للتجهيز */
    protected function operation(array $over = [], ?Project $project = null): EventOperation
    {
        return EventOperations::create($project ?? $this->project, $over + [
            'name' => 'اليوم الأوّل',
            'venue_name' => 'قاعة عُمان',
            'setup_starts_at' => '2026-10-01 06:00',
            'event_starts_at' => '2026-10-01 09:00',
            'event_ends_at' => '2026-10-01 17:00',
            'teardown_starts_at' => '2026-10-01 17:00',
            'teardown_ends_at' => '2026-10-01 19:00',
            'operations_lead_id' => $this->manager->id,
        ], $this->owner);
    }

    protected function asset(string $name = 'حاسوب', string $category = 'laptop', array $over = [], ?int $businessId = null): Asset
    {
        return Assets::create($businessId ?? (int) $this->business->id, $over + ['name' => $name, 'category' => $category], $this->owner);
    }
}
