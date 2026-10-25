<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\TransferEmployee;
use App\Models\CoreAuditLog;
use App\Models\DataQualityIssue;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\ExportRun;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\User;
use App\Services\DataQualityScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsGoldenPath;
use Tests\TestCase;

/** P4/P5 (ADR 0011): the data-quality issue lifecycle, scoped reports and audited exports. */
class CoreOversightTest extends TestCase
{
    use BuildsGoldenPath, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGoldenPath();
        Storage::fake('exports');
    }

    private function person(string $role, ?Organization $scope = null): User
    {
        return User::query()->create([
            'name' => $role.' '.Str::random(4), 'email' => Str::random(8).'@core.test', 'password' => 'secret-password', 'role' => $role,
            'scope_organization_id' => $scope?->id, 'scope_type' => $scope === null ? User::SCOPE_CITYWIDE : User::SCOPE_SUBTREE, 'is_active' => true,
        ]);
    }

    private function signIn(User $user): User
    {
        Auth::forgetGuards();
        $this->actingAs($user, 'web');

        return $user;
    }

    public function test_a_finding_becomes_an_issue_with_its_severity_and_follows_the_lifecycle(): void
    {
        // An active card whose holder is no longer active: critical.
        $this->employee->forceFill(['status' => Employee::SUBMITTED])->save();
        app(DataQualityScanner::class)->scan();
        $issue = DataQualityIssue::query()->where('check_code', 'active_card_inactive_employee')->sole();
        $this->assertSame(['critical', 'open', (string) $this->card->id, (string) $this->bureauA->id], [$issue->severity, $issue->status, $issue->entity_id, (string) $issue->organization_id]);

        // Another organization's reader does not see it; the bureau's officer works on it.
        $this->signIn($this->person('core_officer', $this->bureauB));
        $this->get('/core/data-quality?tab=open')->assertInertia(fn ($page) => $page->where('issues.meta.total', 0));
        $this->post("/core/data-quality/issues/{$issue->id}/investigate")->assertNotFound();
        $officer = $this->signIn($this->person('core_officer', $this->bureauA));
        $this->get('/core/data-quality?tab=critical')->assertInertia(fn ($page) => $page->where('issues.meta.total', 1)->where('issues.data.0.steps', ['investigate', 'resolve', 'false_positive']));
        $this->post("/core/data-quality/issues/{$issue->id}/investigate")->assertSessionHasNoErrors();
        $this->assertSame([DataQualityIssue::INVESTIGATING, $officer->id], [$issue->fresh()->status, (int) $issue->fresh()->assigned_to]);
        $this->post("/core/data-quality/issues/{$issue->id}/accept_risk", ['note' => 'Known and tolerated'])->assertForbidden();
        $this->post("/core/data-quality/issues/{$issue->id}/resolve", ['note' => ''])->assertSessionHasErrors('note');

        // Fixed in the record: the next scan resolves it by itself, and audits that.
        $this->employee->forceFill(['status' => Employee::ACTIVE])->save();
        app(DataQualityScanner::class)->scan();
        $this->assertSame([DataQualityIssue::RESOLVED, true], [$issue->fresh()->status, $issue->fresh()->auto_resolved]);
        $this->assertTrue(CoreAuditLog::query()->where('action', 'data_quality.auto_resolved')->where('subject_id', $issue->id)->exists());

        // Broken again: reopened, not duplicated.
        $this->employee->forceFill(['status' => Employee::SUBMITTED])->save();
        app(DataQualityScanner::class)->scan();
        $this->assertSame([DataQualityIssue::OPEN, 1], [$issue->fresh()->status, $issue->fresh()->times_reopened]);
        $this->assertSame(1, DataQualityIssue::query()->where('check_code', 'active_card_inactive_employee')->count());

        // A false positive stays closed however often the scan finds it.
        $this->post("/core/data-quality/issues/{$issue->id}/false_positive", ['note' => 'Status sync from the HR system pending'])->assertSessionHasNoErrors();
        app(DataQualityScanner::class)->scan();
        $this->assertSame(DataQualityIssue::FALSE_POSITIVE, $issue->fresh()->status);
    }

    public function test_reports_are_limited_to_the_scope_and_leave_out_personal_details(): void
    {
        $this->employee->forceFill(['phone' => '+251911000000', 'email' => 'abebe@example.test'])->save();
        $other = $this->placedEmployee('EMP-0002', $this->positionB, '2024-01-01');
        $this->signIn($this->person('core_officer', $this->bureauA));
        $this->get('/core/reports?report=employees')->assertOk()->assertInertia(fn ($page) => $page->component('Core/Reports')
            ->where('preview.meta.total', 1)->where('preview.rows.0.employee_number', 'EMP-0001')
            ->where('preview.columns', fn ($columns) => ! collect($columns)->pluck('key')->contains('phone')));
        $this->get('/core/reports?report=user_access')->assertForbidden();
        $this->get('/core/reports?report=employees&filters[organization]='.$this->bureauB->id)->assertInertia(fn ($page) => $page->where('preview.meta.total', 0));
        $this->assertSame('EMP-0002', $other->employee_number);

        $this->signIn($this->person('core_viewer', $this->bureauA));
        $this->post('/core/reports/employees/export', ['format' => 'csv'])->assertForbidden();
    }

    public function test_an_export_is_queued_audited_neutralized_and_downloaded_only_by_its_requester(): void
    {
        $this->employee->forceFill(['full_name' => '=HYPERLINK("http://evil.test")', 'phone' => '+251911000000'])->save();
        $officer = $this->signIn($this->person('core_officer', $this->bureauA));
        $this->post('/core/reports/employees/export', ['format' => 'csv', 'include_sensitive' => true, 'purpose' => 'Payroll reconciliation for October'])
            ->assertSessionHasErrors('include_sensitive');
        $this->post('/core/reports/employees/export', ['format' => 'csv'])->assertSessionHasNoErrors();
        $run = ExportRun::query()->sole();
        $this->assertSame(['completed', 1, $officer->id], [$run->status, $run->row_count, (int) $run->requested_by]);
        $csv = Storage::disk('exports')->get($run->file_path);
        $this->assertStringContainsString("\"'=HYPERLINK", $csv);
        $this->assertStringNotContainsString('+251911000000', $csv);
        foreach (['report.export_requested', 'report.export_completed'] as $action) {
            $this->assertTrue(CoreAuditLog::query()->where('action', $action)->where('subject_id', $run->id)->exists(), $action);
        }

        $response = $this->get('/core/reports/exports/'.$run->id)->assertOk();
        $this->assertStringContainsString('core-employees-', (string) $response->headers->get('Content-Disposition'));
        $this->assertTrue(CoreAuditLog::query()->where('action', 'report.export_downloaded')->where('subject_id', $run->id)->exists());
        $admin = $this->signIn($this->person('core_admin'));
        $this->get('/core/reports/exports/'.$run->id)->assertNotFound();

        // An administrator may add contact details, with a purpose that is kept.
        $this->post('/core/reports/employees/export', ['format' => 'xlsx', 'include_sensitive' => true])->assertSessionHasErrors('purpose');
        $this->post('/core/reports/employees/export', ['format' => 'xlsx', 'include_sensitive' => true, 'purpose' => 'Payroll reconciliation for October'])->assertSessionHasNoErrors();
        $sensitive = ExportRun::query()->where('requested_by', $admin->id)->sole();
        $this->assertSame([true, 'completed', 'Payroll reconciliation for October'], [$sensitive->include_sensitive, $sensitive->status, $sensitive->purpose]);
    }

    public function test_assignment_history_keeps_the_organization_of_the_time(): void
    {
        $admin = $this->person('core_admin');
        app(TransferEmployee::class)->execute($this->employee, (string) $this->positionB->id, '2025-02-01', $admin, 'Reorganization');
        $this->signIn($admin);
        $this->get('/core/reports?report=assignment_history&filters[as_of]=2024-06-01')->assertInertia(fn ($page) => $page
            ->where('preview.meta.total', 1)->where('preview.rows.0.organization', 'BUR-A office'));
        $this->get('/core/reports?report=assignment_history&filters[as_of]=2025-03-01')->assertInertia(fn ($page) => $page
            ->where('preview.rows.0.organization', 'BUR-B office'));
        $this->assertSame(2, EmployeeAssignment::query()->where('employee_id', $this->employee->id)->count());
        $this->assertSame(IdCard::ACTIVE, $this->card->fresh()->status);
        $this->assertSame(1, DB::table('employees')->count());
    }
}
