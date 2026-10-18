<?php

namespace App\Support\Exports;

use App\Support\ReportPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * قائمةٌ تشغيليّة تُصدَّر — بالاستعلام نفسِه الذي تقرؤه شاشتُها.
 *
 * كلُّ شاشةٍ (الحركة المالية، الفواتير، المشاريع…) تبني قائمتَها من دالّةٍ
 * واحدةٍ في متحكّمها (`listQuery`)، والتصديرُ يستدعي الدالّةَ نفسَها بالرابط
 * نفسِه. فلا يخرج ملفٌّ بفلترٍ غير الذي على الشاشة — كان ملفُّ «الحركة المالية»
 * يقرأ الشهرَ الجاري مهما كتب التاجرُ في البحث والتاريخ.
 *
 * والفرقُ الوحيد: الشاشةُ مرقَّمة (عشرون صفًّا)، والملفُّ يحمل كلَّ ما طابق بلا
 * سقف — دفعةً بعد دفعة (`Rows`). وPDF وحده له حدٌّ يُقال قبل التنزيل (`TableExport::pdfCut`).
 */
abstract class Dataset
{
    /** عنوانُ الملفّ — اسمُ الشاشة */
    abstract public function title(): string;

    /** @return list<array{key: string, label: string, kind: string}> */
    abstract public function columns(Request $request): array;

    /**
     * الصفوفُ ومجاميعُها وبطاقاتُها — والصفوفُ كلُّ ما طابق (`Rows` أو مصفوفة)،
     * يُعدّ بـ`count()` قبل أن يُقرأ.
     *
     * @return array{rows: Rows|list<array<string, mixed>>, totals?: array|\Closure|null, cards?: list<array{label: string, value: string}>}
     */
    abstract public function data(Request $request, int $bid): array;

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

    /** سطرُ المدّة كاملًا لقائمةٍ بلا فترة — الرصيدُ الحاليّ (`TableExport::periodLine`) */
    public function periodLine(Request $request): ?string
    {
        return null;
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
     * الصفوفُ من استعلامٍ مرشَّح — كلُّ ما طابق، دفعةً بعد دفعة بتحميلها المسبق (`with`).
     *
     * @return array{rows: Rows}
     */
    protected function fromQuery(Builder $query, callable $row): array
    {
        return ['rows' => Rows::each($query, $row)];
    }

    /** عمودٌ بوصفه */
    protected static function col(string $key, string $label, string $kind = 'text'): array
    {
        return ['key' => $key, 'label' => __($label), 'kind' => $kind];
    }
}
