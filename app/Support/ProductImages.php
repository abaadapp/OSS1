<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * ملفّاتُ صور المنتج القديمة — ما يحفظها لا ما يرفعها.
 *
 * الصورُ خرجت من تجربة المنتج: لا رفعَ ولا معرضَ ولا صورةَ في القائمة أو
 * الصفحة — بقايا متجر التجزئة لا كتالوجُ معارضَ وفعاليّات. والقاعدةُ القديمة
 * باقيةٌ لما في القاعدة والقرص: **الرئيسية في `products.image`، وما سواها في
 * `product_images`** — لا تُمحى لمجرّد أنّها لم تعد تُعرض.
 *
 * وبقي بابان يحرسانها: النسخُ يملك ملفَّه فلا يتقاسم منتجان ملفًّا واحدًا،
 * والمحوُ النهائيّ من السلّة يمحو ملفّاتِ منتجه وحده لا ما يشير إليه غيرُه.
 */
class ProductImages
{
    private const DISK = 'public';

    private const FOLDER = 'products';

    /**
     * نسخةٌ خاصّةٌ من صورة المنتج الرئيسية — لِما يُنسخ منه منتجٌ جديد.
     *
     * ولا يُنسخ المسار كما هو: منتجان يشيران إلى ملفٍّ واحد، فمحوُ أحدهما
     * نهائيًّا يمحو الملفّ ويترك الآخر — وهو حيٌّ معروض — بصورةٍ مكسورة.
     *
     * وملفٌّ مفقودٌ على القرص لا يُنسخ ولا يُنسَخ مسارُه: نسخةٌ بمسارٍ إلى
     * لا شيء تُعرض مربّعًا مكسورًا، والفراغُ يُعرض حالتَه الفارغة.
     */
    public static function copyMainFile(Product $source): ?string
    {
        $path = $source->getRawOriginal('image');

        if (blank($path)) {
            return null;
        }

        // ‏رابطٌ خارجيّ ليس ملفًّا عندنا: يُنسخ نصُّه ولا يمحوه محوُ منتج
        if (str_starts_with((string) $path, 'http')) {
            return (string) $path;
        }

        if (! Storage::disk(self::DISK)->exists($path)) {
            return null;
        }

        $extension = pathinfo((string) $path, PATHINFO_EXTENSION);
        $copy = self::FOLDER.'/'.Str::random(40).($extension ? '.'.$extension : '');

        return Storage::disk(self::DISK)->copy($path, $copy) ? $copy : null;
    }

    /**
     * ملفّاتُ المنتج التي تُمحى معه — الرئيسية والمعرض.
     *
     * لمن يمحو المنتج نهائيًّا: الصفوف يأخذها قيدُ المفتاح، والملفّات لا
     * يأخذها شيء. ومتجرٌ يمحو موسمه كلَّه يترك عشرات الميغابايت لصورٍ لا
     * صفَّ يشير إليها — عطبٌ صامت لا يظهر إلا بعدّ ما على القرص.
     *
     * ═══ وملفٌّ يشير إليه منتجٌ آخر لا يُمحى ═══
     *
     * «نسخُ منتج» كان ينسخ مسار الصورة كما هو، فيتقاسم المنتجان ملفًّا
     * واحدًا. ثمّ يُحذف أحدهما وتمرّ عليه سلّةُ المحذوفات بعد تسعين يومًا
     * فيُمحى الملفّ — فيفقد **الآخر، وهو حيٌّ معروضٌ في المتجر**، صورتَه.
     * ولا يُعرف السبب: لا أحد لمس ذلك المنتج، والصفحةُ تعرض مربّعًا مكسورًا
     * لا حالةً فارغة، لأنّ عموده ما زال يحمل مسارًا.
     *
     * والنسخُ صار يملك ملفَّه (انظر `ProductController::duplicate`) — وهذا
     * لما نُسخ قبل ذلك.
     *
     * @return list<string>
     */
    public static function files(Product $product): array
    {
        $mine = array_values(array_filter([
            $product->getRawOriginal('image'),
            ...ProductImage::where('product_id', $product->id)->pluck('path')->all(),
        ], fn ($path) => filled($path) && ! str_starts_with((string) $path, 'http')));

        if ($mine === []) {
            return [];
        }

        /*
         * ما يشير إليه غيرُه — منتجًا كان أو صورةَ معرضٍ لمنتجٍ آخر — يبقى.
         *
         * والقراءةُ من مُنشئ الاستعلام لا من النموذج: `pluck('image')` على
         * `Product` تمرّ بالقارئ فتردّ «‎/storage/…‎» لا المسار الخام، فلا
         * يطابق شيئًا — وتُمحى الملفّات المشتركة كما لو لم يكن هناك حارس.
         * والمحذوفُ مشمول: صفٌّ في السلّة ما زال يشير إلى ملفّه.
         */
        $shared = DB::table('products')
            ->where('business_id', $product->business_id)
            ->where('id', '!=', $product->id)
            ->whereIn('image', $mine)
            ->pluck('image')
            ->merge(
                DB::table('product_images')
                    ->where('business_id', $product->business_id)
                    ->where('product_id', '!=', $product->id)
                    ->whereIn('path', $mine)
                    ->pluck('path')
            )
            ->all();

        return array_values(array_diff($mine, $shared));
    }
}
