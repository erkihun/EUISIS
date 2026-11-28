<?php

declare(strict_types=1);

namespace Modules\WorkExperience\Application\Services;

use App\Enums\AssignmentStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use Carbon\CarbonImmutable;
use Modules\WorkExperience\Domain\Models\WorkExperienceRecord;

/**
 * An employee's work experience, line by line, oldest first.
 *
 * Two sources are read together. The assignments the system already holds
 * give the positions, units and dates with no typing. The office's own
 * records add what the system cannot know: the salary of an assignment, a
 * corrected title or date, service before the system existed, and service in
 * another institution taken from evidence.
 */
class WorkExperienceTimeline
{
    /**
     * @param  string|null  $officeId  the institution that keeps the record; its service is "internal"
     * @return array<int, array<string, mixed>>
     */
    public function linesFor(Employee $employee, ?string $officeId, ?string $today = null): array
    {
        $today ??= $this->today();
        $records = WorkExperienceRecord::query()->where('employee_id', $employee->id)->get();
        $byAssignment = $records->whereNotNull('employee_assignment_id')->keyBy('employee_assignment_id');

        $assignments = EmployeeAssignment::query()
            ->with(['organization:id,name_en,name_am', 'position:id,title_en,title_am', 'organizationUnit:id,name_en,name_am'])
            ->where('employee_id', $employee->id)
            ->whereNotNull('effective_from')
            ->where('assignment_status', '!=', AssignmentStatus::PendingTransfer->value)
            ->orderBy('effective_from')->orderBy('created_at')
            ->get()->values();

        $lines = [];
        foreach ($assignments as $index => $assignment) {
            $record = $byAssignment->get($assignment->id);
            $endedOn = $assignment->effective_to?->toDateString();
            // A closed assignment with no end date ended the day before the next one began.
            if ($endedOn === null && ! $assignment->is_current && isset($assignments[$index + 1])) {
                $endedOn = CarbonImmutable::parse($assignments[$index + 1]->effective_from->toDateString())->subDay()->toDateString();
                $endedOn = max($endedOn, $assignment->effective_from->toDateString());
            }
            $titleAm = $assignment->position?->title_am ?: $assignment->position?->title_en;
            $titleEn = $assignment->position?->title_en ?: $assignment->position?->title_am;
            // A corrected title, typed by the office, replaces both languages.
            if ($record !== null && ! in_array($record->position_title, [$titleAm, $titleEn], true)) {
                $titleAm = $titleEn = $record->position_title;
            }
            $internal = $officeId !== null && (string) $assignment->organization_id === $officeId;

            $lines[] = $this->line([
                'key' => 'assignment:'.$assignment->id,
                'source' => 'assignment',
                'record_id' => $record?->id,
                'assignment_id' => $assignment->id,
                'kind' => $internal ? WorkExperienceRecord::KIND_INTERNAL : WorkExperienceRecord::KIND_EXTERNAL,
                'employer_am' => $assignment->organization?->name_am ?: $assignment->organization?->name_en,
                'employer_en' => $assignment->organization?->name_en ?: $assignment->organization?->name_am,
                'position_am' => $titleAm,
                'position_en' => $titleEn,
                'unit_am' => $assignment->organizationUnit?->name_am ?: $assignment->organizationUnit?->name_en,
                'unit_en' => $assignment->organizationUnit?->name_en ?: $assignment->organizationUnit?->name_am,
                'started_on' => $record?->started_on?->toDateString() ?? $assignment->effective_from->toDateString(),
                'ended_on' => $record !== null ? $record->ended_on?->toDateString() : $endedOn,
                'monthly_salary' => $record?->monthly_salary === null ? null : (float) $record->monthly_salary,
                'note' => $record?->note,
                'evidence_name' => $record?->evidence_path === null ? null : $record->evidence_name,
            ], $today);
        }

        foreach ($records->whereNull('employee_assignment_id') as $record) {
            $lines[] = $this->line([
                'key' => 'record:'.$record->id,
                'source' => 'record',
                'record_id' => $record->id,
                'assignment_id' => null,
                'kind' => $record->kind,
                'employer_am' => $record->employer_name,
                'employer_en' => $record->employer_name,
                'position_am' => $record->position_title,
                'position_en' => $record->position_title,
                'unit_am' => $record->unit_name,
                'unit_en' => $record->unit_name,
                'started_on' => $record->started_on->toDateString(),
                'ended_on' => $record->ended_on?->toDateString(),
                'monthly_salary' => $record->monthly_salary === null ? null : (float) $record->monthly_salary,
                'note' => $record->note,
                'evidence_name' => $record->evidence_path === null ? null : $record->evidence_name,
            ], $today);
        }

        usort($lines, static fn (array $a, array $b): int => [$a['started_on'], $a['key']] <=> [$b['started_on'], $b['key']]);

        return $lines;
    }

    /**
     * Total service over the lines. Overlapping periods are counted once.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{years: int, months: int, days: int}
     */
    public function total(array $lines, ?string $today = null): array
    {
        $today ??= $this->today();
        $periods = [];
        foreach ($lines as $line) {
            $start = (string) $line['started_on'];
            $end = min((string) ($line['ended_on'] ?? $today), $today);
            if ($start <= $end) {
                $periods[] = [$start, $end];
            }
        }
        sort($periods);

        $merged = [];
        foreach ($periods as [$start, $end]) {
            $last = count($merged) - 1;
            // Touching periods (one ends the day before the next begins) are one stretch of service.
            if ($last >= 0 && $start <= CarbonImmutable::parse($merged[$last][1])->addDay()->toDateString()) {
                $merged[$last][1] = max($merged[$last][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }

        $years = $months = $days = 0;
        foreach ($merged as [$start, $end]) {
            $length = $this->length($start, $end);
            $years += $length['years'];
            $months += $length['months'];
            $days += $length['days'];
        }
        $months += intdiv($days, 30);
        $days %= 30;
        $years += intdiv($months, 12);
        $months %= 12;

        return ['years' => $years, 'months' => $months, 'days' => $days];
    }

    public function today(): string
    {
        return now('Africa/Addis_Ababa')->toDateString();
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function line(array $line, string $today): array
    {
        $end = min((string) ($line['ended_on'] ?? $today), $today);
        $line['duration'] = $line['started_on'] <= $end ? $this->length((string) $line['started_on'], $end) : ['years' => 0, 'months' => 0, 'days' => 0];

        return $line;
    }

    /**
     * Both days count: 1 Meskerem to 30 Meskerem is thirty days.
     *
     * @return array{years: int, months: int, days: int}
     */
    private function length(string $start, string $end): array
    {
        $diff = CarbonImmutable::parse($start)->diff(CarbonImmutable::parse($end)->addDay());

        return ['years' => (int) $diff->y, 'months' => (int) $diff->m, 'days' => (int) $diff->d];
    }
}
