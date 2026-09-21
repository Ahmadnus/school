<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
     * Firebase Cloud Messaging (HTTP v1). Leave FCM_CREDENTIALS empty and push
     * is skipped silently — the app still works over Reverb while it is open.
     */
    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID'),
        'credentials' => env('FCM_CREDENTIALS', storage_path('app/firebase/service-account.json')),
        // محتوى مفتاح الخدمة نصّاً بدل ملف؛ منصّات النشر تبني من Git ولا مكان
        // فيها لملف اعتماد — ورفعه إلى المستودع يكشف مفتاحاً خاصّاً كامل الصلاحية.
        'credentials_json' => env('FCM_CREDENTIALS_JSON'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
     * Guardian sign-in codes.
     *
     * Until the app ships there is no SMS provider, so the code is returned in
     * the response itself and the app fills it in. This MUST be turned off
     * before launch: with it on, anyone who knows a registered phone number
     * can sign in as that guardian.
     */
    'guardian_auth' => [
        'expose_code' => (bool) env('GUARDIAN_OTP_EXPOSE_CODE', false),

        // رقم تجريبي واحد برمزٍ ثابت — للعرض والاختبار قبل وصل مزوّد SMS.
        //
        // يبقى مقفلاً ما لم يُضبط المتغيّران معاً في البيئة، ويسري على هذا
        // الرقم وحده: كل رقم آخر يأخذ رمزاً عشوائيّاً كما كان. وحين يُوصَل
        // مزوّد الرسائل يُحذف السطران من `.env` فيُغلق الباب بلا نشر.
        'demo_phone' => env('GUARDIAN_OTP_DEMO_PHONE'),
        'demo_code' => env('GUARDIAN_OTP_DEMO_CODE'),
    ],
];
