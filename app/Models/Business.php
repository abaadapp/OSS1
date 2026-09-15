<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Business extends Model
{
    protected $guarded = [];
    protected $casts = [
        'is_demo' => 'boolean',
        // ختمُ التاجر على هويّة متجره — انظر `Support\ShopIdentity`
        'identity_confirmed_at' => 'datetime',
    ];

    /**
     * المتجر تاجرٌ حقيقيّ ما لم يُوسَم تجريبيًّا — والقيمة هنا لا في القاعدة
     * وحدها: قيمة العمود الافتراضية لا تصل إلى النموذج المُنشأ في الذاكرة،
     * فمتجرٌ يُنشأ ويُسأل عن حاله في الطلب نفسه كان يُجيب بـnull.
     */
    protected $attributes = ['is_demo' => false];

    /**
     * ما يُنسخ من ملفّ الشركة ويُستعاد — ولا شيء سواه.
     *
     * حالُ الحساب ليست منها: استعادةُ ملفٍّ قديم كانت ستُعيد تشغيل حسابٍ
     * أُوقف. وموضعٌ واحد يقرؤه بناءُ النسخة والاستعادة معًا: قائمتان
     * تفترقان يومًا، فيُنسخ حقلٌ ولا يُستعاد.
     */
    public const BACKUP_FIELDS = [
        'name', 'type', 'owner_name', 'phone', 'email',
        'country', 'city', 'address', 'logo',
        // والإقرارُ بالهويّة معها: استعادةُ متجرٍ مكتملٍ لا تُعيد سؤاله عن اسمه
        'identity_confirmed_at',
    ];

    /** الشركات الحقيقيّة وحدها — دون ما وُسم بيانَ عرض */
    public function scopeReal($query)
    {
        return $query->where('is_demo', false);
    }

    /** الشركات التجريبيّة وحدها */
    public function scopeDemo($query)
    {
        return $query->where('is_demo', true);
    }

    /**
     * رابط الشعار — أو لا شيء.
     *
     * كان يردّ صورةً من `picsum.photos` حين لا شعار: صورةٌ عشوائيّة من
     * الإنترنت تُعرض علامةً تجاريّةً لتاجرٍ لم يرفع شيئًا — على فاتورته
     * وفي رأس موقعه وفي قائمة المنصّة. ثلاثةُ أعطالٍ في سطر:
     *
     *  ١) بياناتٌ وهميّة في حساب تاجر — وهي القاعدة التي لا تُخرَق هنا.
     *  ٢) `BackupService` يقرأ الخاصيّة لا العمود، فتُكتب الصورة في النسخة
     *     الاحتياطية ثمّ تُستعاد قيمةً حقيقيّة: «لا شعار» تصير رابطًا ثابتًا
     *     لصورةِ غريبٍ لا يُمحى إلا باليد.
     *  ٣) تبعيّةٌ لخادمٍ خارجيّ في كل صفحة: يسقط أو يُحجب فتظهر صورٌ مكسورة.
     *
     * والفراغ يُقال فراغًا: الشاشات تعرض حرفَ الاسم أو مربّعًا خافتًا مكانه،
     * وهي تحتمل `null` أصلًا.
     */
    public function getLogoAttribute($value): ?string
    {
        if (! $value) {
            return null;
        }

        if (str_starts_with($value, 'http')) {
            return $value;
        }

        return \Illuminate\Support\Facades\Storage::url($value);
    }

    public function users(): HasMany { return $this->hasMany(User::class); }
    public function branches(): HasMany { return $this->hasMany(Branch::class); }
    public function categories(): HasMany { return $this->hasMany(Category::class); }
    public function products(): HasMany { return $this->hasMany(Product::class); }
    public function customers(): HasMany { return $this->hasMany(Customer::class); }
    public function orders(): HasMany { return $this->hasMany(Order::class); }
    public function expenses(): HasMany { return $this->hasMany(Expense::class); }
    public function transactions(): HasMany { return $this->hasMany(Transaction::class); }
}
