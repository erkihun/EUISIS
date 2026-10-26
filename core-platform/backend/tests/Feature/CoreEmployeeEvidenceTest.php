<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\EmployeeVerification;
use App\Models\CoreAuditLog;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsGoldenPath;
use Tests\TestCase;

/** P2 (ADR 0009): employee documents, photo and signature — private, versioned, verified, audited. */
class CoreEmployeeEvidenceTest extends TestCase
{
    use BuildsGoldenPath, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGoldenPath();
        Storage::fake('evidence');
        Storage::fake('public');
    }

    private function signIn(string $role = 'core_officer', ?Organization $scope = null): User
    {
        $user = User::query()->create([
            'name' => $role.' '.Str::random(4), 'email' => Str::random(8).'@core.test', 'password' => 'secret-password',
            'role' => $role, 'scope_organization_id' => $scope?->id,
            'scope_type' => $scope === null ? User::SCOPE_CITYWIDE : User::SCOPE_SUBTREE, 'is_active' => true,
        ]);
        Auth::forgetGuards();
        $this->actingAs($user, 'web');

        return $user;
    }

    private function pdf(string $name = 'letter.pdf', string $body = 'BT /F1 12 Tf (Appointment) Tj ET'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n{$body}\n%%EOF");
    }

    private function url(string $suffix = ''): string
    {
        return '/employees/'.$this->employee->id.'/documents'.$suffix;
    }

    private function upload(array $data, ?Employee $employee = null): EmployeeDocument
    {
        $before = EmployeeDocument::query()->pluck('id')->all();
        $this->post('/employees/'.($employee ?? $this->employee)->id.'/documents', $data)->assertSessionHasNoErrors();

        return EmployeeDocument::query()->whereNotIn('id', $before)->sole();
    }

    public function test_a_document_is_stored_privately_with_its_checksum_and_never_exposes_its_path(): void
    {
        $officer = $this->signIn();
        $file = $this->pdf();
        $document = $this->upload(['kind' => 'document', 'document_type' => 'appointment_letter', 'title' => 'Appointment', 'file' => $file]);

        $this->assertSame('evidence', $document->storage_disk);
        Storage::disk('evidence')->assertExists($document->storage_path);
        Storage::disk('public')->assertMissing($document->storage_path);
        $this->assertStringStartsWith($this->employee->id.'/document/', $document->storage_path);
        $this->assertSame(hash_file('sha256', $file->getRealPath()), $document->checksum);
        $this->assertSame(['pending', 1, true], [$document->status, $document->version, $document->is_current]);

        $page = $this->get('/employees/'.$this->employee->id)->assertOk()
            ->assertInertia(fn ($page) => $page->where('evidence.documents.items.0.current.document_type', 'appointment_letter'))->viewData('page');
        $this->assertStringNotContainsString($document->storage_path, json_encode($page));
        $audit = CoreAuditLog::query()->where('action', 'employee_document.uploaded')->sole();
        $this->assertSame((string) $officer->id, (string) $audit->actor_user_id);
        $this->assertSame($document->checksum, $audit->new_values['checksum']);
        $this->assertStringNotContainsString('Appointment) Tj', json_encode($audit->toArray()));
    }

    public function test_the_server_refuses_spoofed_oversized_empty_and_active_files(): void
    {
        $this->signIn();
        $existing = EmployeeDocument::query()->pluck('id')->all();
        $type = ['kind' => 'document', 'document_type' => 'identity_document'];

        // Text pretending to be a PDF, HTML pretending to be an image, an image with a PDF name, a script-laden PDF.
        $this->post($this->url(), $type + ['file' => UploadedFile::fake()->createWithContent('id.pdf', 'just text')])->assertSessionHasErrors('file');
        $this->post($this->url(), ['kind' => 'photo', 'file' => UploadedFile::fake()->createWithContent('face.png', '<html><script>alert(1)</script></html>')])->assertSessionHasErrors('file');
        $this->post($this->url(), $type + ['file' => UploadedFile::fake()->image('scan.pdf', 400, 500)])->assertSessionHasErrors('file');
        $this->post($this->url(), $type + ['file' => $this->pdf('active.pdf', '/OpenAction << /S /JavaScript /JS (app.alert(1)) >>')])->assertSessionHasErrors('file');
        $this->post($this->url(), $type + ['file' => UploadedFile::fake()->createWithContent('empty.pdf', '')])->assertSessionHasErrors('file');
        $this->post($this->url(), ['kind' => 'photo', 'file' => UploadedFile::fake()->createWithContent('drawing.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>')])->assertSessionHasErrors('file');
        DB::table('core_settings')->insert(['group' => 'files', 'key' => 'document_max_kb', 'value' => json_encode(100), 'created_at' => now(), 'updated_at' => now()]);
        cache()->forget('core.settings.values');
        $this->post($this->url(), $type + ['file' => $this->pdf('big.pdf', str_repeat('A', 120 * 1024))])->assertSessionHasErrors('file');
        // A photo too small, or sideways, for a card.
        $this->post($this->url(), ['kind' => 'photo', 'file' => UploadedFile::fake()->image('small.jpg', 120, 160)])->assertSessionHasErrors('file');
        $this->post($this->url(), ['kind' => 'photo', 'file' => UploadedFile::fake()->image('wide.jpg', 800, 400)])->assertSessionHasErrors('file');

        $this->assertSame($existing, EmployeeDocument::query()->pluck('id')->all());
        $this->assertSame([], Storage::disk('evidence')->allFiles());
        // A double extension is only a name: the real JPEG is stored under a generated name.
        $photo = $this->upload(['kind' => 'photo', 'file' => UploadedFile::fake()->image('../../evil.php.jpg', 400, 500)]);
        $this->assertStringEndsWith('.jpg', $photo->storage_path);
        $this->assertStringNotContainsString('php', $photo->storage_path);
        $this->assertSame('evil.php.jpg', $photo->original_filename);
    }

    public function test_a_replacement_adds_a_version_and_keeps_the_history(): void
    {
        $this->signIn();
        $first = $this->upload(['kind' => 'document', 'document_type' => 'appointment_letter', 'file' => $this->pdf('v1.pdf', 'first')]);
        // The same bytes again are not a new version.
        $this->post($this->url('/'.$first->id.'/replace'), ['file' => $this->pdf('v1-again.pdf', 'first')])->assertSessionHasErrors('file');
        $this->post($this->url('/'.$first->id.'/replace'), ['file' => $this->pdf('v2.pdf', 'second')])->assertSessionHasNoErrors();
        // Only the current version is replaced: version 1 is history now.
        $this->post($this->url('/'.$first->id.'/replace'), ['file' => $this->pdf('v3.pdf', 'third')])->assertForbidden();
        $second = EmployeeDocument::query()->where('lineage_id', $first->id)->where('is_current', true)->sole();
        $this->post($this->url('/'.$second->id.'/replace'), ['file' => $this->pdf('v3.pdf', 'third')])->assertSessionHasNoErrors();

        $versions = EmployeeDocument::query()->where('lineage_id', $first->id)->orderBy('version')->get();
        $this->assertSame([1, 2, 3], $versions->pluck('version')->all());
        $this->assertSame([false, false, true], $versions->pluck('is_current')->all());
        $this->assertSame(['replaced', 'replaced', 'pending'], $versions->pluck('status')->all());
        $this->assertSame($versions[1]->id, $versions[0]->replaced_by_id);
        $this->get($this->url('/'.$first->id.'/file'))->assertOk();
        $this->assertSame(2, CoreAuditLog::query()->where('action', 'employee_document.replaced')->count());
    }

    public function test_only_someone_else_verifies_and_a_rejection_needs_a_reason(): void
    {
        $uploader = $this->signIn();
        $document = $this->upload(['kind' => 'document', 'document_type' => 'identity_document', 'file' => $this->pdf()]);
        $this->post($this->url('/'.$document->id.'/verify'))->assertForbidden();

        $this->signIn('core_viewer');
        $this->post($this->url('/'.$document->id.'/verify'))->assertForbidden();

        $verifier = $this->signIn();
        $this->post($this->url('/'.$document->id.'/reject'), ['notes' => ''])->assertSessionHasErrors('notes');
        $this->post($this->url('/'.$document->id.'/verify'), ['notes' => 'Original seen'])->assertSessionHasNoErrors();
        $document->refresh();
        $this->assertSame([EmployeeDocument::VERIFIED, $verifier->id], [$document->status, (int) $document->verified_by]);
        $this->assertNotSame($uploader->id, (int) $document->verified_by);
        $this->post($this->url('/'.$document->id.'/reject'), ['notes' => 'Changed my mind'])->assertForbidden();
        $this->assertDatabaseHas('core_audit_logs', ['action' => 'employee_document.verified', 'subject_id' => $document->id, 'actor_user_id' => $verifier->id]);
    }

    public function test_reading_needs_the_permission_and_the_scope_and_is_audited(): void
    {
        $this->signIn();
        $document = $this->upload(['kind' => 'document', 'document_type' => 'identity_document', 'file' => $this->pdf()]);
        $photo = $this->upload(['kind' => 'photo', 'file' => UploadedFile::fake()->image('photo.jpg', 400, 500)]);
        $signature = $this->upload(['kind' => 'signature', 'file' => UploadedFile::fake()->image('signature.png', 400, 120)]);

        $download = $this->get($this->url('/'.$document->id.'/file'))->assertOk();
        $this->assertStringContainsString('attachment', (string) $download->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $download->headers->get('X-Content-Type-Options'));
        $this->get($this->url('/'.$photo->id.'/file?inline=1'))->assertOk();
        $this->get($this->url('/'.$signature->id.'/file?inline=1'))->assertOk();
        $this->assertSame([$document->id, $signature->id], CoreAuditLog::query()->where('action', 'employee_document.downloaded')->orderBy('id')->pluck('subject_id')->all());

        // A viewer sees the metadata and the photo, not the document file nor the signature.
        $this->signIn('core_viewer');
        $this->get('/employees/'.$this->employee->id)->assertInertia(fn ($page) => $page->where('evidence.signature', null)
            ->where('evidence.documents.items.0.current.urls.file', null));
        $this->get($this->url('/'.$document->id.'/file'))->assertForbidden();
        $this->get($this->url('/'.$signature->id.'/file'))->assertForbidden();
        $this->get($this->url('/'.$photo->id.'/file?inline=1'))->assertOk();

        // Out of scope: the employee is not found, whatever the document id.
        $this->signIn('core_officer', $this->bureauB);
        $this->get($this->url('/'.$document->id.'/file'))->assertNotFound();
        $this->post($this->url(), ['kind' => 'document', 'document_type' => 'other', 'file' => $this->pdf()])->assertNotFound();
        // Another employee's id with this document is not found either.
        $other = $this->placedEmployee('EMP-0002', $this->positionB, '2024-01-01');
        $this->get('/employees/'.$other->id.'/documents/'.$document->id.'/file')->assertNotFound();
        // No account without a scope reads anything.
        $unscoped = $this->signIn();
        $unscoped->forceFill(['scope_type' => null])->save();
        $this->get($this->url('/'.$photo->id.'/file?inline=1'))->assertForbidden();
    }

    public function test_archiving_keeps_the_row_and_only_an_unused_draft_upload_is_discarded(): void
    {
        $this->signIn();
        $document = $this->upload(['kind' => 'document', 'document_type' => 'other', 'file' => $this->pdf()]);
        // The placed employee is approved: the upload is archived, never deleted.
        $this->delete($this->url('/'.$document->id))->assertSessionHasErrors('document');
        $this->post($this->url('/'.$document->id.'/archive'), ['reason' => 'Uploaded to the wrong employee'])->assertSessionHasNoErrors();
        $this->assertSame(EmployeeDocument::ARCHIVED, $document->fresh()->status);
        Storage::disk('evidence')->assertExists($document->storage_path);

        $draft = $this->placedEmployee('EMP-0009', $this->positionA, '2024-01-01');
        $draft->forceFill(['status' => Employee::DRAFT])->save();
        $this->post('/employees/'.$draft->id.'/documents', ['kind' => 'document', 'document_type' => 'other', 'file' => $this->pdf()])->assertSessionHasNoErrors();
        $unused = EmployeeDocument::query()->where('employee_id', $draft->id)->where('kind', 'document')->sole();
        $this->delete('/employees/'.$draft->id.'/documents/'.$unused->id)->assertSessionHasNoErrors();
        $this->assertModelMissing($unused);
        Storage::disk('evidence')->assertMissing($unused->storage_path);
        $this->assertDatabaseHas('core_audit_logs', ['action' => 'employee_document.discarded', 'subject_id' => $unused->id]);
    }

    public function test_approval_can_require_a_verified_photo_and_documents(): void
    {
        DB::table('core_settings')->insert([
            ['group' => 'workforce', 'key' => 'verification_requires_photo', 'value' => json_encode(true), 'created_at' => now(), 'updated_at' => now()],
            ['group' => 'workforce', 'key' => 'required_document_types', 'value' => json_encode(['appointment_letter']), 'created_at' => now(), 'updated_at' => now()],
        ]);
        cache()->forget('core.settings.values');
        $draft = $this->placedEmployee('EMP-0010', $this->positionB, '2024-01-01');
        EmployeeDocument::query()->where('employee_id', $draft->id)->delete();
        $verifier = $this->signIn();
        $draft->forceFill(['status' => Employee::VERIFIED, 'verification_status' => 'verified', 'verified_by' => $verifier->id])->save();
        $draft->currentAssignment->forceFill(['assignment_status' => 'pending'])->save();
        $approver = $this->signIn('core_admin');
        $approve = fn () => app(EmployeeVerification::class)->approve($draft->fresh(), $approver);
        $codes = fn (): array => rescue(fn () => $approve() ? [] : [], fn (ValidationException $e) => array_keys($e->errors()), false);
        $this->assertSame(['evidence.PHOTO_REQUIRED', 'evidence.REQUIRED_DOCUMENT_MISSING:appointment_letter'], $codes());

        $this->signIn();
        $this->upload(['kind' => 'photo', 'file' => UploadedFile::fake()->image('photo.jpg', 400, 500)], $draft);
        $this->upload(['kind' => 'document', 'document_type' => 'appointment_letter', 'file' => $this->pdf()], $draft);
        $this->assertSame(['evidence.PHOTO_NOT_VERIFIED', 'evidence.REQUIRED_DOCUMENT_NOT_VERIFIED:appointment_letter'], $codes());

        $this->signIn();
        foreach (EmployeeDocument::query()->where('employee_id', $draft->id)->get() as $evidence) {
            $this->post('/employees/'.$draft->id.'/documents/'.$evidence->id.'/verify')->assertSessionHasNoErrors();
        }
        $this->assertSame(Employee::ACTIVE, $approve()->status);
    }

    public function test_the_integration_api_never_exposes_evidence(): void
    {
        $this->signIn();
        $this->upload(['kind' => 'photo', 'file' => UploadedFile::fake()->image('photo.jpg', 400, 500)]);
        $this->actingAsCore('core_admin');
        $secret = $this->postJson('/api/v1/api-clients', ['name' => 'HR', 'scope_type' => 'citywide', 'scopes' => ['employees:basic-read']])->json('secret');
        Auth::forgetGuards();
        $body = $this->getJson('/api/v1/integration/employees/'.$this->employee->id, ['Authorization' => 'Bearer '.$secret])->assertOk()->getContent();

        foreach (['photo', 'signature', 'document', 'national_id', 'storage', 'evidence'] as $field) {
            $this->assertStringNotContainsString($field, $body);
        }
    }
}
