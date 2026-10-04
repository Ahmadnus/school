<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentType;
use App\Models\Grade;
use App\Models\School;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * حقلٌ فارغ في نموذج التقييم لا يُسقط الحفظ.
 *
 * الوزن والعلامة القصوى اختياريّان في النموذج، لكنّ عموديهما لا يقبلان
 * `null`: فكان تقييمٌ بلا وزن يُرفض بخطأ خادم (٢٠٢٦-١٠-٠٢، «تسميع 1»).
 * والوزن الفارغ يعني «وزن نوعه» — وهو ما يعنيه الصفر عند حساب العلامة.
 */
class AssessmentEmptyFieldsTest extends TestCase
{
    use RefreshDatabase;

    private Subject $subject;

    private AssessmentType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $school = School::factory()->create();
        AcademicYear::factory()->current()->create(['school_id' => $school->id]);
        $grade = Grade::factory()->create(['school_id' => $school->id]);
        $this->subject = Subject::factory()->create(['grade_id' => $grade->id]);
        $this->type = AssessmentType::factory()->create(['school_id' => $school->id]);

        Sanctum::actingAs(User::factory()->role(UserRole::Admin)->create(['school_id' => $school->id]));
    }

    public function test_an_assessment_without_weight_or_max_is_created(): void
    {
        $id = $this->postJson('/api/assessments', [
            'subject_id' => $this->subject->id,
            'assessment_type_id' => $this->type->id,
            'name' => 'تسميع 1',
            'max_score' => null,
            'weight_percent' => null,
        ])->assertCreated()->json('data.id');

        $assessment = Assessment::findOrFail($id);
        $this->assertEquals(0, $assessment->weight_percent);
        $this->assertEquals(100, $assessment->max_score);
    }

    public function test_clearing_the_weight_falls_back_and_an_empty_max_keeps_the_old_one(): void
    {
        $assessment = Assessment::factory()->create([
            'subject_id' => $this->subject->id,
            'assessment_type_id' => $this->type->id,
            'max_score' => 20,
            'weight_percent' => 30,
        ]);

        $this->putJson("/api/assessments/{$assessment->id}", [
            'weight_percent' => null,
            'max_score' => null,
        ])->assertOk();

        $assessment->refresh();
        // الوزن الممسوح يعود إلى وزن النوع؛ والعلامة القصوى لا تُمسح بحقلٍ فارغ.
        $this->assertEquals(0, $assessment->weight_percent);
        $this->assertEquals(20, $assessment->max_score);
    }
}
