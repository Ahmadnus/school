<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// تذكير الأقساط: الإشعار الوحيد بلا فعلٍ يُطلقه — القسط يحلّ موعده وحده.
// الساعة السابعة صباحاً بتوقيت المدرسة، لا منتصف الليل: تنبيهٌ يوقظ أباً
// نائماً يُغلَق إشعاره لا يُقرأ.
Schedule::command('fees:notify-due')->dailyAt('07:00');
