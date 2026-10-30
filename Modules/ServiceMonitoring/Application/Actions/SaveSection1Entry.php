<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Actions;

use App\Backbone\Contracts\AssignmentContextReader;
use App\Backbone\Contracts\EmployeeDirectory;
use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\ServiceMonitoring\Application\Services\FiscalCalendar;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAudit;
use Modules\ServiceMonitoring\Application\Services\SubmissionGuard;
use Modules\ServiceMonitoring\Application\Services\SubmissionValidator;
use Modules\ServiceMonitoring\Domain\Enums\EntrySource;
use Modules\ServiceMonitoring\Domain\Enums\ReportingLevel;
use Modules\ServiceMonitoring\Domain\Enums\RoleCategory;
use Modules\ServiceMonitoring\Domain\Enums\SubjectType;
use Modules\ServiceMonitoring\Domain\Models\AccountabilityEntry;
use Modules\ServiceMonitoring\Domain\Models\Submission;

/**
 * Adds or changes a Section I.1 row (D12–D14):
 *
 * - an employee row names a current employee of the institution; the name is
 *   snapshotted; a customer row is free text and uses the customer role;
 * - every row has a final action and a date inside the quarter;
 * - woreda rows name the office (ጽ/ቤት);
 * - one row per employee per submission, and an employee already reported in
 *   an earlier accepted quarter of the year needs an explicit confirmation.
 *
 * The audit trail records categories and ids, never the names or details.
 */
final readonly class SaveSection1Entry
{
    public function __construct(
        private SubmissionGuard $guard,
        private SubmissionValidator $validator,
        private FiscalCalendar $calendar,
        private ServiceMonitoringAudit $audit,
        private EmployeeDirectory $employees,
        private AssignmentContextReader $assignments,
    ) {}

    /** @param array<string, mixed> $data validated by SaveAccountabilityEntryRequest */
    public function execute(Submission $submission, array $data, User $actor, ?AccountabilityEntry $entry = null, EntrySource $source = EntrySource::Manual, ?string $decisionId = null): AccountabilityEntry
    {
        $this->guard->assertWritable($submission);
        if ($submission->section_1_nothing) {
            throw ValidationException::withMessages(['section_1' => __('service-monitoring.validation.nothing_not_empty', ['section' => 1])]);
        }

        $subject = SubjectType::from((string) $data['subject_type']);
        $role = RoleCategory::from((string) $data['role_category']);
        $errors = [];
        $employee = null;
        if ($subject === SubjectType::Employee) {
            // People come from the Core's employee directory (ADR 0016), never the HR tables.
            $employee = isset($data['employee_id']) ? $this->employees->findByUuid((string) $data['employee_id']) : null;
            if ($employee === null) {
                $errors['employee_id'] = __('service-monitoring.validation.employee_required');
            } elseif ($this->assignments->currentFor($employee->employeeUuid)?->organizationUuid !== (string) $submission->organization_id && $source === EntrySource::Manual) {
                $errors['employee_id'] = __('service-monitoring.validation.employee_out_of_scope');
            }
        }
        if (($subject === SubjectType::Customer) !== ($role === RoleCategory::Customer)) {
            $errors['role_category'] = __('service-monitoring.validation.customer_role');
        }
        if ($submission->level === ReportingLevel::Woreda && trim((string) ($data['office_name'] ?? '')) === '') {
            $errors['office_name'] = __('service-monitoring.validation.office_required');
        }
        $period = $submission->period;
        $date = (string) $data['action_date'];
        if ($date < $period->starts_on->toDateString() || $date > $period->ends_on->toDateString()) {
            $errors['action_date'] = __('service-monitoring.validation.action_date_in_quarter', ['from' => $this->calendar->display($period->starts_on->toDateString()), 'to' => $this->calendar->display($period->ends_on->toDateString())]);
        }
        if ($employee !== null) {
            $duplicate = AccountabilityEntry::query()->where('submission_id', $submission->id)->where('employee_id', $employee->employeeUuid)
                ->when($entry !== null, fn ($q) => $q->whereKeyNot($entry->id))->exists();
            if ($duplicate) {
                $errors['employee_id'] = __('service-monitoring.validation.duplicate_employee');
            } elseif (! (bool) ($data['repeat_confirmed'] ?? false) && $this->validator->earlierAcceptedEmployees($submission, [$employee->employeeUuid])->isNotEmpty()) {
                $errors['repeat_confirmed'] = __('service-monitoring.validation.repeat_employee');
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($submission, $data, $actor, $entry, $subject, $role, $employee, $source, $decisionId, $date): AccountabilityEntry {
            $values = [
                'subject_type' => $subject,
                'employee_id' => $employee?->employeeUuid,
                'full_name' => $employee !== null ? (string) ($employee->fullName ?: $employee->nameEn) : ($data['full_name'] ?? null),
                'office_name' => $data['office_name'] ?? null,
                'role_category' => $role,
                'reason_category' => $data['reason_category'],
                'reason_detail' => $data['reason_detail'],
                'final_action' => $data['final_action'],
                'action_detail' => $data['action_detail'],
                'action_date' => $date,
                'remark' => $data['remark'] ?? null,
                'repeat_confirmed' => (bool) ($data['repeat_confirmed'] ?? false),
            ];
            if ($entry === null) {
                $entry = AccountabilityEntry::query()->create($values + [
                    'submission_id' => $submission->id,
                    'sequence' => (int) AccountabilityEntry::query()->where('submission_id', $submission->id)->max('sequence') + 1,
                    'source' => $source,
                    'source_decision_id' => $decisionId,
                ]);
            } else {
                $entry->fill($values)->save();
            }

            $this->audit->record(AuditEventType::ServiceMonitoringSectionSaved, $actor, $entry, $submission->organization_id, [
                'section' => 1, 'submission_id' => $submission->id, 'subject_type' => $subject->value, 'role_category' => $role->value,
                'final_action' => (string) $data['final_action'], 'source' => $source->value, 'source_decision_id' => $decisionId,
            ]);

            return $entry;
        });
    }
}
