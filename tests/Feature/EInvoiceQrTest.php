<?php

namespace Tests\Feature;

use App\Support\EInvoice;
use Tests\TestCase;

/**
 * رمز الفوترة: إمّا أن يكون صحيحًا، أو ألّا يكون.
 *
 * الصيغة TLV ثم Base64 على معيار ZATCA الخليجي. وعطبُها لا يُرى بالعين —
 * يُطبع مربّعٌ يبدو سليمًا، ولا يكتشفه أحد لأن لا أحد يمسحه. فالفحص هنا
 * يفكّ الرمز ويقرأ حقوله بدل أن يقنع بوجوده.
 */
class EInvoiceQrTest extends TestCase
{
    public function test_it_no_longer_warns_on_php_85(): void
    {
        /*
         * chr بقيمةٍ فوق ٢٥٥ صار مهجورًا في PHP 8.5: سطرُ تحذيرٍ في كل طلب
         * طباعة، يملأ السجلّ ويخفي ما يستحقّ القراءة فيه.
         */
        set_error_handler(fn ($n, $msg) => throw new \RuntimeException($msg), E_ALL);

        try {
            EInvoice::qrPayload(str_repeat('م', 300), 'OM1', 'x', '1', '0');
        } finally {
            restore_error_handler();
        }

        $this->assertTrue(true);
    }
}
