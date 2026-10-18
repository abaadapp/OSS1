<?php

namespace App\Support\Exports;

use App\Support\ReportPeriod;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * قائمةٌ تشغيليّة تُصدَّر — بالاستعلام نفسِه الذي تقرؤه شاشتُها.
 *
 * كلُّ شاشةٍ (الحركة المالية، الفواتير، المشاريع…) تبني قائمتَها من دالّةٍ
 * واحدةٍ في متحكّمها (`listQuery`)، والتصديرُ يستدعي الدالّةَ نفسَها بالرابط
 * نفسِه. فلا يخرج ملفٌّ بفلترٍ غير الذي على الشاشة — كان ملفُّ «الحركة المالية»
 * يقرأ الشهرَ الجاري مهما كتب التاجرُ في البحث والتاريخ.
 *
 * والفرقُ الوحيد: الشاشةُ مرقَّمة (عشرون صفًّا)، والملفُّ يحمل كلَّ ما طابق حتى
 * سقف صيغته (`TableExport::limit`) — ويقول ذلك إن بُلغ.
 */
abstract class Dataset
{
    /** عنوانُ الملفّ — اسمُ الشاشة */
    abstract public function title(): string;

    /** @return list<array{key: string, label: string, kind: string}> */
    abstract public function columns(Request $request): array;

    /**
     * الصفوفُ ومجموعُها ومجاميعُها وبطاقاتُها.
     *
     * @return array{rows: iterable<array<string, mixed>>, total: int, shown?: int, totals?: ?array, cards?: list<array{label: string, value: string}>}
     */
    abstract public function data(Request $request, int $bid, int $limit): array;

    /** الإذنُ فوق قسم المسار (حارسُ المسار يقيس القسمَ باسمه) — 403 */
    public function authorize(Request $request): void {}

    /** اسمُ الملفّ بلا امتداد */
    public function filename(Request $request): string
    {
        return static::slug().'-'.now()->format('Y-m-d');
    }

    /** مفتاحُ القائمة في الروابط وأسماء الملفّات */
    public static function slug(): string
    {
        return 'export';
    }

    /** الحدّان من الرابط — يُفحصان قبل أن يُقرآ: الخاطئُ يُردّ بـ422 لا بملفٍّ للتاريخ كلّه */
    public function validate(Request $request): void
    {
        $error = ReportPeriod::checkOpen(self::param($request, 'from'), self::param($request, 'to'));
        abort_if($error !== null, 422, (string) $error);
    }

    /** ما يُكتب في الترويسة عن المدّة */
    public function periodLabel(Request $request): string
    {
        return ReportPeriod::openLabel(self::param($request, 'from'), self::param($request, 'to'));
    }

    /** المرشّحاتُ المطبَّقة بأسمائها — @return list<string> */
    public function filterLines(Request $request): array
    {
        return [];
    }

    /** قيمةُ معاملٍ نصّيّةٌ غيرُ فارغة — أو null */
    public static function param(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * يومٌ من الرابط بصيغته الصارمة (سنة-شهر-يوم) — أو null.
     *
     * الشاشةُ تتجاهل التاريخَ الخاطئ ولا تسقط به (كان يُمرَّر إلى `whereDate` كما
     * هو فيرفضه PostgreSQL خطأَ خادم)، والتصديرُ يردّه بـ422 قبل أن يبلغ هنا (`validate`).
     */
    public static function day(Request $request, string $key): ?string
    {
        $value = self::param($request, $key);

        return $value !== null && ReportPeriod::checkOpen($value, null) === null ? $value : null;
    }

    /**
     * الصفوفُ من استعلامٍ مرشَّح — كلُّ ما طابق حتى السقف، دفعةً بعد دفعة.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    protected function fromQuery(Builder $query, int $limit, callable $row): array
    {
        $total = (clone $query)->toBase()->getCountForPagination();
        $rows = [];

        /*
         * دفعاتٌ بإزاحةٍ يُكتب سقفُها بيد: `lazy()` يكتب حدَّه فوق حدّ الاستعلام
         * فيضيع السقف، و`cursor()` يُسقط التحميلَ المسبق (`with`) فيسأل عن كلّ صفٍّ وحده.
         */
        for ($offset = 0, $want = min($total, $limit); $offset < $want; $offset += 500) {
            foreach ((clone $query)->offset($offset)->limit(min(500, $want - $offset))->get() as $model) {
                $rows[] = $row($model);
            }
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /** عمودٌ بوصفه */
    protected static function col(string $key, string $label, string $kind = 'text'): array
    {
        return ['key' => $key, 'label' => __($label), 'kind' => $kind];
    }
}
