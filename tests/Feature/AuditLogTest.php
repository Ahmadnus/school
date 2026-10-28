<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\TestCase;

/**
 * سجلّ التدقيق: يسجّل من غيّر ماذا، ولا يُعاد كتابته، ويراه الإداري وحده.
 */
class AuditLogTest extends TestCase
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

    public function test_creating_updating_and_deleting_are_recorded_with_the_actor(): void
    {
        Sanctum::actingAs($this->admin);

        $student = Student::factory()->create(['school_id' => $this->school->id, 'phone' => '0911111111']);
        $student->update(['phone' => '0922222222']);
        $student->delete();

        $logs = AuditLog::where('auditable_type', 'Student')->where('auditable_id', $student->id)->orderBy('id')->get();

        $this->assertSame(['created', 'updated', 'deleted'], $logs->pluck('event')->all());
        $this->assertSame($this->admin->id, $logs[1]->user_id);
        $this->assertSame('0911111111', $logs[1]->old_values['phone']);
        $this->assertSame('0922222222', $logs[1]->new_values['phone']);
    }

    public function test_an_update_that_changes_nothing_is_not_logged(): void
    {
        $student = Student::factory()->create(['school_id' => $this->school->id]);
        $before = AuditLog::count();

        $student->update(['phone' => $student->phone]);
        $student->touch();

        $this->assertSame($before, AuditLog::count());
    }

    public function test_passwords_are_never_written_to_the_log(): void
    {
        Sanctum::actingAs($this->admin);
        $user = User::factory()->role(UserRole::Teacher)->create(['school_id' => $this->school->id]);
        $user->update(['password' => 'brand-new-secret']);

        $log = AuditLog::where('auditable_type', 'User')->where('event', 'updated')->firstOrFail();

        $this->assertSame('***', $log->new_values['password']);
        $this->assertStringNotContainsString('brand-new-secret', json_encode($log->toArray()));
    }

    public function test_log_rows_cannot_be_edited_or_deleted(): void
    {
        $student = Student::factory()->create(['school_id' => $this->school->id]);
        $log = AuditLog::firstOrFail();

        $this->expectException(LogicException::class);
        $log->update(['event' => 'deleted']);
        $this->assertNotNull($student);
    }

    public function test_only_administrators_read_the_log_and_only_their_school(): void
    {
        Student::factory()->create(['school_id' => $this->school->id]);
        $other = School::factory()->create();
        Student::factory()->create(['school_id' => $other->id]);

        $teacher = User::factory()->role(UserRole::Teacher)->create(['school_id' => $this->school->id]);
        Sanctum::actingAs($teacher);
        $this->getJson('/api/audit-logs')->assertForbidden();

        Sanctum::actingAs($this->admin);
        $rows = $this->getJson('/api/audit-logs?type=Student')->assertOk()->json('data');

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame('Student', $row['type']);
        }
        $this->assertSame(
            AuditLog::where('school_id', $this->school->id)->where('auditable_type', 'Student')->count(),
            count($rows),
        );
    }
}
