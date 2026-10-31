<?php

declare(strict_types=1);

namespace Modules\Court\Application\Services\Euisis;

use Modules\Court\Domain\Models\EuisisEmployee;
use Modules\Court\Domain\Models\EuisisOrganization;
use Modules\Court\Domain\Models\EuisisOrganizationUnit;
use Modules\Court\Domain\Models\EuisisPosition;
use Modules\Court\Domain\Models\EuisisStaffNotice;
use Modules\Court\Domain\Models\EuisisStaffUnit;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Court staff accounts linked to their EUISIS employee records
 * (docs/euisis-integration.md, "Staff accounts"). The court's staff are the
 * EUISIS employees of the units chosen as the court's own (with their
 * sub-units where asked). A staff account is linked by employee number to an
 * active employee placed in one of those units; court roles stay in court-ms.
 *
 * After each `euisis:sync`, a linked account whose employee is no longer
 * active or has left the court's units is suspended (status `inactive`,
 * `euisis_suspended_at`) and the administrators are told; it is never
 * deleted, as case history refers to it. The suspension is lifted when the
 * employee is back.
 */
final class StaffLink
{
    public const LEFT_UNIT = 'left_unit';

    public const NOT_ACTIVE = 'not_active';

    public const NOT_IN_EUISIS = 'not_in_euisis';

    /** Pages read per unit for the roster (100 employees a page). */
    private const MAX_ROSTER_PAGES = 20;

    public function __construct(private readonly EuisisClient $client, private readonly EuisisSync $sync) {}

    /**
     * Every unit the court counts as its own: the chosen units and, where asked,
     * every unit below them in the mirrored EUISIS tree.
     *
     * @return list<string>
     */
    public function unitUuids(): array
    {
        $chosen = EuisisStaffUnit::query()->get();
        if ($chosen->isEmpty()) {
            return [];
        }

        $children = EuisisOrganizationUnit::query()
            ->whereIn('organization_uuid', $chosen->pluck('organization_uuid')->unique()->all())
            ->whereNotNull('parent_unit_uuid')
            ->get(['uuid', 'parent_unit_uuid'])
            ->groupBy('parent_unit_uuid')
            ->map(fn ($units) => $units->pluck('uuid')->all());

        $found = [];
        foreach ($chosen as $unit) {
            $queue = [(string) $unit->unit_uuid];
            while ($queue !== []) {
                $uuid = array_shift($queue);
                if (isset($found[$uuid])) {
                    continue;
                }
                $found[$uuid] = true;
                if ($unit->include_sub_units) {
                    array_push($queue, ...array_map('strval', $children->get($uuid, [])));
                }
            }
        }

        return array_keys($found);
    }

    /**
     * Replaces the court's units with the ones given, each one of the mirrored units.
     *
     * @param  list<array{unit_uuid: string, include_sub_units: bool}>  $units
     */
    public function saveUnits(array $units): void
    {
        DB::transaction(function () use ($units): void {
            EuisisStaffUnit::query()->delete();
            foreach ($units as $row) {
                $unit = EuisisOrganizationUnit::query()->findOrFail($row['unit_uuid']);
                EuisisStaffUnit::query()->create([
                    'unit_uuid' => $unit->uuid,
                    'organization_uuid' => $unit->organization_uuid,
                    'include_sub_units' => (bool) $row['include_sub_units'],
                ]);
            }
        });
    }

    /**
     * Why an employee is not court staff, or null when they are: active in EUISIS and
     * currently placed in one of the court's units.
     *
     * @param  list<string>|null  $units
     */
    public function standing(?EuisisEmployee $employee, ?array $units = null): ?string
    {
        if ($employee === null) {
            return self::NOT_IN_EUISIS;
        }

        return $this->standingOf($employee->status, $employee->assignment_uuid !== null ? $employee->unit_uuid : null, $units);
    }

    /**
     * The EUISIS employee with this number, when they may hold a staff account here.
     *
     * @return array<string, mixed> the EUISIS employee record
     *
     * @throws StaffLinkException|EuisisException
     */
    public function eligible(string $employeeNumber, ?User $for = null): array
    {
        $units = $this->unitUuids();
        if ($units === []) {
            throw new StaffLinkException('no_court_unit');
        }

        $number = trim($employeeNumber);
        $matches = array_values(array_filter(
            $this->client->employeesByNumber($number),
            fn (array $row): bool => mb_strtolower((string) ($row['employee_number'] ?? '')) === mb_strtolower($number),
        ));
        if ($matches === []) {
            throw new StaffLinkException('not_found');
        }
        if (count($matches) > 1) {
            throw new StaffLinkException('ambiguous');
        }

        return $this->assertEligible($matches[0], $units, $for);
    }

    /**
     * The EUISIS employee with this UUID, when they may hold a staff account here
     * (the roster's "Create account").
     *
     * @return array<string, mixed>
     *
     * @throws StaffLinkException|EuisisException
     */
    public function eligibleByUuid(string $uuid): array
    {
        $units = $this->unitUuids();
        if ($units === []) {
            throw new StaffLinkException('no_court_unit');
        }
        $row = $this->client->employee($uuid);
        if ($row === null) {
            throw new StaffLinkException('not_found');
        }

        return $this->assertEligible($row, $units, null);
    }

    /**
     * Links the account to the employee (an `eligible()` record), caching the employee
     * so the account's page shows them; lifts an EUISIS suspension the link now clears.
     *
     * @param  array<string, mixed>  $row
     */
    public function attach(User $user, array $row): EuisisEmployee
    {
        $employee = $this->sync->cacheEmployee($row);
        $user->forceFill([
            'euisis_employee_uuid' => $employee->uuid,
            'euisis_linked_at' => now(),
        ])->save();
        if ($user->euisis_suspended_at !== null) {
            $this->restore($user);
        }

        return $employee;
    }

    /** @throws StaffLinkException|EuisisException */
    public function link(User $user, string $employeeNumber): EuisisEmployee
    {
        return $this->attach($user, $this->eligible($employeeNumber, $user));
    }

    /**
     * Takes the link away. An EUISIS suspension stays: the account remains inactive
     * until an administrator sets it active.
     */
    public function unlink(User $user): void
    {
        $user->forceFill(['euisis_employee_uuid' => null, 'euisis_linked_at' => null])->save();
    }

    /**
     * Suspends linked accounts whose employee left the court's units or is no longer
     * active, and lifts the suspensions that no longer hold. With `$refresh` (no change
     * feed), employee records older than an hour are read again first. Nothing happens
     * while the court has no units, so an empty setting never suspends everyone.
     *
     * @return array{checked: int, suspended: int, restored: int, errors: int}
     */
    public function review(bool $refresh = false): array
    {
        $summary = ['checked' => 0, 'suspended' => 0, 'restored' => 0, 'errors' => 0];
        $units = $this->unitUuids();
        if ($units === []) {
            return $summary;
        }

        foreach (User::query()->whereNotNull('euisis_employee_uuid')->get() as $user) {
            $summary['checked']++;
            $employee = EuisisEmployee::query()->find($user->euisis_employee_uuid);
            $stale = $employee === null || $employee->synced_at === null || $employee->synced_at->lt(now()->subHour());
            if ($employee === null || ($refresh && $stale)) {
                try {
                    $row = $this->client->employee((string) $user->euisis_employee_uuid);
                } catch (EuisisException) {
                    $summary['errors']++;

                    continue; // never suspend on a failed read
                }
                $employee = $row === null ? null : $this->sync->cacheEmployee($row);
            }

            $reason = $this->standing($employee, $units);
            if ($reason !== null && $user->euisis_suspended_at === null && $user->status === 'active') {
                $this->suspend($user, $reason);
                $summary['suspended']++;
            } elseif ($reason === null && $user->euisis_suspended_at !== null) {
                $this->restore($user);
                $summary['restored']++;
            }
        }

        return $summary;
    }

    /** Signs the account out everywhere and keeps it from signing in; the record stays. */
    public function suspend(User $user, string $reason): void
    {
        DB::transaction(function () use ($user, $reason): void {
            $user->forceFill([
                'status' => 'inactive',
                'euisis_suspended_at' => now(),
                'euisis_suspension_reason' => $reason,
            ])->save();
            EuisisStaffNotice::query()->create(['user_id' => $user->id, 'event' => EuisisStaffNotice::SUSPENDED, 'reason' => $reason]);
        });
    }

    public function restore(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user->forceFill(['status' => 'active', 'euisis_suspended_at' => null, 'euisis_suspension_reason' => null])->save();
            EuisisStaffNotice::query()->create(['user_id' => $user->id, 'event' => EuisisStaffNotice::RESTORED, 'reason' => null]);
        });
    }

    /**
     * The court units' employees as EUISIS lists them, each with the staff account
     * linked to them, if any. Units without a code in the mirror cannot be asked for.
     *
     * @return array{employees: list<array<string, mixed>>, skipped_units: list<string>}
     *
     * @throws EuisisException
     */
    public function roster(): array
    {
        $uuids = $this->unitUuids();
        $units = EuisisOrganizationUnit::query()->whereIn('uuid', $uuids)->get()->keyBy('uuid');
        $organizations = EuisisOrganization::query()->whereIn('uuid', $units->pluck('organization_uuid')->unique()->all())->get()->keyBy('uuid');

        $rows = [];
        $skipped = [];
        foreach ($units as $unit) {
            $organization = $organizations->get($unit->organization_uuid);
            if (blank($unit->code) || blank($organization?->code)) {
                $skipped[] = $this->unitName($unit);

                continue;
            }
            for ($page = 1, $last = 1; $page <= $last && $page <= self::MAX_ROSTER_PAGES; $page++) {
                $result = $this->client->employeesInUnit((string) $organization->code, (string) $unit->code, $page);
                $last = $result['last_page'];
                foreach ($result['data'] as $row) {
                    $rows[(string) $row['id']] = $row;
                }
            }
        }

        foreach ($organizations->keys() as $organizationUuid) {
            if (! EuisisPosition::query()->where('organization_uuid', $organizationUuid)->exists()) {
                $this->sync->loadPositions((string) $organizationUuid);
            }
        }
        $positions = EuisisPosition::query()->whereIn('uuid', array_filter(array_map(fn (array $row) => $this->placement($row)['position_uuid'], $rows)))->get()->keyBy('uuid');
        $accounts = User::query()->whereIn('euisis_employee_uuid', array_keys($rows))->get()->keyBy('euisis_employee_uuid');

        $employees = [];
        foreach ($rows as $uuid => $row) {
            $placement = $this->placement($row);
            $account = $accounts->get($uuid);
            $employees[] = [
                'id' => $uuid,
                'employee_number' => (string) ($row['employee_number'] ?? ''),
                'name' => (string) ($row['full_name'] ?? $row['full_name_en'] ?? ''),
                'name_en' => $row['full_name_en'] ?? null,
                'status' => $row['employment_status'] ?? null,
                'unit' => ($unit = $units->get($placement['unit_uuid'])) !== null ? $this->unitName($unit) : null,
                'position' => $positions->get($placement['position_uuid'])?->displayTitle(),
                'account' => $account === null ? null : [
                    'id' => (int) $account->id,
                    'name' => (string) $account->name,
                    'email' => (string) $account->email,
                    'status' => (string) $account->status,
                    'suspended' => $account->euisis_suspended_at !== null,
                ],
            ];
        }
        usort($employees, fn (array $a, array $b): int => strnatcasecmp($a['employee_number'], $b['employee_number']));

        return ['employees' => $employees, 'skipped_units' => $skipped];
    }

    /** The unit's name in the current locale. */
    public function unitName(EuisisOrganizationUnit $unit): string
    {
        return app()->getLocale() === 'am' && filled($unit->name_am) ? (string) $unit->name_am : (string) $unit->name_en;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $units
     * @return array<string, mixed>
     */
    private function assertEligible(array $row, array $units, ?User $for): array
    {
        $placement = $this->placement($row);
        $reason = $this->standingOf($row['employment_status'] ?? null, $placement['assignment_uuid'] !== null ? $placement['unit_uuid'] : null, $units);
        if ($reason === self::NOT_ACTIVE) {
            throw new StaffLinkException('not_active');
        }
        if ($reason !== null) {
            throw new StaffLinkException('outside_court');
        }
        $taken = User::query()->where('euisis_employee_uuid', $row['id'])
            ->when($for !== null, fn ($query) => $query->whereKeyNot($for->id))
            ->exists();
        if ($taken) {
            throw new StaffLinkException('linked_to_another_account');
        }

        return $row;
    }

    /**
     * @param  list<string>|null  $units
     */
    private function standingOf(?string $status, ?string $unitUuid, ?array $units): ?string
    {
        if ($status !== 'active') {
            return self::NOT_ACTIVE;
        }

        return $unitUuid !== null && in_array($unitUuid, $units ?? $this->unitUuids(), true) ? null : self::LEFT_UNIT;
    }

    /**
     * Where an EUISIS employee record places the employee now.
     *
     * @param  array<string, mixed>  $row
     * @return array{assignment_uuid: ?string, unit_uuid: ?string, position_uuid: ?string}
     */
    private function placement(array $row): array
    {
        $assignment = (array) ($row['active_assignment'] ?? []);

        return [
            'assignment_uuid' => isset($assignment['id']) ? (string) $assignment['id'] : null,
            'unit_uuid' => ($row['organization_unit_id'] ?? $assignment['organization_unit_id'] ?? null) !== null ? (string) ($row['organization_unit_id'] ?? $assignment['organization_unit_id']) : null,
            'position_uuid' => ($row['position_id'] ?? $assignment['position_id'] ?? null) !== null ? (string) ($row['position_id'] ?? $assignment['position_id']) : null,
        ];
    }
}
