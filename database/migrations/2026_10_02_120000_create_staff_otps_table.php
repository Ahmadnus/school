<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * رموز دخول الكادر على واتساب — بديلٌ عن كلمة المرور لا ناسخٌ لها.
 *
 * جدولٌ مستقلّ عن `guardian_otps` لأنّ رقماً واحداً قد يكون لأستاذٍ ولوليّ
 * أمر معاً: لو اشتركا في الجدول لأبطل طلبُ أحد التطبيقين رمزَ الآخر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_otps', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->index();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_otps');
    }
};
