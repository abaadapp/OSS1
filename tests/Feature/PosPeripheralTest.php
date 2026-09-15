<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
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
    public function test_deleting_a_register_takes_its_peripherals_with_it(): void
    {
        // قيدٌ أجنبي cascade: ملحقٌ بلا صندوق صفٌّ يتيم لا يظهر في شاشة ولا يُحذف
        $this->device->peripherals()->create($this->payload() + ['business_id' => $this->business->id]);

        $this->device->delete();

        $this->assertDatabaseCount('pos_peripherals', 0);
    }
}
