<?php

declare(strict_types=1);

namespace Modules\Court\Application\Services\Euisis;

use Modules\Court\Domain\Models\EuisisEmployee;
use Modules\Court\Domain\Models\EuisisOrganization;
use Modules\Court\Domain\Models\EuisisOrganizationUnit;
use Modules\Court\Domain\Models\EuisisPosition;
use Modules\Court\Domain\Models\EuisisReferral;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the court-ms cache of EUISIS master data current
 * (docs/euisis-integration.md, Flow 1), following the EUISIS partner
 * contract: store the feed watermark, page through the directory, then apply
 * the change feed from the watermark and save the cursor after each page.
 *
 * Organizations and their units are mirrored in full. Positions are kept only
 * for organizations someone linked a person in, and employees only when they
 * are a party to a case — never the whole workforce.
 */
final class EuisisSync
{
    public const CURSOR_KEY = 'change_feed_cursor';

    public const BOOTSTRAPPED_KEY = 'bootstrapped_at';

    public const LAST_RUN_KEY = 'last_run_at';

    public const LAST_ERROR_KEY = 'last_error';

    public function __construct(private readonly EuisisClient $client) {}

    /**
     * Bootstraps once, then applies the feed. When EUISIS has its change feed
     * switched off, the directory is refreshed instead, at most hourly, until
     * the feed is on.
     *
     * @return array{bootstrapped: bool, organizations: int, units: int, events: int, applied: int, feed: bool, people: int}
     */
    public function run(bool $forceBootstrap = false): array
    {
        $summary = ['bootstrapped' => false, 'organizations' => 0, 'units' => 0, 'events' => 0, 'applied' => 0, 'feed' => true, 'people' => 0];

        try {
            if ($forceBootstrap || $this->state(self::CURSOR_KEY) === null) {
                $lastBootstrap = $this->state(self::BOOTSTRAPPED_KEY);
                $due = $forceBootstrap || $lastBootstrap === null || now()->subHour()->gt($lastBootstrap);
                if ($due) {
                    $summary = [...$summary, ...$this->bootstrap(), 'bootstrapped' => true];
                }
            }
            if ($this->state(self::CURSOR_KEY) === null) {
                $summary['feed'] = false;
            } else {
                [$events, $applied] = $this->pull();
                $summary['events'] = $events;
                $summary['applied'] = $applied;
            }
            $summary['people'] = $this->cacheReferredPeople();
            $this->remember(self::LAST_ERROR_KEY, null);
        } catch (EuisisException $e) {
            $this->remember(self::LAST_ERROR_KEY, $e->getMessage());
            throw $e;
        } finally {
            $this->remember(self::LAST_RUN_KEY, now()->toIso8601String());
        }

        return $summary;
    }

    /** @return array{organizations: int, units: int} */
    public function bootstrap(): array
    {
        try {
            $watermark = $this->client->watermark()['cursor'];
        } catch (EuisisException $e) {
            if ($e->errorCode !== 'change_feed_disabled') {
                throw $e;
            }
            // The feed is off in EUISIS: refresh the directory and keep no cursor.
            $watermark = null;
        }
        $organizations = 0;
        $units = 0;

        for ($page = 1, $last = 1; $page <= $last; $page++) {
            $result = $this->client->organizations($page);
            $last = $result['last_page'];
            foreach ($result['data'] as $row) {
                $organization = $this->upsertOrganization([
                    'uuid' => $row['id'], 'code' => $row['code'] ?? null, 'name_en' => $row['name_en'] ?? '',
                    'name_am' => $row['name_am'] ?? null, 'status' => $row['status'] ?? null,
                ]);
                $organizations++;
                $units += $this->loadUnits($organization->uuid);
            }
        }

        // Positions already tracked for an organization are refreshed too.
        foreach (EuisisPosition::query()->distinct()->pluck('organization_uuid') as $organizationUuid) {
            $this->loadPositions((string) $organizationUuid);
        }

        if ($watermark !== null && $watermark !== '') {
            $this->remember(self::CURSOR_KEY, $watermark);
        }
        $this->remember(self::BOOTSTRAPPED_KEY, now()->toIso8601String());

        return ['organizations' => $organizations, 'units' => $units];
    }

    /** Applies change-feed pages from the saved cursor. @return array{0: int, 1: int} events read, events applied */
    public function pull(): array
    {
        $cursor = $this->state(self::CURSOR_KEY);
        $events = 0;
        $applied = 0;
        $maxPages = max(1, (int) config('court.euisis.max_pages_per_run', 20));

        for ($page = 0; $page < $maxPages; $page++) {
            $result = $this->client->changes($cursor);
            DB::transaction(function () use ($result, &$events, &$applied): void {
                foreach ($result['data'] as $event) {
                    $events++;
                    $applied += $this->apply($event) ? 1 : 0;
                }
                if ($result['next_cursor'] !== null && $result['next_cursor'] !== '') {
                    $this->remember(self::CURSOR_KEY, (string) $result['next_cursor']);
                }
            });
            $cursor = $result['next_cursor'] ?? $cursor;
            if (! $result['has_more']) {
                break;
            }
        }

        return [$events, $applied];
    }

    /** Applies one event envelope; false when it is not for anything court-ms keeps or is older than what it has. */
    public function apply(array $event): bool
    {
        $type = (string) ($event['event_type'] ?? '');
        $data = (array) ($event['data'] ?? []);
        $sequence = (int) ($event['sequence'] ?? 0);
        $tombstone = ($event['change_kind'] ?? 'upsert') === 'tombstone';

        return match (true) {
            str_starts_with($type, 'organization_unit.') => $this->applyUnit($type, $data, $sequence, $tombstone),
            str_starts_with($type, 'organization.') => $this->applyOrganization($type, $data, $sequence, $tombstone),
            str_starts_with($type, 'position.') => $this->applyPosition($type, $data, $sequence, $tombstone),
            str_starts_with($type, 'employee.') || str_starts_with($type, 'assignment.') => $this->applyPerson($type, $data, $sequence),
            default => false,
        };
    }

    /** Positions of one organization, loaded when a person there is first linked. */
    public function loadPositions(string $organizationUuid): int
    {
        $count = 0;
        for ($page = 1, $last = 1; $page <= $last; $page++) {
            $result = $this->client->positions($organizationUuid, $page);
            $last = $result['last_page'];
            foreach ($result['data'] as $row) {
                EuisisPosition::query()->updateOrCreate(['uuid' => $row['id']], [
                    'organization_uuid' => $row['organization_id'] ?? $organizationUuid,
                    'unit_uuid' => $row['organization_unit_id'] ?? null,
                    'code' => $row['code'] ?? null,
                    'title_en' => (string) ($row['standard_name'] ?? $row['code'] ?? ''),
                    'title_am' => $row['standard_name_am'] ?? null,
                    'is_active' => ($row['position_status'] ?? 'active') === 'active',
                    'synced_at' => now(),
                ]);
                $count++;
            }
        }

        return $count;
    }

    /** The organization from the cache, fetched from EUISIS when it is not there yet. */
    public function organization(string $uuid): ?EuisisOrganization
    {
        $cached = EuisisOrganization::query()->find($uuid);
        if ($cached !== null) {
            return $cached;
        }
        $row = $this->client->organization($uuid);

        return $row === null ? null : $this->upsertOrganization([
            'uuid' => $row['id'], 'code' => $row['code'] ?? null, 'name_en' => $row['name_en'] ?? '',
            'name_am' => $row['name_am'] ?? null, 'status' => $row['status'] ?? null,
        ]);
    }

    /** Caches an employee from an EUISIS employee record (`/employees`, `/employees/{id}`). */
    public function cacheEmployee(array $row): EuisisEmployee
    {
        $assignment = (array) ($row['active_assignment'] ?? []);
        $organizationUuid = $row['organization_id'] ?? ($assignment['organization_id'] ?? null);

        $employee = EuisisEmployee::query()->updateOrCreate(['uuid' => $row['id']], [
            'employee_number' => $row['employee_number'] ?? null,
            'full_name' => $row['full_name'] ?? null,
            'full_name_en' => $row['full_name_en'] ?? null,
            'status' => $row['employment_status'] ?? null,
            'organization_uuid' => $organizationUuid,
            'unit_uuid' => $row['organization_unit_id'] ?? ($assignment['organization_unit_id'] ?? null),
            'position_uuid' => $row['position_id'] ?? ($assignment['position_id'] ?? null),
            'assignment_uuid' => $assignment['id'] ?? null,
            'synced_at' => now(),
        ]);

        if ($organizationUuid !== null) {
            $this->organization((string) $organizationUuid);
            if (! EuisisPosition::query()->where('organization_uuid', $organizationUuid)->exists()) {
                $this->loadPositions((string) $organizationUuid);
            }
        }

        return $employee;
    }

    /**
     * Complainants named on EUISIS referrals, cached so the case page shows
     * who they are; done here, after the referral answer, never inside it.
     */
    public function cacheReferredPeople(int $limit = 50): int
    {
        $missing = EuisisReferral::query()->whereNotNull('complainant_employee_uuid')
            ->whereNotIn('complainant_employee_uuid', EuisisEmployee::query()->select('uuid'))
            ->limit($limit)->pluck('complainant_employee_uuid');
        $cached = 0;
        foreach ($missing as $uuid) {
            $row = $this->client->employee((string) $uuid);
            if ($row !== null) {
                $this->cacheEmployee($row);
                $cached++;
            }
        }

        return $cached;
    }

    public function state(string $key): ?string
    {
        $value = DB::table('court_euisis_sync_state')->where('key', $key)->value('value');

        return $value === null ? null : (string) $value;
    }

    public function remember(string $key, ?string $value): void
    {
        DB::table('court_euisis_sync_state')->updateOrInsert(['key' => $key], ['value' => $value, 'updated_at' => now(), 'created_at' => now()]);
    }

    private function loadUnits(string $organizationUuid): int
    {
        $count = 0;
        for ($page = 1, $last = 1; $page <= $last; $page++) {
            $result = $this->client->units($organizationUuid, $page);
            $last = $result['last_page'];
            foreach ($result['data'] as $row) {
                EuisisOrganizationUnit::query()->updateOrCreate(['uuid' => $row['id']], [
                    'organization_uuid' => $row['organization_id'] ?? $organizationUuid,
                    'parent_unit_uuid' => $row['parent_unit_id'] ?? null,
                    'code' => $row['code'] ?? null,
                    'name_en' => (string) ($row['name_en'] ?? ''),
                    'name_am' => $row['name_am'] ?? null,
                    'status' => $row['status'] ?? null,
                    'synced_at' => now(),
                ]);
                $count++;
            }
        }

        return $count;
    }

    private function upsertOrganization(array $values): EuisisOrganization
    {
        return EuisisOrganization::query()->updateOrCreate(['uuid' => $values['uuid']], [
            ...collect($values)->except('uuid')->all(),
            'synced_at' => now(),
        ]);
    }

    private function applyOrganization(string $type, array $data, int $sequence, bool $tombstone): bool
    {
        $uuid = $data['organization_uuid'] ?? null;
        if ($uuid === null) {
            return false;
        }
        $existing = EuisisOrganization::query()->find($uuid);
        if ($existing !== null && $sequence > 0 && $sequence <= $existing->last_sequence) {
            return false;
        }

        $values = ['last_sequence' => $sequence, 'synced_at' => now()];
        if (in_array($type, ['organization.created', 'organization.updated'], true)) {
            $values += ['code' => $data['code'] ?? null, 'name_en' => (string) ($data['name_en'] ?? $existing?->name_en ?? ''), 'name_am' => $data['name_am'] ?? null, 'status' => $data['status'] ?? null];
        } else {
            $values['status'] = $data['status'] ?? ($tombstone ? 'archived' : $existing?->status);
        }
        if ($existing === null && ! isset($values['name_en'])) {
            // A status change for an organization the cache never saw: fetch it whole.
            return $this->organization((string) $uuid) !== null;
        }

        EuisisOrganization::query()->updateOrCreate(['uuid' => $uuid], $values);

        return true;
    }

    private function applyUnit(string $type, array $data, int $sequence, bool $tombstone): bool
    {
        $uuid = $data['unit_uuid'] ?? null;
        if ($uuid === null) {
            return false;
        }
        $existing = EuisisOrganizationUnit::query()->find($uuid);
        if ($existing !== null && $sequence > 0 && $sequence <= $existing->last_sequence) {
            return false;
        }

        $values = ['last_sequence' => $sequence, 'synced_at' => now()];
        if (isset($data['organization_uuid'])) {
            $values['organization_uuid'] = $data['organization_uuid'];
        }
        if (array_key_exists('parent_unit_uuid', $data)) {
            $values['parent_unit_uuid'] = $data['parent_unit_uuid'];
        }
        if (in_array($type, ['organization_unit.created', 'organization_unit.updated'], true)) {
            $values += ['code' => $data['code'] ?? null, 'name_en' => (string) ($data['name_en'] ?? ''), 'name_am' => $data['name_am'] ?? null, 'status' => $data['status'] ?? null];
        } elseif ($type === 'organization_unit.status_changed') {
            $values['status'] = $data['status'] ?? ($tombstone ? 'archived' : $existing?->status);
        }
        if ($existing === null && ! isset($values['name_en'])) {
            return false;
        }

        EuisisOrganizationUnit::query()->updateOrCreate(['uuid' => $uuid], $values);

        return true;
    }

    private function applyPosition(string $type, array $data, int $sequence, bool $tombstone): bool
    {
        $uuid = $data['position_uuid'] ?? null;
        $organizationUuid = $data['organization_uuid'] ?? null;
        if ($uuid === null) {
            return false;
        }
        $existing = EuisisPosition::query()->find($uuid);
        $tracked = $existing !== null || ($organizationUuid !== null && EuisisPosition::query()->where('organization_uuid', $organizationUuid)->exists());
        if (! $tracked || ($existing !== null && $sequence > 0 && $sequence <= $existing->last_sequence)) {
            return false;
        }

        $values = ['last_sequence' => $sequence, 'synced_at' => now()];
        if ($organizationUuid !== null) {
            $values['organization_uuid'] = $organizationUuid;
        }
        if (in_array($type, ['position.created', 'position.updated'], true)) {
            $values += ['unit_uuid' => $data['unit_uuid'] ?? null, 'code' => $data['code'] ?? null, 'title_en' => (string) ($data['title_en'] ?? $data['code'] ?? ''), 'title_am' => $data['title_am'] ?? null, 'is_active' => (bool) ($data['is_active'] ?? true)];
        } elseif ($type === 'position.status_changed') {
            $values['is_active'] = (bool) ($data['is_active'] ?? ! $tombstone);
        } elseif ($type === 'position.archived') {
            $values['is_active'] = false;
        } else {
            return false; // occupied / vacated change nothing court-ms shows
        }
        if ($existing === null && ! isset($values['title_en'])) {
            return false;
        }

        EuisisPosition::query()->updateOrCreate(['uuid' => $uuid], $values);

        return true;
    }

    /** Employee and assignment events, for cached employees only. */
    private function applyPerson(string $type, array $data, int $sequence): bool
    {
        $employee = isset($data['employee_uuid']) ? EuisisEmployee::query()->find($data['employee_uuid']) : null;
        if ($employee === null) {
            return false;
        }

        if (str_starts_with($type, 'employee.') && ! in_array($type, ['employee.assignment_changed', 'employee.transferred'], true)) {
            if ($sequence > 0 && $sequence <= $employee->last_sequence) {
                return false;
            }
            if ($type === 'employee.updated') {
                // Names are not in the minimised event: read the record again.
                $row = $this->client->employee($employee->uuid);
                if ($row !== null) {
                    $this->cacheEmployee($row);
                }
            }
            $employee->refresh()->forceFill([
                'employee_number' => $data['employee_number'] ?? $employee->employee_number,
                'status' => $data['status'] ?? $employee->status,
                'last_sequence' => $sequence,
                'synced_at' => now(),
            ])->save();

            return true;
        }

        // Placement: assignment.* (current ones only), employee.assignment_changed, employee.transferred.
        if (str_starts_with($type, 'assignment.') && ! ($data['is_current'] ?? false) && $type !== 'assignment.closed') {
            return false;
        }
        if ($type === 'assignment.closed') {
            if ($employee->assignment_uuid !== ($data['assignment_uuid'] ?? null)) {
                return false;
            }
            $employee->forceFill(['assignment_uuid' => null, 'synced_at' => now()])->save();

            return true;
        }

        $employee->forceFill([
            'organization_uuid' => $data['organization_uuid'] ?? $employee->organization_uuid,
            'unit_uuid' => $data['unit_uuid'] ?? $employee->unit_uuid,
            'position_uuid' => $data['position_uuid'] ?? $employee->position_uuid,
            'assignment_uuid' => $data['assignment_uuid'] ?? $employee->assignment_uuid,
            'synced_at' => now(),
        ])->save();
        if ($employee->organization_uuid !== null) {
            $this->organization((string) $employee->organization_uuid);
            if (! EuisisPosition::query()->where('organization_uuid', $employee->organization_uuid)->exists()) {
                $this->loadPositions((string) $employee->organization_uuid);
            }
        }

        return true;
    }
}
