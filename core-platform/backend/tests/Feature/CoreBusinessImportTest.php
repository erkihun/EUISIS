<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessImportBatch;
use App\Jobs\ValidateImportBatch;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeDuplicateReview;
use App\Models\HierarchyVersion;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\Position;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\CoreSettings;
use App\Services\DuplicateEmployees;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsGoldenPath;
use Tests\TestCase;

/** P2 (ADR 0009): business imports — checked with the Core's rules, approved by a second person, processed once. */
class CoreBusinessImportTest extends TestCase
{
    use BuildsGoldenPath, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGoldenPath();
        Storage::fake('imports');
    }

    private function signIn(string $role, ?Organization $scope = null): User
    {
        $user = User::query()->create([
            'name' => $role.' '.Str::random(4), 'email' => Str::random(8).'@core.test', 'password' => 'secret-password', 'role' => $role,
            'scope_organization_id' => $scope?->id, 'scope_type' => $scope === null ? User::SCOPE_CITYWIDE : User::SCOPE_SUBTREE, 'is_active' => true,
        ]);
        Auth::forgetGuards();
        $this->actingAs($user, 'web');

        return $user;
    }

    /** @param list<list<string>> $rows */
    private function csv(array $header, array $rows, string $name = 'import.csv'): UploadedFile
    {
        $lines = array_map(fn (array $row): string => implode(',', array_map(fn (string $cell): string => str_contains($cell, ',') ? '"'.$cell.'"' : $cell, $row)), [$header, ...$rows]);

        return UploadedFile::fake()->createWithContent($name, implode("\n", $lines)."\n");
    }

    private function upload(string $type, UploadedFile $file): ImportBatch
    {
        $this->post('/core/imports', ['type' => $type, 'file' => $file])->assertSessionHasNoErrors()->assertRedirect();

        return ImportBatch::query()->latest('created_at')->firstOrFail();
    }

    private function errors(ImportBatch $batch, int $row): array
    {
        return array_column(ImportRow::query()->where('import_batch_id', $batch->id)->where('row_number', $row)->sole()->errors ?? [], 'code');
    }

    private const ORGANIZATION = ['code', 'name_en', 'name_am', 'type_code', 'status', 'parent_code', 'effective_from'];

    public function test_organizations_are_imported_as_drafts_with_their_parents_in_a_hierarchy_draft(): void
    {
        $uploader = $this->signIn('core_admin');
        $published = HierarchyVersion::current();
        $batch = $this->upload('organizations', $this->csv(self::ORGANIZATION, [
            ['HEALTH', 'Health Bureau', 'ጤና ቢሮ', 'BUREAU', '', 'CITY', '2024-01-01'],
            ['CLINIC-1', 'Clinic One', '', 'BUREAU', 'active', 'HEALTH', ''],
            ['LOST', 'Nowhere', '', 'BUREAU', '', 'NOPE', ''],
            ['BUR-A', 'Taken code', '', 'BUREAU', '', '', ''],
        ]));

        $this->assertSame([ImportBatch::REQUIRES_ATTENTION, 4, 2, 2], [$batch->fresh()->status, $batch->fresh()->total_rows, $batch->fresh()->valid_rows, $batch->fresh()->invalid_rows]);
        $this->assertSame(['UNKNOWN_PARENT'], $this->errors($batch, 4));
        $this->assertSame(['DUPLICATE_CODE'], $this->errors($batch, 5));
        $this->assertSame(0, Organization::query()->where('code', 'HEALTH')->count(), 'Nothing is written before approval.');

        // The uploader does not approve their own import; a second administrator must acknowledge the invalid rows.
        $this->post('/core/imports/'.$batch->id.'/approve', ['acknowledge_invalid' => true])->assertForbidden();
        $approver = $this->signIn('core_admin');
        $this->post('/core/imports/'.$batch->id.'/approve')->assertSessionHasErrors('acknowledge_invalid');
        $this->post('/core/imports/'.$batch->id.'/approve', ['acknowledge_invalid' => true])->assertSessionHasNoErrors();

        $batch->refresh();
        $this->assertSame([ImportBatch::COMPLETED, 2, 0], [$batch->status, $batch->processed_rows, $batch->failed_rows]);
        $health = Organization::query()->where('code', 'HEALTH')->sole();
        $this->assertSame(['draft', 'active'], [$health->status, Organization::query()->where('code', 'CLINIC-1')->value('status')]);
        // The published hierarchy is untouched; the parents wait in a draft for the governed review.
        $this->assertSame($published->id, HierarchyVersion::current()->id);
        $draft = HierarchyVersion::query()->findOrFail($batch->hierarchy_version_id);
        $this->assertSame('draft', $draft->status);
        $this->assertTrue(OrganizationEdge::query()->where('hierarchy_version_id', $draft->id)->where('child_organization_id', $health->id)->where('parent_organization_id', $this->city->id)->exists());
        $this->assertFalse(OrganizationEdge::query()->where('hierarchy_version_id', $published->id)->where('child_organization_id', $health->id)->exists());
        foreach (['import.uploaded', 'import.validated', 'import.approved', 'import.started', 'import.completed'] as $action) {
            $this->assertDatabaseHas('core_audit_logs', ['action' => $action, 'subject_id' => $batch->id]);
        }
        $this->assertNotSame($uploader->id, $approver->id);
    }

    public function test_employees_go_through_registration_rules_and_enter_as_drafts(): void
    {
        $this->employee->update(['date_of_birth' => '1990-01-01']);
        $this->employee->forceFill(['national_id_hash' => DuplicateEmployees::nationalIdHash('1234 5678 9012')])->save();
        $second = Position::query()->create(['organization_id' => $this->bureauB->id, 'job_position_code' => 'POS-B2', 'title_en' => 'Clerk', 'is_active' => true]);
        $header = ['employee_number', 'first_name', 'middle_name', 'last_name', 'gender', 'date_of_birth', 'phone', 'email', 'national_id', 'position_code', 'effective_from', 'duplicate_reason'];
        $this->signIn('core_officer');
        $batch = $this->upload('employees', $this->csv($header, [
            ['', 'Hana', '', 'Girma', 'female', '1992-03-04', '', '', '5555 6666 7777', 'POS-B', '2025-01-01', ''],
            ['', 'Yonas', '', 'Tesfaye', '', '', '', '', '', 'POS-B', '2025-01-01', ''],
            ['', 'Abebe', '', 'EMP-0001', '', '1990-01-01', '', '', '', 'POS-B2', '2025-01-01', ''],
            ['', 'Abebe', '', 'EMP-0001', '', '', '', '', '', 'POS-B2', '2025-01-01', 'Another Abebe, other parents on file'],
            ['', 'Selam', '', 'Haile', '', '', '', '', '1234 5678 9012', 'POS-NONE', '2099-01-01', ''],
        ]));

        $this->assertSame([], $this->errors($batch, 2));
        $this->assertSame(['POSITION_ALREADY_OCCUPIED'], $this->errors($batch, 3));
        $this->assertSame(['DUPLICATE_EMPLOYEE'], $this->errors($batch, 4));
        $this->assertSame([], $this->errors($batch, 5));
        $this->assertEqualsCanonicalizing(['DUPLICATE_NATIONAL_ID', 'INVALID_DATE', 'UNKNOWN_POSITION'], $this->errors($batch, 6));

        $this->signIn('core_admin');
        $this->post('/core/imports/'.$batch->id.'/approve', ['acknowledge_invalid' => true])->assertSessionHasNoErrors();
        $hana = Employee::query()->where('full_name', 'Hana Girma')->sole();
        $this->assertSame([Employee::DRAFT, EmployeeAssignment::PENDING], [$hana->status, $hana->currentAssignment->assignment_status]);
        $other = Employee::query()->where('full_name', 'Abebe EMP-0001')->whereKeyNot($this->employee->id)->sole();
        $this->assertSame('import', EmployeeDuplicateReview::query()->where('employee_id', $other->id)->sole()->source);
        $this->assertSame((string) $second->id, (string) $other->currentAssignment->position_id);
    }

    public function test_positions_need_a_known_organization_inside_the_scope(): void
    {
        $this->signIn('core_officer', $this->bureauA);
        $batch = $this->upload('positions', $this->csv(['code', 'title_en', 'title_am', 'organization_code', 'unit_code', 'grade_level', 'approved_slots', 'effective_from'], [
            ['POS-NEW', 'Analyst', '', 'BUR-A', '', 'IX', '2', '2025-01-01'],
            ['', 'Driver', '', 'NO-SUCH', '', '', '', ''],
            ['', 'Guard', '', 'BUR-B', '', '', '', ''],
            ['POS-A', 'Taken', '', 'BUR-A', '', '', '', ''],
        ]));
        $this->assertSame(['UNKNOWN_ORGANIZATION'], $this->errors($batch, 3));
        $this->assertSame(['OUT_OF_SCOPE_ORGANIZATION'], $this->errors($batch, 4));
        $this->assertSame(['DUPLICATE_CODE'], $this->errors($batch, 5));

        // A scoped batch is invisible outside the uploader's organizations.
        $this->signIn('core_admin', $this->bureauB);
        $this->get('/core/imports/'.$batch->id)->assertNotFound();
        $this->get('/core/imports')->assertInertia(fn ($page) => $page->where('counts.ready', 0));

        $this->signIn('core_admin', $this->bureauA);
        $this->post('/core/imports/'.$batch->id.'/approve', ['acknowledge_invalid' => true])->assertSessionHasNoErrors();
        $position = Position::query()->where('job_position_code', 'POS-NEW')->sole();
        $this->assertSame(2, $position->establishments()->sole()->approved_slots);
    }

    public function test_placement_changes_reuse_the_transfer_rules_and_a_retry_writes_nothing_twice(): void
    {
        $other = $this->placedEmployee('EMP-0002', $this->positionB, '2024-01-01');
        $target = Position::query()->create(['organization_id' => $this->bureauB->id, 'job_position_code' => 'POS-T', 'title_en' => 'Target', 'is_active' => true]);
        $this->signIn('core_officer');
        $batch = $this->upload('assignments', $this->csv(['employee_number', 'position_code', 'effective_from', 'reason'], [
            ['EMP-0001', 'POS-T', '2025-02-01', 'Reorganization'],
            ['EMP-0001', 'POS-B', '2025-02-01', ''],
            ['EMP-0002', 'POS-B', '2025-02-01', ''],
            ['EMP-9999', 'POS-T', '2025-02-01', ''],
        ]));
        $this->assertSame([], $this->errors($batch, 2));
        $this->assertContains('ASSIGNMENT_CONFLICT', $this->errors($batch, 3));
        $this->assertContains('ASSIGNMENT_CONFLICT', $this->errors($batch, 4));
        $this->assertSame(['UNKNOWN_EMPLOYEE'], $this->errors($batch, 5));

        $this->signIn('core_admin');
        $this->post('/core/imports/'.$batch->id.'/approve', ['acknowledge_invalid' => true])->assertSessionHasNoErrors();
        $this->assertSame((string) $target->id, (string) $this->employee->fresh()->currentAssignment->position_id);
        $placements = EmployeeAssignment::query()->where('employee_id', $this->employee->id)->count();

        // A retried job (the batch put back to processing) finds every row done.
        $batch->refresh()->forceFill(['status' => ImportBatch::PROCESSING])->save();
        (new ProcessImportBatch($batch->id))->handle(app(AuditWriter::class));
        $this->assertSame($placements, EmployeeAssignment::query()->where('employee_id', $this->employee->id)->count());
        $this->assertSame([ImportBatch::COMPLETED, 1], [$batch->fresh()->status, $batch->fresh()->processed_rows]);
        $this->assertNotNull($other->id);
    }

    public function test_steps_are_queued_and_nothing_runs_before_approval(): void
    {
        Queue::fake();
        $this->signIn('core_officer');
        $batch = $this->upload('positions', $this->csv(['code', 'title_en', 'title_am', 'organization_code', 'unit_code', 'grade_level', 'approved_slots', 'effective_from'], [['', 'Analyst', '', 'BUR-A', '', '', '', '']]));
        Queue::assertPushed(ValidateImportBatch::class, fn (ValidateImportBatch $job) => $job->batchId === $batch->id);
        Queue::assertNotPushed(ProcessImportBatch::class);
        $this->assertSame(ImportBatch::UPLOADED, $batch->status);

        (new ValidateImportBatch($batch->id))->handle(app(AuditWriter::class), app(CoreSettings::class));
        $this->assertSame(ImportBatch::READY, $batch->fresh()->status);
        $this->signIn('core_admin');
        $this->post('/core/imports/'.$batch->id.'/approve')->assertSessionHasNoErrors();
        Queue::assertPushed(ProcessImportBatch::class, fn (ProcessImportBatch $job) => $job->batchId === $batch->id);
        $this->assertSame(0, Position::query()->where('title_en', 'Analyst')->count());
    }

    public function test_unsafe_files_are_refused_and_the_error_report_cannot_run_formulas(): void
    {
        $this->signIn('core_officer');
        $header = ['code', 'title_en', 'title_am', 'organization_code', 'unit_code', 'grade_level', 'approved_slots', 'effective_from'];
        $this->post('/core/imports', ['type' => 'positions', 'file' => UploadedFile::fake()->createWithContent('tool.csv', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF\x00\x00")])->assertSessionHasErrors('file');
        $this->post('/core/imports', ['type' => 'positions', 'file' => UploadedFile::fake()->createWithContent('page.html', '<html><script>alert(1)</script></html>')])->assertSessionHasErrors('file');
        $this->post('/core/imports', ['type' => 'positions', 'file' => $this->csv(['code', 'title_en'], [['X', 'Y']])])->assertSessionHasErrors('file');
        $macro = tempnam(sys_get_temp_dir(), 'xlsm');
        $zip = new \ZipArchive;
        $zip->open($macro, \ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<workbook/>');
        $zip->addFromString('xl/vbaProject.bin', 'macro');
        $zip->close();
        $this->post('/core/imports', ['type' => 'positions', 'file' => new UploadedFile($macro, 'book.xlsx', null, null, true)])->assertSessionHasErrors('file');
        $this->post('/core/imports', ['type' => 'organizations', 'file' => $this->csv(self::ORGANIZATION, [['X', 'Y', '', 'BUREAU', '', '', '']])])->assertForbidden();
        $this->assertSame(0, ImportBatch::query()->count());

        $batch = $this->upload('positions', $this->csv($header, [['', '=HYPERLINK("http://evil.test","x")', '', 'NO-SUCH', '', '', '', '']]));
        $report = $this->get('/core/imports/'.$batch->id.'/errors')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $report);
        $this->assertStringNotContainsString(',=HYPERLINK', $report);
        $this->assertDatabaseHas('core_audit_logs', ['action' => 'import.error_report_downloaded', 'subject_id' => $batch->id]);
    }
}
