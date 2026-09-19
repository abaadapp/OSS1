<?php

namespace App\Support\Import\Readers;

use Generator;

/**
 * قارئُ ملفٍّ تاريخيّ — يمشي على الصفوف ولا يحمّلها كلَّها.
 *
 * كلُّ قارئ يعطي رأسَ الجدول مرّةً، ثمّ صفوفَه واحدًا واحدًا برقم صفّه في
 * المصدر (رقمُ السطر بعد الرأس، يبدأ من 1). والخليّةُ تُعطى **كما هي**:
 * نصًّا أو رقمًا؛ ومعادلةُ الجدول تُعطى نصَّها لا ناتجَها — لا شيءَ يُحسب
 * على الخادم من ملفٍّ لا يُوثَق به.
 */
interface Reader
{
    /** @return list<string> */
    public function headers(): array;

    /**
     * @return Generator<int, list<string|int|float|null>> رقمُ الصفّ ⇽ خلاياه بترتيب الرأس
     */
    public function rows(): Generator;

    /** عددُ الأعمدة في الرأس */
    public function columnCount(): int;
}
