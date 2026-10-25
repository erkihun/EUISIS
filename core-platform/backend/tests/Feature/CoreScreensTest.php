<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CardRequest;
use App\Models\Employee;
use App\Models\HierarchyVersion;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\OrganizationUnitType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsGoldenPath;
use Tests\TestCase;

/** The EUISIS screens served by the Core: every page renders its EUISIS component, with the Core rules behind the forms. */
class CoreScreensTest extends TestCase
{
    use BuildsGoldenPath;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGoldenPath();
    }

    private function signIn(string $role = 'core_admin', ?Organization $scope = null): User
    {
        $user = User::query()->create([
            'name' => $role, 'email' => $role.'-'.Str::random(6).'@core.test', 'password' => 'secret-password-1',
            'role' => $role, 'scope_organization_id' => $scope?->id, 'scope_type' => $scope === null ? User::SCOPE_CITYWIDE : User::SCOPE_SUBTREE, 'is_active' => true,
        ]);
        $this->actingAs($user, 'web');

        return $user;
    }

    public function test_every_core_page_renders_its_euisis_component(): void
    {
        $this->signIn();
        $type = OrganizationUnitType::query()->create(['code' => 'directorate', 'name_en' => 'Directorate']);
        $unit = $this->bureauA->units()->create(['code' => 'U-1', 'name_en' => 'Finance', 'unit_type' => 'directorate', 'organization_unit_type_id' => $type->id, 'status' => 'active']);
        $request = CardRequest::query()->create(['employee_id' => $this->employee->id, 'requested_by' => 1, 'status' => 'submitted', 'request_type' => 'renewal', 'submitted_at' => now()]);

        $pages = [
            '/core' => 'Core/Dashboard',
            '/core/assignments' => 'Core/Assignments',
            '/core/audit' => 'Core/AuditCenter',
            '/core/data-quality' => 'Core/DataQuality',
            '/core/settings' => 'Core/Settings',
            '/system-settings' => 'SystemSettings/Index',
            '/audit-logs' => 'AuditLogs/Index',
            '/profile' => 'Profile/Edit',
            '/organizations' => 'Organizations/Index',
            '/organizations/create' => 'Organizations/Create',
            '/organizations/'.$this->bureauA->id => 'Organizations/Show',
            '/organizations/'.$this->bureauA->id.'/edit' => 'Organizations/Edit',
            '/organizations/'.$this->bureauA->id.'/organogram' => 'Organizations/Organogram',
            '/organization-types' => 'OrganizationTypes/Index',
            '/organization-types/create' => 'OrganizationTypes/Create',
            '/organization-types/'.$this->city->organization_type_id => 'OrganizationTypes/Show',
            '/organization-types/'.$this->city->organization_type_id.'/edit' => 'OrganizationTypes/Edit',
            '/organization-unit-types' => 'OrganizationUnitTypes/Index',
            '/organization-unit-types/create' => 'OrganizationUnitTypes/Create',
            '/organization-unit-types/'.$type->id => 'OrganizationUnitTypes/Show',
            '/organization-unit-types/'.$type->id.'/edit' => 'OrganizationUnitTypes/Edit',
            '/organization-units?organization_id='.$this->bureauA->id => 'OrganizationUnits/Index',
            '/organization-units/create?organization_id='.$this->bureauA->id => 'OrganizationUnits/Create',
            '/organization-units/'.$unit->id => 'OrganizationUnits/Show',
            '/organization-units/'.$unit->id.'/edit' => 'OrganizationUnits/Edit',
            '/hierarchy-versions' => 'HierarchyVersions/Index',
            '/hierarchy-versions/'.HierarchyVersion::current()->id => 'HierarchyVersions/Show',
            '/hierarchy-versions/'.HierarchyVersion::current()->id.'/tree' => 'HierarchyVersions/Tree',
            '/positions?organization_id='.$this->bureauA->id => 'Positions/Index',
            '/positions/status' => 'Positions/Status',
            '/positions/create?organization_id='.$this->bureauA->id => 'Positions/Create',
            '/positions/'.$this->positionA->id => 'Positions/Show',
            '/positions/'.$this->positionA->id.'/edit' => 'Positions/Edit',
            '/position-establishments' => 'PositionEstablishments/Index',
            '/employees' => 'Employees/Index',
            '/employees/create' => 'Employees/Create',
            '/employees/'.$this->employee->id => 'Employees/Show',
            '/employees/'.$this->employee->id.'/edit' => 'Employees/Edit',
            '/id-cards' => 'IdCards/Index',
            '/id-cards/'.$this->card->id => 'IdCards/Show',
            '/card-requests' => 'CardRequests/Index',
            '/card-requests/create' => 'CardRequests/Create',
            '/card-requests/'.$request->id => 'CardRequests/Show',
            '/users' => 'Users/Index',
            '/users/create' => 'Users/Create',
            '/users/'.auth()->id().'/edit' => 'Users/Edit',
            '/id-checker' => 'Public/IdChecker',
        ];
        foreach ($pages as $url => $component) {
            $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component($component));
        }
    }

    public function test_the_shell_gets_the_euisis_shared_props_with_the_core_workspace(): void
    {
        $this->signIn('core_officer');

        $this->get('/core')->assertInertia(fn (Assert $page) => $page
            ->where('workspace.name', 'core')
            ->where('auth.roles.0', 'City-wide Core Officer')
            ->where('auth.isSuperAdmin', false)
            ->where('communication', null)
            ->where('assistant.enabled', false)
            ->etc());
        $this->assertArrayHasKey('app.name', $this->get('/core')->inertiaProps('settings'));
        $nav = collect($this->get('/core')->inertiaProps('workspace.nav'))->flatMap(fn (array $section) => collect($section['groups'])->flatMap(fn (array $g) => $g['items']))->pluck('route');
        $this->assertContains('organizations.index', $nav);
        $this->assertNotContains('users.index', $nav);
        $this->assertNotContains('print-batches.index', $nav);
    }

    public function test_sign_in_and_out_use_the_session(): void
    {
        User::query()->create(['name' => 'Hana', 'email' => 'hana@core.test', 'password' => 'secret-password-1', 'role' => 'core_viewer', 'scope_type' => User::SCOPE_CITYWIDE, 'is_active' => true]);
        User::query()->create(['name' => 'Off', 'email' => 'off@core.test', 'password' => 'secret-password-1', 'role' => 'core_viewer', 'scope_type' => User::SCOPE_CITYWIDE, 'is_active' => false]);

        $this->get('/core')->assertRedirect('/login');
        $this->get('/login')->assertInertia(fn (Assert $page) => $page->component('Auth/Login')->where('entry', 'core'));
        $this->post('/login', ['email' => 'off@core.test', 'password' => 'secret-password-1'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => 'hana@core.test', 'password' => 'secret-password-1'])->assertRedirect('/core');
        $this->assertAuthenticated('web');
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest('web');
    }

    public function test_a_one_time_password_is_replaced_before_anything_else(): void
    {
        $user = $this->signIn('core_officer');
        $user->forceFill(['must_change_password' => true])->save();

        $this->get('/organizations')->assertRedirect('/change-password');
        $this->get('/change-password')->assertInertia(fn (Assert $page) => $page->component('Auth/ForcedPasswordChange'));
        $this->post('/change-password', ['current_password' => 'wrong', 'password' => 'NewPassword2026', 'password_confirmation' => 'NewPassword2026'])
            ->assertSessionHasErrors('current_password');
        $this->post('/change-password', ['current_password' => 'secret-password-1', 'password' => 'NewPassword2026', 'password_confirmation' => 'NewPassword2026'])
            ->assertRedirect('/core');
        $this->assertFalse($user->fresh()->must_change_password);
        // A real request reloads the account from the session; the test's signed-in model is refreshed by hand.
        $this->actingAs($user->fresh(), 'web')->get('/organizations')->assertOk();
    }

    public function test_a_possible_duplicate_is_registered_only_after_the_warning_and_a_reason(): void
    {
        $this->signIn('core_officer');
        $person = ['first_name' => 'Abebe', 'last_name' => 'EMP-0001', 'position_id' => $this->positionB->id];

        $this->post('/employees', $person + ['reason' => 'Transferred in from the zone'])->assertSessionHasErrors('reason');
        $this->assertSame(1, Employee::query()->where('full_name', 'Abebe EMP-0001')->count());

        $this->post('/employees', $person + ['reason' => 'abc'])->assertSessionHasErrors('reason');
        $this->post('/employees', $person + ['reason' => 'A different person: other date of birth on file'])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(2, Employee::query()->where('full_name', 'Abebe EMP-0001')->count());
        $this->assertDatabaseHas('employee_duplicate_reviews', ['matched_employee_id' => $this->employee->id, 'decision' => 'pending']);
    }

    public function test_settings_belong_to_a_city_wide_administrator(): void
    {
        $this->signIn('core_admin', $this->bureauA);
        $this->get('/system-settings')->assertForbidden();
        $this->get('/users')->assertOk();

        $this->signIn('core_admin');
        $this->get('/system-settings')->assertOk();
    }

    public function test_a_viewer_reads_but_cannot_open_the_forms(): void
    {
        $this->signIn('core_viewer');

        $this->get('/organizations')->assertOk();
        $this->get('/organizations/create')->assertForbidden();
        $this->get('/users')->assertForbidden();
        $this->get('/system-settings')->assertForbidden();
        $this->patch('/organizations/'.$this->bureauA->id, ['name_en' => 'X'])->assertForbidden();
    }

    public function test_editing_an_organization_keeps_its_place_in_the_hierarchy(): void
    {
        $this->signIn('core_officer');

        $this->patch('/organizations/'.$this->bureauA->id, ['name_en' => 'Bureau A renamed', 'status' => 'active'])->assertRedirect('/organizations/'.$this->bureauA->id);

        $this->assertSame('Bureau A renamed', $this->bureauA->fresh()->name_en);
        $this->assertTrue(OrganizationEdge::query()->where('child_organization_id', $this->bureauA->id)->where('parent_organization_id', $this->city->id)->exists());
    }

    public function test_a_card_request_needs_a_second_person_from_the_screens(): void
    {
        $this->card->update(['status' => IdCard::REVOKED, 'is_current' => false]);
        $this->signIn('core_officer');
        $this->post('/card-requests', ['employee_ids' => [$this->employee->id], 'request_type' => 'new'])->assertRedirect('/card-requests');
        $request = CardRequest::query()->where('employee_id', $this->employee->id)->where('status', 'submitted')->firstOrFail();

        $this->post('/card-requests/'.$request->id.'/approve')->assertSessionHasErrors('request');

        $this->signIn('core_admin');
        $this->post('/card-requests/'.$request->id.'/approve')->assertSessionHasNoErrors();
        $card = IdCard::query()->where('card_request_id', $request->id)->firstOrFail();
        $this->post('/id-cards/'.$card->id.'/prepare-print')->assertSessionHasNoErrors();
        $this->post('/id-cards/'.$card->id.'/issue')->assertSessionHasNoErrors();
        $this->post('/id-cards/'.$card->id.'/activate')->assertSessionHasNoErrors();
        $this->assertSame(IdCard::ACTIVE, $card->fresh()->status);

        $this->post('/id-cards/'.$card->id.'/suspend', [])->assertSessionHasErrors('reason');
    }

    public function test_a_new_account_gets_a_one_time_password_and_one_role(): void
    {
        $this->signIn('core_admin');

        $this->post('/users', ['name' => 'Hana', 'email' => 'hana@core.test', 'status' => 'active', 'role_ids' => [1, 2], 'organization_scope_ids' => [$this->bureauA->id]])
            ->assertRedirect('/users')->assertSessionHas('flash.temporary_password');

        $user = User::query()->where('email', 'hana@core.test')->firstOrFail();
        $this->assertSame('core_officer', $user->role);
        $this->assertSame($this->bureauA->id, $user->scope_organization_id);
    }

    public function test_the_public_check_shows_the_card_state_but_not_the_holder(): void
    {
        $this->card->update(['public_card_uuid' => (string) Str::uuid(), 'qr_status' => 'active']);

        $this->get('/id-checker/'.$this->card->public_card_uuid)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Public/IdChecker')
            ->where('card.found', true)
            ->where('card.status_code', 'active')
            ->missing('card.full_name'));
        $this->get('/id-checker/'.Str::uuid())->assertInertia(fn (Assert $page) => $page->where('card.status_code', 'invalid'));
    }
}
