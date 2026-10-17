<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * المورّدُ يقول ما يقدّمه بكلماته — «تصوير فوتوغرافي»، «شاشات LED»، «تأجير كراسي».
 *
 * كان النوعُ قائمةً مغلقة (`supplier_type`) والخدماتُ كتالوجًا يُنشأ قبل أن يُختار
 * منه (`service_categories`). فصار نصًّا حرًّا في `suppliers.service` يُكتب في
 * نموذج المورّد ويُبحث به.
 *
 * ولا يُمحى شيء: العمودُ القديم والكتالوجُ وروابطُه تبقى بصفوفها، وكلُّ مورّدٍ
 * سابق يُكتب له نصُّه مرّةً من خدماته المربوطة، وإلّا من اسم نوعه — فيُعرض كما
 * كان يُعرض قبل التحويل.
 */
return new class extends Migration
{
    /** أسماءُ الأنواع كما كانت تُعرض — مكتوبةً هنا لا مقروءةً من صنفٍ قد يتغيّر */
    private const TYPE_LABELS = [
        'products' => 'مورد منتجات',
        'services' => 'مقدم خدمات',
        'both' => 'منتجات وخدمات',
    ];

    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('service', 255)->nullable();
        });

        DB::table('suppliers')->select(['id', 'supplier_type'])->orderBy('id')->chunkById(500, function ($suppliers) {
            $services = DB::table('service_category_supplier as l')
                ->join('service_categories as c', 'c.id', '=', 'l.service_category_id')
                ->whereIn('l.supplier_id', $suppliers->pluck('id'))
                ->orderBy('c.sort_order')->orderBy('c.name')
                ->get(['l.supplier_id', 'c.name'])
                ->groupBy('supplier_id');

            foreach ($suppliers as $supplier) {
                $names = ($services[$supplier->id] ?? collect())->pluck('name')->all();
                $text = $names !== []
                    ? implode('، ', $names)
                    : (self::TYPE_LABELS[$supplier->supplier_type ?: 'products'] ?? null);

                if ($text !== null) {
                    DB::table('suppliers')->where('id', $supplier->id)->update(['service' => mb_substr($text, 0, 255)]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('service');
        });
    }
};
