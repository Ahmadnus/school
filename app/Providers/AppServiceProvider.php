<?php

namespace App\Providers;

use App\Models\AbsenceExcuse;
use App\Models\Message;
use App\Models\Post;
use App\Support\SlowRequestLog;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // attachments.owner_type stores these short keys, not class names
        // (decision 12-a). report_card joins the map in the reports group.
        // Not enforceMorphMap: that would also demand a key for every other
        // morphed model, Sanctum's tokenable included.
        // مراقبة دائمة للطلبات البطيئة — {@see SlowRequestLog}.
        SlowRequestLog::watch();

        Relation::morphMap([
            'post' => Post::class,
            'message' => Message::class,
            'excuse' => AbsenceExcuse::class,
        ]);
    }
}
