<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\School;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * سعر نوع القسط، و«الافتراضي» الذي ترث منه خطط الصف كلّها.
 */
class FeeTypePriceTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private Grade $grade;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $this->grade = Grade::factory()->create([
            'school_id' => $this->school->id,
            'name' => 'بكالوريا علمي',
        ]);

        Sanctum::actingAs(
            User::factory()->role(UserRole::Admin)->create(['school_id' => $this->school->id]),
        );
    }

    public function test_the_price_typed_by_the_user_is_actually_stored(): void
    {
        // العمود `total_minor` والعقد `total_amount`؛ تمريره بلا ربط يجعل
        // لارافيل يُسقطه بصمت فيُحفظ النوع بصفر مهما كُتب.
        $id = $this->postJson('/api/fee-types', [
            'name' => 'القسط السنوي',
            'grade_id' => $this->grade->id,
            'total_amount' => '2000000',
        ])->assertCreated()->json('data.id');

        $this->assertTrue(
            FeeType::findOrFail($id)->totalAmount()->equals(Money::fromDecimal('2000000')),
        );
    }

    public function test_the_stored_price_comes_back_in_the_response(): void
    {
        $this->postJson('/api/fee-types', [
            'name' => 'القسط السنوي',
            'grade_id' => $this->grade->id,
            'total_amount' => '2000000',
        ])->assertCreated()->assertJsonPath('data.total_amount', '2000000.00');
    }

    public function test_editing_the_price_changes_it(): void
    {
        $type = FeeType::factory()->create([
            'school_id' => $this->school->id,
            'grade_id' => $this->grade->id,
            'total_minor' => 1000,
        ]);

        $this->putJson("/api/fee-types/{$type->id}", [
            'name' => $type->name,
            'total_amount' => '3500000',
        ])->assertOk();

        $this->assertTrue(
            $type->fresh()->totalAmount()->equals(Money::fromDecimal('3500000')),
        );
    }

    public function test_one_default_per_grade_the_previous_one_steps_down(): void
    {
        $old = FeeType::factory()->create([
            'school_id' => $this->school->id,
            'grade_id' => $this->grade->id,
            'is_default' => true,
        ]);

        $this->postJson('/api/fee-types', [
            'name' => 'القسط الجديد',
            'grade_id' => $this->grade->id,
            'total_amount' => '2000000',
            'is_default' => true,
        ])->assertCreated();

        // اثنان افتراضيان يعني أن الطالب قد يرث أيّهما وجدته الاستعلامة أوّلاً.
        $this->assertFalse($old->fresh()->is_default);
        $this->assertSame(
            1,
            FeeType::query()->where('grade_id', $this->grade->id)->where('is_default', true)->count(),
        );
    }

    public function test_a_default_in_another_grade_is_left_alone(): void
    {
        $otherGrade = Grade::factory()->create(['school_id' => $this->school->id]);
        $otherDefault = FeeType::factory()->create([
            'school_id' => $this->school->id,
            'grade_id' => $otherGrade->id,
            'is_default' => true,
        ]);

        $this->postJson('/api/fee-types', [
            'name' => 'القسط السنوي',
            'grade_id' => $this->grade->id,
            'total_amount' => '2000000',
            'is_default' => true,
        ])->assertCreated();

        // لكل صف افتراضيّه؛ «بكالوريا علمي» لا يُطفئ «أدبي».
        $this->assertTrue($otherDefault->fresh()->is_default);
    }

    public function test_marking_an_existing_type_default_unsets_the_other(): void
    {
        $old = FeeType::factory()->create([
            'school_id' => $this->school->id,
            'grade_id' => $this->grade->id,
            'is_default' => true,
        ]);
        $candidate = FeeType::factory()->create([
            'school_id' => $this->school->id,
            'grade_id' => $this->grade->id,
            'is_default' => false,
        ]);

        $this->putJson("/api/fee-types/{$candidate->id}", [
            'name' => $candidate->name,
            'total_amount' => '2000000',
            'is_default' => true,
        ])->assertOk();

        $this->assertFalse($old->fresh()->is_default);
        $this->assertTrue($candidate->fresh()->is_default);
    }
}
