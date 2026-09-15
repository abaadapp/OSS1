<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\PosDevice;
use App\Models\PosPeripheral;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الأجهزة الملحقة بصندوق البيع.
 *
 * وأخطرها ليس الإضافة بل الحدود: ملحقٌ يتبع صندوقًا يتبع متجرًا، فمعرّفٌ
 * صحيح تحت جهاز الجار يجب ألّا يفتح شيئًا. والكاشير لا يبدّل طابعة صندوقه:
 * من يفعل يوجّه إيصالات فرعٍ إلى ورق فرعٍ آخر.
 */
class PosPeripheralTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private PosDevice $device;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'سالم', 'email' => 'salem@eventplus.om',
            'role' => 'admin', 'job_title' => 'مدير', 'status' => 'نشط', 'password' => 'x',
        ]);

        $this->device = $this->activatePosDevice($this->business->id);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'name' => 'طابعة الكاشير',
            'type' => PosPeripheral::PRINTER,
            'connection' => 'usb',
        ], $over);
    }
    public function test_deleting_a_register_takes_its_peripherals_with_it(): void
    {
        // قيدٌ أجنبي cascade: ملحقٌ بلا صندوق صفٌّ يتيم لا يظهر في شاشة ولا يُحذف
        $this->device->peripherals()->create($this->payload() + ['business_id' => $this->business->id]);

        $this->device->delete();

        $this->assertDatabaseCount('pos_peripherals', 0);
    }
}
