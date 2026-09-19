<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
        /*
         * سرُّ webhook التسليم — المرحلة 6.
         *
         * Postmark تنادي `/webhooks/postmark` بخبر الوصول والارتداد والفتح، ولا
         * توقّع طلبها. فتُعطى في عنوان الـwebhook هويّةً (`https://x:SECRET@…`)
         * تُقارَن هنا بزمنٍ ثابت — وبلا سرٍّ مضبوطٍ يُغلق الباب كلُّه، فلا
         * يكتب مجهولٌ «وصلت» على رسالةٍ لم تصل.
         */
        'webhook_secret' => env('POSTMARK_WEBHOOK_SECRET'),
    ],

    /*
     * واتساب — WhatsApp Cloud API من Meta — المرحلة 6.
     *
     * ═══ في البيئة لا في القاعدة ═══
     *
     * رمزُ الوصول يُرسل باسم رقم الشركة إلى أيّ رقم، وما يُخزَّن في القاعدة
     * يخرج مع كلّ نسخةٍ احتياطيّة. فالأربعةُ هنا سرائرُ خادم: لا تُعرض في
     * شاشة، ولا تُكتب في سجلّ، ولا تُرسَل إلى الواجهة — انظر `WhatsAppNotifier`.
     *
     * ═══ وغيابُها يعني «غيرُ موصول» ═══
     *
     * `configured()` تسأل عن الرمز ومعرّف الرقم معًا؛ وما دونهما تُخفي الشاشةُ
     * بابَ واتساب ولا تدّعي أنّه يعمل — درسُ `Mailer::configured()` نفسُه.
     *
     * - `app_secret`: يوقّع Meta به كلَّ webhook (`X-Hub-Signature-256`)
     * - `verify_token`: ما نختاره نحن ويُعاد لنا عند تسجيل الـwebhook
     */
    'whatsapp' => [
        'token' => env('WHATSAPP_ACCESS_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
     * ملفُّ Google Business Profile — إدارةُ تقييمات المتجر والردُّ عليها.
     *
     * ═══ ولمَ في البيئة لا في القاعدة ═══
     *
     * سرُّ العميل يفتح بابَ الإذن لكلّ متاجر المنصّة. وما يُخزَّن في القاعدة
     * يخرج مع كلّ نسخةٍ احتياطية، ونسخُ القاعدة تُنقل وتُشارك.
     *
     * ═══ وهذا **غيرُ** مفتاح Places ═══
     *
     * ذاك يقرأ الملفَّ العامّ لأيّ مكان. وهذا يفتح ملفَّ المتجر نفسِه: يقرأ
     * تقييماته كلَّها ويردّ عليها باسمه. فيحتاج إذنَ صاحبه (OAuth) ووصولًا
     * مُعتمَدًا من Google إلى «Business Profile APIs» — وهو طلبٌ يُقدَّم
     * ويُراجَع، لا واجهةٌ تُفعَّل بضغطة.
     *
     * وغيابُ أيٍّ من الثلاثة يعني أنّ الميزة **غيرُ موصولة** ولا تُعرض
     * كأنّها تعمل — انظر `GoogleBusiness::configured`.
     */
    'google_business' => [
        'client_id' => env('GOOGLE_BUSINESS_CLIENT_ID'),
        'client_secret' => env('GOOGLE_BUSINESS_CLIENT_SECRET'),

        /*
         * عنوانُ العودة — يجب أن يُطابق المسجَّل في Google Cloud حرفًا بحرف.
         *
         * ويُقرأ من البيئة لا يُبنى بـ`route()`: خادمُ التطوير وخادمُ الإنتاج
         * عنوانان، و`APP_URL` قد تختلف عمّا سُجّل عندهم فيُردّ الإذن بلا خبر.
         */
        'redirect' => env('GOOGLE_BUSINESS_REDIRECT'),
    ],

    /*
     * عنوانُ خادمنا كما تراه الخدماتُ الخارجيّة.
     *
     * يُعرض للتاجر في دليل المفتاح: Google تسمح بتقييد المفتاح بعناوينَ
     * بعينها، فلا يعمل عند غيرها ولو سُرِّب. ونحن نطلب منه ذلك — فيجب أن
     * نُمكّنه منه.
     *
     * ويُقرأ من البيئة لا يُكتب في الشاشة: الخادمُ يُبدَّل يومًا، ورقمٌ
     * محفورٌ في واجهةٍ يبقى يُعرض بعد أن يصير خطأً — فيقيّد التاجر مفتاحه
     * بعنوانٍ لا نناديه منه، ويرى «رفضت Google المفتاح» بلا سببٍ مفهوم.
     *
     * وغيابُه لا يُخمَّن: تقول الشاشة «اطلبه من الدعم» ولا تعرض عنوانًا.
     */
    'outbound_ip' => env('OUTBOUND_IP'),

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
