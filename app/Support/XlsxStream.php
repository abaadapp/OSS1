<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * ملفُّ إكسل يُكتب صفًّا صفًّا إلى القرص — لا يُبنى في الذاكرة.
 *
 * PhpSpreadsheet يبني كلَّ خليّةٍ كائنًا قبل أن يكتب حرفًا (~٢٥ م.ب لكلّ ألف
 * صفّ)، فكان لملفّ إكسل سقفٌ يبتر ما بعده. وملفُّ XLSX في حقيقته ملفّاتُ XML
 * في حزمةٍ مضغوطة: فتُكتب ورقتُه سطرًا سطرًا إلى ملفٍّ مؤقّت، وتُحزم في آخرها.
 * فالذاكرةُ سطرٌ واحد مهما طال الجدول، ولا سقفَ يبتر.
 *
 * ═══ والنصُّ نصٌّ لا معادلة ═══
 *
 * كلُّ نصٍّ يُكتب في جدول النصوص المشترك (`sharedStrings.xml`) وتُشير إليه خليّتُه
 * (`t="s"`) — وهذا النوعُ لا يُقرأ معادلةً أبدًا: اسمُ عميلٍ كتبه أحدٌ
 * «=HYPERLINK(…)» يبقى حروفًا كما كُتب. والجدولُ نفسُه يُكتب إلى القرص مع
 * الصفوف بلا فهرسٍ في الذاكرة (كلُّ نصٍّ مدخلٌ جديد، والضغطُ يطوي المكرَّر).
 * والرقمُ رقمٌ بتنسيق عموده (`#,##0.000` للمبلغ) يُجمع كما يُجمع.
 */
final class XlsxStream
{
    /* أنماطُ الخلايا — فهارسُ `cellXfs` في styles.xml أدناه */
    public const PLAIN = 0;

    public const TITLE = 1;

    public const BOLD = 2;

    public const HEAD = 3;

    public const MONEY = 4;

    public const NUMBER = 5;

    public const TOTAL = 6;

    public const TOTAL_MONEY = 7;

    public const TOTAL_NUMBER = 8;

    public const NOTE = 9;

    /** أقصى ما تحمله خليّةٌ في إكسل */
    private const CELL_MAX = 32767;

    /** @var list<array{name: string, path: string}> */
    private array $sheets = [];

    /** @var resource|null */
    private $handle = null;

    private int $row = 0;

    /** جدولُ النصوص المشترك — ملفٌّ مؤقّتٌ يُكتب مع الصفوف */
    private ?string $stringsPath = null;

    /** @var resource|null */
    private $strings = null;

    /** فهرسُ النصّ التالي في الجدول */
    private int $stringCount = 0;

    /**
     * يفتح ورقةً جديدة.
     *
     * @param  list<float|int>  $widths  عرضُ كلّ عمود
     * @param  int|null  $frozen  آخرُ صفٍّ يبقى ظاهرًا حين تُمرَّر الصفوف (رأسُ الجدول)
     */
    public function open(string $name, array $widths, ?int $frozen): void
    {
        $this->close();

        $path = tempnam(sys_get_temp_dir(), 'xlsx-sheet-');
        $handle = $path === false ? false : fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('تعذّر إنشاء ملفٍّ مؤقّت لورقة إكسل');
        }
        $this->handle = $handle;
        $this->sheets[] = ['name' => $this->uniqueName($name), 'path' => $path];
        $this->row = 0;

        $view = '<sheetView rightToLeft="1" workbookViewId="0"'.(count($this->sheets) === 1 ? ' tabSelected="1"' : '').'>';
        if ($frozen !== null && $frozen > 0) {
            $top = 'A'.($frozen + 1);
            $view .= '<pane ySplit="'.$frozen.'" topLeftCell="'.$top.'" activePane="bottomLeft" state="frozen"/>'
                .'<selection pane="bottomLeft" activeCell="'.$top.'" sqref="'.$top.'"/>';
        }
        $view .= '</sheetView>';

        $cols = '';
        foreach (array_values($widths) as $i => $width) {
            $cols .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.$width.'" customWidth="1"/>';
        }

        fwrite($handle, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheetViews>'.$view.'</sheetViews>'
            .'<sheetFormatPr defaultRowHeight="15"/>'
            .($cols !== '' ? '<cols>'.$cols.'</cols>' : '')
            .'<sheetData>');
    }

    /** رقمُ الصفّ التالي — لمن يحسب أين يتجمّد الرأس */
    public function nextRow(): int
    {
        return $this->row + 1;
    }

    /**
     * سطرٌ واحد.
     *
     * @param  list<mixed>  $cells
     * @param  int|list<int>  $styles  نمطٌ واحدٌ للسطر كلّه أو نمطٌ لكلّ خليّة
     */
    public function line(array $cells, int|array $styles = self::PLAIN): void
    {
        if ($this->handle === null) {
            throw new RuntimeException('لا ورقةَ مفتوحة');
        }

        $r = ++$this->row;
        $xml = '<row r="'.$r.'">';
        foreach (array_values($cells) as $i => $value) {
            $style = is_array($styles) ? ($styles[$i] ?? self::PLAIN) : $styles;
            $xml .= $this->cell(self::column($i + 1).$r, $value, $style);
        }
        fwrite($this->handle, $xml.'</row>');
    }

    /** سطرٌ فارغ */
    public function blank(): void
    {
        $this->row++;
    }

    /**
     * يحزم الأوراقَ في ملفٍّ واحد ويردّ مسارَه — والمؤقّتاتُ تُحذف.
     */
    public function finish(): string
    {
        $this->close();
        if ($this->sheets === []) {
            $this->open('Sheet1', [], null);
            $this->close();
        }

        $path = tempnam(sys_get_temp_dir(), 'xlsx-book-');
        $zip = new ZipArchive;
        if ($path === false || $zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('تعذّر إنشاء ملفّ إكسل');
        }

        $sheets = '';
        $rels = '';
        $types = '';
        foreach ($this->sheets as $i => $sheet) {
            $n = $i + 1;
            $sheets .= '<sheet name="'.self::escape($sheet['name']).'" sheetId="'.$n.'" r:id="rId'.$n.'"/>';
            $rels .= '<Relationship Id="rId'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
            $types .= '<Override PartName="/xl/worksheets/sheet'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $zip->addFile($sheet['path'], 'xl/worksheets/sheet'.$n.'.xml');
        }
        $styles = count($this->sheets) + 1;
        $shared = $styles + 1;

        $this->strings();
        fwrite($this->strings, '</sst>');
        fclose($this->strings);
        $this->strings = null;
        $zip->addFile((string) $this->stringsPath, 'xl/sharedStrings.xml');

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            .$types.'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<bookViews><workbookView activeTab="0"/></bookViews><sheets>'.$sheets.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels
            .'<Relationship Id="rId'.$styles.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'<Relationship Id="rId'.$shared.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/styles.xml', self::STYLES);

        // الحزمُ يقرأ المؤقّتاتِ عند الإغلاق — فلا تُحذف قبله
        $closed = $zip->close();
        foreach ($this->sheets as $sheet) {
            @unlink($sheet['path']);
        }
        @unlink((string) $this->stringsPath);
        $this->sheets = [];
        $this->stringsPath = null;
        $this->stringCount = 0;
        if (! $closed) {
            @unlink($path);
            throw new RuntimeException('تعذّر حزم ملفّ إكسل');
        }

        return $path;
    }

    private function close(): void
    {
        if ($this->handle !== null) {
            fwrite($this->handle, '</sheetData></worksheet>');
            fclose($this->handle);
            $this->handle = null;
        }
    }

    /** خليّةٌ بنوعها: الرقمُ رقم، وما سواه نصٌّ من الجدول المشترك لا يُقرأ معادلة */
    private function cell(string $ref, mixed $value, int $style): string
    {
        $s = $style !== self::PLAIN ? ' s="'.$style.'"' : '';

        if ((is_int($value) || is_float($value)) && is_finite((float) $value)) {
            return '<c r="'.$ref.'"'.$s.'><v>'.(is_int($value) ? $value : self::float($value)).'</v></c>';
        }

        $text = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        if ($text === '') {
            return $s === '' ? '' : '<c r="'.$ref.'"'.$s.'/>';
        }

        fwrite($this->strings(), '<si><t xml:space="preserve">'.self::escape(mb_substr($text, 0, self::CELL_MAX)).'</t></si>');

        return '<c r="'.$ref.'" t="s"'.$s.'><v>'.($this->stringCount++).'</v></c>';
    }

    /** ملفُّ جدول النصوص — يُفتح عند أوّل نصّ
     *
     * @return resource
     */
    private function strings()
    {
        if ($this->strings === null) {
            $path = tempnam(sys_get_temp_dir(), 'xlsx-strings-');
            $handle = $path === false ? false : fopen($path, 'wb');
            if ($handle === false) {
                throw new RuntimeException('تعذّر إنشاء ملفٍّ مؤقّت لنصوص إكسل');
            }
            $this->stringsPath = $path;
            $this->strings = $handle;
            fwrite($handle, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
                .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">');
        }

        return $this->strings;
    }

    /** الرقمُ العشريّ بنقطةٍ مهما كانت لغةُ الخادم، وبأقصر صورةٍ تعود إليه بعينه */
    private static function float(float $value): string
    {
        return (string) json_encode($value);
    }

    /** النصُّ لـXML: رموزُه مهرَّبة، ومحارفُ التحكّم التي يرفضها إكسل محذوفة */
    private static function escape(string $text): string
    {
        $text = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text);
    }

    /** حرفُ العمود: ١ ← A، ٢٧ ← AA */
    public static function column(int $index): string
    {
        $letters = '';
        while ($index > 0) {
            $index--;
            $letters = chr(65 + $index % 26).$letters;
            $index = intdiv($index, 26);
        }

        return $letters;
    }

    /** اسمُ اللسان: ٣١ محرفًا على الأكثر، بلا رموزٍ يرفضها إكسل، ولا يتكرّر */
    private function uniqueName(string $name): string
    {
        $clean = trim(str_replace(['/', '\\', '?', '*', '[', ']', ':'], ' ', $name), " '");
        $clean = mb_substr($clean !== '' ? $clean : 'Sheet', 0, 31);

        $taken = array_map(fn ($s) => mb_strtolower($s['name']), $this->sheets);
        $candidate = $clean;
        for ($i = 2; in_array(mb_strtolower($candidate), $taken, true); $i++) {
            $candidate = mb_substr($clean, 0, 31 - strlen(' '.$i)).' '.$i;
        }

        return $candidate;
    }

    private const STYLES = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
        .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<numFmts count="2"><numFmt numFmtId="164" formatCode="#,##0.000"/><numFmt numFmtId="165" formatCode="#,##0.###"/></numFmts>'
        .'<fonts count="5">'
        .'<font><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
        .'<font><b/><sz val="14"/><name val="Calibri"/><family val="2"/></font>'
        .'<font><b/><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
        .'<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/><family val="2"/></font>'
        .'<font><b/><sz val="11"/><color rgb="FF9A3412"/><name val="Calibri"/><family val="2"/></font>'
        .'</fonts>'
        .'<fills count="4">'
        .'<fill><patternFill patternType="none"/></fill>'
        .'<fill><patternFill patternType="gray125"/></fill>'
        .'<fill><patternFill patternType="solid"><fgColor rgb="FF111111"/><bgColor indexed="64"/></patternFill></fill>'
        .'<fill><patternFill patternType="solid"><fgColor rgb="FFF3F4F6"/><bgColor indexed="64"/></patternFill></fill>'
        .'</fills>'
        .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        .'<cellXfs count="10">'
        .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        .'<xf numFmtId="0" fontId="3" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="right"/></xf>'
        .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        .'<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        .'<xf numFmtId="0" fontId="2" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
        .'<xf numFmtId="164" fontId="2" fillId="3" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1"/>'
        .'<xf numFmtId="165" fontId="2" fillId="3" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1"/>'
        .'<xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        .'</cellXfs>'
        .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        .'</styleSheet>';
}
