<?php

declare(strict_types=1);

use App\Models\OrganizationSeal;
use App\Models\Permission;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Grievance\Application\Services\GrievanceReadinessService;
use Modules\Grievance\Domain\Models\GrievanceCommitteeMember;
use Modules\Grievance\Domain\Models\GrievanceHandlerUnit;
use Modules\Grievance\Domain\Models\GrievanceHandlerUnitMember;
use Modules\Grievance\Domain\Models\GrievanceSlaProfile;
use Tests\Support\GrievanceScenario;

/*
 * Owner request 2026-11-08, after the member sign-in walkthrough: the
 * grievance dashboard tells those who set grievances up what each
 * institution still needs before a case can run end to end.
 */

it('reports what an institution still needs, and turns each item green once it is set up', function (): void {
    $s = GrievanceScenario::build();
    $readiness = app(GrievanceReadinessService::class);

    $before = $readiness->forOrganization($s->woreda->id);
    // The scenario committee has three members without selection sources: not an Art. 30(2) committee.
    expect($before['checks']['committee'])->toBeFalse()
        ->and($before['checks']['seal'])->toBeFalse()
        ->and($before['checks']['appeal_route'])->toBeFalse()
        ->and($before['checks']['member_accounts'])->toBeTrue()
        ->and($before['missing_accounts'])->toBe(0);

    // A compliant committee: chair appointed, three elected (one a woman), HR secretary.
    GrievanceCommitteeMember::query()->where('committee_id', $s->committee->id)->delete();
    $members = [['chairperson', 'appointed', 'male'], ['member', 'elected', 'female'], ['member', 'elected', 'male'], ['member', 'elected', 'male'], ['writer', 'hr_representative', 'male']];
    foreach ($members as [$role, $source, $gender]) {
        $employee = GrievanceScenario::employee($s->woreda, $s->woredaUnit);
        $employee->forceFill(['gender' => $gender])->save();
        GrievanceScenario::user($employee, []);
        GrievanceCommitteeMember::query()->create(['committee_id' => $s->committee->id, 'employee_id' => $employee->id, 'role' => $role, 'selection_source' => $source,
            'effective_from' => now()->subMonth()->toDateString(), 'status' => 'active']);
    }
    OrganizationSeal::query()->forceCreate(['id' => (string) Str::uuid7(), 'organization_id' => $s->woreda->id, 'name' => 'Seal', 'disk' => 'local', 'path' => 'seals/x.png',
        'mime_type' => 'image/png', 'sha256' => str_repeat('a', 64), 'status' => 'active', 'effective_from' => now()->subDay()->toDateString()]);
    GrievanceScenario::route('committee', $s->committee->id, 'organization_unit', $s->directorate->id, 'employee_appeal');

    $after = $readiness->forOrganization($s->woreda->id);
    expect($after['checks'])->toMatchArray(['committee' => true, 'seal' => true, 'appeal_route' => true, 'member_accounts' => true]);

    // A directorate member with no account is counted.
    $unit = GrievanceHandlerUnit::query()->create(['organization_id' => $s->woreda->id, 'organization_unit_id' => $s->woredaUnit->id, 'kind' => 'team', 'status' => 'active']);
    GrievanceHandlerUnitMember::query()->create(['handler_unit_id' => $unit->id, 'employee_id' => GrievanceScenario::employee($s->woreda, $s->woredaUnit)->id, 'responsibility' => 'expert',
        'effective_from' => now()->subDay()->toDateString(), 'status' => 'active']);
    expect($readiness->forOrganization($s->woreda->id))->toMatchArray(['missing_accounts' => 1])
        ->and($readiness->forOrganization($s->woreda->id)['checks']['member_accounts'])->toBeFalse();
});

it('shows the checklist on the dashboard only to those who set grievances up, with the system-wide items', function (): void {
    $s = GrievanceScenario::build();
    GrievanceSlaProfile::query()->where('purpose', 'appeal_filing')->update(['is_active' => false]);
    Permission::findOrCreate('grievance_settings.view', 'web');
    $s->intake->givePermissionTo('grievance_settings.view');

    $this->actingAs($s->intake)->get(route('grievances.dashboard'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Grievances/Dashboard')
            ->where('readiness.system.appeal_deadline', false)
            ->where('readiness.organizations', fn ($rows) => collect($rows)->pluck('id')->all() === [$s->woreda->id]));

    // A committee member works cases; the setup checklist is not theirs.
    $this->actingAs($s->member)->get(route('grievances.dashboard'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('readiness', null));
});
