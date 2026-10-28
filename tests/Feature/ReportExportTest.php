<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Services\SpreadsheetReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * التصدير: رابط موقّع قصير العمر، للإدارة، ومحصور بمدرسة صاحبه.
 */
class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = School::factory()->create();
        $this->admin = User::factory()->role(UserRole::Admin)->create(['school_id' => $this->school->id]);
    }

    private function download(string $url)
    {
        // الرابط المطلق يتضمّن النطاق؛ الاختبار يريد المسار والاستعلام فقط.
        return $this->get(substr($url, strlen(config('app.url'))));
    }

    public function test_an_admin_gets_a_signed_link_that_downloads_only_their_school(): void
    {
        Student::factory()->count(2)->create(['school_id' => $this->school->id]);
        Student::factory()->count(3)->create(['school_id' => School::factory()->create()->id]);

        Sanctum::actingAs($this->admin);
        $url = $this->postJson('/api/exports', ['type' => 'students'])->assertOk()->json('data.url');

        $response = $this->download($url)->assertOk();
        $file = $response->baseResponse->getFile()->getPathname();
        $sheet = SpreadsheetReader::read($file, 'xlsx');

        $this->assertCount(2, $sheet['rows']);
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type'),
        );
    }

    public function test_a_tampered_link_is_rejected(): void
    {
        Sanctum::actingAs($this->admin);
        $url = $this->postJson('/api/exports', ['type' => 'students'])->json('data.url');

        $other = School::factory()->create();
        $tampered = preg_replace('/school=\d+/', 'school='.$other->id, $url);

        $this->download($tampered)->assertForbidden();
        $this->get('/api/exports/download?type=students&school='.$this->school->id)->assertForbidden();
    }

    public function test_non_admins_and_unknown_types_are_refused(): void
    {
        $teacher = User::factory()->role(UserRole::Teacher)->create(['school_id' => $this->school->id]);
        Sanctum::actingAs($teacher);
        $this->postJson('/api/exports', ['type' => 'students'])->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/exports', ['type' => 'passwords'])->assertUnprocessable();
    }

    public function test_every_report_type_builds_a_valid_workbook(): void
    {
        Sanctum::actingAs($this->admin);
        foreach (['students', 'fees', 'ledger', 'payroll', 'audit'] as $type) {
            $url = $this->postJson('/api/exports', ['type' => $type])->assertOk()->json('data.url');
            $file = $this->download($url)->assertOk()->baseResponse->getFile()->getPathname();
            $sheet = SpreadsheetReader::read($file, 'xlsx');
            $this->assertNotEmpty($sheet['headers'], $type);
        }
    }
}
