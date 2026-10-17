<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * الطلبُ القديم — بيعةُ صندوقٍ من أيّام متجر التجزئة. **تاريخٌ يُقرأ، لا بابٌ يُكتب.**
 *
 * خرجت «المبيعات» من النظام بقسمها وشاشاتها وتصحيحها وأوراقها وتصديرها
 * وأوامر ترحيلها: لا مسارَ يُنشئ طلبًا ولا يعدّله ولا يطبعه، ولا يُرحَّل منه
 * قيدٌ ولا تتحرّك به كميّة.
 *
 * ويبقى النموذجُ وجدولُه لأنّ ما وقع لا يُمحى:
 *  • قيودُه في الدفتر وحركاتُ صندوقه (`Transaction::order`) — تُقرأ في المالية والضريبة.
 *  • والبيعُ الآجلُ الذي لم يُفوتَر ذمّةٌ قائمة: يُعدّ في مطابقة الذمم
 *    (`Receivables::uninvoicedOrders`) ويُفوتَر من «الذمم» (`CustomerInvoices::consolidate`).
 *  • والفاتورةُ المولودةُ منه تذكره (`CustomerInvoice::orders`).
 *  • وبنودُه تحمي المنتجَ الذي بيع من المحو النهائيّ (`Product::orderItems`).
 *  • وصفوفُه في النسخة الاحتياطيّة تُستعاد كما هي (`TenantTables`) — بلا شاشةٍ تعود معها.
 */
class Order extends Model
{
    protected $guarded = [];

    protected $casts = [
        'document_snapshot' => 'array',
        'subtotal' => 'decimal:3', 'discount' => 'decimal:3', 'tax' => 'decimal:3',
        'delivery_fee' => 'decimal:3', 'total' => 'decimal:3',
        'ordered_at' => 'datetime',
        'scheduled_for' => 'datetime',
        'hide_sender' => 'boolean',
        /*
         * وختمان يُقرآن في الشاشة — وبلا `cast` يعودان نصًّا.
         *
         * `optional('2026-09-11 10:00:00')->toIso8601String()` تُرجع `null`
         * بلا خطأ: `Optional` تستدعي على الكائنات وتصمت على ما سواها. فبقي
         * «طلب التقييم مجددًا» لا يظهر أبدًا، ولا شيء يقول لماذا.
         */
    ];

    /**
     * الحالة التي تعني أن البيعة رُدَّت ولم تعد بيعًا.
     *
     * مقروءةٌ في نطاق `sold` وفي الذمم (`Receivables`) والصندوق
     * (`Transaction::scopeNotCancelled`). وكانت تُشير إلى `OrderStatus` —
     * سجلِّ حالات الطلب وانتقالاتها — وخرج مع شاشة الطلبات: لا طلبَ تُنقل
     * حالتُه، ويبقى معنى «الملغى» لقراءة ما مضى.
     */
    public const CANCELLED = 'ملغي';

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * الفواتيرُ التي تغطّي هذا الطلب — واحدةٌ أو لا شيء.
     *
     * وبها يُسأل: «هل فُوتر هذا الطلب؟» — فتُستبعد بيعةٌ آجلةٌ طُبعت ورقتُها
     * من مجموع «ما لم يُفوتَر»، ولا يُعدّ الدَّين مرّتين.
     */
    public function customerInvoices(): BelongsToMany
    {
        return $this->belongsToMany(CustomerInvoice::class, 'customer_invoice_orders');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    /**
     * قيود الفاتورة في المالية — دخلُها، وما يُلغى معها.
     *
     * والمالية كلُّها تقرأ `transactions` لا `orders`: فاتورةٌ بلا قيدٍ
     * تظهر تكلفةً بلا إيراد. انظر `finance:repair-order-income`.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * ما بيع فعلًا: لا سلّةً معلّقة ولا طلبًا ملغى.
     *
     * كان الشرط يُكتب بيدٍ في كل استعلام على حدة، فكُتب في ثلاثة مواضع
     * ونُسي في أحدٍ وثلاثين: بطاقات التقارير تجمع الملغى والمخطّط تحتها
     * يستثنيه، فتقرأ الشاشةُ الواحدة رقمين متناقضين عن الفترة نفسها.
     * وأخطرها الإقرار الضريبي — ضريبةٌ تُقرّ على بيعةٍ أُلغيت.
     *
     * فصار موضعًا واحدًا يقرأ منه الجميع: من نسي «الملغى» لا يستطيع أن
     * ينساه، لأن النطاق يحمله معه.
     */
    public function scopeSold($query)
    {
        return $query->where('status', '!=', self::CANCELLED);
    }
}
