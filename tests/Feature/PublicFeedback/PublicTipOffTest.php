<?php

declare(strict_types=1);

use App\Actions\SystemSettings\UpdateSystemSettingsGroupAction;
use App\Enums\AssignmentStatus;
use App\Enums\AuditEventType;
use App\Enums\OrganizationScopeType;
use App\Enums\PublicIntakeChannel;
use App\Enums\PublicTipType;
use App\Enums\ServiceFeedbackStatus;
use App\Enums\ServiceFeedbackType;
use App\Enums\SubmitterIdentityMode;
use App\Models\AuditLog;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeServiceFeedback;
use App\Models\Permission;
use App\Models\Position;
use App\Models\PublicFeedbackRoutingRule;
use App\Models\Role;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\ServiceFeedback\OrganizationFeedbackQrService;
use App\Services\ServiceFeedback\PublicFeedbackMetrics;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PublicFeedbackScenario;

/*
 * Public Tip-off (ጥቆማ): two types, anonymous allowed, no service required,
 * optional photo/video/audio evidence, a server-issued reference, the scanned
 * target fixed, confidential tipster identity, and counts that never rank.
 * The employee QR shows the current job designation under the name.
 */

beforeEach(function (): void {
    app()->setLocale('en');
    Storage::fake('local');

    foreach ([
        'service_feedback.view', 'service_feedback.review', 'service_feedback.complaints.view',
        'service_feedback.view_employee_target', 'service_feedback.view_organization_target',
        'service_feedback.view_submitter_identity', 'service_feedback.view_sensitive_attachments',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('Super Admin');

    $this->a = PublicFeedbackScenario::employeeTarget('TIA');
    $this->b = PublicFeedbackScenario::employeeTarget('TIB');
    $this->pageA = '/service-feedback/'.$this->a->token->token;
    $this->orgToken = app(OrganizationFeedbackQrService::class)->ensureActive($this->a->organization, $this->superAdmin)->public_token;

    // These tests send many forms from one address; the throttle has its own tests.
    foreach (['rate_limit_per_source' => 100, 'rate_limit_per_ip' => 500] as $key => $value) {
        app(UpdateSystemSettingsGroupAction::class)->execute('service_feedback', [$key => $value], $this->superAdmin);
    }
    app(SystemSettingsService::class)->clearCache();
    RateLimiter::clear('sf-submit-ip:127.0.0.1');
});

/** Bytes PHP's finfo reads as the named type — content, not a claimed MIME type. */
function tipMedia(string $kind, string $name): UploadedFile
{
    $bytes = match ($kind) {
        'jpeg' => "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00".str_repeat("\x00", 64),
        'mp4' => "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 64),
        'mp3' => str_repeat("\xFF\xFB\x90\x64".str_repeat("\x00", 413), 6),
        'm4a' => "\x00\x00\x00\x20ftypM4A \x00\x00\x00\x00M4A mp42isom\x00\x00\x00\x00".str_repeat("\x00", 64),
        'pdf' => "%PDF-1.4\n%fake\n",
        'exe' => "MZ\x90\x00\x03\x00\x00\x00\x04\x00".str_repeat("\x00", 64),
        'html' => '<!DOCTYPE html><html><script>alert(1)</script></html>',
        'php' => '<?php echo 1; ?>',
    };
    $path = tempnam(sys_get_temp_dir(), 'tip');
    file_put_contents($path, $bytes);

    // `test: true` keeps the real file, so its type is sniffed from content as in production.
    return new UploadedFile($path, $name, null, null, true);
}

function tipUrl(string $target, string $type = 'suggestion'): string
{
    return $target === 'organization' ? '/feedback/'.test()->orgToken.'/'.$type : test()->pageA.'/'.$type;
}

// ── 95–98, 109: the job designation under the employee's name ────────────────

it('shows the current job designation under the name on the employee QR, in the visitor\'s language', function (): void {
    $this->a->position->forceFill(['title_am' => 'የፈቃድ ኦፊሰር'])->save();

    $this->get($this->pageA)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('target.display_name', 'Hanna Girma')
        ->where('target.position_title', 'Licensing Officer'));

    // The visitor's language arrives as the X-Locale header (SetClientLocale).
    $this->withHeaders(['X-Locale' => 'am'])->get($this->pageA)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('target.position_title', 'የፈቃድ ኦፊሰር'));

    // A position with no Amharic title falls back to English, never to nothing.
    $this->a->position->forceFill(['title_am' => null])->save();
    $this->withHeaders(['X-Locale' => 'am'])->get($this->pageA)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('target.position_title', 'Licensing Officer'));
});

it('shows the current post, never a stale one, and follows a transfer on the same QR while old submissions keep their snapshot', function (): void {
    $this->post(tipUrl('employee'), ['message' => 'Before the move.', 'tip_type' => 'IMPROPER_PRACTICE'])->assertRedirect();
    $before = EmployeeServiceFeedback::query()->sole();
    $token = $this->a->token->token;

    // The transfer: the old row is closed, a new one opened active and current.
    $this->a->assignment->forceFill(['is_current' => false, 'assignment_status' => AssignmentStatus::Closed->value, 'effective_to' => now()->subDay()->toDateString()])->save();
    $newPosition = Position::query()->create([
        'organization_id' => $this->b->organization->id, 'organization_unit_id' => $this->b->unit->id,
        'job_position_code' => 'TIB-P9', 'title_en' => 'Senior Inspector', 'is_active' => true,
    ]);
    $moved = EmployeeAssignment::query()->create([
        'employee_id' => $this->a->employee->id, 'organization_id' => $this->b->organization->id,
        'organization_unit_id' => $this->b->unit->id, 'position_id' => $newPosition->id,
        'is_current' => true, 'assignment_status' => AssignmentStatus::Active->value, 'effective_from' => now()->toDateString(),
    ]);
    $this->a->employee->forceFill(['current_assignment_id' => $moved->id])->save();

    $this->get($this->pageA)->assertOk()->assertInertia(fn (Assert $page) => $page->where('target.position_title', 'Senior Inspector'));
    expect($this->a->token->refresh()->token)->toBe($token)
        ->and($before->refresh()->position_id)->toBe($this->a->position->id)
        ->and($before->organization_id)->toBe($this->a->organization->id);
});

it('keeps the QR usable and shows no designation when the employee holds no active post', function (): void {
    $this->a->assignment->forceFill(['assignment_status' => AssignmentStatus::PendingTransfer->value])->save();

    $this->get($this->pageA)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('available', true)
        ->where('target.display_name', 'Hanna Girma')
        ->where('target.position_title', null));

    $this->post(tipUrl('employee'), ['message' => 'Still reachable.', 'tip_type' => 'CORRUPTION'])->assertRedirect()->assertSessionHasNoErrors();
    expect(EmployeeServiceFeedback::query()->sole()->employee_id)->toBe($this->a->employee->id);
});

// ── 99–100, 103, 105: the two Tip-off types from both QR kinds ───────────────

it('takes an anonymous Tip-off of either type from either QR, with no service received', function (string $target, string $tipType): void {
    $this->post(tipUrl($target), ['message' => 'I saw a fee being asked for a free service.', 'tip_type' => $tipType])
        ->assertRedirect(tipUrl($target))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('feedback_receipt', fn (array $receipt): bool => $receipt['tip_type'] === $tipType && str_starts_with((string) $receipt['reference'], 'PTO-'));

    $row = EmployeeServiceFeedback::query()->sole();
    expect($row->type)->toBe(ServiceFeedbackType::Suggestion)
        ->and($row->tip_type)->toBe(PublicTipType::from($tipType))
        ->and($row->intake_channel)->toBe(PublicIntakeChannel::WebQr)
        ->and($row->submitter_identity_mode)->toBe(SubmitterIdentityMode::Optional)
        ->and($row->position_service_id)->toBeNull()
        ->and($row->client_name)->toBeNull()
        ->and($row->client_contact)->toBeNull()
        ->and($row->client_email)->toBeNull()
        ->and($row->reference_number)->toStartWith('PTO-')
        ->and($row->submitted_at)->not->toBeNull()
        ->and($row->rating)->toBeNull();
})->with([
    'employee QR, corruption' => ['employee', 'CORRUPTION'],
    'employee QR, improper practice' => ['employee', 'IMPROPER_PRACTICE'],
    'organization QR, corruption' => ['organization', 'CORRUPTION'],
    'organization QR, improper practice' => ['organization', 'IMPROPER_PRACTICE'],
]);

// ── 101–102: the type is required and closed ─────────────────────────────────

it('refuses a Tip-off with no type, or a forged one, and stores nothing', function (?string $tipType): void {
    $this->post(tipUrl('employee'), array_filter(['message' => 'Facts.', 'tip_type' => $tipType], fn ($v) => $v !== null))
        ->assertSessionHasErrors('tip_type');

    expect(EmployeeServiceFeedback::query()->count())->toBe(0);
})->with([
    'missing' => [null],
    'forged OTHER' => ['OTHER'],
    'lower-case label' => ['corruption'],
    'a translated label' => ['የሙስና ጥቆማ'],
]);

it('refuses a tip type on Feedback and Complaint, and a Tip-off without facts', function (): void {
    $this->post(tipUrl('employee', 'complaint'), pfComplete('complaint', ['message' => 'x', 'tip_type' => 'CORRUPTION']))->assertSessionHasErrors('tip_type');
    $this->post(tipUrl('employee', 'feedback'), pfComplete('feedback', ['rating' => 3, 'position_service_id' => $this->a->positionService->id, 'tip_type' => 'CORRUPTION']))->assertSessionHasErrors('tip_type');
    $this->post(tipUrl('employee'), ['tip_type' => 'CORRUPTION'])->assertSessionHasErrors('message');

    expect(EmployeeServiceFeedback::query()->count())->toBe(0);
});

// ── 104: an identified tipster, stored securely, never in aggregates ─────────

it('stores a tipster\'s details encrypted and confidential, and keeps them out of every aggregate view', function (): void {
    $this->post(tipUrl('organization'), [
        'message' => 'An officer asks for money.', 'tip_type' => 'CORRUPTION',
        'client_name' => 'Tipster Tesfaye', 'client_contact' => '0911 55 66 77', 'client_email' => 'tipster@example.test',
        'submitter_address' => ['city' => 'Addis Ababa', 'sub_city' => 'Bole', 'woreda' => '', 'detail' => ''],
        'involved_parties' => 'A cashier at window 3',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $row = EmployeeServiceFeedback::query()->sole();
    expect($row->client_name)->toBe('Tipster Tesfaye')
        ->and($row->client_email)->toBe('tipster@example.test')
        ->and($row->submitter_address)->toBe(['city' => 'Addis Ababa', 'sub_city' => 'Bole'])
        ->and($row->involved_parties)->toBe('A cashier at window 3');

    // Encrypted at rest: neither the e-mail nor the address is readable in the table.
    $raw = DB::table('employee_service_feedback')->where('id', $row->id)->first();
    expect((string) $raw->client_email)->not->toContain('tipster@')
        ->and((string) $raw->submitter_address)->not->toContain('Bole');

    // Model serialisation never carries identity.
    expect(json_encode($row->toArray()))->not->toContain('Tipster Tesfaye')->not->toContain('0911 55 66 77');

    // Aggregates: the reports page and the dashboard show counts, never the tipster.
    $report = $this->actingAs($this->superAdmin)->get(route('service-feedback.submission-reports.index'))->assertOk();
    $dashboard = $this->actingAs($this->superAdmin)->get(route('dashboard'))->assertOk();
    foreach ([$report, $dashboard] as $response) {
        expect((string) $response->getContent())
            ->not->toContain('Tipster Tesfaye')->not->toContain('tipster@example.test')->not->toContain('0911 55 66 77')
            ->not->toContain('An officer asks for money.');
    }
});

it('hides the tipster from a reviewer without the identity grant and audits each view by one who has it', function (): void {
    $this->post(tipUrl('employee'), ['message' => 'Facts.', 'tip_type' => 'IMPROPER_PRACTICE', 'client_name' => 'Hidden Name', 'client_contact' => '0911223344'])->assertRedirect();
    $row = EmployeeServiceFeedback::query()->sole();

    $reviewer = User::factory()->create();
    $reviewer->givePermissionTo(['service_feedback.view', 'service_feedback.view_employee_target']);
    $this->actingAs($reviewer)->get(route('service-submissions.show', ['type' => 'suggestion', 'submission' => $row]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('entry.identity_hidden', true)->where('entry.client_name', null)->where('entry.client_contact', null)->where('entry.tip_type', 'IMPROPER_PRACTICE'));
    expect(AuditLog::query()->where('event_type', AuditEventType::PublicSubmitterIdentityViewed->value)->count())->toBe(0);

    $this->actingAs($this->superAdmin)->get(route('service-submissions.show', ['type' => 'suggestion', 'submission' => $row]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('entry.client_name', 'Hidden Name')->where('entry.intake_channel', 'WEB_QR'));
    $audit = AuditLog::query()->where('event_type', AuditEventType::PublicSubmitterIdentityViewed->value)->sole();
    expect(json_encode($audit->toArray()))->not->toContain('Hidden Name')->not->toContain('0911223344');
});

// ── 106–107: evidence ────────────────────────────────────────────────────────

it('accepts photo, video, audio, mixed or no evidence on a Tip-off and a Complaint, stored privately', function (string $type, array $kinds): void {
    $files = array_map(fn (array $k): UploadedFile => tipMedia($k[0], $k[1]), $kinds);
    $payload = pfComplete($type, ['message' => 'With evidence.', 'tip_type' => $type === 'suggestion' ? 'CORRUPTION' : null]);
    $payload = array_filter($payload, fn ($v) => $v !== null) + ($files === [] ? [] : ['attachments' => $files]);

    $this->post(tipUrl('employee', $type), $payload)->assertRedirect()->assertSessionHasNoErrors();

    $row = EmployeeServiceFeedback::query()->sole();
    expect($row->attachments)->toHaveCount(count($kinds));
    foreach ($row->attachments as $attachment) {
        Storage::disk('local')->assertExists($attachment->path);
        expect($attachment->path)->toStartWith('service-feedback/'.$row->id.'/')
            ->and($attachment->scan_status)->toBe('not_scanned')
            ->and($attachment->toArray())->not->toHaveKey('path');
    }
})->with([
    'Tip-off, photo' => ['suggestion', [['jpeg', 'photo.jpg']]],
    'Tip-off, video' => ['suggestion', [['mp4', 'clip.mp4']]],
    'Tip-off, audio' => ['suggestion', [['mp3', 'voice.mp3']]],
    'Tip-off, mixed' => ['suggestion', [['jpeg', 'a.jpg'], ['mp4', 'b.mp4'], ['m4a', 'c.m4a'], ['pdf', 'd.pdf']]],
    'Tip-off, none' => ['suggestion', []],
    'Complaint, photo' => ['complaint', [['jpeg', 'photo.jpg']]],
    'Complaint, video' => ['complaint', [['mp4', 'clip.mp4']]],
    'Complaint, audio' => ['complaint', [['m4a', 'voice.m4a']]],
    'Complaint, mixed' => ['complaint', [['jpeg', 'a.jpg'], ['mp3', 'b.mp3'], ['pdf', 'c.pdf']]],
    'Complaint, none' => ['complaint', []],
]);

it('refuses executable, script and spoofed evidence, oversize files and too many files', function (UploadedFile|array $files, string $errorKey): void {
    $this->post(tipUrl('employee'), ['message' => 'Bad file.', 'tip_type' => 'CORRUPTION', 'attachments' => is_array($files) ? $files : [$files]])
        ->assertSessionHasErrors($errorKey);

    expect(EmployeeServiceFeedback::query()->count())->toBe(0);
})->with([
    'executable named as a photo' => fn () => [tipMedia('exe', 'photo.jpg'), 'attachments.0'],
    'HTML named as a video' => fn () => [tipMedia('html', 'clip.mp4'), 'attachments.0'],
    'PHP named as audio' => fn () => [tipMedia('php', 'voice.mp3'), 'attachments.0'],
    'real photo with a script extension' => fn () => [tipMedia('jpeg', 'photo.php'), 'attachments.0'],
    'double extension' => fn () => [tipMedia('jpeg', 'photo.php.jpg'), 'attachments.0'],
    'photo over its limit' => fn () => [UploadedFile::fake()->create('big.jpg', 6 * 1024, 'image/jpeg'), 'attachments.0'],
    'six files' => fn () => [array_map(fn (int $i) => tipMedia('jpeg', "p{$i}.jpg"), range(1, 6)), 'attachments'],
]);

it('lets an authorized reviewer download Tip-off evidence, audited, and refuses one without the evidence grant', function (): void {
    $this->post(tipUrl('employee'), ['message' => 'Evidence.', 'tip_type' => 'CORRUPTION', 'attachments' => [tipMedia('jpeg', 'photo.jpg')]])->assertRedirect();
    $row = EmployeeServiceFeedback::query()->sole();
    $file = $row->attachments()->sole();
    $url = route('service-submissions.attachments.download', ['type' => 'suggestion', 'submission' => $row, 'attachment' => $file]);

    $this->actingAs($this->superAdmin)->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    expect(AuditLog::query()->where('event_type', AuditEventType::ServiceTipOffAttachmentDownloaded->value)->count())->toBe(1);

    $reviewer = User::factory()->create();
    $reviewer->givePermissionTo(['service_feedback.view', 'service_feedback.view_employee_target']);
    $this->actingAs($reviewer)->get($url)->assertForbidden();
    // A complaint URL cannot reach a Tip-off's file.
    $this->actingAs($this->superAdmin)->get(route('service-submissions.attachments.download', ['type' => 'complaint', 'submission' => $row, 'attachment' => $file]))->assertNotFound();
});

// ── 59, 108: the scanned target is fixed ─────────────────────────────────────

it('keeps the scanned target whatever the payload or the narrative names', function (): void {
    $this->post(tipUrl('employee'), [
        'message' => 'It was really '.$this->b->employee->full_name.'.', 'tip_type' => 'CORRUPTION',
        'involved_parties' => $this->b->employee->full_name.' at '.$this->b->organization->name_en,
        'employee_id' => $this->b->employee->id, 'organization_id' => $this->b->organization->id,
        'organization_target_id' => $this->b->organization->id, 'target_type' => 'organization',
        'employee_assignment_id' => $this->b->assignment->id, 'intake_channel' => 'PHONE', 'reference_number' => 'PTO-1999-000001',
    ])->assertRedirect();

    $row = EmployeeServiceFeedback::query()->sole();
    expect($row->employee_id)->toBe($this->a->employee->id)
        ->and($row->organization_target_id)->toBeNull()
        ->and($row->organization_id)->toBe($this->a->organization->id)
        ->and($row->employee_assignment_id)->toBe($this->a->assignment->id)
        ->and($row->intake_channel)->toBe(PublicIntakeChannel::WebQr)
        ->and($row->reference_number)->not->toBe('PTO-1999-000001');

    $this->post('/feedback/'.$this->orgToken.'/suggestion', ['message' => 'Org.', 'tip_type' => 'IMPROPER_PRACTICE', 'organization_target_id' => $this->b->organization->id, 'organization_id' => $this->b->organization->id])->assertRedirect();
    expect(EmployeeServiceFeedback::query()->where('target_type', 'organization')->sole()->organization_target_id)->toBe($this->a->organization->id);
});

// ── 60–61: no automatic discipline ───────────────────────────────────────────

it('never starts discipline, a referral or a score change from a Tip-off', function (): void {
    $this->post(tipUrl('employee'), ['message' => 'Corruption.', 'tip_type' => 'CORRUPTION'])->assertRedirect();

    expect(DB::table('public_feedback_referrals')->count())->toBe(0)
        ->and(DB::table('disciplinary_cases')->count())->toBe(0)
        ->and($this->a->employee->refresh()->status->value)->toBe('active')
        ->and($this->a->employee->current_assignment_id)->toBe($this->a->assignment->id);
});

// ── 68–69, 114: routing ──────────────────────────────────────────────────────

it('routes a corruption Tip-off only through a rule that names corruption, and keeps it unrouted otherwise', function (): void {
    $officer = User::factory()->create();
    $officer->givePermissionTo(['service_feedback.view', 'service_feedback.view_employee_target']);
    UserOrganizationScope::query()->create(['user_id' => $officer->id, 'organization_id' => $this->a->organization->id, 'scope_type' => OrganizationScopeType::Self]);
    // A generic rule written for suggestions before Tip-off types existed.
    PublicFeedbackRoutingRule::query()->create(['organization_id' => $this->a->organization->id, 'submission_type' => 'suggestion', 'handler_type' => 'user', 'handler_id' => (string) $officer->id, 'priority' => 10, 'is_active' => true, 'created_by' => $this->superAdmin->id]);

    $this->post(tipUrl('employee'), ['message' => 'Corruption one.', 'tip_type' => 'CORRUPTION'])->assertRedirect();
    $this->post(tipUrl('employee'), ['message' => 'Improper one.', 'tip_type' => 'IMPROPER_PRACTICE'])->assertRedirect();
    $rows = EmployeeServiceFeedback::query()->get()->keyBy(fn ($row) => $row->tip_type->value);
    expect($rows['CORRUPTION']->status)->toBe(ServiceFeedbackStatus::Unrouted)
        ->and($rows['IMPROPER_PRACTICE']->status)->toBe(ServiceFeedbackStatus::Assigned);

    $designated = User::factory()->create();
    $designated->givePermissionTo(['service_feedback.view', 'service_feedback.view_employee_target']);
    UserOrganizationScope::query()->create(['user_id' => $designated->id, 'organization_id' => $this->a->organization->id, 'scope_type' => OrganizationScopeType::Self]);
    PublicFeedbackRoutingRule::query()->create(['organization_id' => $this->a->organization->id, 'submission_type' => 'suggestion', 'tip_type' => 'CORRUPTION', 'handler_type' => 'user', 'handler_id' => (string) $designated->id, 'priority' => 10, 'is_active' => true, 'created_by' => $this->superAdmin->id]);

    $this->post(tipUrl('employee'), ['message' => 'Corruption two.', 'tip_type' => 'CORRUPTION'])->assertRedirect();
    $routed = EmployeeServiceFeedback::query()->where('comment', 'Corruption two.')->sole();
    expect($routed->status)->toBe(ServiceFeedbackStatus::Assigned)
        ->and($routed->currentAssignment?->handler_id)->toBe((string) $designated->id);
});

it('refuses a tip type on a complaint routing rule', function (): void {
    $this->actingAs($this->superAdmin)->post(route('service-feedback.routing-rules.store'), [
        'organization_id' => $this->a->organization->id, 'submission_type' => 'complaint', 'tip_type' => 'CORRUPTION',
        'handler_type' => 'user', 'handler_id' => (string) $this->superAdmin->id,
    ])->assertSessionHasErrors('tip_type');
});

// ── 63–67, 111–113: analytics reconcile, never rank, keep legacy rows honest ─

it('splits Tip-offs into the two types plus legacy unclassified rows, and every view adds up', function (): void {
    foreach (['CORRUPTION', 'CORRUPTION', 'IMPROPER_PRACTICE'] as $i => $tipType) {
        $this->post(tipUrl('employee'), ['message' => "Tip {$i}", 'tip_type' => $tipType])->assertRedirect();
    }
    $this->post(tipUrl('organization'), ['message' => 'Org tip', 'tip_type' => 'IMPROPER_PRACTICE'])->assertRedirect();
    // A suggestion stored before Tip-off types existed: kept as it is, never back-filled.
    $legacy = EmployeeServiceFeedback::query()->create([
        'employee_id' => $this->b->employee->id, 'organization_id' => $this->b->organization->id,
        'type' => ServiceFeedbackType::Suggestion, 'comment' => 'Open earlier.', 'status' => ServiceFeedbackStatus::Pending, 'submitted_at' => now(),
    ]);

    $metrics = app(PublicFeedbackMetrics::class);
    $all = $metrics->tipOffs($metrics->query($this->superAdmin));
    expect($all)->toBe(['total' => 5, 'CORRUPTION' => 2, 'IMPROPER_PRACTICE' => 2, 'UNCLASSIFIED_LEGACY' => 1])
        ->and($all['total'])->toBe(EmployeeServiceFeedback::query()->where('type', 'suggestion')->count())
        ->and($legacy->refresh()->tip_type)->toBeNull();

    $byOrg = $metrics->tipOffsByOrganization($metrics->query($this->superAdmin))->keyBy('id');
    expect($byOrg[$this->a->organization->id]['total'])->toBe(4)
        ->and($byOrg[$this->a->organization->id]['CORRUPTION'])->toBe(2)
        ->and($byOrg[$this->b->organization->id]['UNCLASSIFIED_LEGACY'])->toBe(1)
        ->and($byOrg->sum('total'))->toBe($all['total']);

    // The type filter narrows to that type only.
    expect($metrics->query($this->superAdmin, ['tip_type' => 'CORRUPTION'])->count())->toBe(2)
        ->and($metrics->query($this->superAdmin, ['tip_type' => 'UNCLASSIFIED_LEGACY'])->count())->toBe(1);

    // Employee-targeted counts exist only inside one institution, listed by name.
    expect($metrics->employeeTargetedTipOffs($metrics->query($this->superAdmin), $this->superAdmin, []))->toHaveCount(0);
    $filters = ['organization_id' => $this->a->organization->id];
    $people = $metrics->employeeTargetedTipOffs($metrics->query($this->superAdmin, $filters), $this->superAdmin, $filters);
    expect($people)->toHaveCount(1)
        ->and($people[0]['full_name'])->toBe('Hanna Girma')
        ->and($people[0]['total'])->toBe(3);

    // The wall-display dashboard: the same counts, nothing else.
    $this->actingAs($this->superAdmin)->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('cards.tipOffs.total', 5)
        ->where('cards.tipOffs.corruption', 2)
        ->where('cards.tipOffs.improperPractice', 2)
        ->where('cards.tipOffs.unclassified', 1));

    $this->actingAs($this->superAdmin)->get(route('service-feedback.submission-reports.index', ['tip_type' => 'CORRUPTION']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('tipOffs.total', 2)->where('summary.total', 2));
    $this->actingAs($this->superAdmin)->get(route('service-submissions.suggestions', ['tip_type' => 'UNCLASSIFIED_LEGACY']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('entries.total', 1)->where('entries.data.0.tip_type', null));
});

it('labels nothing as a finding or a ranking in the Tip-off analytics', function (): void {
    foreach (['en', 'am'] as $locale) {
        $source = (string) file_get_contents(resource_path("js/i18n/{$locale}/serviceFeedback.ts"));
        expect($source)->not->toMatch('/most corrupt|worst employee|corrupt employees|ranking of/i');
    }
});
