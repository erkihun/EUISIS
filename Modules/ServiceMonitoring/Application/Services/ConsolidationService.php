<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Services;

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Modules\ServiceMonitoring\Domain\Enums\FinalAction;
use Modules\ServiceMonitoring\Domain\Enums\ReportingLevel;
use Modules\ServiceMonitoring\Domain\Enums\ReportRange;
use Modules\ServiceMonitoring\Domain\Enums\RoleCategory;
use Modules\ServiceMonitoring\Domain\Enums\SnapshotKind;
use Modules\ServiceMonitoring\Domain\Enums\SubmissionStatus;
use Modules\ServiceMonitoring\Domain\Enums\VerificationStatus;
use Modules\ServiceMonitoring\Domain\Forms\FormDefinition;
use Modules\ServiceMonitoring\Domain\Models\ConsolidationSnapshot;
use Modules\ServiceMonitoring\Domain\Models\FiscalYear;
use Modules\ServiceMonitoring\Domain\Models\Participant;
use Modules\ServiceMonitoring\Domain\Models\ReportingPeriod;
use Modules\ServiceMonitoring\Domain\Models\Submission;
use RuntimeException;

/**
 * Consolidation of accepted reports (D3, D4, D24).
 *
 * Institutions report one quarter each; the official 3-, 6-, 9-month and
 * annual reports are derived here, so the figures reconcile by construction.
 * Flow figures are summed over the quarters of the range; stock figures take
 * the latest quarter with an accepted report. Section I.2 is computed from
 * I.1, counting each employee once in the range, by the latest action date.
 *
 * Per period, the figures come from set-based queries over the accepted
 * revisions (one query per section, digital counts grouped in SQL), or, once
 * the period is locked, from its immutable snapshot. Ranges combine the
 * per-period figures. Only the accepted revision counts: drafts, returned,
 * submitted and superseded revisions never do.
 */
class ConsolidationService
{
    /** @var array<string, array<string, mixed>> */
    private array $figures = [];

    /**
     * The figures of one period, keyed by participant, from the lock snapshot
     * when the period is locked and from the live accepted revisions otherwise.
     *
     * @return array{source: string, participants: array<string, array<string, mixed>>, entries: list<array<string, mixed>>, digital_services: list<array<string, mixed>>}
     */
    public function periodFigures(ReportingPeriod $period): array
    {
        if (isset($this->figures[$period->id])) {
            return $this->figures[$period->id];
        }
        if ($period->isLocked()) {
            $snapshot = ConsolidationSnapshot::query()->where('period_id', $period->id)->where('kind', SnapshotKind::PeriodLock->value)
                ->latest('created_at')->first();
            if ($snapshot !== null) {
                if (! $snapshot->isIntact()) {
                    throw new RuntimeException("Consolidation snapshot {$snapshot->id} fails its checksum.");
                }

                return $this->figures[$period->id] = ['source' => 'snapshot'] + $snapshot->payload;
            }
        }

        return $this->figures[$period->id] = ['source' => 'live'] + $this->live($period);
    }

    /**
     * The payload stored in a snapshot: the live figures of the period, for
     * every participant or for the given ones.
     *
     * @param  list<string>|null  $participantIds
     * @return array<string, mixed>
     */
    public function snapshotPayload(ReportingPeriod $period, ?array $participantIds = null): array
    {
        $live = $this->live($period);
        if ($participantIds !== null) {
            $live['participants'] = array_intersect_key($live['participants'], array_flip($participantIds));
            $live['entries'] = array_values(array_filter($live['entries'], fn (array $e): bool => in_array($e['participant_id'], $participantIds, true)));
            $live['digital_services'] = array_values(array_filter($live['digital_services'], fn (array $d): bool => in_array($d['participant_id'], $participantIds, true)));
        }

        return ['period_id' => $period->id, 'quarter' => $period->quarter, 'taken_at' => now()->toIso8601String()] + $live;
    }

    public function storeSnapshot(ReportingPeriod $period, SnapshotKind $kind, ?Participant $participant, ?User $actor, ?array $participantIds = null): ConsolidationSnapshot
    {
        $payload = $this->snapshotPayload($period, $participantIds);
        unset($this->figures[$period->id]);

        return ConsolidationSnapshot::query()->create([
            'period_id' => $period->id,
            'kind' => $kind,
            'participant_id' => $participant?->id,
            'payload' => $payload,
            'checksum' => ConsolidationSnapshot::checksumOf($payload),
            'created_by' => $actor?->id,
            'created_at' => now(),
        ]);
    }

    public function forget(): void
    {
        $this->figures = [];
    }

    /** @return list<string> the sub-city and its woredas */
    public function subCityParticipantIds(Participant $subCity): array
    {
        return [$subCity->id, ...Participant::query()->where('parent_participant_id', $subCity->id)->pluck('id')->map(fn ($id): string => (string) $id)->all()];
    }

    /**
     * A derived report for a range.
     *
     * @param  list<string>|null  $participantIds  null = the whole city
     * @return array<string, mixed>
     */
    public function report(FiscalYear $year, ReportRange $range, int $quarter, ?array $participantIds, bool $named): array
    {
        $quarters = $range->quarters($quarter);
        $periods = $year->periods()->whereIn('quarter', $quarters)->orderBy('quarter')->get();
        $keep = fn (string $pid): bool => $participantIds === null || in_array($pid, $participantIds, true);

        /** @var array<string, array<int, array<string, mixed>>> $byParticipant participant => quarter => figures */
        $byParticipant = [];
        $entries = [];
        $digital = [];
        $sources = [];
        foreach ($periods as $period) {
            $figures = $this->periodFigures($period);
            $sources[$period->quarter] = $figures['source'];
            foreach ($figures['participants'] as $pid => $row) {
                if ($keep((string) $pid)) {
                    $byParticipant[(string) $pid][$period->quarter] = $row;
                }
            }
            foreach ($figures['entries'] as $entry) {
                if ($keep((string) $entry['participant_id'])) {
                    $entries[] = $entry + ['quarter' => $period->quarter];
                }
            }
            foreach ($figures['digital_services'] as $service) {
                if ($keep((string) $service['participant_id'])) {
                    $digital[] = $service + ['quarter' => $period->quarter];
                }
            }
        }

        $rows = array_map(fn (array $quartersOf): array => $this->combine($quartersOf), $byParticipant);
        uasort($rows, fn (array $a, array $b): int => [$this->levelOrder($a['level']), $a['name_en']] <=> [$this->levelOrder($b['level']), $b['name_en']]);

        return [
            'range' => $range->value,
            'quarters' => $quarters,
            'fiscal_year' => $year->ec_year,
            'from' => $periods->first()?->starts_on?->toDateString(),
            'to' => $periods->last()?->ends_on?->toDateString(),
            'sources' => $sources,
            'from_snapshot' => in_array('snapshot', $sources, true),
            'rows' => array_values($rows),
            'totals' => $this->totals($rows),
            'summary' => $this->summary($entries),
            'register' => $this->register($entries, $byParticipant, $named),
            'digital_services' => $this->digitalList($digital, $byParticipant),
        ];
    }

    /**
     * One participant over the quarters of a range (D4).
     *
     * @param  array<int, array<string, mixed>>  $quarters
     * @return array<string, mixed>
     */
    private function combine(array $quarters): array
    {
        ksort($quarters);
        $latest = end($quarters);
        $latestQuarter = (int) key($quarters);
        $section2 = [];
        foreach (FormDefinition::section2Keys() as $field) {
            $section2[$field] = FormDefinition::aggregationOf($field)->value === 'flow'
                ? array_sum(array_map(fn (array $q): int => (int) ($q['section2'][$field] ?? 0), $quarters))
                : (int) ($latest['section2'][$field] ?? 0);
        }
        $section4 = [];
        foreach (FormDefinition::SECTION_4 as $field) {
            $section4[$field] = array_sum(array_map(fn (array $q): int => (int) ($q['section4'][$field] ?? 0), $quarters));
        }

        return [
            'participant_id' => $latest['participant_id'],
            'organization_id' => $latest['organization_id'],
            'name_en' => $latest['name_en'],
            'name_am' => $latest['name_am'],
            'level' => $latest['level'],
            'parent_participant_id' => $latest['parent_participant_id'],
            'quarters_reported' => array_keys($quarters),
            'latest_quarter' => $latestQuarter,
            'section2' => $section2,
            'narrative' => $latest['narrative'] ?? null,
            'totals' => ['main_total' => (int) ($latest['totals']['main_total'] ?? 0), 'sub_total' => (int) ($latest['totals']['sub_total'] ?? 0)],
            'digital' => [
                'main' => array_sum(array_map(fn (array $q): int => (int) ($q['digital']['main'] ?? 0), $quarters)),
                'sub' => array_sum(array_map(fn (array $q): int => (int) ($q['digital']['sub'] ?? 0), $quarters)),
                'systems' => array_sum(array_map(fn (array $q): int => (int) ($q['digital']['systems'] ?? 0), $quarters)),
            ],
            'section4' => $section4,
            'section4_latest' => array_intersect_key($latest['section4'] ?? [], array_flip(FormDefinition::SECTION_4)),
            'below_reasons' => $latest['section4']['below_reasons'] ?? null,
            'actions_taken' => $latest['section4']['actions_taken'] ?? null,
        ];
    }

    /**
     * Sums per level and overall, for the totals rows of the forms.
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function totals(array $rows): array
    {
        $groups = ['all' => $rows];
        foreach (ReportingLevel::cases() as $level) {
            $groups[$level->value] = array_filter($rows, fn (array $r): bool => $r['level'] === $level->value);
        }

        return array_map(function (array $group): array {
            $sum = fn (string $part, string $field): int => array_sum(array_map(fn (array $r): int => (int) ($r[$part][$field] ?? 0), $group));

            return [
                'count' => count($group),
                'section2' => array_combine(FormDefinition::section2Keys(), array_map(fn (string $f): int => $sum('section2', $f), FormDefinition::section2Keys())),
                'totals' => ['main_total' => $sum('totals', 'main_total'), 'sub_total' => $sum('totals', 'sub_total')],
                'digital' => ['main' => $sum('digital', 'main'), 'sub' => $sum('digital', 'sub'), 'systems' => $sum('digital', 'systems')],
                'section4' => array_combine(FormDefinition::SECTION_4, array_map(fn (string $f): int => $sum('section4', $f), FormDefinition::SECTION_4)),
            ];
        }, $groups);
    }

    /**
     * Section I.2 (D15): final action × role × level, each employee counted
     * once in the range by the latest action date; customers counted per row.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    public function summary(array $entries): array
    {
        $latest = [];
        $counted = [];
        foreach ($entries as $entry) {
            if ($entry['employee_id'] === null) {
                $counted[] = $entry;

                continue;
            }
            $key = (string) $entry['employee_id'];
            $rank = [(string) $entry['action_date'], (int) $entry['quarter'], (int) $entry['sequence']];
            if (! isset($latest[$key]) || $rank > $latest[$key][0]) {
                $latest[$key] = [$rank, $entry];
            }
        }
        foreach ($latest as [, $entry]) {
            $counted[] = $entry;
        }

        $matrix = [];
        foreach (ReportingLevel::cases() as $level) {
            foreach (FinalAction::cases() as $action) {
                foreach (RoleCategory::cases() as $role) {
                    $matrix[$level->value][$action->value][$role->value] = 0;
                }
            }
        }
        foreach ($counted as $entry) {
            $matrix[$entry['level']][$entry['final_action']][$entry['role_category']]++;
        }

        $rowTotals = [];
        $columnTotals = [];
        foreach ($matrix as $level => $actions) {
            foreach ($actions as $action => $roles) {
                $rowTotals[$level][$action] = array_sum($roles);
                foreach ($roles as $role => $n) {
                    $columnTotals[$level][$role] = ($columnTotals[$level][$role] ?? 0) + $n;
                }
            }
        }

        return [
            'matrix' => $matrix,
            'row_totals' => $rowTotals,
            'column_totals' => $columnTotals,
            'level_totals' => array_map(fn (array $c): int => array_sum($c), $columnTotals),
            'grand_total' => count($counted),
        ];
    }

    /**
     * Section I.1 rows in form order; names decrypted only for a named report.
     *
     * @param  list<array<string, mixed>>  $entries
     * @param  array<string, array<int, array<string, mixed>>>  $byParticipant
     * @return list<array<string, mixed>>
     */
    private function register(array $entries, array $byParticipant, bool $named): array
    {
        usort($entries, fn (array $a, array $b): int => [$this->levelOrder($a['level']), $a['participant_id'], $a['quarter'], $a['sequence']] <=> [$this->levelOrder($b['level']), $b['participant_id'], $b['quarter'], $b['sequence']]);

        return array_values(array_map(function (array $e) use ($byParticipant, $named): array {
            $participant = $byParticipant[$e['participant_id']] ?? [];
            $info = $participant === [] ? [] : end($participant);

            return [
                'level' => $e['level'],
                'participant_id' => $e['participant_id'],
                'institution_en' => $info['name_en'] ?? null,
                'institution_am' => $info['name_am'] ?? null,
                'quarter' => $e['quarter'],
                'subject_type' => $e['subject_type'],
                'role_category' => $e['role_category'],
                'final_action' => $e['final_action'],
                'reason_category' => $e['reason_category'],
                'action_date' => $e['action_date'],
                'office_name' => $e['office_name'],
                'remark' => $e['remark'],
                'full_name' => $named ? self::decrypt($e['full_name']) : null,
                'reason_detail' => $named ? self::decrypt($e['reason_detail']) : null,
                'action_detail' => $named ? self::decrypt($e['action_detail']) : null,
            ];
        }, $entries));
    }

    /**
     * Verified Section III rows, main services followed by their sub services.
     *
     * @param  list<array<string, mixed>>  $digital
     * @param  array<string, array<int, array<string, mixed>>>  $byParticipant
     * @return list<array<string, mixed>>
     */
    private function digitalList(array $digital, array $byParticipant): array
    {
        $ordered = [];
        $mains = array_filter($digital, fn (array $d): bool => $d['level'] === 'main');
        usort($mains, fn (array $a, array $b): int => [$a['participant_id'], $a['quarter'], $a['sequence']] <=> [$b['participant_id'], $b['quarter'], $b['sequence']]);
        foreach ($mains as $main) {
            $ordered[] = $main;
            foreach ($digital as $sub) {
                if ($sub['level'] === 'sub' && $sub['parent_entry_id'] === $main['id']) {
                    $ordered[] = $sub;
                }
            }
        }

        return array_map(function (array $d) use ($byParticipant): array {
            $participant = $byParticipant[$d['participant_id']] ?? [];
            $info = $participant === [] ? [] : end($participant);

            return $d + ['institution_en' => $info['name_en'] ?? null, 'institution_am' => $info['name_am'] ?? null, 'participant_level' => $info['level'] ?? null];
        }, $ordered);
    }

    /**
     * One submission in the report shape, whatever its status (a draft or a
     * submitted report can be printed before acceptance). Only verified
     * digital services count, as everywhere.
     *
     * @return array<string, mixed>
     */
    public function forSubmission(Submission $submission, bool $named): array
    {
        $period = $submission->period;
        $figures = $this->live($period, $submission->id);
        $pid = (string) $submission->participant_id;
        $byParticipant = isset($figures['participants'][$pid]) ? [$pid => [$period->quarter => $figures['participants'][$pid]]] : [];
        $entries = array_map(fn (array $e): array => $e + ['quarter' => $period->quarter], $figures['entries']);
        $digital = array_map(fn (array $d): array => $d + ['quarter' => $period->quarter], $figures['digital_services']);
        $rows = array_map(fn (array $quarters): array => $this->combine($quarters), $byParticipant);

        return [
            'range' => ReportRange::Quarter->value,
            'quarters' => [$period->quarter],
            'fiscal_year' => $period->fiscalYear->ec_year,
            'from' => $period->starts_on->toDateString(),
            'to' => $period->ends_on->toDateString(),
            'sources' => [$period->quarter => 'live'],
            'from_snapshot' => false,
            'rows' => array_values($rows),
            'totals' => $this->totals($rows),
            'summary' => $this->summary($entries),
            'register' => $this->register($entries, $byParticipant, $named),
            'digital_services' => $this->digitalList($digital, $byParticipant),
        ];
    }

    /** @return array{participants: array<string, array<string, mixed>>, entries: list<array<string, mixed>>, digital_services: list<array<string, mixed>>} */
    private function live(ReportingPeriod $period, ?string $submissionId = null): array
    {
        // The accepted revisions of the period, or exactly one submission whatever its status.
        $only = fn ($query) => $submissionId !== null
            ? $query->where('s.id', $submissionId)
            : $query->where('s.period_id', $period->id)->where('s.status', SubmissionStatus::Accepted->value);
        $accepted = fn () => $only(DB::table('service_monitoring_submissions as s')->select('s.id'));

        $rows = $only(DB::table('service_monitoring_submissions as s')
            ->join('service_monitoring_participants as p', 'p.id', '=', 's.participant_id')
            ->join('organizations as o', 'o.id', '=', 's.organization_id')
            ->leftJoin('service_monitoring_institution_building as ib', 'ib.submission_id', '=', 's.id')
            ->leftJoin('service_monitoring_service_totals as t', 't.submission_id', '=', 's.id')
            ->leftJoin('service_monitoring_standard_performance as sp', 'sp.submission_id', '=', 's.id'))
            ->select(['s.id as submission_id', 's.participant_id', 's.organization_id', 's.level', 's.revision_no', 's.special_support_narrative',
                'p.parent_participant_id', 'o.name_en', 'o.name_am', 't.main_total', 't.sub_total', 'sp.below_reasons', 'sp.actions_taken',
                ...array_map(fn (string $f): string => "ib.{$f}", FormDefinition::section2Keys()),
                ...array_map(fn (string $f): string => "sp.{$f}", FormDefinition::SECTION_4)])
            ->get();

        $digitalCounts = DB::table('service_monitoring_digital_services')
            ->whereIn('submission_id', $accepted())->where('verification_status', VerificationStatus::Verified->value)
            ->groupBy('submission_id', 'level')
            ->selectRaw('submission_id, level, COUNT(*) AS services, SUM(system_count) AS systems')
            ->get()->groupBy('submission_id');

        $participants = [];
        foreach ($rows as $row) {
            $counts = $digitalCounts->get($row->submission_id, collect())->keyBy('level');
            $participants[(string) $row->participant_id] = [
                'participant_id' => (string) $row->participant_id,
                'organization_id' => (string) $row->organization_id,
                'name_en' => $row->name_en,
                'name_am' => $row->name_am,
                'level' => $row->level,
                'parent_participant_id' => $row->parent_participant_id,
                'submission_id' => (string) $row->submission_id,
                'revision_no' => (int) $row->revision_no,
                'section2' => array_combine(FormDefinition::section2Keys(), array_map(fn (string $f): int => (int) $row->{$f}, FormDefinition::section2Keys())),
                'narrative' => $row->special_support_narrative,
                'totals' => ['main_total' => (int) $row->main_total, 'sub_total' => (int) $row->sub_total],
                'digital' => [
                    'main' => (int) ($counts->get('main')->services ?? 0),
                    'sub' => (int) ($counts->get('sub')->services ?? 0),
                    'systems' => (int) ($counts->get('main')->systems ?? 0) + (int) ($counts->get('sub')->systems ?? 0),
                ],
                'section4' => array_combine(FormDefinition::SECTION_4, array_map(fn (string $f): int => (int) $row->{$f}, FormDefinition::SECTION_4))
                    + ['below_reasons' => $row->below_reasons, 'actions_taken' => $row->actions_taken],
            ];
        }

        // Encrypted columns stay ciphertext here and in snapshots; only named reports decrypt.
        $entries = $only(DB::table('service_monitoring_accountability_entries as e')
            ->join('service_monitoring_submissions as s', 's.id', '=', 'e.submission_id'))
            ->orderBy('e.sequence')
            ->get(['s.participant_id', 's.level', 'e.employee_id', 'e.subject_type', 'e.role_category', 'e.final_action', 'e.reason_category',
                'e.action_date', 'e.office_name', 'e.sequence', 'e.remark', 'e.full_name', 'e.reason_detail', 'e.action_detail'])
            ->map(fn (object $e): array => ['participant_id' => (string) $e->participant_id, 'action_date' => substr((string) $e->action_date, 0, 10), 'sequence' => (int) $e->sequence] + (array) $e)
            ->all();

        $services = $only(DB::table('service_monitoring_digital_services as d')
            ->join('service_monitoring_submissions as s', 's.id', '=', 'd.submission_id'))
            ->where('d.verification_status', VerificationStatus::Verified->value)
            ->orderBy('d.sequence')
            ->get(['d.id', 'd.parent_entry_id', 's.participant_id', 'd.level', 'd.sequence', 'd.service_name_am', 'd.service_name_en',
                'd.technology_type', 'd.technology_other', 'd.system_name', 'd.system_count', 'd.go_live_on', 'd.remark'])
            ->map(fn (object $d): array => ['participant_id' => (string) $d->participant_id, 'id' => (string) $d->id, 'sequence' => (int) $d->sequence, 'system_count' => (int) $d->system_count, 'go_live_on' => substr((string) $d->go_live_on, 0, 10)] + (array) $d)
            ->all();

        return ['participants' => $participants, 'entries' => array_values($entries), 'digital_services' => array_values($services)];
    }

    private function levelOrder(string $level): int
    {
        return match ($level) {
            ReportingLevel::Center->value => 0,
            ReportingLevel::SubCity->value => 1,
            default => 2,
        };
    }

    private static function decrypt(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return $value;
        }
    }
}
