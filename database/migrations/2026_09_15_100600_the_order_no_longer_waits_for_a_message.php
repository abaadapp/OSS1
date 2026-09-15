<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الطلب لم يعد ينتظر رسالةً تخرج عنه.
 *
 * ثلاثةُ أعمدةٍ كتبها تكاملان ذهبا:
 *
 *  • `review_request_sent_at` — «طُلب التقييم على Google مرّةً واحدة»،
 *    وحارسُ التكرار هذا لا معنى له بلا بابٍ يطلب.
 *  • `status_notice_at` و`status_notice_event` — «أُبلغ الزبونُ بحالة طلبه
 *    على واتساب»، يمنعان إرسال البلاغ مرّتين عن الحدث نفسه.
 *
 * ولا شيء يقرؤها أو يكتبها اليوم: تكاملُ ميتا حُذف بمتحكّماته وأوامره
 * وإعداداته، وتقييمُ Google معه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['review_request_sent_at', 'status_notice_at', 'status_notice_event']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('review_request_sent_at')->nullable();
            $table->timestamp('status_notice_at')->nullable();
            $table->string('status_notice_event')->nullable();
        });
    }
};
