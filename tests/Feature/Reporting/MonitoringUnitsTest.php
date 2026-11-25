<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\OrganizationUnit;
use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Reporting\Application\Ports\ReportingAccessResolver;
use Modules\Reporting\Application\Queries\ComplianceQuery;
use Modules\Reporting\Application\Support\ReportingAccessDenied;
use Modules\Reporting\Application\UseCases\ManageMonitoringUnits;
use Modules\Reporting\Contracts\Data\ReportingRoles;
use Modules\Reporting\Domain\Workflow\WorkflowViolation;
use Modules\Reporting\Infrastructure\Outbox\DatabaseOutbox;
use Modules\Reporting\Infrastructure\Persistence\Models\MonitoringUnit;
use Tests\Support\ReportingScenario;

/*
 * Every institution has, in its own structure, the work unit that follows up
 * reports. Naming that unit gives the people placed in it the institution's
 * reports and the notices about what it receives; their permissions still
 * decide what each of them may do.
 */

beforeEach(function (): void {
    ReportingScenario::clock();
    $this->s = ReportingScenario::build()->configure();
    $this->cycle = $this->s->publish();
    $this->unit = fn (string $org, string $code, ?string $parent = null): OrganizationUnit => OrganizationUnit::query()->create([
        'organization_id' => $this->s->org[$org]->id, 'unit_type' => 'department', 'code' => $code, 'name_en' => $code.' unit', 'name_am' => $code.' ክፍል',
        'status' => 'active', 'parent_unit_id' => $parent,
    ]);
    // A user with Reporting permissions and no organization scope at all, placed in a unit through HR.
    $this->placed = function (OrganizationUnit $unit, array $permissions): User {
        $user = $this->s->user($permissions);
        $employee = Employee::query()->create(['employee_number' => 'RPT-'.Str::upper(Str::random(6)), 'first_name' => 'Unit', 'last_name' => 'Staff', 'full_name' => 'Unit Staff', 'status' => EmployeeStatus::Active->value]);
        $assignment = EmployeeAssignment::query()->create([
            'employee_id' => $employee->id, 'organization_id' => $unit->organization_id, 'organization_unit_id' => $unit->id, 'is_current' => true,
            'assignment_status' => AssignmentStatus::Active->value, 'effective_from' => now()->subYear()->toDateString(),
        ]);
        $employee->forceFill(['current_assignment_id' => $assignment->id])->save();
        $user->forceFill(['employee_id' => $employee->id])->save();

        return $user;
    };
    $this->monitoring = ($this->unit)('HQ', 'HQ-MON');
    $this->designate = fn () => app(ManageMonitoringUnits::class)->designate($this->s->actor($this->s->admin), $this->s->org['HQ']->id, $this->monitoring->id);
    $this->seen = fn (User $user): int => app(ComplianceQuery::class)->register(app(ReportingAccessResolver::class)->contextFor((int) $user->id), [])->total();
});

it('marks the staff of the follow-up unit, and of the teams beneath it, as reaching their institution\'s reports', function (): void {
    $staff = ($this->placed)($this->monitoring, ReportingRoles::REVIEWER);
    // Placed in a team beneath the unit: still its staff.
    $inTeam = ($this->placed)(($this->unit)('HQ', 'HQ-MON-T1', $this->monitoring->id), ReportingRoles::REVIEWER);
    $elsewhere = ($this->placed)(($this->unit)('HQ', 'HQ-OTHER'), ReportingRoles::REVIEWER);
    $monitored = fn (User $user): array => app(ReportingAccessResolver::class)->contextFor((int) $user->id)->monitoredOrganizationIds;

    expect($monitored($staff))->toBe([]);

    ($this->designate)();
    app()->forgetInstance(ReportingAccessResolver::class);

    expect($monitored($staff))->toBe([$this->s->org['HQ']->id])
        ->and($monitored($inTeam))->toBe([$this->s->org['HQ']->id])
        ->and($monitored($elsewhere))->toBe([])
        ->and(app(ReportingAccessResolver::class)->contextFor((int) $staff->id)->reaches($this->s->org['HQ']->id))->toBeTrue()
        ->and(($this->seen)($staff))->toBe(3)
        // The placement gives reach, not permissions: a member without Reporting permissions sees nothing.
        ->and(($this->seen)(($this->placed)($this->monitoring, [])))->toBe(0);

    app(ManageMonitoringUnits::class)->remove($this->s->actor($this->s->admin), $this->s->org['HQ']->id);
    app()->forgetInstance(ReportingAccessResolver::class);
    expect($monitored($staff))->toBe([]);
});

it('sends the notice of a submitted report to the follow-up unit when the institution has one', function (): void {
    $scoped = $this->s->reviewer();
    $staff = ($this->placed)($this->monitoring, ReportingRoles::REVIEWER);
    ($this->designate)();
    app()->forgetInstance(ReportingAccessResolver::class);

    $this->s->writeAndSubmit($this->s->obligation($this->cycle, 'A'), $this->s->officer('A'));
    app(DatabaseOutbox::class)->relay();

    expect($staff->notifications()->count())->toBe(1)
        ->and($scoped->notifications()->count())->toBe(0);
});

it('falls back to everyone who holds the permission while nobody in the unit does', function (): void {
    $scoped = $this->s->reviewer();
    ($this->placed)($this->monitoring, [ReportingRoles::VIEW]);
    ($this->designate)();
    app()->forgetInstance(ReportingAccessResolver::class);

    $this->s->writeAndSubmit($this->s->obligation($this->cycle, 'A'), $this->s->officer('A'));
    app(DatabaseOutbox::class)->relay();

    expect($scoped->notifications()->count())->toBe(1);
});

it('lets only an administrator who reaches the institution name its unit, and only a unit of that institution', function (): void {
    $units = app(ManageMonitoringUnits::class);
    $foreign = ($this->unit)('A', 'A-MON');
    $localAdmin = $this->s->user(ReportingRoles::ADMINISTRATOR, ['A']);

    expect(fn () => $units->designate($this->s->actor($localAdmin), $this->s->org['HQ']->id, $this->monitoring->id))->toThrow(ReportingAccessDenied::class)
        ->and(fn () => $units->designate($this->s->actor($this->s->reviewer()), $this->s->org['HQ']->id, $this->monitoring->id))->toThrow(ReportingAccessDenied::class)
        ->and(fn () => $units->designate($this->s->actor($this->s->admin), $this->s->org['HQ']->id, $foreign->id))->toThrow(WorkflowViolation::class, 'unknown_unit');

    // One unit per institution: naming another replaces the first.
    ($this->designate)();
    $units->designate($this->s->actor($this->s->admin), $this->s->org['HQ']->id, ($this->unit)('HQ', 'HQ-MON2')->id);
    $units->designate($this->s->actor($localAdmin), $this->s->org['A']->id, $foreign->id);

    expect(MonitoringUnit::query()->count())->toBe(2)
        ->and(MonitoringUnit::query()->where('organization_id', $this->s->org['HQ']->id)->value('unit_code'))->toBe('HQ-MON2')
        ->and(array_column(array_column($units->list($this->s->actor($localAdmin)), 'unit'), 'code'))->toBe(['A-MON']);
});

it('names and removes the unit from the report settings page', function (): void {
    $this->actingAs($this->s->admin)->post(route('reporting.monitoring-units.store'), ['organization_id' => $this->s->org['HQ']->id, 'organization_unit_id' => $this->monitoring->id])->assertRedirect();
    $this->actingAs($this->s->admin)->get(route('reporting.settings.edit'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('monitoring_units', 1)->where('monitoring_units.0.unit.code', 'HQ-MON')->where('monitoring_units.0.organization.code', 'RPT-HQ'));

    $this->actingAs($this->s->reviewer())->post(route('reporting.monitoring-units.store'), ['organization_id' => $this->s->org['HQ']->id, 'organization_unit_id' => $this->monitoring->id])->assertForbidden();
    $this->actingAs($this->s->admin)->delete(route('reporting.monitoring-units.destroy', $this->s->org['HQ']->id))->assertRedirect();
    expect(MonitoringUnit::query()->count())->toBe(0);
});
