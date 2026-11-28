<?php

declare(strict_types=1);

namespace Modules\WorkExperience\Application\Services;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Modules\WorkExperience\Contracts\Data\WorkExperienceStatus;
use Modules\WorkExperience\Domain\Models\WorkExperienceRecord;
use Modules\WorkExperience\Domain\Models\WorkExperienceRequest;
use Throwable;

/** Shapes work experience for the pages. What a reader may not do is decided here, on the server. */
class WorkExperiencePresenter
{
    public function __construct(
        private readonly WorkExperienceAccess $access,
        private readonly WorkExperienceTimeline $timeline,
        private readonly WorkExperienceOffice $office,
        private readonly WorkExperienceRequests $requests,
    ) {}

    /** @return array<string, mixed>|null */
    public function employee(?Employee $employee): ?array
    {
        if ($employee === null) {
            return null;
        }

        return [
            'id' => $employee->id,
            'employee_number' => $employee->employee_number,
            'name_en' => $employee->fullNameEn(),
            'name_am' => $employee->fullNameAm(),
            'status' => $employee->status?->value,
            'has_left' => $this->requests->hasLeft($employee),
        ];
    }

    /**
     * An employee's experience: every line and the total.
     *
     * @return array{lines: array<int, array<string, mixed>>, total: array{years: int, months: int, days: int}}
     */
    public function experience(Employee $employee): array
    {
        $lines = $this->timeline->linesFor($employee, $this->access->officeOf($employee));

        return ['lines' => $lines, 'total' => $this->timeline->total($lines)];
    }

    /** @return array<string, mixed> */
    public function row(WorkExperienceRequest $request): array
    {
        $request->loadMissing(['employee', 'organization:id,name_en,name_am']);

        return [
            'id' => $request->id,
            'reference_number' => $request->reference_number,
            'employee' => $this->employee($request->employee),
            'organization' => $request->organization === null ? null : ['id' => $request->organization->id, 'name_en' => $request->organization->name_en, 'name_am' => $request->organization->name_am],
            'addressee' => $request->addressee,
            'purpose' => $request->purpose,
            'language' => $request->language,
            'status' => $request->status->value,
            'registered_by_office' => $request->registered_by_office,
            'submitted_at' => $request->submitted_at?->toIso8601String(),
            'decided_at' => $request->decided_at?->toIso8601String(),
            'decision_comment' => $request->decision_comment,
            'certificate_number' => $request->certificate_number,
            'print_count' => $request->print_count,
            'printed_at' => $request->printed_at?->toIso8601String(),
            'sent_at' => $request->sent_at?->toIso8601String(),
            'delivery_note' => $request->delivery_note,
        ];
    }

    /**
     * One request for the office's popup: the row, what the certificate says
     * (frozen once accepted, a preview before), and what the reader may do.
     *
     * @return array<string, mixed>
     */
    public function detail(WorkExperienceRequest $request, User $viewer): array
    {
        $request->loadMissing(['decider:id,name', 'sender:id,name']);
        $manage = $this->access->canManage($viewer, $request);
        $certificate = $request->certificate;
        $problem = null;
        if ($certificate === null && $request->status === WorkExperienceStatus::Submitted) {
            try {
                $certificate = $this->requests->certificateFor($request);
            } catch (Throwable $exception) {
                // No signatory yet, or nothing recorded: the popup says what is missing.
                $problem = $exception instanceof ValidationException ? collect($exception->errors())->flatten()->first() : null;
                $employee = $request->employee;
                $lines = $employee === null ? [] : $this->timeline->linesFor($employee, $request->organization_id);
                $lines = array_values(array_filter($lines, static fn (array $line): bool => $line['kind'] === WorkExperienceRecord::KIND_INTERNAL));
                $certificate = ['lines' => $lines, 'total' => $this->timeline->total($lines), 'include_salary' => $this->office->settingsOf($request->organization_id)['include_salary'], 'signatory' => null];
            }
        }

        return [
            ...$this->row($request),
            'verification_code' => $manage || $this->access->canView($viewer, $request) ? $request->verification_code : null,
            'decided_by' => $request->decider?->name,
            'sent_by' => $request->sender?->name,
            'certificate' => $certificate === null ? null : [
                'lines' => $certificate['lines'] ?? [],
                'total' => $certificate['total'] ?? ['years' => 0, 'months' => 0, 'days' => 0],
                'include_salary' => (bool) ($certificate['include_salary'] ?? false),
                'signatory' => $certificate['signatory'] ?? null,
                'issued_on' => $certificate['issued_on'] ?? null,
            ],
            'problem' => $problem,
            'can' => [
                'decide' => $manage && $request->status === WorkExperienceStatus::Submitted,
                'print' => $manage && $request->status->hasCertificate(),
                'rewrite' => $manage && $request->status === WorkExperienceStatus::Accepted,
                'send' => $manage && $request->status === WorkExperienceStatus::Accepted && $request->print_count > 0,
                'record' => $request->employee !== null && $this->access->canRecord($viewer, $request->employee),
            ],
        ];
    }
}
