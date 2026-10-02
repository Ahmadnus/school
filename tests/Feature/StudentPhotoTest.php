<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Guardian;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Services\GuardianAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** صورة الطالب: يرفعها الكادر، ويراها الأب مع بيانات ابنه. */
class StudentPhotoTest extends TestCase
{
    use RefreshDatabase;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->student = Student::factory()->create(['school_id' => School::factory()->create()->id]);
    }

    public function test_an_admin_uploads_and_replaces_a_photo(): void
    {
        Sanctum::actingAs(User::factory()->role(UserRole::Admin)->create(['school_id' => $this->student->school_id]));

        $first = $this->post("/api/students/{$this->student->id}/photo", [
            'photo' => UploadedFile::fake()->image('a.jpg'),
        ], ['Accept' => 'application/json'])->assertOk()->json('data.photo_url');

        $this->assertNotNull($first);
        $old = $this->student->fresh()->photo_path;
        Storage::disk('public')->assertExists($old);

        $this->post("/api/students/{$this->student->id}/photo", [
            'photo' => UploadedFile::fake()->image('b.png'),
        ], ['Accept' => 'application/json'])->assertOk();

        // الصورة القديمة لا تبقى يتيمةً على القرص.
        Storage::disk('public')->assertMissing($old);
    }

    public function test_a_file_that_is_not_an_image_is_refused(): void
    {
        Sanctum::actingAs(User::factory()->role(UserRole::Admin)->create(['school_id' => $this->student->school_id]));

        $this->post("/api/students/{$this->student->id}/photo", [
            'photo' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('photo');
    }

    public function test_a_guardian_cannot_change_it_but_sees_it(): void
    {
        $this->student->forceFill(['photo_path' => 'students/photos/x.jpg'])->save();
        $guardian = Guardian::factory()->create(['school_id' => $this->student->school_id]);
        $this->student->guardians()->attach($guardian->id, ['relation' => 'father', 'is_primary' => true]);
        Sanctum::actingAs(GuardianAccount::for($guardian));

        $this->post("/api/students/{$this->student->id}/photo", [
            'photo' => UploadedFile::fake()->image('a.jpg'),
        ], ['Accept' => 'application/json'])->assertForbidden();

        $this->getJson('/api/guardian/digest')
            ->assertOk()
            ->assertJsonPath('data.children.0.student.photo_url', Storage::disk('public')->url('students/photos/x.jpg'));
    }
}
