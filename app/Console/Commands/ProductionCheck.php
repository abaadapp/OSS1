<?php

namespace App\Console\Commands;

/**
 * `php artisan eventplus:production-check` — الاسمُ الذي تطلبه كتبُ التشغيل (المرحلة 12 §66).
 *
 * هو `eventplus:preflight` نفسُه: فحصٌ واحد باسمين، كي لا يفترق ما يقرؤه
 * المهندسُ في docs/GO_LIVE_CHECKLIST.md عمّا يعرفه الخادم.
 */
class ProductionCheck extends Preflight
{
    protected $signature = 'eventplus:production-check';

    protected $description = 'فحص جاهزية الإنتاج — الاسم البديل لـ eventplus:preflight';
}
