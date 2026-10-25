<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CardRequest;
use App\Models\CoreRole;
use App\Models\Employee;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\User;
use App\Services\MfaPolicy;
use App\Support\CorePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsGoldenPath;
use Tests\TestCase;

/** P2 (ADR 0009): granular permissions in roles, and policies that need permission, scope and state together. */
class CorePermissionsAndPoliciesTest extends TestCase
{
    use BuildsGoldenPath, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGoldenPath();
    }

    private function account(string $role, Organization|false|null $scope = null): User
    {
        return User::query()->create([
            'name' => $role.' '.Str::random(4), 'email' => Str::random(8).'@core.test', 'password' => 'secret-password', 'role' => $role,
            'scope_organization_id' => $scope ? $scope->id : null,
            'scope_type' => $scope === false ? null : ($scope === null ? User::SCOPE_CITYWIDE : User::SCOPE_SUBTREE), 'is_active' => true,
        ]);
    }

    private function signIn(User $user): User
    {
        Auth::forgetGuards();
        $this->actingAs($user, 'web');

        return $user;
    }

    private function role(string $name, array $permissions): CoreRole
    {
        $this->signIn($this->account('core_admin'));
        $this->post('/roles', ['name' => $name, 'scope_type' => 'scoped', 'permissions' => $permissions])->assertSessionHasNoErrors();

        return CoreRole::query()->where('name_en', $name)->sole();
    }

    public function test_permission_scope_and_state_are_each_required(): void
    {
        $gate = fn (User $user) => Gate::forUser($user);
        $inScope = $this->account('core_officer', $this->bureauA);
        $outOfScope = $this->account('core_officer', $this->bureauB);
        $noPermission = $this->account($this->role('Readers', ['core.access', 'employees.view'])->key, $this->bureauA);
        $noScope = $this->account('core_officer', false);
        $cityWide = $this->account('core_officer');

        $this->assertTrue($gate($inScope)->allows('update', $this->employee));
        $this->assertFalse($gate($outOfScope)->allows('update', $this->employee));
        $this->assertFalse($gate($noPermission)->allows('update', $this->employee));
        $this->assertTrue($gate($noPermission)->allows('view', $this->employee));
        $this->assertFalse($gate($noScope)->allows('view', $this->employee));
        $this->assertTrue($gate($cityWide)->allows('update', $this->employee));

        // State: a submitted record is not edited; its submitter does not verify it.
        $this->employee->forceFill(['status' => Employee::SUBMITTED, 'submitted_by' => $inScope->id])->save();
        $this->assertFalse($gate($inScope)->allows('update', $this->employee));
        $this->assertFalse($gate($inScope)->allows('verify', $this->employee));
        $this->assertTrue($gate($cityWide)->allows('verify', $this->employee));
    }

    public function test_card_steps_are_separate_permissions_and_follow_the_card_status(): void
    {
        $printer = $this->account($this->role('Card printers', ['core.access', 'cards.view', 'cards.manage', 'id-cards.print'])->key);
        $this->card->update(['status' => IdCard::PENDING_PRINT]);

        $this->assertTrue(Gate::forUser($printer)->allows('transition', [$this->card, 'print']));
        $this->assertFalse(Gate::forUser($printer)->allows('transition', [$this->card, 'issue']));
        $this->assertFalse(Gate::forUser($printer)->allows('transition', [$this->card, 'revoke']));
        $this->signIn($printer);
        $this->post('/id-cards/'.$this->card->id.'/issue')->assertForbidden();
        $this->post('/id-cards/'.$this->card->id.'/prepare-print')->assertSessionHasNoErrors();
        $this->assertSame(IdCard::PRINTED, $this->card->fresh()->status);
        // Printing again is the wrong status, whatever the permission.
        $this->post('/id-cards/'.$this->card->id.'/prepare-print')->assertForbidden();
        // A role that changes data is an officer for MFA.
        $this->assertTrue(CorePermissions::changesData($printer));
    }

    public function test_card_requests_need_someone_other_than_the_requester(): void
    {
        $requester = $this->account('core_officer');
        $request = CardRequest::query()->create(['employee_id' => $this->employee->id, 'requested_by' => $requester->id, 'status' => CardRequest::SUBMITTED, 'request_type' => 'renewal', 'submitted_at' => now()]);

        $this->assertFalse(Gate::forUser($requester)->allows('approve', $request));
        $this->assertFalse(Gate::forUser($requester)->allows('verify', $request));
        $this->assertTrue(Gate::forUser($this->account('core_officer'))->allows('approve', $request));
        $this->assertFalse(Gate::forUser($this->account('core_officer', $this->bureauB))->allows('approve', $request));
        $this->assertFalse(Gate::forUser($this->account('core_viewer'))->allows('approve', $request));
    }

    public function test_a_uuid_alone_reaches_nothing_outside_the_scope(): void
    {
        $this->signIn($this->account('core_admin', $this->bureauB));
        $this->get('/employees/'.$this->employee->id)->assertNotFound();
        $this->get('/id-cards/'.$this->card->id)->assertNotFound();
        $this->get('/organizations/'.$this->bureauA->id)->assertNotFound();
        $this->get('/positions/'.$this->positionA->id)->assertNotFound();
        $this->post('/id-cards/'.$this->card->id.'/revoke', ['reason' => 'Trying my luck'])->assertNotFound();
        // A selector for another organization's units is refused, not filled.
        $this->get('/organization-units/create?organization_id='.$this->bureauA->id)->assertForbidden();
        // The dashboard does not count unplaced employees city-wide for a scoped reader.
        $this->get('/core')->assertInertia(fn ($page) => $page->where('metrics', fn ($metrics) => ! collect($metrics)->contains(fn ($metric) => ($metric['key'] ?? null) === 'employees_unplaced' && ($metric['value'] ?? null) !== null)));
    }

    public function test_roles_are_edited_city_wide_without_losing_the_last_administrator(): void
    {
        $admin = $this->signIn($this->account('core_admin'));
        $this->get('/roles')->assertOk()->assertInertia(fn ($page) => $page->component('Roles/Index')->has('roles.data', 3)
            ->where('roles.data.2.can.delete', false));
        $this->get('/roles/create')->assertOk()->assertInertia(fn ($page) => $page->component('Roles/Create')->has('permissions.cards'));
        $this->post('/roles', ['name' => 'Bad', 'scope_type' => 'scoped', 'permissions' => ['nonsense.do']])->assertSessionHasErrors('permissions.0');

        $administrators = CoreRole::query()->where('key', 'core_admin')->sole();
        $without = array_values(array_diff($administrators->load('permissions')->permissionNames(), ['roles.manage']));
        // Not from one's own role, and not when it would leave the city without an administrator.
        $this->patch('/roles/'.$administrators->id, ['name' => 'Core Administrator', 'scope_type' => 'scoped', 'permissions' => $without])->assertSessionHasErrors('permissions');
        $this->assertTrue(CorePermissions::roleHas('core_admin', 'roles.manage'));
        $this->delete('/roles/'.$administrators->id)->assertSessionHasErrors('role');

        $custom = $this->role('Auditors', ['core.access', 'audit.view']);
        $auditor = $this->account($custom->key);
        $this->assertTrue($auditor->can('audit.view'));
        $this->assertFalse($auditor->can('employees.view'));
        $this->delete('/roles/'.$custom->id)->assertSessionHasErrors('role');
        $auditor->update(['role' => 'core_viewer']);
        $this->delete('/roles/'.$custom->id)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('core_audit_logs', ['action' => 'role.deleted', 'actor_user_id' => Auth::id()]);
        $this->assertDatabaseHas('core_audit_logs', ['action' => 'role.created', 'subject_id' => (string) $custom->id]);
        $this->assertNotNull($admin->id);

        // A scoped administrator holds no city-wide permission, so does not manage roles.
        $this->signIn($this->account('core_admin', $this->bureauA));
        $this->get('/roles/create')->assertForbidden();
        $this->assertFalse(Auth::user()->can('roles.manage'));
    }

    public function test_the_users_screen_offers_the_roles_from_the_database(): void
    {
        $custom = $this->role('Card printers', ['core.access', 'cards.view', 'id-cards.print']);
        $this->signIn($this->account('core_admin'));
        $this->get('/users/create')->assertOk()->assertInertia(fn ($page) => $page->where('roles', fn ($roles) => collect($roles)->contains('name', 'Card printers')
            && collect($roles)->contains('name', 'City-wide Card printers')));

        $this->post('/users', ['name' => 'Printer', 'email' => 'printer@core.test', 'status' => 'active', 'role_ids' => [$custom->id * 10 + 1],
            'organization_scope_ids' => [$this->bureauA->id]])->assertSessionHasNoErrors();
        $printer = User::query()->where('email', 'printer@core.test')->sole();
        $this->assertSame([$custom->key, User::SCOPE_SUBTREE], [$printer->role, $printer->scope_type]);
        // Changing data puts the account in MFA's officer category.
        config(['core.mfa_enforce' => true]);
        $this->assertTrue(app(MfaPolicy::class)->requires($printer));
    }
}
