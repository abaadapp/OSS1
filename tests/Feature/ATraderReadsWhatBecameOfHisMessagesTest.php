<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Support\Demo;
use App\Support\OrderStatus;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use ReflectionClass;
use Tests\TestCase;

/**
 * التاجرُ يقرأ ما جرى لرسائله — لا يسأل عنه إنسانًا.
 *
 * ═══ العطب ═══
 *
 * `whatsapp_messages` مكتوبٌ في رأسه أنّه «دفتر الحقيقة الذي يُدقَّق»، وفيه
 * صفٌّ لكلّ رسالةٍ حتّى الممنوعةِ بسببها. ولم تكن تفتحه شاشةٌ واحدة. فكان
 * جرسُ اللوحة يقول «لم تصل ٣ رسائل إلى زبائنك» ويقود إلى **شاشة الربط** —
 * وهي تقول إنّ الرقم مربوطٌ وحصّتَك باقية، ولا تعرف أيَّ الثلاث ولا لمن ولا
 * لماذا.
 *
 * فيُقال للتاجر إنّ شيئًا انكسر ولا يُقال ماذا. وبابٌ معروضٌ لا يُفتح أسوأ
 * من بابٍ لا يُعرض.
 *
 * وهذه الحرّاسُ تحرس ثلاثةً: أنّ الدفتر يُفتح ولا يعبر حدَّ المتجر، وأنّ كلّ
 * حالٍ وكلّ سببٍ له كلمةٌ تُقرأ، وأنّ «خرجت» لا تُسمّى «وصلت».
 */
class ATraderReadsWhatBecameOfHisMessagesTest extends TestCase
{
    use RefreshDatabase;

    private Business $mine;

    private Business $theirs;

    private User $owner;
    /* ═════════════ أوّلًا: الدفترُ يُفتح، ولا يعبر حدَّ المتجر ═════════════ */
    /**
     * ولا يقرأ تاجرٌ دفترَ متجرٍ آخر — وفيه أرقامُ زبائنه.
     *
     * والفحصُ على الصفوف لا على رمز الرد: صفحةٌ تُردّ بـ٢٠٠ وقد عرضت ما لا
     * يجوز تبدو سليمةً وهي أسوأ ما في الباب.
     */
    public function test_another_shops_rows_never_reach_this_book(): void
    {
        $this->row($this->mine, ['recipient_phone' => '96891111111']);
        $this->row($this->theirs, ['recipient_phone' => '96892222222']);

        $this->open([], fn (Assert $p) => $p
            ->has('rows', 1)
            ->where('rows.0.phone', '96891111111'));
    }

    /**
     * ومعرّفُ المتجر من الجلسة لا من العنوان.
     *
     * لو قُرئ ممّا يصل لَكفى أن يُبدَّل رقمٌ في شريط العنوان ليُقرأ دفترُ
     * الجيران — ولا شيء في الشاشة يقول إنّ شيئًا تبدّل.
     */
    public function test_a_number_in_the_address_does_not_open_the_neighbours_book(): void
    {
        $this->row($this->mine, ['recipient_phone' => '96891111111']);
        $this->row($this->theirs, ['recipient_phone' => '96892222222']);

        $this->open(
            ['business_id' => $this->theirs->id, 'business' => $this->theirs->id],
            fn (Assert $p) => $p->has('rows', 1)->where('rows.0.phone', '96891111111'),
        );
    }

    /* ═════════════ ثانيًا: كلُّ حالٍ وكلُّ سببٍ له كلمة ═════════════ */
    /* ═════════════ ثالثًا: «خرجت» ليست «وصلت» ═════════════ */
    /* ═════════════ رابعًا: الحِزَمُ قائمةٌ واحدة ═════════════ */
    /**
     * والبحثُ يجد — صندوقٌ يُكتب فيه ولا يُضيّق شيئًا مقبضٌ لا يُدير شيئًا.
     *
     * والسؤالُ الذي يُفتح له الدفتر هو «أين رسالةُ هذا الزبون؟»، فالرقمُ
     * أوّلُ ما يُكتب فيه.
     */
    public function test_the_search_narrows_to_the_number_asked_for(): void
    {
        $this->row($this->mine, ['recipient_phone' => '96891111111']);
        $this->row($this->mine, ['recipient_phone' => '96892222222']);

        $this->open(['q' => '2222'], fn (Assert $p) => $p
            ->has('rows', 1)
            ->where('rows.0.phone', '96892222222'));
    }

    /**
     * ومرشِّحٌ لا نعرفه يعود إلى «الكلّ» لا إلى صفحةٍ فارغة.
     *
     * صفحةٌ فارغةٌ تُقرأ «لا رسائل لك» — وهي كذبةٌ يقودها حرفٌ في العنوان.
     */
    public function test_an_unknown_filter_does_not_empty_the_book(): void
    {
        $this->row($this->mine);
        $this->row($this->mine);

        $this->open(['filter' => 'nonsense'], fn (Assert $p) => $p
            ->has('rows', 2)
            ->where('params.filter', 'all'));
    }
    /* ═════════════ سادسًا: الصفُّ يُسمّي ورقته ═════════════ */

    /** ورسالةٌ عن طلبٍ تفتح الطلبَ نفسه — لا تُترك رقمًا في عمود */
    public function test_a_row_opens_the_paper_it_speaks_of(): void
    {
        $customer = Customer::create([
            'business_id' => $this->mine->id, 'name' => 'زبون', 'phone' => '99887766',
        ]);

        $order = Order::create([
            'business_id' => $this->mine->id,
            'branch_id' => Branch::where('business_id', $this->mine->id)->value('id'),
            'customer_id' => $customer->id, 'number' => 'ORD-9',
            'status' => OrderStatus::READY, 'total' => 10, 'subtotal' => 10,
        ]);

        $this->row($this->mine, ['order_id' => $order->id]);

        $this->open([], fn (Assert $p) => $p
            ->where('rows.0.subject.label', '#ORD-9')
            ->where('rows.0.subject.url', route('admin.orders.show', 'ORD-9')));
    }
}
