<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\ChangeCardStatus;
use App\Actions\EmployeeVerification;
use App\Actions\ManageBreakGlass;
use App\Actions\ManageDigitalCredentials;
use App\Models\ApiClient;
use App\Models\BreakGlassGrant;
use App\Models\CardPrintBatch;
use App\Models\CoreAuditLog;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeDocument;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnitType;
use App\Models\Position;
use App\Models\User;
use App\Services\BreakGlassAccess;
use App\Services\OrganizationScope;
use App\Services\WebhookDeliverer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsGoldenPath;
use Tests\TestCase;

/**
 * P4/P5 UAT (ADR 0011): the whole Golden Path, each stage with its
 * permission, scope, status change and audit row, and the failures a real
 * user must meet — run as automated acceptance tests on synthetic data.
 */
class CoreGoldenPathUatTest extends TestCase
{
    use BuildsGoldenPath, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGoldenPath();
    }

    private function person(string $role, ?Organization $scope = null): User
    {
        return User::query()->create([
            'name' => $role.' '.Str::random(4), 'email' => Str::random(8).'@core.test', 'password' => 'secret-password-1', 'role' => $role,
            'scope_organization_id' => $scope?->id, 'scope_type' => $scope === null ? User::SCOPE_CITYWIDE : User::SCOPE_SUBTREE, 'is_active' => true,
        ]);
    }

    private function api(User $user): User
    {
        Auth::forgetGuards();
        Sanctum::actingAs($user);

        return $user;
    }

    private function web(User $user): User
    {
        Auth::forgetGuards();
        $this->actingAs($user, 'web');

        return $user;
    }

    private function audited(string $action, string $subject): void
    {
        $this->assertTrue(CoreAuditLog::query()->where('action', $action)->where('subject_id', $subject)->exists(), "No audit row {$action} for {$subject}.");
    }

    public function test_the_golden_path_from_organization_to_a_verified_credential(): void
    {
        [$admin, $admin2] = [$this->person('core_admin'), $this->person('core_admin')];
        $type = OrganizationType::query()->where('code', 'BUREAU')->sole();

        // Organization → hierarchy (governed: a draft, reviewed, approved by another, published).
        $this->api($this->person('core_viewer'));
        $this->postJson('/api/v1/organizations', ['organization_type_id' => $type->id, 'name_en' => 'Woreda 7 Health Office'])->assertForbidden();
        $this->api($admin);
        $organization = $this->postJson('/api/v1/organizations', ['organization_type_id' => $type->id, 'code' => 'WOR-7', 'name_en' => 'Woreda 7 Health Office',
            'parent_organization_id' => $this->city->id])->assertCreated()->json('data.id');
        $this->audited('organization.created', $organization);
        $version = $this->postJson('/api/v1/hierarchy-versions', ['version_name' => 'UAT 2026', 'effective_from' => today()->toDateString(), 'legal_basis' => 'UAT synthetic basis'])
            ->assertCreated()->json('data.id');
        $this->putJson("/api/v1/hierarchy-versions/{$version}/parent", ['child_organization_id' => $organization, 'parent_organization_id' => $this->bureauA->id])->assertOk();
        $this->postJson("/api/v1/hierarchy-versions/{$version}/review")->assertOk();
        $this->postJson("/api/v1/hierarchy-versions/{$version}/approve")->assertUnprocessable();
        $this->api($admin2);
        $this->postJson("/api/v1/hierarchy-versions/{$version}/approve")->assertOk();
        $this->postJson("/api/v1/hierarchy-versions/{$version}/publish")->assertOk()->assertJsonPath('data.status', 'published');
        $this->audited('hierarchy.publish', $version);

        // Unit → position (approved by its creator, who may approve positions) with seats.
        $unitType = OrganizationUnitType::query()->create(['code' => 'case-team', 'name_en' => 'Case team']);
        $unit = $this->postJson('/api/v1/organization-units', ['organization_id' => $organization, 'organization_unit_type_id' => $unitType->id, 'name_en' => 'Clinical services'])
            ->assertCreated()->json('data.id');
        $position = $this->postJson('/api/v1/positions', ['organization_id' => $organization, 'organization_unit_id' => $unit, 'title_en' => 'Nurse'])->assertCreated()->json('data.id');
        $this->assertSame(Position::APPROVED, Position::query()->findOrFail($position)->lifecycle_status);
        $this->postJson("/api/v1/positions/{$position}/establishments", ['approved_slots' => 2, 'effective_from' => '2025-01-01', 'approval_reference' => 'UAT-EST-1'])->assertSuccessful();

        // Employee draft → submit → verify → approve: three different people, inside the scope.
        $registrar = $this->api($this->person('core_officer', $this->bureauA));
        $this->postJson('/api/v1/employees', ['first_name' => 'Hana', 'last_name' => 'Tesfaye', 'position_id' => $this->positionB->id, 'effective_from' => '2025-01-01'])->assertStatus(422);
        $employee = $this->postJson('/api/v1/employees', ['first_name' => 'Hana', 'last_name' => 'Tesfaye', 'date_of_birth' => '1990-01-01', 'position_id' => $position, 'effective_from' => '2025-01-01'])
            ->assertCreated()->assertJsonPath('data.status', Employee::DRAFT)->json('data.id');
        $this->postJson("/api/v1/employees/{$employee}/submit")->assertOk();
        $this->postJson("/api/v1/employees/{$employee}/verify", ['checks' => array_fill_keys(array_keys(app(EmployeeVerification::class)->checklist(Employee::query()->findOrFail($employee))), true)])->assertUnprocessable(); // not the submitter
        $this->api($this->person('core_officer', $this->bureauA));
        $this->postJson("/api/v1/employees/{$employee}/verify", ['checks' => array_fill_keys(array_keys(app(EmployeeVerification::class)->checklist(Employee::query()->findOrFail($employee))), true)])->assertOk();
        $this->giveVerifiedPhoto($employee);
        $this->api($admin);
        $this->postJson("/api/v1/employees/{$employee}/approve")->assertOk()->assertJsonPath('data.status', Employee::ACTIVE);
        foreach (['employee.registered', 'employee.submitted', 'employee.verified', 'employee.approved'] as $action) {
            $this->audited($action, $employee);
        }
        $this->assertSame(EmployeeAssignment::ACTIVE, Employee::query()->findOrFail($employee)->currentAssignment->assignment_status);

        // Card request → approve (another person) → print batch → quality check (not the printer) → issue → activate.
        $this->api($registrar);
        $request = $this->postJson("/api/v1/employees/{$employee}/card-requests", ['request_type' => 'new'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/card-requests/{$request}/approve")->assertUnprocessable();
        $this->api($admin);
        $card = $this->postJson("/api/v1/card-requests/{$request}/approve")->assertOk()->assertJsonPath('data.status', IdCard::PENDING_PRINT)->json('data.id');
        $this->audited('card_request.approved', $request);
        $printer = $this->web($this->person('core_officer', $this->bureauA));
        $this->post('/print-batches', ['card_ids' => [$card]])->assertSessionHasNoErrors();
        $batch = CardPrintBatch::query()->sole();
        $this->post("/print-batches/{$batch->id}/mark-printed")->assertSessionHasNoErrors();
        $this->audited('card.print', $card);
        $this->post("/id-cards/{$card}/quality-check", ['result' => 'passed'])->assertSessionHasErrors('card');
        $this->web($this->person('core_officer', $this->bureauA));
        $this->post("/id-cards/{$card}/quality-check", ['result' => 'passed'])->assertSessionHasNoErrors();
        $this->audited('card.quality_passed', $card);
        $this->api($admin);
        $this->postJson("/api/v1/id-cards/{$card}/activate")->assertUnprocessable();
        $this->postJson("/api/v1/id-cards/{$card}/issue")->assertOk();
        $this->postJson("/api/v1/id-cards/{$card}/activate")->assertOk()->assertJsonPath('data.status', IdCard::ACTIVE);
        $this->audited('card.activate', $card);
        $this->assertSame((int) $printer->id, (int) IdCard::query()->findOrFail($card)->printed_by);

        // Digital credential → verification API (a partner's client, scope-limited).
        $token = app(ManageDigitalCredentials::class)->tokenFor(IdCard::query()->findOrFail($card));
        $this->assertNotNull($token);
        $client = new ApiClient(['name' => 'Cafeteria', 'scopes' => ['cards:verify'], 'scope_type' => User::SCOPE_CITYWIDE, 'is_active' => true]);
        $client->save();
        $secret = $client->createToken('integration', ['cards:verify'])->plainTextToken;
        Auth::forgetGuards();
        $this->postJson('/api/v1/integration/cards/verify', ['token' => $token], ['Authorization' => 'Bearer '.$secret])
            ->assertOk()->assertJsonPath('valid', true)->assertJsonPath('code', null)->assertJsonPath('holder', null);
    }

    public function test_the_failures_a_user_meets_on_the_way(): void
    {
        $admin = $this->person('core_admin');

        // No scope: an account reaching no organization is refused before anything else.
        $this->web(User::query()->create(['name' => 'Nobody', 'email' => 'nobody@core.test', 'password' => 'secret-password-1', 'role' => 'core_officer', 'scope_type' => null, 'is_active' => true]));
        $this->get('/employees')->assertForbidden();
        // Wrong permission.
        $this->api($this->person('core_viewer'));
        $this->postJson('/api/v1/positions', ['organization_id' => $this->bureauA->id, 'title_en' => 'Clerk'])->assertForbidden();
        // Cross-organization UUID: not there at all.
        $this->api($this->person('core_officer', $this->bureauB));
        $this->getJson('/api/v1/employees/'.$this->employee->id)->assertNotFound();

        $this->api($admin);
        // Duplicate employee: the registrar is warned and must state a reason.
        $this->employee->forceFill(['date_of_birth' => '1980-05-05'])->save();
        $this->postJson('/api/v1/employees', ['first_name' => 'Abebe', 'last_name' => 'EMP-0001', 'date_of_birth' => '1980-05-05', 'position_id' => $this->positionB->id, 'effective_from' => '2025-01-01'])
            ->assertStatus(409)->assertJsonPath('code', 'POSSIBLE_DUPLICATE');
        // Inactive organization.
        $this->bureauB->forceFill(['status' => 'inactive'])->save();
        $this->postJson('/api/v1/employees', ['first_name' => 'New', 'last_name' => 'Person', 'position_id' => $this->positionB->id, 'effective_from' => '2025-01-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('position_id');
        $this->bureauB->forceFill(['status' => 'active'])->save();
        // Abolished position and a full position refuse a transfer.
        $this->positionB->forceFill(['lifecycle_status' => Position::ABOLISHED])->save();
        $this->postJson('/api/v1/employees/'.$this->employee->id.'/transfer', ['position_id' => $this->positionB->id, 'effective_date' => '2025-03-01'])->assertJsonValidationErrors('position_id');
        $this->positionB->forceFill(['lifecycle_status' => Position::APPROVED])->save();
        $this->placedEmployee('EMP-0009', $this->positionB, '2024-01-01');
        $this->postJson('/api/v1/employees/'.$this->employee->id.'/transfer', ['position_id' => $this->positionB->id, 'effective_date' => '2025-03-01'])->assertJsonValidationErrors('position_id');

        // Employee not verified: approval refused.
        $draft = $this->postJson('/api/v1/employees', ['first_name' => 'Lidya', 'last_name' => 'Mekonnen', 'date_of_birth' => '1992-02-02', 'position_id' => $this->position($this->bureauA, 'POS-C')->id, 'effective_from' => '2025-01-01'])
            ->assertCreated()->json('data.id');
        $this->postJson("/api/v1/employees/{$draft}/approve")->assertUnprocessable();
        // Card already active: no second card request.
        $this->postJson('/api/v1/employees/'.$this->employee->id.'/card-requests', ['request_type' => 'new'])->assertUnprocessable();
        // Missing photo: no card request for an active employee without one.
        $other = $this->placedEmployee('EMP-0010', $this->positionA, '2024-01-01');
        EmployeeDocument::query()->where('employee_id', $other->id)->delete();
        $this->postJson('/api/v1/employees/'.$other->id.'/card-requests', ['request_type' => 'new'])->assertUnprocessable();
        // Print, issue and activate out of order.
        $this->postJson('/api/v1/id-cards/'.$this->card->id.'/print')->assertUnprocessable();
        $this->card->forceFill(['status' => IdCard::PRINTED, 'qc_status' => 'pending'])->save();
        $this->postJson('/api/v1/id-cards/'.$this->card->id.'/issue')->assertUnprocessable();
        $this->postJson('/api/v1/id-cards/'.$this->card->id.'/activate')->assertUnprocessable();
        $this->card->forceFill(['status' => IdCard::ACTIVE, 'qc_status' => 'passed'])->save();

        // Revoked credential and expired card fail verification.
        $credentials = app(ManageDigitalCredentials::class);
        $credentials->issue($this->card, null);
        $token = $credentials->tokenFor($this->card);
        $this->card->forceFill(['expires_at' => now()->subDay()])->save();
        $this->postJson('/api/v1/cards/verify', ['token' => $token])->assertJsonPath('valid', false)->assertJsonPath('code', 'CARD_EXPIRED');
        app(ChangeCardStatus::class)->execute($this->card->fresh(), 'revoke', 'Left the service', $admin);
        $this->postJson('/api/v1/cards/verify', ['token' => $token])->assertJsonPath('valid', false)->assertJsonPath('code', 'CREDENTIAL_REVOKED');

        // MFA cannot be skipped once enforced.
        config(['core.mfa_enforce' => true]);
        $this->web($this->person('core_admin'));
        $this->get('/core')->assertRedirect();
        config(['core.mfa_enforce' => false]);

        // Expired break-glass gives nothing.
        $officer = $this->person('core_officer', $this->bureauA);
        $grant = app(ManageBreakGlass::class)->request(['permissions' => ['employees.view'], 'scope_type' => 'organization', 'organization_id' => $this->bureauB->id,
            'duration_minutes' => 15, 'reason' => 'Synthetic UAT emergency for bureau B'], $officer);
        app(ManageBreakGlass::class)->decide($grant, true, null, $admin);
        $this->travel(16)->minutes();
        app(BreakGlassAccess::class)->forget();
        app(OrganizationScope::class)->clearCache();
        $this->assertFalse(app(OrganizationScope::class)->allows($officer, (string) $this->bureauB->id));
        $this->assertSame(BreakGlassGrant::ACTIVE, $grant->fresh()->status); // recorded as expired by the scheduler; it already gives nothing

        // A revoked API client is refused; a webhook signed with another secret does not match.
        $client = new ApiClient(['name' => 'Gone', 'scopes' => ['cards:verify'], 'scope_type' => User::SCOPE_CITYWIDE, 'is_active' => true]);
        $client->save();
        $secret = $client->createToken('integration', ['cards:verify'])->plainTextToken;
        $client->tokens()->delete();
        $client->forceFill(['revoked_at' => now(), 'is_active' => false])->save();
        Auth::forgetGuards();
        $this->postJson('/api/v1/integration/cards/verify', ['token' => $token], ['Authorization' => 'Bearer '.$secret])->assertUnauthorized();
        $body = '{"event_id":"x"}';
        $this->assertNotSame(WebhookDeliverer::signature('whsec_right', '1700000000', $body), WebhookDeliverer::signature('whsec_wrong', '1700000000', $body));
    }
}
