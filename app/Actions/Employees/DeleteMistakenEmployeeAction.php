<?php

declare(strict_types=1);

namespace App\Actions\Employees;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeDocument;
use App\Models\PositionOccupancy;
use App\Models\User;
use App\Services\ReportingLines\ReportingGraphRepository;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Removes an employee record that was registered by mistake.
 *
 * Employee records are a retained class (ADR 0004): a person who worked here
 * is never deleted, only separated by employment status. This action is for
 * the other case — a registration that never should have existed — and it
 * refuses as soon as the record has become a real history: a card or card
 * request, a transfer, a service or cafeteria transaction, a case, daily
 * activity, performance, field work or anything else another table keeps a
 * restricting reference to. What it removes is the registration itself:
 * the employee row, its placement rows (assignments, occupancies, status
 * history), its registration artefacts (documents, photo, duplicate flags,
 * one-time codes, feedback tokens, contact verifications, correction
 * requests) and the link from a sign-in account. The audit log keeps the
 * full record and the reason.
 */
readonly class DeleteMistakenEmployeeAction
{
    /** Tables whose rows mean the record is a history, with the message key naming them. */
    private const BLOCKERS = [
        'id_cards' => 'employees.delete_blocked_cards',
        'card_requests' => 'employees.delete_blocked_cards',
        'employee_transfers' => 'employees.delete_blocked_transfers',
        'transfer_applications' => 'employees.delete_blocked_transfers',
        'vacancy_applications' => 'employees.delete_blocked_applications',
        'service_transactions' => 'employees.delete_blocked_transactions',
        'cafeteria_transactions' => 'employees.delete_blocked_transactions',
        'cafeteria_subsidy_ledger' => 'employees.delete_blocked_transactions',
        'coupon_balances' => 'employees.delete_blocked_transactions',
        'consumer_memberships' => 'employees.delete_blocked_transactions',
        'entitlements' => 'employees.delete_blocked_transactions',
        'daily_activity_logs' => 'employees.delete_blocked_work',
        'field_work_requests' => 'employees.delete_blocked_work',
        'field_work_participants' => 'employees.delete_blocked_work',
        'employee_performance_agreements' => 'employees.delete_blocked_work',
        'performance_results' => 'employees.delete_blocked_work',
        'grievance_initial_issues' => 'employees.delete_blocked_cases',
        'disciplinary_suspensions' => 'employees.delete_blocked_cases',
        'public_feedback_subject_employees' => 'employees.delete_blocked_cases',
    ];

    /** Registration artefacts removed with the record (employee_id column). */
    private const OWN_ROWS = [
        'employee_registration_otps', 'employee_feedback_tokens', 'employee_contact_verifications', 'employee_correction_requests',
        'employee_duplicate_flags', 'employment_status_histories', 'daily_activity_reminders',
    ];

    public function __construct(
        private WriteAuditLogAction $audit,
        private ReportingGraphRepository $graphs,
    ) {}

    public function execute(Employee $employee, string $reason, User $actor, ?Request $request = null): void
    {
        DB::transaction(function () use ($employee, $reason, $actor, $request): void {
            $locked = Employee::query()->whereKey($employee->getKey())->lockForUpdate()->firstOrFail();
            $id = (string) $locked->getKey();

            foreach (self::BLOCKERS as $table => $message) {
                if (Schema::hasTable($table) && DB::table($table)->where('employee_id', $id)->exists()) {
                    throw ValidationException::withMessages(['employee' => __($message)]);
                }
            }
            // Flagged as a duplicate OF this record: the other record keeps its flag only while this one exists.
            if (Schema::hasColumn('employee_duplicate_flags', 'matched_employee_id')) {
                DB::table('employee_duplicate_flags')->where('matched_employee_id', $id)->delete();
            }

            $snapshot = $locked->toArray();
            $organizationId = EmployeeAssignment::query()->where('employee_id', $id)->where('is_current', true)->value('organization_id');
            $documents = EmployeeDocument::query()->where('employee_id', $id)->get();

            try {
                // The current pointer references an assignment row that is about to go.
                $locked->forceFill(['current_assignment_id' => null])->save();
                User::query()->where('employee_id', $id)->update(['employee_id' => null]);
                PositionOccupancy::query()->where('employee_id', $id)->delete();
                EmployeeAssignment::query()->where('employee_id', $id)->delete();
                foreach (self::OWN_ROWS as $table) {
                    if (Schema::hasTable($table)) {
                        DB::table($table)->where('employee_id', $id)->delete();
                    }
                }
                EmployeeDocument::query()->where('employee_id', $id)->delete();
                $locked->delete();
            } catch (QueryException $e) {
                // A table this action does not know about still refers to the record: it is a history, keep it.
                throw ValidationException::withMessages(['employee' => __('employees.delete_blocked_referenced')]);
            }

            foreach ($documents as $document) {
                if ($document->file_path && $document->storage_disk) {
                    Storage::disk($document->storage_disk)->delete($document->file_path);
                }
            }
            if ($snapshot['photo_path'] ?? null) {
                Storage::disk('local')->delete((string) $snapshot['photo_path']);
            }
            $this->graphs->forget();

            $this->audit->execute(
                AuditEventType::EmployeeDeleted,
                $actor,
                null,
                $organizationId === null ? null : (string) $organizationId,
                oldValues: $snapshot + ['documents' => $documents->count()],
                reason: $reason,
                request: $request,
            );
        });
    }
}
