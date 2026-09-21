<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

/**
 * لا خليّةَ في ملفٍّ نصدّره تصير معادلة — المرحلة 12 §50.
 *
 * اسمُ عميلٍ كُتب `=HYPERLINK(...)` أو `=cmd|...` كان يُكتب في XLSX خليّةَ
 * معادلةٍ فينفّذه إكسل على جهاز من يفتح التقرير. والرابطُ الافتراضيّ في
 * PhpSpreadsheet يعدّ كلَّ نصٍّ يبدأ بـ«=» معادلةً؛ فيُستبدل به هذا: ما بدأ
 * بحرفٍ من حروف المعادلات يُكتب نصًّا صريحًا — كما هو، بلا تنفيذ.
 *
 * ويُسجَّل مرّةً في مزوّد الخدمة فيصيب كلَّ تصديرٍ في النظام.
 */
final class SafeCellBinder extends DefaultValueBinder
{
    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value) && Sheet::looksLikeFormula($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
