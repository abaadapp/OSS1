<?php

namespace Tests\Feature\V1\Concurrency;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\JournalEntry;
use App\Support\Ledger;

/**
 * قيدان يُكتبان في اللحظة نفسها لا يتقاسمان رقمًا ولا يُسقط أحدُهما الآخر.
 *
 * كان `JournalEntry::nextNumber` يقرأ آخر رقمٍ بلا قفل، والفهرسُ الفريد يردّ
 * الثاني — فتسقط دفعةٌ أو صرفُ مستهلَكٍ بخطأ قاعدةٍ لا علاقةَ له بالمستخدم،
 * ولا يظهر إلّا في يومٍ مزدحم.
 */
class TheLedgerNumbersEntriesUnderContentionTest extends RaceTestCase
{
    public function test_parallel_postings_all_succeed_with_distinct_numbers(): void
    {
        $business = Business::create(['name' => 'سباق', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $business->id, 'name' => 'الرئيسي']);
        Currency::create(['business_id' => $business->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart((int) $business->id);
        $bid = (int) $business->id;

        $worker = fn () => function () use ($bid) {
            $numbers = [];
            for ($i = 0; $i < 5; $i++) {
                $entry = Ledger::post($bid, 'سباق', [['account' => 'cash', 'debit' => 1], ['account' => 'capital', 'credit' => 1]], now(), 'يدوي');
                $numbers[] = $entry->number;
            }

            return $numbers;
        };

        $results = $this->race([$worker(), $worker(), $worker(), $worker()]);

        $this->assertSame(4, $this->successes($results), 'أخفق عاملٌ: '.json_encode(array_column($results, 'error'), JSON_UNESCAPED_UNICODE));
        $numbers = JournalEntry::where('business_id', $bid)->pluck('number');
        $this->assertCount(20, $numbers);
        $this->assertCount(20, $numbers->unique(), 'رقمٌ تكرّر');
    }
}
