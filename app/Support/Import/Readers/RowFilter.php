<?php

namespace App\Support\Import\Readers;

use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/** مرشّحُ الصفوف: يحمّل ما بين حدَّين فقط — فالملفُّ الكبير يُقرأ دفعةً دفعة */
final class RowFilter implements IReadFilter
{
    public function __construct(private readonly int $from, private readonly int $to) {}

    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
    {
        return $row >= $this->from && $row <= $this->to;
    }
}
