<?php

use App\Enums\OrganizationStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\ExternalApplication;
use App\Models\IdCard;
use App\Models\NfcCredential;
use App\Models\NfcVerificationLog;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\Permission;
use App\Models\ServiceTerminal;
use App\Models\User;
use App\Services\Nfc\NfcCredentialService;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Give a role every NFC permission it needs for the screen under test. */
function nfcGrant(User $user, array $permissions, string $roleName): User
{
    $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);

    foreach ($permissions as $name) {
        $role->givePermissionTo(Permission::firstOrCreate(
            ['name' => $name, 'guard_name' => 'web'],
            ['group' => 'nfc', 'label_en' => $name, 'description_en' => $name],
        ));
    }

    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->fresh();
}

function nfcOrganization(string $name): Organization
{
    $type = OrganizationType::query()->firstOrCreate(
        ['code' => 'NFC-UI-TYPE'],
        ['name_en' => 'NFC UI Type', 'is_active' => true],
    );

    return Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'NFC-ORG-'.uniqid(),
        'name_en' => $name,
        'status' => OrganizationStatus::Active,
        'effective_from' => now()->toDateString(),
    ]);
}

/** An employee + approved card + active NFC credential inside one organization. */
function nfcCardFor(Organization $organization, string $suffix): IdCard
{
    $employee = Employee::create([
        'employee_number' => "UI-EMP-{$suffix}",
        'first_name' => 'Ui',
        'last_name' => $suffix,
        'full_name' => "Ui {$suffix}",
        'status' => 'active',
    ]);

    $assignment = EmployeeAssignment::create([
        'employee_id' => $employee->id,
        'organization_id' => $organization->id,
        'assignment_status' => 'active',
        'effective_from' => now()->subYear()->toDateString(),
        'is_current' => true,
    ]);

    $employee->update(['current_assignment_id' => $assignment->id]);

    return IdCard::create([
        'employee_id' => $employee->id,
        'card_number' => "UI-CARD-{$suffix}",
        'status' => 'active',
        'is_current' => true,
        'public_card_uuid' => (string) Str::uuid(),
        'qr_status' => 'active',
        'expires_at' => now()->addYear(),
        'activated_at' => now(),
    ]);
}

beforeEach(function () {
    $this->organization = nfcOrganization('NFC Org A');
    $this->otherOrganization = nfcOrganization('NFC Org B');

    $this->card = nfcCardFor($this->organization, 'A');
    $this->otherCard = nfcCardFor($this->otherOrganization, 'B');

    $this->admin = nfcGrant(User::factory()->create(), [], 'Super Admin');

    $this->credential = app(NfcCredentialService::class)->provision($this->card, $this->admin);
    $this->otherCredential = app(NfcCredentialService::class)->provision($this->otherCard, $this->admin);
});

// ── Access control ───────────────────────────────────────────────────────

it('denies every NFC management screen to a user without permissions', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('nfc-management.dashboard'))->assertForbidden();
    $this->get(route('nfc-management.credentials.index'))->assertForbidden();
    $this->get(route('nfc-management.terminals.index'))->assertForbidden();
    $this->get(route('nfc-management.logs.index'))->assertForbidden();
});

it('opens the dashboard for an authorized user with aggregate counts', function () {
    $this->actingAs($this->admin)
        ->get(route('nfc-management.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('NfcManagement/Dashboard')
            ->where('summary.credentials', 2)
            ->where('summary.statuses.pending', 2)
            ->where('summary.statuses.active', 0)
            ->has('trend', 14));
});

it('lists credentials with server-side pagination metadata', function () {
    $this->actingAs($this->admin)
        ->get(route('nfc-management.credentials.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('NfcManagement/Credentials/Index')
            ->has('credentials.data', 2)
            ->has('credentials.meta.total'));
});

it('scopes credentials, logs and detail pages to the users own organization', function () {
    $scoped = nfcGrant(User::factory()->create(), [
        'nfc_credentials.view', 'nfc_logs.view', 'nfc_terminals.view',
    ], 'Organizational Admin');

    $scoped->organizationScopes()->create(['organization_id' => $this->organization->id, 'scope_type' => 'self', 'is_active' => true]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($scoped)
        ->get(route('nfc-management.credentials.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('credentials.data', 1)
            ->where('credentials.data.0.credential_id', $this->credential->credential_id));

    // A credential in another organization is absent, not merely forbidden.
    $this->get(route('nfc-management.credentials.show', $this->otherCredential->credential_id))
        ->assertNotFound();
});

// ── Credential detail ────────────────────────────────────────────────────

it('shows credential detail without any secret material', function () {
    $this->credential->update(['key_version' => 'v3', 'key_reference' => 'kms://super-secret-pointer', 'chip_uid_hash' => str_repeat('a', 64)]);

    $response = $this->actingAs($this->admin)
        ->get(route('nfc-management.credentials.show', $this->credential->credential_id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('NfcManagement/Credentials/Show')
            ->where('credential.key_version', 'v3')
            ->has('lifecycle')
            ->has('employee'));

    $body = $response->getContent();
    expect($body)->not->toContain('kms://super-secret-pointer')
        ->and($body)->not->toContain('key_reference')
        ->and($body)->not->toContain('chip_uid_hash');
});

// ── ID card integration ──────────────────────────────────────────────────

it('shows the NFC panel on the ID card detail page', function () {
    $this->actingAs($this->admin)
        ->get(route('id-cards.show', $this->card))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('nfc.can')
            ->has('nfc.credentials', 1));
});

it('requires permission to open the provisioning page', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('nfc.provision.create', $this->otherCard))
        ->assertForbidden();

    $this->actingAs($this->admin)
        ->get(route('nfc.provision.create', $this->otherCard))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('IdCards/NfcProvision')
            // Honest hardware reporting: no adapter bound in tests.
            ->where('hardware.secure_available', false));
});

// ── Lifecycle ────────────────────────────────────────────────────────────

it('applies each lifecycle action and leaves the QR identity untouched', function (string $action, string $expected) {
    $uuid = $this->card->public_card_uuid;

    if (in_array($action, ['suspend', 'replace'], true)) {
        app(NfcCredentialService::class)->transition($this->credential, 'activate', $this->admin);
    }

    $this->actingAs($this->admin)
        ->post(route('nfc.transition', [
            'card' => $this->card->id,
            'credential' => $this->credential->credential_id,
            'action' => $action,
        ]))
        ->assertRedirect();

    expect($this->credential->fresh()->status)->toBe($expected)
        ->and($this->card->fresh()->public_card_uuid)->toBe($uuid)
        ->and($this->card->fresh()->qr_status)->toBe('active');
})->with([
    'activate' => ['activate', 'active'],
    'suspend' => ['suspend', 'suspended'],
    'lost' => ['lost', 'lost'],
    'revoke' => ['revoke', 'revoked'],
    'replace' => ['replace', 'replaced'],
]);

it('refuses lifecycle actions from a user without the matching permission', function () {
    $viewer = nfcGrant(User::factory()->create(), ['nfc_credentials.view'], 'Organizational Admin');
    $viewer->organizationScopes()->create(['organization_id' => $this->organization->id, 'scope_type' => 'self', 'is_active' => true]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($viewer)
        ->post(route('nfc.transition', [
            'card' => $this->card->id,
            'credential' => $this->credential->credential_id,
            'action' => 'revoke',
        ]))
        ->assertForbidden();

    expect($this->credential->fresh()->status)->toBe('pending');
});

it('records a replacement as a new credential linked from the old one', function () {
    app(NfcCredentialService::class)->transition($this->credential, 'activate', $this->admin);

    $this->actingAs($this->admin)->post(route('nfc.transition', [
        'card' => $this->card->id,
        'credential' => $this->credential->credential_id,
        'action' => 'replace',
    ]))->assertRedirect();

    $old = $this->credential->fresh();
    expect($old->status)->toBe('replaced')->and($old->replaced_by_id)->not->toBeNull();

    $replacement = NfcCredential::findOrFail($old->replaced_by_id);
    expect($replacement->credential_id)->not->toBe($old->credential_id)
        ->and($replacement->status)->toBe('pending');
});

// ── Terminals ────────────────────────────────────────────────────────────

it('enforces permissions across terminal CRUD', function () {
    $application = ExternalApplication::create([
        'name' => 'UI terminal app', 'code' => 'UI-APP', 'status' => 'active',
        'allowed_scopes' => ['nfc.verify'], 'rate_limit_per_minute' => 60,
    ]);

    $payload = [
        'terminal_code' => 'UI-T1',
        'name' => 'Lobby reader',
        'terminal_type' => 'verification',
        'status' => 'active',
        'external_application_id' => $application->id,
        'organization_id' => $this->organization->id,
    ];

    // No permission at all.
    $this->actingAs(User::factory()->create())
        ->post(route('nfc-management.terminals.store'), $payload)
        ->assertForbidden();

    // View-only cannot create.
    $viewer = nfcGrant(User::factory()->create(), ['nfc_terminals.view'], 'Terminal Viewer');
    $this->actingAs($viewer)->post(route('nfc-management.terminals.store'), $payload)->assertForbidden();
    $this->actingAs($viewer)->get(route('nfc-management.terminals.index'))->assertOk();

    // Full access creates, edits and deletes.
    $this->actingAs($this->admin)->post(route('nfc-management.terminals.store'), $payload)->assertRedirect();
    $terminal = ServiceTerminal::where('terminal_code', 'UI-T1')->firstOrFail();

    $this->actingAs($this->admin)
        ->put(route('nfc-management.terminals.update', $terminal), [...$payload, 'name' => 'Renamed reader'])
        ->assertRedirect();
    expect($terminal->fresh()->name)->toBe('Renamed reader');

    // Retiring a terminal revokes it: the row and its audit history survive,
    // but it can no longer verify credentials.
    $this->actingAs($this->admin)
        ->delete(route('nfc-management.terminals.destroy', $terminal))
        ->assertRedirect();
    expect($terminal->fresh()->status)->toBe('revoked');
});

it('never exposes the raw certificate reference, only a fingerprint', function () {
    $application = ExternalApplication::create([
        'name' => 'Cert app', 'code' => 'CERT-APP', 'status' => 'active',
        'allowed_scopes' => [], 'rate_limit_per_minute' => 60,
    ]);

    $terminal = ServiceTerminal::create([
        'terminal_code' => 'CERT-1', 'name' => 'Cert reader', 'terminal_type' => 'access',
        'status' => 'active', 'external_application_id' => $application->id,
        'organization_id' => $this->organization->id,
        'certificate_reference' => 'hsm://private-key-handle-do-not-leak',
    ]);

    $response = $this->actingAs($this->admin)
        ->get(route('nfc-management.terminals.show', $terminal))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('terminal.certificate_fingerprint'));

    expect($response->getContent())->not->toContain('hsm://private-key-handle-do-not-leak');
});

it('stops a scoped admin reaching a terminal in another organization', function () {
    $application = ExternalApplication::create([
        'name' => 'Scoped app', 'code' => 'SCOPED-APP', 'status' => 'active',
        'allowed_scopes' => [], 'rate_limit_per_minute' => 60,
    ]);

    $foreign = ServiceTerminal::create([
        'terminal_code' => 'FOREIGN-1', 'name' => 'Foreign reader', 'terminal_type' => 'access',
        'status' => 'active', 'external_application_id' => $application->id,
        'organization_id' => $this->otherOrganization->id,
    ]);

    $scoped = nfcGrant(User::factory()->create(), ['nfc_terminals.view', 'nfc_terminals.update'], 'Organizational Admin');
    $scoped->organizationScopes()->create(['organization_id' => $this->organization->id, 'scope_type' => 'self', 'is_active' => true]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($scoped)->get(route('nfc-management.terminals.show', $foreign))->assertNotFound();
    $this->get(route('nfc-management.terminals.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('terminals.data', 0));
});

// ── Logs ─────────────────────────────────────────────────────────────────

it('lists verification logs and filters them by result', function () {
    foreach ([['allowed', null], ['blocked', 'CARD_EXPIRED']] as [$result, $reason]) {
        NfcVerificationLog::create([
            'nfc_credential_id' => $this->credential->id,
            'event_type' => 'verification',
            'result' => $result,
            'reason_code' => $reason,
            'occurred_at' => now(),
        ]);
    }

    // Provisioning already wrote its own audit rows, so assert on the
    // verification events this test added rather than the total.
    $this->actingAs($this->admin)
        ->get(route('nfc-management.logs.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('NfcManagement/Logs/Index')
            ->has('logs.data')
            ->has('logs.meta.total'));

    $this->get(route('nfc-management.logs.index', ['result' => 'blocked']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('logs.data', 1)
            ->where('logs.data.0.reason_code', 'CARD_EXPIRED'));
});
