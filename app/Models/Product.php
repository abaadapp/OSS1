<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    // الحذف يُخفي ولا يمحو — انظر الهجرة add_soft_deletes_to_products_and_expenses
    use SoftDeletes;

    /** عتادٌ يُشترى ويُستهلك — كميّتُه تُتابَع على الرفّ */
    public const ITEM = 'item';

    /** عملٌ يُباع بالوقت أو بالمهمّة — لا رفَّ له ولا كميّة */
    public const SERVICE = 'service';

    /** باقةٌ تحوي بنودًا — انظر `PackageItem` */
    public const PACKAGE = 'package';

    /**
     * أنواعُ الكتالوج الثلاثة — مصدرٌ واحد يقرؤه التحقّقُ والشاشةُ معًا.
     *
     * وقائمتان تُكتبان باليد تفترقان يومًا: يُقبل نوعٌ في الحفظ ولا يُعرض
     * في القائمة، أو يُعرض ولا يُقبل.
     *
     * @var list<string>
     */
    public const KINDS = [self::ITEM, self::SERVICE, self::PACKAGE];

    /**
     * ما لا رفَّ له — فلا كميّةَ تُتابَع ولا تنبيهَ نفادٍ يُرفع.
     *
     * @var list<string>
     */
    public const UNSTOCKED = [self::SERVICE, self::PACKAGE];

    protected $guarded = [];

    protected $casts = ['price' => 'decimal:3', 'cost' => 'decimal:3', 'active' => 'boolean'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** مقاسات هذا المنتج — الفارغة تعني منتجًا بسيطًا يُباع بسعره */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order')->orderBy('id');
    }

    /** مكوّناته ومكوّنات مقاساته جميعًا — التصفية على المقاس في Recipe */
    public function recipeItems(): HasMany
    {
        return $this->hasMany(RecipeItem::class)->orderBy('id');
    }

    /**
     * بنودُ الباقة — وليست وصفتَها.
     *
     * الوصفةُ تقول «ما يُخصم من الرفّ حين يُباع»، والباقةُ تقول «ما يشمله
     * هذا العرض». وقد يجتمعان في منتجٍ واحد ولا يعني أحدُهما الآخر.
     */
    public function packageItems(): HasMany
    {
        return $this->hasMany(PackageItem::class, 'package_id')
            ->orderBy('sort_order')->orderBy('id');
    }

    /** أهذا خدمةٌ أو باقة؟ — فلا مخزونَ له */
    public function isStocked(): bool
    {
        return ! in_array($this->kind ?? self::ITEM, self::UNSTOCKED, true);
    }

    /** ما يُتابَع على الرفّ وحده */
    public function scopeStocked($query)
    {
        return $query->whereNotIn('kind', self::UNSTOCKED);
    }

    /** أصنافٌ من نوعٍ بعينه — والمجهولُ لا يردّ الجدولَ كلَّه */
    public function scopeOfKind($query, string $kind)
    {
        return in_array($kind, self::KINDS, true)
            ? $query->where('kind', $kind)
            : $query->whereRaw('1 = 0');
    }

    /** الإضافات المسموحة معه — الفارغة تعني «كلّ إضافات المتجر» لا «لا شيء» */
    public function allowedAddons(): BelongsToMany
    {
        return $this->belongsToMany(Addon::class, 'product_addons')
            ->withPivot('sort_order')->withTimestamps();
    }

    /** بنود الطلبات التي بيع فيها — يقرؤها مرشّح «الراكد» */
    /**
     * الصور الإضافية — والرئيسية ليست منها، هي في `image`.
     *
     * مرتّبةٌ بموضعها ثمّ بمعرّفها: ترتيبان متساويان يجب أن يُقرآ بالترتيب
     * نفسه في كلّ مرّة، وإلّا تحرّكت الصور في الشاشة بلا سبب.
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    // مفاتيح متوافقة مع الواجهات (Demo shape)
    public function getQtyAttribute()
    {
        return $this->quantity;
    }

    public function getAlertAttribute()
    {
        return $this->alert_qty;
    }

    public function getCatAttribute()
    {
        return $this->category?->name;
    }

    public function getStockStatusAttribute(): string
    {
        return self::statusFor((int) $this->quantity, (int) $this->alert_qty);
    }

    /**
     * نسبة خصم الصنف — مقيَّدة بين صفر ومئة.
     *
     * كان الحقل يقبل «٥٠٠» ويُحفظ كما هو. القيد في التحقّق يمنع القادم،
     * والقصّ هنا يحمي ممّا حُفظ قبله: سطرُ فاتورةٍ بسالب أسوأ من خصمٍ ناقص.
     */
    public function discountRate(): float
    {
        return max(0.0, min(100.0, (float) $this->discount));
    }

    /**
     * سعر البيع الفعليّ بعد خصم الصنف.
     *
     * كان حقل «الخصم (%)» في نموذج المنتج لا يقرؤه أحد: البيع يقرأ السعر
     * وحده. فمن وضع خصمًا على صنفٍ ثم باعه بكامل سعره لا يعلم — والمقبض
     * غير الموصول أسوأ من غيابه لأنه يطمئن.
     */
    public function sellingPrice(): float
    {
        return round((float) $this->price * (1 - $this->discountRate() / 100), 3);
    }

    /**
     * نسبة ضريبة الصنف حين تختلف عن نسبة المتجر.
     *
     * حاجةٌ حقيقية لا رفاهية: الخبز والحليب والدواء صفرية في عُمان، وكانت
     * الفاتورة تحسب على الجميع نسبةَ الإعداد العامّ.
     */
    public function taxRate(float $default): float
    {
        return $this->tax === null ? $default : max(0.0, min(100.0, (float) $this->tax));
    }

    /**
     * حالة المخزون لأي كمية — لا لكمية المنتج الإجمالية وحدها.
     *
     * نقطة البيع تحكم على رصيد الفرع لا على مجموع الشركة، فتحتاج القاعدة
     * نفسها مطبَّقة على رقم آخر. تركُها مكرّرة في موضعين يعني أن تغيير حدّ
     * التنبيه يومًا ما يُطبَّق في أحدهما فقط.
     */
    public static function statusFor(int $quantity, int $alertQty): string
    {
        if ($quantity <= 0) {
            return 'نفد المخزون';
        }

        return $quantity < $alertQty ? 'منخفض' : 'متوفر';
    }

    /**
     * رابط الصورة: يدعم الروابط الخارجية والملفات المرفوعة.
     *
     * ═══ ومنتجٌ بلا صورة يقول «بلا صورة» ═══
     *
     * كانت تردّ رابط `picsum.photos` — خدمةُ صورٍ عشوائيّة على الإنترنت —
     * لكلّ منتجٍ لم تُرفع له صورة. فيُعرض للتاجر ولزبونه **صورةُ شيءٍ لا صلة
     * له بالمنتج**: سيّارةٌ أو شاطئٌ أو وجهُ إنسان مكان «لانيارد». ولا يفرّق
     * أحدٌ بين صورةٍ رفعها التاجر وصورةٍ اخترعها النظام.
     *
     * وتُطلب من خادمٍ خارجيّ في كلّ فتحةِ شاشة: بطيئةٌ، وتنكسر إن حُجب أو
     * سقط، وتُخبر طرفًا ثالثًا بمن يتصفّح.
     *
     * والشاشاتُ كلُّها كانت تكتب حالتَها الفارغة أصلًا — مربّعٌ رماديّ في
     * القائمة و«📦» في نقطة البيع — ولا تقع أبدًا، لأنّ هذا السطر لا يُرجع
     * فارغًا قطّ. فرُدّ الفراغُ فراغًا، ووقعت الحالاتُ المكتوبة.
     *
     * والبديلُ المصنوع يبقى للمتجر التجريبيّ وحده: يُكتب في عموده صراحةً
     * عند بذره — انظر `DemoStore` — فهو بيانُ عرضٍ لا بيانُ تاجر.
     */
    public function getImageAttribute($value): string
    {
        if (! $value) {
            return '';
        }

        if (str_starts_with($value, 'http')) {
            return $value;
        }

        return Storage::url($value);
    }
}
