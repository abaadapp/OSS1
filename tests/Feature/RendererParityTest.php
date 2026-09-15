<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * المعاينة والموقع يُرسمان بالشيفرة نفسها.
 *
 * `resources/js/Pages/Admin/Website/preview/renderer/` ليست نسخةً «مأخوذة
 * عن» عارض المتاجر — هي هو، تُنسخ إليه بأمرٍ واحد من `storefront/`
 * (`node storefront/scripts/sync-renderer.mjs`). والبصمة المسجَّلة بجانبها هي
 * ما يمنع الافتراق: من عدّل ملفًّا هنا بيده ولم يُعدّل الأصل يسقط هذا
 * الاختبار.
 *
 * ولماذا كلّ هذا؟ لأنّ الافتراق يظهر في أسوأ موضعٍ ممكن: التاجر يرى في
 * معاينته موقعًا، ثمّ ينشر، فيرى زبونُه موقعًا آخر. وهو لا يشكّ في المعاينة
 * — يشكّ في نفسه.
 */
class RendererParityTest extends TestCase
{
    /** البصمة نفسها التي يحسبها `scripts/renderer-hash.mjs` */
    private function fingerprint(string $dir): string
    {
        $files = array_values(array_filter(
            scandir($dir) ?: [],
            fn ($f) => (bool) preg_match('/\.(tsx?|css)$/', $f),
        ));
        sort($files);

        $context = hash_init('sha256');

        foreach ($files as $file) {
            hash_update($context, $file);
            hash_update($context, "\0");
            hash_update($context, (string) file_get_contents($dir.'/'.$file));
            hash_update($context, "\0");
        }

        return substr(hash_final($context), 0, 16);
    }

    public function test_the_vendored_renderer_matches_its_recorded_fingerprint(): void
    {
        $dir = $this->dir();

        $this->assertFileExists($dir.'/RENDERER_HASH', 'طبقة الرسم غير منسوخة — شغّل: node storefront/scripts/sync-renderer.mjs');

        $recorded = trim((string) file_get_contents($dir.'/RENDERER_HASH'));

        $this->assertSame(
            $recorded,
            $this->fingerprint($dir),
            'طبقة الرسم عُدّلت هنا بلا مزامنة: عدّلها في storefront/src/site ثمّ شغّل `node storefront/scripts/sync-renderer.mjs`',
        );
    }
    public function test_the_renderer_carries_no_dependency_beyond_react(): void
    {
        /*
         * وهذا شرط بقائها مشتركة.
         *
         * أيّ استيرادٍ من `@/…` أو من حزمةٍ يجعلها تعمل في أحد المستودعين
         * دون الآخر — فتُنسخ فتنكسر، فتُصلَح بيدها هناك، فتفترق.
         */
        foreach (glob($this->dir().'/*.{ts,tsx}', GLOB_BRACE) ?: [] as $file) {
            preg_match_all("/from '([^']+)'/", (string) file_get_contents($file), $m);

            foreach ($m[1] as $import) {
                $allowed = str_starts_with($import, '.') || $import === 'react';

                $this->assertTrue($allowed, basename($file).' تستورد «'.$import.'» — وطبقة الرسم بلا تبعيّات');
            }
        }
    }
}
