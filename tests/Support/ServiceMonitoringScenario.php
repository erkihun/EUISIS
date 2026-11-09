<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\OrganizationScopeType;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\OrganizationScope\OrganizationScopeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\ServiceMonitoring\Application\Actions\AcceptSubmission;
use Modules\ServiceMonitoring\Application\Actions\CreateFiscalYear;
use Modules\ServiceMonitoring\Application\Actions\RegisterParticipant;
use Modules\ServiceMonitoring\Application\Actions\SaveSection2;
use Modules\ServiceMonitoring\Application\Actions\SaveSection4;
use Modules\ServiceMonitoring\Application\Actions\SetNothingToReport;
use Modules\ServiceMonitoring\Application\Actions\StartSubmission;
use Modules\ServiceMonitoring\Application\Actions\SubmitSubmission;
use Modules\ServiceMonitoring\Application\Services\ConsolidationService;
use Modules\ServiceMonitoring\Application\Services\FiscalCalendar;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAccess;
use Modules\ServiceMonitoring\Contracts\Data\ServiceMonitoringRoles;
use Modules\ServiceMonitoring\Domain\Forms\FormDefinition;
use Modules\ServiceMonitoring\Domain\Models\FiscalYear;
use Modules\ServiceMonitoring\Domain\Models\Participant;
use Modules\ServiceMonitoring\Domain\Models\ReportingPeriod;
use Modules\ServiceMonitoring\Domain\Models\Submission;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * A small city for Service Delivery Monitoring tests: one center bureau,
 * sub-city A with woredas A1 and A2, sub-city B with woreda B1, FY 2019 E.C.
 * with its four periods, and the registry. Users get scope records, as the
 * platform's scoped roles require (fail closed).
 *
 * "Now" is set inside a period's submission window by at().
 */
final class ServiceMonitoringScenario
{
    public FiscalYear $year;

    /** @var array<int, ReportingPeriod> */
    public array $periods = [];

    /** @var array<string, Organization> */
    public array $org = [];

    /** @var array<string, Participant> */
    public array $p = [];

    private int $users = 0;

    public static function build(): self
    {
        $s = new self;
        $s->at(1);

        $bureau = OrganizationType::query()->firstOrCreate(['code' => 'SM-BUREAU'], ['name_en' => 'Bureau', 'level_order' => 2, 'category' => 'functional']);
        $subCity = OrganizationType::query()->firstOrCreate(['code' => 'SM-SUB'], ['name_en' => 'Sub-city', 'level_order' => 2, 'category' => 'geographic']);
        $woreda = OrganizationType::query()->firstOrCreate(['code' => 'SM-WOREDA'], ['name_en' => 'Woreda', 'level_order' => 3, 'category' => 'geographic']);
        foreach ([
            'center' => [$bureau, 'Trade Bureau', 'ንግድ ቢሮ'],
            'subA' => [$subCity, 'Bole Sub-city', 'ቦሌ ክ/ከተማ'],
            'wA1' => [$woreda, 'Bole Woreda 1', 'ቦሌ ወረዳ 1'],
            'wA2' => [$woreda, 'Bole Woreda 2', 'ቦሌ ወረዳ 2'],
            'subB' => [$subCity, 'Yeka Sub-city', 'የካ ክ/ከተማ'],
            'wB1' => [$woreda, 'Yeka Woreda 1', 'የካ ወረዳ 1'],
        ] as $key => [$type, $en, $am]) {
            $s->org[$key] = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'SM-'.strtoupper($key), 'name_en' => $en, 'name_am' => $am, 'status' => 'active']);
        }

        $s->year = app(CreateFiscalYear::class)->execute(2019, null);
        foreach ($s->year->periods as $period) {
            $s->periods[$period->quarter] = $period;
        }

        $register = app(RegisterParticipant::class);
        $from = $s->year->starts_on->toDateString();
        $s->p['center'] = $register->execute(['organization_id' => $s->org['center']->id, 'level' => 'center', 'active_from' => $from], null);
        $s->p['subA'] = $register->execute(['organization_id' => $s->org['subA']->id, 'level' => 'sub_city', 'active_from' => $from], null);
        $s->p['subB'] = $register->execute(['organization_id' => $s->org['subB']->id, 'level' => 'sub_city', 'active_from' => $from], null);
        $s->p['wA1'] = $register->execute(['organization_id' => $s->org['wA1']->id, 'level' => 'woreda', 'parent_participant_id' => $s->p['subA']->id, 'active_from' => $from], null);
        $s->p['wA2'] = $register->execute(['organization_id' => $s->org['wA2']->id, 'level' => 'woreda', 'parent_participant_id' => $s->p['subA']->id, 'active_from' => $from], null);
        $s->p['wB1'] = $register->execute(['organization_id' => $s->org['wB1']->id, 'level' => 'woreda', 'parent_participant_id' => $s->p['subB']->id, 'active_from' => $from], null);

        return $s;
    }

    public function period(int $quarter): ReportingPeriod
    {
        return $this->periods[$quarter]->fresh();
    }

    /** Sets "now" to 09:00 Addis Ababa, $days after the period opens (default: inside the submission window). */
    public function at(int $quarter, int $days = 2, string $time = '09:00'): void
    {
        $opens = isset($this->periods[$quarter])
            ? $this->periods[$quarter]->opens_on->toDateString()
            : app(FiscalCalendar::class)->defaultSchedule(2019, $quarter)['opens_on'];
        $this->clock(CarbonImmutable::parse($opens)->addDays($days)->toDateString(), $time);
    }

    public function clock(string $date, string $time = '09:00'): void
    {
        Carbon::setTestNow(Carbon::parse("{$date} {$time}:00", 'Africa/Addis_Ababa')->setTimezone(date_default_timezone_get()));
        CarbonImmutable::setTestNow(CarbonImmutable::parse("{$date} {$time}:00", 'Africa/Addis_Ababa')->setTimezone(date_default_timezone_get()));
        $this->forget();
    }

    /**
     * A user holding $permissions, scoped to the given organizations (or city-wide).
     *
     * @param  list<string>  $permissions
     * @param  list<string>  $orgKeys
     */
    public function user(array $permissions, array $orgKeys = [], bool $citywide = false, string $role = ''): User
    {
        $this->users++;
        $roleModel = Role::findOrCreate($role !== '' ? $role : 'SM test role '.$this->users, 'web');
        $roleModel->syncPermissions(array_map(fn (string $p): Permission => Permission::findOrCreate($p, 'web'), $permissions));
        $user = User::factory()->create(['status' => 'active', 'email' => 'sm'.$this->users.'-'.Str::random(6).'@example.test']);
        $user->assignRole($roleModel);
        if ($citywide) {
            UserOrganizationScope::query()->create(['user_id' => $user->id, 'organization_id' => null, 'scope_type' => OrganizationScopeType::Citywide->value, 'is_active' => true]);
        }
        foreach ($orgKeys as $key) {
            UserOrganizationScope::query()->create(['user_id' => $user->id, 'organization_id' => $this->org[$key]->id, 'scope_type' => OrganizationScopeType::Self->value, 'is_active' => true]);
        }
        $user->forgetCachedPermissions();
        $this->forget();

        return $user->fresh();
    }

    public function head(string $key): User
    {
        return $this->user(ServiceMonitoringRoles::HEAD_PERMISSIONS, [$key]);
    }

    public function reporter(string $key): User
    {
        return $this->user(ServiceMonitoringRoles::REPORTER_PERMISSIONS, [$key]);
    }

    /** A sub-city reviewer: the sub-city and its woredas. */
    public function reviewer(string $subKey): User
    {
        $woredas = $subKey === 'subA' ? ['wA1', 'wA2'] : ['wB1'];

        return $this->user(ServiceMonitoringRoles::REVIEWER_PERMISSIONS, [$subKey, ...$woredas]);
    }

    public function coordinator(): User
    {
        return $this->user(ServiceMonitoringRoles::COORDINATOR_PERMISSIONS, [], true);
    }

    public function verifier(): User
    {
        return $this->user(ServiceMonitoringRoles::VERIFIER_PERMISSIONS, [], true);
    }

    public function employee(string $orgKey, string $name = 'Abebe Kebede'): Employee
    {
        $employee = Employee::query()->create([
            'employee_number' => 'SM-'.Str::upper(Str::random(8)), 'first_name' => explode(' ', $name)[0], 'last_name' => explode(' ', $name)[1] ?? 'X',
            'full_name' => $name, 'status' => 'active',
        ]);
        $assignment = EmployeeAssignment::query()->create([
            'employee_id' => $employee->id, 'organization_id' => $this->org[$orgKey]->id, 'assignment_status' => 'active',
            'effective_from' => '2026-01-01', 'is_current' => true,
        ]);
        $employee->update(['current_assignment_id' => $assignment->id]);

        return $employee->fresh();
    }

    public function start(string $key, int $quarter = 1, ?User $actor = null): Submission
    {
        return app(StartSubmission::class)->execute($this->period($quarter), $this->p[$key], $actor ?? $this->head($key));
    }

    /**
     * Fills a submission so it can be submitted: Sections I and III "የለም",
     * Section II figures (default 1..13 in form order), totals and Section IV.
     *
     * @param  array<string, int>  $section2
     * @param  array<string, int>  $section4
     */
    public function complete(Submission $submission, array $section2 = [], int $main = 10, int $sub = 20, array $section4 = [], bool $nothing1 = true, bool $nothing3 = true): Submission
    {
        $actor = $this->head($this->keyOf($submission));
        $values = [];
        foreach (FormDefinition::section2Keys() as $i => $field) {
            $values[$field] = $section2[$field] ?? $i + 1;
        }
        app(SaveSection2::class)->execute($submission, $values, null, [], $actor);
        app(SaveSection4::class)->execute($submission, ['main_total' => $main, 'sub_total' => $sub], $section4 + [
            'main_above' => 2, 'main_at' => $main - 2, 'main_below' => 0, 'sub_above' => 5, 'sub_at' => $sub - 5, 'sub_below' => 0,
        ], $actor);
        if ($nothing1) {
            app(SetNothingToReport::class)->execute($submission, 1, true, $actor);
        }
        if ($nothing3) {
            app(SetNothingToReport::class)->execute($submission, 3, true, $actor);
        }

        return $submission->fresh();
    }

    public function submit(Submission $submission, ?User $actor = null): Submission
    {
        return app(SubmitSubmission::class)->execute($submission->fresh(), $actor ?? $this->head($this->keyOf($submission)), true);
    }

    public function accept(Submission $submission, ?User $reviewer = null): Submission
    {
        $reviewer ??= $submission->level->value === 'woreda'
            ? $this->reviewer(in_array($this->keyOf($submission), ['wA1', 'wA2'], true) ? 'subA' : 'subB')
            : $this->coordinator();

        return app(AcceptSubmission::class)->execute($submission->fresh(), $reviewer);
    }

    /** @param array<string, int> $section2 */
    public function accepted(string $key, int $quarter = 1, array $section2 = [], int $main = 10, int $sub = 20): Submission
    {
        $this->at($quarter);
        $submission = $this->complete($this->start($key, $quarter), $section2, $main, $sub);

        return $this->accept($this->submit($submission));
    }

    public function keyOf(Submission $submission): string
    {
        foreach ($this->p as $key => $participant) {
            if ($participant->id === $submission->participant_id) {
                return $key;
            }
        }

        return 'center';
    }

    public function forget(): void
    {
        app(OrganizationScopeService::class)->clearCache();
        app(ServiceMonitoringAccess::class)->forget();
        app(ConsolidationService::class)->forget();
    }
}
