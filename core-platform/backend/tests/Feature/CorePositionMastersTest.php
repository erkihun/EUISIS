<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\ChangePositionLifecycle;
use App\Actions\SavePosition;
use App\Actions\TransferEmployee;
use App\Models\CoreRole;
use App\Models\CoreRolePermission;
use App\Models\JobGrade;
use App\Models\JobLevel;
use App\Models\Occupation;
use App\Models\Position;
use App\Models\PositionHistory;
use App\Models\User;
use App\Support\CorePermissions;
use App\Support\PositionMasterBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsGoldenPath;
use Tests\TestCase;

/** P3 (ADR 0010): job masters, the position lifecycle and its history. */
class CorePositionMastersTest extends TestCase
{
    use BuildsGoldenPath, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGoldenPath();
    }

    private function person(string $role): User
    {
        return User::query()->create([
            'name' => $role.' '.Str::random(4), 'email' => Str::random(8).'@core.test', 'password' => 'secret-password',
            'role' => $role, 'scope_type' => User::SCOPE_CITYWIDE, 'is_active' => true,
        ]);
    }

    private function signIn(User $user): User
    {
        Auth::forgetGuards();
        $this->actingAs($user, 'web');

        return $user;
    }

    public function test_the_backfill_turns_free_text_grades_and_bare_occupations_into_masters(): void
    {
        $occupationId = (string) Str::uuid7();
        // As EUISIS rows arrive: an occupation id with no occupation row yet. Only SQLite can defer the
        // foreign key inside the test transaction; elsewhere the import test covers the copy.
        $bareOccupation = DB::getDriverName() === 'sqlite';
        if ($bareOccupation) {
            DB::statement('PRAGMA defer_foreign_keys = ON');
        }
        DB::table('positions')->where('id', $this->positionA->id)->update(['grade_level' => 'XII', 'job_grade_id' => null, 'occupation_id' => $bareOccupation ? $occupationId : null]);
        DB::table('positions')->where('id', $this->positionB->id)->update(['grade_level' => 'XII', 'job_grade_id' => null, 'is_active' => false, 'lifecycle_status' => 'approved']);

        PositionMasterBackfill::run();
        PositionMasterBackfill::run(); // repeating changes nothing

        $grade = JobGrade::query()->where('code', 'XII')->sole();
        $this->assertSame([(string) $grade->id, (string) $grade->id], Position::query()->whereKey([$this->positionA->id, $this->positionB->id])->pluck('job_grade_id')->map(fn ($id) => (string) $id)->all());
        if ($bareOccupation) {
            $this->assertTrue(Occupation::query()->findOrFail($occupationId)->needs_review);
        }
        $this->assertSame('archived', $this->positionB->fresh()->lifecycle_status);
    }

    public function test_a_position_created_without_the_approve_permission_waits_as_a_draft_and_takes_nobody(): void
    {
        $officerRole = CoreRole::query()->where('key', 'core_officer')->sole();
        CoreRolePermission::query()->where('core_role_id', $officerRole->id)->where('permission', 'positions.approve')->delete();
        CorePermissions::flush();
        $officer = $this->person('core_officer');

        $draft = app(SavePosition::class)->execute(null, ['organization_id' => $this->bureauA->id, 'title_en' => 'Planner'], $officer);
        $this->assertSame(Position::DRAFT, $draft->lifecycle_status);
        $this->assertFalse($draft->is_active);
        $this->assertSame('created', PositionHistory::query()->where('position_id', $draft->id)->sole()->change_type);

        try {
            app(TransferEmployee::class)->execute($this->employee, (string) $draft->id, now()->toDateString(), $this->person('core_admin'), 'Reorganization');
            $this->fail('A draft position took a placement.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('position_id', $e->errors());
        }

        $this->signIn($officer);
        $this->post('/positions/'.$draft->id.'/lifecycle', ['step' => 'approve'])->assertForbidden();
        $admin = $this->signIn($this->person('core_admin'));
        $this->post('/positions/'.$draft->id.'/lifecycle', ['step' => 'approve'])->assertSessionHasNoErrors();
        $draft->refresh();
        $this->assertSame(Position::APPROVED, $draft->lifecycle_status);
        $this->assertTrue($draft->is_active);
        $this->assertSame($admin->id, (int) $draft->approved_by);
        $this->assertDatabaseHas('core_audit_logs', ['action' => 'position.approve', 'subject_id' => $draft->id]);
    }

    public function test_suspension_needs_a_reason_and_abolition_needs_an_empty_position(): void
    {
        $admin = $this->person('core_admin');
        $lifecycle = app(ChangePositionLifecycle::class);

        try {
            $lifecycle->execute($this->positionA, 'suspend', '', $admin);
            $this->fail('A suspension went through without a reason.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }
        $lifecycle->execute($this->positionA, 'suspend', 'Budget freeze', $admin);
        $this->assertFalse($this->positionA->fresh()->is_active);

        try {
            $lifecycle->execute($this->positionA, 'abolish', 'Function moved', $admin);
            $this->fail('A held position was abolished.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('holds this position', $e->errors()['step'][0]);
        }
        try {
            $lifecycle->execute($this->positionB, 'archive', null, $admin);
            $this->fail('An approved position skipped abolition.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('step', $e->errors());
        }

        $lifecycle->execute($this->positionA, 'reinstate', 'Budget restored', $admin);
        $lifecycle->execute($this->positionB, 'abolish', 'Function moved', $admin);
        $lifecycle->execute($this->positionB, 'archive', null, $admin);
        $this->assertSame(Position::ARCHIVED, $this->positionB->fresh()->lifecycle_status);
        $this->assertSame(['status_changed', 'status_changed'], PositionHistory::query()->where('position_id', $this->positionA->id)->pluck('change_type')->all());
    }

    public function test_master_references_must_be_active_and_each_change_is_kept_in_the_history(): void
    {
        $admin = $this->person('core_admin');
        $save = app(SavePosition::class);
        $grade = JobGrade::query()->create(['code' => 'IX', 'name_en' => 'Grade IX']);
        $retired = JobLevel::query()->create(['code' => 'OLD', 'name_en' => 'Old level', 'is_active' => false]);

        try {
            $save->execute($this->positionA, ['grade_level' => 'NO-SUCH'], $admin);
            $this->fail('An unknown grade was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('grade_level', $e->errors());
        }
        try {
            $save->execute($this->positionA, ['job_level_id' => $retired->id], $admin);
            $this->fail('An inactive level was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('job_level_id', $e->errors());
        }

        $save->execute($this->positionA, ['grade_level' => 'IX', 'title_en' => 'Senior officer'], $admin);
        $position = $this->positionA->fresh();
        $this->assertSame((string) $grade->id, (string) $position->job_grade_id);
        $history = PositionHistory::query()->where('position_id', $position->id)->get()->keyBy('change_type');
        $this->assertSame(['from' => null, 'to' => 'IX'], $history['regraded']->changes['grade_level']);
        $this->assertSame('Senior officer', $history['retitled']->changes['title_en']['to']);

        // Deactivating the grade later leaves the position's reference alone.
        $grade->update(['is_active' => false]);
        $save->execute($position, ['grade_level' => 'IX', 'title_en' => 'Senior officer II'], $admin);
        $this->assertSame((string) $grade->id, (string) $position->fresh()->job_grade_id);
    }

    public function test_job_masters_are_read_by_position_readers_and_changed_only_with_their_permission(): void
    {
        $this->signIn($this->person('core_officer'));
        $this->get('/core/job-masters?kind=occupations')->assertOk()->assertInertia(fn ($page) => $page->component('Core/JobMasters')->where('can_manage', false));
        $this->post('/core/job-masters/grades', ['code' => 'X', 'name_en' => 'Grade X'])->assertForbidden();

        $this->signIn($this->person('core_admin'));
        $this->post('/core/job-masters/grades', ['code' => 'X', 'name_en' => 'Grade X'])->assertSessionHasNoErrors();
        $this->post('/core/job-masters/grades', ['code' => 'X', 'name_en' => 'Again'])->assertSessionHasErrors('code');
        $grade = JobGrade::query()->where('code', 'X')->sole();
        $this->patch('/core/job-masters/grades/'.$grade->id, ['code' => 'X', 'name_en' => 'Grade X', 'is_active' => false])->assertSessionHasNoErrors();
        $this->assertFalse($grade->fresh()->is_active);
        $this->assertDatabaseHas('core_audit_logs', ['action' => 'job_master.updated', 'subject_id' => $grade->id]);
        $this->get('/core/job-masters?kind=grades')->assertInertia(fn ($page) => $page->where('can_manage', true)->where('values.0.code', 'X'));
    }

    public function test_the_position_page_shows_the_lifecycle_steps_the_reader_may_take(): void
    {
        $this->signIn($this->person('core_viewer'));
        $this->get('/positions/'.$this->positionB->id)->assertInertia(fn ($page) => $page->where('lifecycle.status', 'approved')->where('lifecycle.steps', []));

        $this->signIn($this->person('core_admin'));
        $this->get('/positions/'.$this->positionA->id)->assertInertia(fn ($page) => $page
            ->where('lifecycle.occupancy.holders', 1)
            ->where('lifecycle.steps', [['step' => 'suspend', 'needs_reason' => true], ['step' => 'abolish', 'needs_reason' => true]]));
    }
}
