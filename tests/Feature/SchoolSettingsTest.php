<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\School;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** School-level configuration: who may change it and where its defaults land. */
class SchoolSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_updates_configuration_and_defaults_apply_to_subjects(): void
    {
        $school = School::factory()->create();
        $admin = User::factory()->role(UserRole::SuperAdmin)->create(['school_id' => $school->id]);
        Sanctum::actingAs($admin);

        $this->putJson('/api/school', [
            'phone' => '0911111111',
            'address' => 'دمشق — المزة',
            'website' => 'https://school.example',
            'absence_warning_threshold' => 7,
            'default_pass_score' => 60,
            'default_max_score' => 120,
        ])->assertOk()
            ->assertJsonPath('data.phone', '0911111111')
            ->assertJsonPath('data.absence_warning_threshold', 7);

        $school->refresh();
        $this->assertSame('https://school.example', $school->website);
        $this->assertSame('60.00', $school->default_pass_score);

        // A subject created without explicit scores inherits the school defaults.
        $year = AcademicYear::factory()->current()->create(['school_id' => $school->id]);
        $term = Term::factory()->create(['academic_year_id' => $year->id]);
        $grade = Grade::factory()->create(['school_id' => $school->id]);

        $this->postJson('/api/subjects', [
            'grade_id' => $grade->id,
            'term_id' => $term->id,
            'name' => 'رياضيات',
        ])->assertCreated()
            ->assertJsonPath('data.max_score', '120.00')
            ->assertJsonPath('data.pass_score', '60.00');
    }

    public function test_pass_score_may_not_exceed_max_and_teachers_are_refused(): void
    {
        $school = School::factory()->create();
        $admin = User::factory()->role(UserRole::Admin)->create(['school_id' => $school->id]);
        $teacher = User::factory()->role(UserRole::Teacher)->create(['school_id' => $school->id]);

        Sanctum::actingAs($admin);
        $this->putJson('/api/school', ['default_pass_score' => 150, 'default_max_score' => 100])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['default_pass_score']);

        Sanctum::actingAs($teacher);
        $this->putJson('/api/school', ['phone' => '0900000000'])->assertForbidden();
        $this->getJson('/api/school')->assertOk()->assertJsonStructure(['data' => ['currency', 'phone']]);
    }
}
