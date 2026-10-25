<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DataQualityIssue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns the integrity checks' findings into issues (ADR 0011), reusing the
 * checks as they are. Each scan, per check:
 *
 *  - a new finding becomes an open issue with the check's fixed severity and
 *    the organization it belongs to (an employee's or card holder's current
 *    placement, a position's organization, the organization itself);
 *  - a finding seen again updates its last-seen time; a resolved one is
 *    reopened; an accepted risk or a false positive stays closed;
 *  - an open or investigating issue the check no longer finds is resolved
 *    automatically (the checks are deterministic SQL), and audited.
 *
 * A check finds at most MAX findings per scan; when it hits that cap no issue
 * of the check is resolved automatically that time.
 */
final class DataQualityScanner
{
    public const MAX = 5000;

    public function __construct(private readonly IntegrityChecks $checks, private readonly AuditWriter $audit) {}

    /** @return array<string, array{found: int, opened: int, reopened: int, resolved: int}> */
    public function scan(?string $only = null): array
    {
        $summary = [];
        foreach (IntegrityChecks::SUBJECTS as $code => [, $kind]) {
            if ($only !== null && $only !== $code) {
                continue;
            }
            $ids = $this->checks->subjects($code, self::MAX);
            $summary[$code] = $this->apply($code, $kind, $ids, count($ids) < self::MAX);
        }

        return $summary;
    }

    /**
     * @param  list<string>  $ids
     * @return array{found: int, opened: int, reopened: int, resolved: int}
     */
    private function apply(string $code, string $kind, array $ids, bool $complete): array
    {
        $now = now();
        $severity = IntegrityChecks::SEVERITY[$code] ?? 'medium';
        $organizations = $this->organizations($kind, $ids);
        $counts = ['found' => count($ids), 'opened' => 0, 'reopened' => 0, 'resolved' => 0];

        DB::transaction(function () use ($code, $kind, $ids, $complete, $now, $severity, $organizations, &$counts): void {
            $existing = DataQualityIssue::query()->where('check_code', $code)->where('entity_type', $kind)->get()->keyBy('entity_id');
            $new = [];
            foreach ($ids as $id) {
                $issue = $existing->get($id);
                if ($issue === null) {
                    $new[] = [
                        'id' => (string) Str::uuid7(), 'check_code' => $code, 'entity_type' => $kind, 'entity_id' => $id,
                        'organization_id' => $organizations[$id] ?? null, 'severity' => $severity, 'status' => DataQualityIssue::OPEN,
                        'summary' => IntegrityChecks::SUMMARIES[$code] ?? $code, 'detected_at' => $now, 'last_detected_at' => $now,
                        'created_at' => $now, 'updated_at' => $now,
                    ];
                } elseif ($issue->status === DataQualityIssue::RESOLVED) {
                    $issue->forceFill(['status' => DataQualityIssue::OPEN, 'last_detected_at' => $now, 'times_reopened' => $issue->times_reopened + 1,
                        'resolution' => null, 'resolved_by' => null, 'resolved_at' => null, 'auto_resolved' => false,
                        'organization_id' => $organizations[$id] ?? $issue->organization_id])->save();
                    $this->audit->write('data_quality.reopened', null, $issue, $issue->organization_id, null, ['status' => DataQualityIssue::OPEN], 'Found again by the scan');
                    $counts['reopened']++;
                } else {
                    $issue->forceFill(['last_detected_at' => $now, 'organization_id' => $organizations[$id] ?? $issue->organization_id])->save();
                }
            }
            foreach (array_chunk($new, 500) as $chunk) {
                DataQualityIssue::query()->insert($chunk);
            }
            $counts['opened'] = count($new);

            if ($complete) {
                $seen = array_flip($ids);
                foreach ($existing as $id => $issue) {
                    if (! isset($seen[$id]) && in_array($issue->status, DataQualityIssue::ACTIVE, true)) {
                        $issue->forceFill(['status' => DataQualityIssue::RESOLVED, 'auto_resolved' => true, 'resolved_at' => $now,
                            'resolution' => 'No longer found by the check.'])->save();
                        $this->audit->write('data_quality.auto_resolved', null, $issue, $issue->organization_id, null, ['status' => DataQualityIssue::RESOLVED], 'No longer found by the check');
                        $counts['resolved']++;
                    }
                }
            }
            if ($severity === 'critical' && ($counts['opened'] + $counts['reopened']) > 0) {
                $this->audit->writeFor('data_quality.critical_detected', null, 'DataQualityCheck', $code, null, null,
                    ['check_code' => $code, 'new' => $counts['opened'], 'reopened' => $counts['reopened']]);
            }
        });

        return $counts;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, ?string> entity id => organization id
     */
    private function organizations(string $kind, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $result = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $rows = match ($kind) {
                'employee' => DB::table('employees')->leftJoin('employee_assignments', 'employee_assignments.id', '=', 'employees.current_assignment_id')
                    ->whereIn('employees.id', $chunk)->pluck('employee_assignments.organization_id', 'employees.id'),
                'card' => DB::table('id_cards')->join('employees', 'employees.id', '=', 'id_cards.employee_id')
                    ->leftJoin('employee_assignments', 'employee_assignments.id', '=', 'employees.current_assignment_id')
                    ->whereIn('id_cards.id', $chunk)->pluck('employee_assignments.organization_id', 'id_cards.id'),
                'position' => DB::table('positions')->whereIn('id', $chunk)->pluck('organization_id', 'id'),
                'organization' => collect($chunk)->mapWithKeys(fn (string $id): array => [$id => $id]),
                default => collect(),
            };
            foreach ($rows as $id => $organization) {
                $result[(string) $id] = $organization === null ? null : (string) $organization;
            }
        }

        return $result;
    }
}
