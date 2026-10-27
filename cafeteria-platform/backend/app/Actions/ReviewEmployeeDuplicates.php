<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Employee;
use App\Models\EmployeeDuplicateReview;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\DuplicateEmployees;
use App\Services\OrganizationScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The duplicate review queue (ADR 0009). Matches are found by
 * DuplicateEmployees (unchanged rules) at registration, on an identity change
 * and in imports; each new pair becomes a pending review, never a merge. A
 * reviewer other than the person whose change raised it decides:
 *  - not_duplicate: the records are different people;
 *  - confirmed_duplicate: the flagged record is blocked (verification,
 *    approval and cards) until a controlled merge;
 *  - needs_more_information: still open, with what is missing.
 */
final readonly class ReviewEmployeeDuplicates
{
    public function __construct(private DuplicateEmployees $duplicates, private AuditWriter $audit, private OrganizationScope $scope) {}

    /**
     * Pending reviews for the matches of this employee that have no review yet
     * (in either direction); the record never matches itself.
     *
     * @param  list<array{employee_id: string, level: string, reasons: list<string>}>  $matches
     * @return int the number of new reviews
     */
    public function record(Employee $employee, array $matches, string $source, ?string $statement, User $actor): int
    {
        $created = 0;
        foreach ($matches as $match) {
            if ($match['employee_id'] === (string) $employee->getKey() || $this->paired((string) $employee->getKey(), $match['employee_id'])) {
                continue;
            }
            EmployeeDuplicateReview::query()->create([
                'employee_id' => $employee->getKey(),
                'matched_employee_id' => $match['employee_id'],
                'match_level' => $match['level'],
                'reasons' => $match['reasons'],
                'decision' => EmployeeDuplicateReview::PENDING,
                'source' => $source,
                'registration_reason' => $statement,
                'detected_by' => $actor->getKey(),
                'detected_at' => now(),
            ]);
            $created++;
        }
        if ($created > 0) {
            $this->audit->write('employee.duplicate_detected', $actor, $employee, $this->organizationId((string) $employee->getKey()), null,
                ['source' => $source, 'new_reviews' => $created]);
        }

        return $created;
    }

    /** After an identity field changes: look again, never against the record itself. */
    public function detectAfterChange(Employee $employee, User $actor): int
    {
        $matches = $this->duplicates->matches([
            'full_name' => (string) $employee->full_name,
            'date_of_birth' => $employee->date_of_birth?->toDateString(),
            'phone' => $employee->phone,
            'email' => $employee->email,
        ], $actor, (string) $employee->getKey());

        return $this->record($employee, $matches, 'update', null, $actor);
    }

    public function decide(EmployeeDuplicateReview $review, string $decision, string $reason, ?string $notes, User $actor): EmployeeDuplicateReview
    {
        if (! in_array($decision, EmployeeDuplicateReview::DECISIONS, true)) {
            throw ValidationException::withMessages(['decision' => 'Choose a decision.']);
        }

        return DB::transaction(function () use ($review, $decision, $reason, $notes, $actor): EmployeeDuplicateReview {
            $review = EmployeeDuplicateReview::query()->lockForUpdate()->findOrFail($review->getKey());
            abort_unless($actor->is_active && $actor->can('employees.duplicates.review')
                && $this->scope->allows($actor, $this->organizationId((string) $review->employee_id)), 403);
            if (! in_array($review->decision, EmployeeDuplicateReview::OPEN, true)) {
                throw ValidationException::withMessages(['review' => 'This review is already decided.']);
            }
            if ((int) $review->detected_by === (int) $actor->getKey()) {
                throw ValidationException::withMessages(['review' => 'Someone other than the person whose change raised the match must review it.']);
            }
            $before = $review->decision;
            $review->forceFill(['decision' => $decision, 'reason' => $reason, 'notes' => $notes, 'decided_by' => $actor->getKey(), 'decided_at' => now()])->save();
            $this->audit->write('employee.duplicate_reviewed', $actor, $review, $this->organizationId((string) $review->employee_id), ['decision' => $before],
                ['decision' => $decision, 'employee_id' => $review->employee_id, 'matched_employee_id' => $review->matched_employee_id], $reason);

            return $review;
        });
    }

    /**
     * What stops this employee's verification: open reviews and a confirmed
     * duplicate, as numbers of the other records.
     *
     * @return array{open: list<string>, confirmed: list<string>}
     */
    public function blocking(Employee $employee): array
    {
        $rows = EmployeeDuplicateReview::query()
            ->where(fn ($q) => $q->where('employee_id', $employee->getKey())->orWhere('matched_employee_id', $employee->getKey()))
            ->whereIn('decision', [...EmployeeDuplicateReview::OPEN, EmployeeDuplicateReview::CONFIRMED])->get();
        $other = fn (EmployeeDuplicateReview $row): string => (string) ($row->employee_id === (string) $employee->getKey() ? $row->matched_employee_id : $row->employee_id);
        $numbers = Employee::query()->whereIn('id', $rows->map($other))->pluck('employee_number', 'id');

        return [
            'open' => $rows->whereIn('decision', EmployeeDuplicateReview::OPEN)->map(fn ($row) => (string) $numbers->get($other($row)))->values()->all(),
            // Only the flagged side of a confirmed pair is the duplicate.
            'confirmed' => $rows->where('decision', EmployeeDuplicateReview::CONFIRMED)->where('employee_id', (string) $employee->getKey())
                ->map(fn ($row) => (string) $numbers->get($other($row)))->values()->all(),
        ];
    }

    private function paired(string $a, string $b): bool
    {
        return EmployeeDuplicateReview::query()->where(fn ($q) => $q->where('employee_id', $a)->where('matched_employee_id', $b))
            ->orWhere(fn ($q) => $q->where('employee_id', $b)->where('matched_employee_id', $a))->exists();
    }

    private function organizationId(string $employeeId): ?string
    {
        $organizationId = DB::table('employees')->join('employee_assignments', 'employee_assignments.id', '=', 'employees.current_assignment_id')
            ->where('employees.id', $employeeId)->value('employee_assignments.organization_id');

        return $organizationId === null ? null : (string) $organizationId;
    }
}
