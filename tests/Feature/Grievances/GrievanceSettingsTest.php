<?php

declare(strict_types=1);

use App\Enums\Grievance\GrievanceLetterLanguage;
use App\Enums\Grievance\GrievanceLetterType;
use App\Models\GrievanceApprovalRule;
use App\Models\GrievanceDelegation;
use App\Models\GrievanceLetterTemplate;
use App\Models\OrganizationSeal;
use App\Services\Grievances\GrievanceCorrespondenceService;
use App\Support\Grievances\GrievanceRoles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\GrievanceScenario;

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00'));
    Storage::fake('local');
    $this->s = GrievanceScenario::build();
    $this->admin = GrievanceScenario::user(GrievanceScenario::employee($this->s->bureau, null), GrievanceRoles::ADMINISTRATOR_PERMISSIONS);
});

afterEach(fn () => Carbon::setTestNow());

function settingsSeal(object $test, object $uploader, string $status = 'pending'): OrganizationSeal
{
    return OrganizationSeal::query()->create([
        'organization_id' => $test->s->bureau->id, 'name' => 'Bureau seal', 'disk' => 'local',
        'path' => 'organization-seals/'.Str::uuid().'.png', 'mime_type' => 'image/png', 'sha256' => str_repeat('a', 64),
        'status' => $status, 'effective_from' => '2026-10-01', 'uploaded_by' => $uploader->id,
    ]);
}

function settingsTemplate(array $overrides = []): array
{
    return [
        'organization_id' => null, 'template_type' => 'acknowledgment', 'language' => 'en', 'name' => 'Acknowledgement',
        'subject_template' => 'Grievance {{case_number}}', 'body_template' => 'Received on {{submission_date}}.',
        'effective_from' => '2026-10-01', 'effective_to' => null, 'is_active' => true, ...$overrides,
    ];
}

it('lets the registry open the settings page to keep custody of seals', function (): void {
    $registry = GrievanceScenario::user(GrievanceScenario::employee($this->s->bureau, null), GrievanceRoles::REGISTRY_PERMISSIONS);
    settingsSeal($this, $this->admin);

    $this->actingAs($registry)->get(route('grievances.settings.index', ['tab' => 'seals']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Grievances/Settings/Index')
            ->where('can.seals', true)->where('can.update', false)->has('seals', 1));
});

it('lists only the seals of organizations in the viewer scope', function (): void {
    $registry = GrievanceScenario::user(GrievanceScenario::employee($this->s->woreda, null), GrievanceRoles::REGISTRY_PERMISSIONS);
    GrievanceScenario::scope($registry, $this->s->woreda);
    settingsSeal($this, $this->admin);

    $this->actingAs($registry)->get(route('grievances.settings.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('seals', 0));
});

it('approves a seal through a second person, retires the previous one and never revives a retired seal', function (): void {
    $uploader = GrievanceScenario::user(GrievanceScenario::employee($this->s->bureau, null), GrievanceRoles::REGISTRY_PERMISSIONS);
    $approver = GrievanceScenario::user(GrievanceScenario::employee($this->s->bureau, null), GrievanceRoles::REGISTRY_PERMISSIONS);

    $this->actingAs($uploader)->post(route('grievances.settings.seals.store'), [
        'organization_id' => $this->s->bureau->id, 'name' => 'Bureau seal',
        'file' => UploadedFile::fake()->image('seal.png', 300, 300),
    ])->assertSessionHasNoErrors();
    $first = OrganizationSeal::query()->firstOrFail();
    expect($first->status)->toBe('pending');

    $this->actingAs($uploader)->post(route('grievances.settings.seals.decide', $first), ['action' => 'approve'])->assertSessionHasErrors('seal');
    $this->actingAs($approver)->post(route('grievances.settings.seals.decide', $first), ['action' => 'approve'])->assertSessionHasNoErrors();
    expect($first->refresh()->status)->toBe('active')->and($first->approved_by)->toBe($approver->id);

    // Approving the replacement retires the current seal: one active seal per organization.
    $second = settingsSeal($this, $uploader);
    $this->actingAs($approver)->post(route('grievances.settings.seals.decide', $second), ['action' => 'approve'])->assertSessionHasNoErrors();
    expect($first->refresh()->status)->toBe('retired')->and($second->refresh()->status)->toBe('active');

    // A retired seal cannot be approved again, and an active seal cannot be re-approved.
    $this->actingAs($approver)->post(route('grievances.settings.seals.decide', $first), ['action' => 'approve'])->assertSessionHasErrors('seal');
    $this->actingAs($approver)->post(route('grievances.settings.seals.decide', $second), ['action' => 'approve'])->assertSessionHasErrors('seal');
    expect($first->refresh()->status)->toBe('retired')
        ->and(OrganizationSeal::query()->where('status', 'active')->count())->toBe(1);

    $this->actingAs($approver)->post(route('grievances.settings.seals.decide', $second), ['action' => 'retire'])->assertSessionHasNoErrors();
    $this->actingAs($approver)->post(route('grievances.settings.seals.decide', $second), ['action' => 'retire'])->assertSessionHasErrors('seal');
});

it('keeps a delegation in force for the whole of its last day', function (): void {
    $manager = GrievanceScenario::user(GrievanceScenario::employee($this->s->bureau, null), ['grievance_settings.manage_delegations']);
    $delegate = GrievanceScenario::user(GrievanceScenario::employee($this->s->bureau, null), GrievanceRoles::APPROVER_PERMISSIONS);

    // Same-day delegation, exactly as the date pickers submit it.
    $this->actingAs($manager)->post(route('grievances.settings.delegations.store'), [
        'delegator_user_id' => $this->s->approver->id, 'delegate_user_id' => $delegate->id,
        'starts_at' => '2026-10-05', 'ends_at' => '2026-10-05', 'reason' => 'One-day mission',
    ])->assertSessionHasNoErrors();

    expect(GrievanceDelegation::query()->firstOrFail()->ends_at->toDateTimeString())->toBe('2026-10-05 23:59:59');
});

it('revokes a delegation once and keeps who revoked it', function (): void {
    $manager = GrievanceScenario::user(GrievanceScenario::employee($this->s->bureau, null), ['grievance_settings.manage_delegations']);
    $other = GrievanceScenario::user(GrievanceScenario::employee($this->s->bureau, null), ['grievance_settings.manage_delegations']);
    $delegation = GrievanceDelegation::query()->create([
        'delegator_user_id' => $this->s->approver->id, 'delegate_user_id' => $this->s->chair->id, 'authority' => 'decision_approval',
        'starts_at' => '2026-10-01 00:00:00', 'ends_at' => '2026-10-31 23:59:59', 'reason' => 'Leave', 'status' => 'active',
    ]);

    $this->actingAs($manager)->post(route('grievances.settings.delegations.revoke', $delegation))->assertSessionHasNoErrors();
    $this->actingAs($other)->post(route('grievances.settings.delegations.revoke', $delegation))->assertSessionHasErrors('delegation');

    expect($delegation->refresh()->status)->toBe('revoked')->and($delegation->revoked_by)->toBe($manager->id);
});

it('lets an approval rule target one committee and rejects a handler that does not exist', function (): void {
    $payload = [
        'name_en' => 'Woreda committee decisions', 'handler_type' => 'committee', 'handler_id' => $this->s->committee->id,
        'requires_approval' => true, 'approver_position_id' => $this->s->headPosition->id,
        'priority' => 10, 'effective_from' => '2026-10-01', 'is_active' => true,
    ];

    $this->actingAs($this->admin)->post(route('grievances.settings.approval-rules.store'), [...$payload, 'handler_id' => (string) Str::uuid()])
        ->assertSessionHasErrors('handler_id');
    $this->actingAs($this->admin)->post(route('grievances.settings.approval-rules.store'), $payload)->assertSessionHasNoErrors();
    expect(GrievanceApprovalRule::query()->where('name_en', 'Woreda committee decisions')->sole()->handler_id)->toBe($this->s->committee->id);

    // Without a handler type there is no specific handler.
    $this->actingAs($this->admin)->post(route('grievances.settings.approval-rules.store'), [...$payload, 'name_en' => 'Any', 'handler_type' => null])
        ->assertSessionHasNoErrors();
    expect(GrievanceApprovalRule::query()->where('name_en', 'Any')->sole()->handler_id)->toBeNull();
});

it('keeps a scoped administrator inside their organizations', function (): void {
    $scoped = GrievanceScenario::user(GrievanceScenario::employee($this->s->woreda, null), GrievanceRoles::ADMINISTRATOR_PERMISSIONS);
    GrievanceScenario::scope($scoped, $this->s->woreda);
    $rule = fn (?string $org) => [
        'name_en' => 'Rule', 'organization_id' => $org, 'requires_approval' => false,
        'priority' => 10, 'effective_from' => '2026-10-01', 'is_active' => true,
    ];

    $this->actingAs($scoped)->post(route('grievances.settings.approval-rules.store'), $rule($this->s->bureau->id))->assertForbidden();
    $this->actingAs($scoped)->post(route('grievances.settings.approval-rules.store'), $rule(null))->assertForbidden();
    $this->actingAs($scoped)->post(route('grievances.settings.templates.store'), settingsTemplate())->assertForbidden();
    $this->actingAs($scoped)->post(route('grievances.settings.approval-rules.store'), $rule($this->s->woreda->id))->assertSessionHasNoErrors();

    // Nor can they move another organization's rule into their own scope.
    $bureauRule = GrievanceApprovalRule::query()->create([...$rule($this->s->bureau->id), 'name_en' => 'Bureau rule']);
    $this->actingAs($scoped)->patch(route('grievances.settings.approval-rules.update', $bureauRule), $rule($this->s->woreda->id))->assertForbidden();
    expect($bureauRule->refresh()->organization_id)->toBe($this->s->bureau->id);
});

it('refuses two active letter templates over the same days', function (): void {
    $bureau = $this->s->bureau->id;
    $active = GrievanceLetterTemplate::query()->where('is_active', true)->count();

    // The seeded default already covers every day for all organizations.
    $this->actingAs($this->admin)->post(route('grievances.settings.templates.store'), settingsTemplate())->assertSessionHasErrors('effective_from');

    $this->actingAs($this->admin)->post(route('grievances.settings.templates.store'), settingsTemplate(['organization_id' => $bureau, 'effective_to' => '2026-12-31']))->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('grievances.settings.templates.store'), settingsTemplate(['organization_id' => $bureau, 'effective_from' => '2026-11-01', 'name' => 'Overlap']))
        ->assertSessionHasErrors('effective_from');
    // An inactive draft or the following year are fine.
    $this->actingAs($this->admin)->post(route('grievances.settings.templates.store'), settingsTemplate(['organization_id' => $bureau, 'is_active' => false, 'name' => 'Draft']))->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('grievances.settings.templates.store'), settingsTemplate(['organization_id' => $bureau, 'effective_from' => '2027-01-01', 'name' => 'Next year']))->assertSessionHasNoErrors();

    // Editing a template does not collide with itself.
    $template = GrievanceLetterTemplate::query()->where('organization_id', $bureau)->where('name', 'Acknowledgement')->sole();
    $this->actingAs($this->admin)->patch(route('grievances.settings.templates.update', $template), settingsTemplate(['organization_id' => $bureau, 'effective_to' => '2026-12-30']))->assertSessionHasNoErrors();

    expect(GrievanceLetterTemplate::query()->where('is_active', true)->count())->toBe($active + 2)
        ->and(app(GrievanceCorrespondenceService::class)->resolveTemplate(GrievanceLetterType::Acknowledgment, GrievanceLetterLanguage::En, $bureau)?->is($template))->toBeTrue();
});
