<?php

namespace Tests\Feature;

use App\Enums\Status;
use App\Enums\UserRole;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** حسابٌ أوقفته الإدارة يخرج من التطبيق في طلبه التالي، لا بعد أيّام. */
class InactiveAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_deactivated_account_is_signed_out_on_its_next_request(): void
    {
        $teacher = User::factory()->role(UserRole::Teacher)->create([
            'school_id' => School::factory()->create()->id,
        ]);
        $token = $teacher->createToken('app')->plainTextToken;

        $this->withToken($token)->getJson('/api/me')->assertOk();

        $teacher->update(['status' => Status::Inactive]);
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();

        // التوكن سُحب: إعادة التفعيل تتطلّب دخولاً جديداً.
        $this->assertSame(0, $teacher->tokens()->count());
    }
}
