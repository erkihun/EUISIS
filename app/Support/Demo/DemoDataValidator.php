<?php

declare(strict_types=1);

namespace App\Support\Demo;

use App\Enums\AssignmentStatus;
use App\Enums\CafeteriaGrantStatus;
use App\Enums\CafeteriaLocationType;
use App\Enums\CafeteriaPolicyStatus;
use App\Enums\HierarchyVersionStatus;
use App\Models\CafeteriaProvider;
use App\Models\CafeteriaServiceAssignment;
use App\Models\CafeteriaServicePolicy;
use App\Models\CafeteriaTransaction;
use App\Models\DailyActivityLog;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Grievance;
use App\Models\HierarchyVersion;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationCafeteriaAccess;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\ProviderUser;
use App\Models\StrategicGoal;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-only checks and counts for the demo dataset (demo-data:validate,
 * the seeder summary and the tests). Never writes.
 */
class DemoDataValidator
{
    public const MINIMUM_PER_ORGANIZATION = 5;

    /** @return array<string, int> */
    public function counts(): array
    {
        $organizationIds = DemoDataset::organizationIds();
        $employeeIds = $this->employees()->pluck('id');
        $providerIds = collect(array_keys(DemoDataset::providers()))->map(fn (string $key) => DemoDataset::provider($key)?->id)->filter()->values();

        return [
            'Organizations' => $this->businessOrganizations()->count(),
            'Support organizations' => DemoDataset::organization(DemoDataset::ROOT) !== null ? 1 : 0,
            'Organization Units' => OrganizationUnit::query()->whereIn('organization_id', $organizationIds)->count(),
            'Positions' => Position::query()->whereIn('organization_id', $organizationIds)->count(),
            'Employees' => $employeeIds->count(),
            'Users' => User::query()->whereIn('email', array_keys(DemoDataset::users()))->count(),
            'Provider users' => ProviderUser::query()->whereIn('email', array_keys(DemoDataset::providerUsers()))->count(),
            'ID Cards' => IdCard::query()->whereIn('employee_id', $employeeIds)->count(),
            'Providers' => $providerIds->count(),
            'Cafeteria Networks' => collect(array_keys(DemoDataset::networks()))->filter(fn (string $key) => DemoDataset::network($key) !== null)->count(),
            'Cafeterias' => CafeteriaProvider::query()->whereIn('provider_id', $providerIds)->count(),
            'Cafeteria Policies' => CafeteriaServicePolicy::query()->whereIn('organization_id', $organizationIds)->count(),
            'Transactions' => CafeteriaTransaction::query()->whereIn('employee_id', $employeeIds)->count(),
            'Daily Activity logs' => DailyActivityLog::query()->whereIn('employee_id', $employeeIds)->count(),
            'Performance goals' => StrategicGoal::query()->whereIn('organization_id', $organizationIds)->count(),
            'Grievance cases' => Grievance::query()->whereIn('employee_id', $employeeIds)->count(),
        ];
    }

    /**
     * @return list<array{check: string, passed: bool, detail: string}>
     */
    public function validate(): array
    {
        $results = [];
        $add = function (string $check, bool $passed, string $detail = '') use (&$results): void {
            $results[] = ['check' => $check, 'passed' => $passed, 'detail' => $detail];
        };

        $organizations = $this->businessOrganizations();
        $expected = count(DemoDataset::businessOrganizationKeys());
        $add("{$expected} demo organizations exist", $organizations->count() === $expected, "found {$organizations->count()}");

        foreach ($organizations as $organization) {
            $label = $organization->metadata['demo_key'].' '.$organization->name_en;
            $units = OrganizationUnit::query()->where('organization_id', $organization->id)->count();
            $positions = Position::query()->where('organization_id', $organization->id)->count();
            $employees = $this->currentAssignments()->where('organization_id', $organization->id)->count();
            $cafeterias = $this->accessibleCafeterias($organization)->count();
            $min = self::MINIMUM_PER_ORGANIZATION;
            $add("{$label}: >= {$min} units", $units >= $min, "{$units}");
            $add("{$label}: >= {$min} positions", $positions >= $min, "{$positions}");
            $add("{$label}: >= {$min} employees", $employees >= $min, "{$employees}");
            $add("{$label}: >= {$min} accessible cafeterias", $cafeterias >= $min, "{$cafeterias}");
            $add("{$label}: active cafeteria policy today", $this->activePolicy($organization) !== null);
        }

        $add('every demo employee has one valid current assignment', ($bad = $this->invalidAssignments()) === [], implode(', ', $bad));
        $add('no position has more than one active occupant', ($over = $this->overOccupiedPositions()) === [], implode(', ', $over));
        $add('at least one vacant demo position', $this->vacantPositionCount() > 0, (string) $this->vacantPositionCount());
        $add('employee numbers are unique', $this->duplicates(Employee::query()->pluck('employee_number')) === []);
        $add('organization codes are unique', $this->duplicates(Organization::withTrashed()->pluck('code')) === []);
        $add('card numbers are unique', $this->duplicates(IdCard::query()->pluck('card_number')) === []);
        $add('cafeteria placement is valid (provider, main, parent)', ($placement = $this->placementProblems()) === [], implode('; ', $placement));
        $add('organization access is valid', ($access = $this->accessProblems()) === [], implode('; ', $access));
        $add('service assignments are valid', ($assignments = $this->assignmentProblems()) === [], implode('; ', $assignments));
        $add('no overlapping binding policies', ($overlaps = $this->policyOverlaps()) === [], implode('; ', $overlaps));
        $add('demo organizations have distinct subsidies', $this->distinctSubsidies(), '');
        $add('transaction snapshots match owner, location and payee', ($tx = $this->transactionProblems()) === [], implode('; ', $tx));

        $version = HierarchyVersion::query()->where('version_name', DemoDataset::HIERARCHY_VERSION_NAME)->first();
        $add('demo hierarchy version exists', $version !== null, $version?->status?->value ?? 'missing');

        return $results;
    }

    /** Informational notes that are not failures. @return list<string> */
    public function notes(): array
    {
        $version = HierarchyVersion::query()->where('version_name', DemoDataset::HIERARCHY_VERSION_NAME)->first();

        return $version !== null && $version->status === HierarchyVersionStatus::Draft
            ? ['"'.DemoDataset::HIERARCHY_VERSION_NAME.'" is a draft because another hierarchy version is published: subtree scopes on demo organizations apply only after it is published.']
            : [];
    }

    /** @return Collection<int, Organization> */
    public function businessOrganizations(): Collection
    {
        return collect(DemoDataset::businessOrganizationKeys())->map(fn (string $key) => DemoDataset::organization($key))->filter()->values();
    }

    /**
     * Locations an organization may use today under its access rules:
     * primary, cross-location, and explicit location exceptions.
     *
     * @return Collection<int, string> cafeteria ids
     */
    public function accessibleCafeterias(Organization $organization, ?Carbon $on = null): Collection
    {
        $on ??= today();

        return OrganizationCafeteriaAccess::query()
            ->with('locationExceptions')
            ->where('organization_id', $organization->id)
            ->where('status', CafeteriaGrantStatus::Active->value)
            ->whereDate('effective_from', '<=', $on)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $on))
            ->get()
            ->flatMap(function (OrganizationCafeteriaAccess $access) use ($on): Collection {
                return CafeteriaProvider::query()->where('cafeteria_service_network_id', $access->cafeteria_service_network_id)
                    ->where('is_active', true)->get()
                    ->filter(fn (CafeteriaProvider $cafeteria): bool => $cafeteria->isServing())
                    ->filter(function (CafeteriaProvider $cafeteria) use ($access, $on): bool {
                        $exception = $access->locationExceptions
                            ->first(fn ($e) => $e->cafeteria_id === $cafeteria->id && $e->effective_from->lte($on) && ($e->effective_to === null || $e->effective_to->gte($on)));
                        if ($exception !== null) {
                            return (bool) $exception->is_allowed;
                        }

                        return $cafeteria->id === $access->primary_cafeteria_id || $access->allow_cross_location_usage;
                    })
                    ->pluck('id');
            })
            ->unique()
            ->values();
    }

    public function activePolicy(Organization $organization, ?Carbon $on = null): ?CafeteriaServicePolicy
    {
        $on ??= today();

        return CafeteriaServicePolicy::query()
            ->where('organization_id', $organization->id)
            ->whereIn('status', CafeteriaPolicyStatus::binding())
            ->whereDate('effective_from', '<=', $on)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $on))
            ->first();
    }

    /** @return Collection<int, Employee> */
    private function employees(): Collection
    {
        return Employee::query()->where('metadata->demo_dataset', DemoDataset::TAG)->get();
    }

    /** @return Collection<int, EmployeeAssignment> */
    private function currentAssignments(): Collection
    {
        return EmployeeAssignment::query()
            ->whereIn('employee_id', $this->employees()->pluck('id'))
            ->where('is_current', true)
            ->where('assignment_status', AssignmentStatus::Active->value)
            ->get();
    }

    /** @return list<string> */
    private function invalidAssignments(): array
    {
        $problems = [];
        foreach ($this->employees() as $employee) {
            $key = $employee->metadata['demo_key'] ?? $employee->id;
            $current = EmployeeAssignment::query()->where('employee_id', $employee->id)
                ->where('is_current', true)->where('assignment_status', AssignmentStatus::Active->value)->get();
            $assignment = $current->first();

            if ($current->count() !== 1 || $assignment->id !== $employee->current_assignment_id) {
                $problems[] = "{$key}: {$current->count()} current assignments";

                continue;
            }

            $position = $assignment->position_id !== null ? Position::query()->find($assignment->position_id) : null;
            $unit = $assignment->organization_unit_id !== null ? OrganizationUnit::query()->find($assignment->organization_unit_id) : null;
            if ($position === null || $unit === null
                || $position->organization_id !== $assignment->organization_id
                || $unit->organization_id !== $assignment->organization_id
                || $position->organization_unit_id !== $unit->id) {
                $problems[] = "{$key}: organization / unit / position mismatch";
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function overOccupiedPositions(): array
    {
        return EmployeeAssignment::query()
            ->whereIn('organization_id', DemoDataset::organizationIds())
            ->whereNotNull('position_id')
            ->where('is_current', true)
            ->where('assignment_status', AssignmentStatus::Active->value)
            ->get()
            ->countBy('position_id')
            ->filter(fn (int $count) => $count > 1)
            ->keys()
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
    }

    private function vacantPositionCount(): int
    {
        $occupied = $this->currentAssignments()->pluck('position_id')->filter()->unique();

        return Position::query()->whereIn('organization_id', DemoDataset::organizationIds())->whereNotIn('id', $occupied)->count();
    }

    /** @return list<string> */
    private function placementProblems(): array
    {
        $problems = [];
        foreach (array_keys(DemoDataset::networks()) as $networkKey) {
            $network = DemoDataset::network($networkKey);
            if ($network === null) {
                $problems[] = "{$networkKey}: missing";

                continue;
            }
            $locations = CafeteriaProvider::query()->where('cafeteria_service_network_id', $network->id)->get();
            $mains = $locations->filter(fn (CafeteriaProvider $c) => $c->location_type === CafeteriaLocationType::Main);
            if ($mains->count() !== 1) {
                $problems[] = "{$networkKey}: {$mains->count()} main cafeterias";
            }
            foreach ($locations as $location) {
                if ($location->provider_id !== $network->provider_id) {
                    $problems[] = "{$location->code}: provider differs from its network's";
                }
                if ($location->location_type !== CafeteriaLocationType::Main
                    && ! $locations->contains(fn (CafeteriaProvider $c) => $c->id === $location->parent_cafeteria_id)) {
                    $problems[] = "{$location->code}: parent outside its network";
                }
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function accessProblems(): array
    {
        return OrganizationCafeteriaAccess::query()->with('locationExceptions')
            ->whereIn('organization_id', DemoDataset::organizationIds())->get()
            ->flatMap(function (OrganizationCafeteriaAccess $access): array {
                $problems = [];
                $inNetwork = fn (?string $id) => $id === null || CafeteriaProvider::query()->whereKey($id)->where('cafeteria_service_network_id', $access->cafeteria_service_network_id)->exists();
                if (! $inNetwork($access->primary_cafeteria_id)) {
                    $problems[] = "access {$access->id}: primary cafeteria outside the network";
                }
                foreach ($access->locationExceptions as $exception) {
                    if (! $inNetwork($exception->cafeteria_id)) {
                        $problems[] = "access {$access->id}: exception outside the network";
                    }
                }

                return $problems;
            })->values()->all();
    }

    /** @return list<string> */
    private function assignmentProblems(): array
    {
        return CafeteriaServiceAssignment::query()->with('network')
            ->whereIn('organization_id', DemoDataset::organizationIds())->get()
            ->filter(fn (CafeteriaServiceAssignment $a) => $a->cafeteria_service_network_id !== null && $a->network?->provider_id !== $a->provider_id)
            ->map(fn (CafeteriaServiceAssignment $a) => "assignment {$a->id}: network of another provider")
            ->values()->all();
    }

    /** @return list<string> */
    private function policyOverlaps(): array
    {
        $problems = [];
        CafeteriaServicePolicy::query()
            ->whereIn('organization_id', DemoDataset::organizationIds())
            ->whereIn('status', CafeteriaPolicyStatus::binding())
            ->orderBy('effective_from')
            ->get()
            ->groupBy('scope_key')
            ->each(function (Collection $policies, string $scope) use (&$problems): void {
                $previous = null;
                foreach ($policies as $policy) {
                    if ($previous !== null && ($previous->effective_to === null || $previous->effective_to->gte($policy->effective_from))) {
                        $problems[] = "scope {$scope}: v{$previous->version_no} overlaps v{$policy->version_no}";
                    }
                    $previous = $policy;
                }
            });

        return $problems;
    }

    private function distinctSubsidies(): bool
    {
        $subsidies = $this->businessOrganizations()->map(fn (Organization $o) => $this->activePolicy($o)?->daily_subsidy_amount)->filter();

        return $subsidies->count() === $subsidies->unique()->count();
    }

    /** @return list<string> */
    private function transactionProblems(): array
    {
        return CafeteriaTransaction::query()
            ->with('provider')
            ->whereIn('employee_id', $this->employees()->pluck('id'))
            ->get()
            ->flatMap(function (CafeteriaTransaction $transaction): array {
                $problems = [];
                $date = Carbon::parse($transaction->transaction_date)->toDateString();
                $owner = EmployeeAssignment::query()->where('employee_id', $transaction->employee_id)
                    ->whereDate('effective_from', '<=', $date)
                    ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
                    ->value('organization_id');
                $policy = CafeteriaServicePolicy::query()->find($transaction->cafeteria_service_policy_id);

                if ($transaction->employee_organization_id !== $owner) {
                    $problems[] = "{$transaction->transaction_number}: owner is not the employee's organization";
                }
                // `provider` is the scanned location (cafeteria_providers); provider_id the payee.
                if ($transaction->provider_id !== $transaction->provider?->provider_id) {
                    $problems[] = "{$transaction->transaction_number}: payee is not the cafeteria's provider";
                }
                if ($policy === null || $policy->organization_id !== $owner
                    || $policy->effective_from->toDateString() > $date
                    || ($policy->effective_to !== null && $policy->effective_to->toDateString() < $date)) {
                    $problems[] = "{$transaction->transaction_number}: policy does not govern this date and owner";
                }

                return $problems;
            })->values()->all();
    }

    /** @param Collection<int, mixed> $values @return list<string> */
    private function duplicates(Collection $values): array
    {
        return $values->filter()->countBy()->filter(fn (int $n) => $n > 1)->keys()->map(fn ($v) => (string) $v)->all();
    }
}
