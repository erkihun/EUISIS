<?php

declare(strict_types=1);

namespace App\Services\ServiceFeedback;

use App\Enums\PublicFeedbackHandlerType;
use App\Enums\ServiceFeedbackStatus;
use App\Models\EmployeeServiceFeedback;
use App\Models\OrganizationUnit;
use App\Models\PublicFeedbackAssignment;
use App\Models\PublicFeedbackRoutingRule;
use App\Models\Role;
use App\Models\User;
use App\Support\Phone\PublicContactPhone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only data-quality checks over the public feedback tables (QA brief
 * §90), and the few repairs that are purely technical and deterministic
 * (§91). Anything that would need a human to decide what a row means is
 * reported as NEEDS_DATA_REVIEW and left alone; a submission is never
 * retargeted.
 */
final class PublicFeedbackDataQuality
{
    public const SEVERITY_FAIL = 'FAIL';

    public const SEVERITY_WARN = 'WARN';

    public const SEVERITY_REVIEW = 'NEEDS_DATA_REVIEW';

    public const SEVERITY_INFO = 'INFO';

    public function __construct(
        private readonly PublicFeedbackRoutingService $routing,
        private readonly PublicFeedbackWorkflow $workflow,
    ) {}

    /**
     * @return list<array{key: string, label: string, severity: string, count: int, sample: list<string>, repairable: bool}>
     */
    public function run(): array
    {
        $findings = [];
        $add = function (string $key, string $label, string $severity, Collection $ids, bool $repairable = false) use (&$findings): void {
            $findings[] = [
                'key' => $key,
                'label' => $label,
                'severity' => $severity,
                'count' => $ids->count(),
                'sample' => $ids->take(5)->map(fn ($id): string => (string) $id)->values()->all(),
                'repairable' => $repairable,
            ];
        };

        // Institutions in service without a public feedback QR (a policy choice, reported for awareness).
        $add('active_organizations_without_qr', 'Active institutions without an active feedback QR', self::SEVERITY_INFO,
            DB::table('organizations')->where('status', 'active')->whereNull('deleted_at')
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('organization_feedback_qr_codes as q')
                    ->whereColumn('q.organization_id', 'organizations.id')->where('q.status', 'active'))
                ->pluck('id'));

        // The Call action on QR pages (docs/public-qr-mobile-actions.md). A missing number is a policy choice;
        // a stored value that is not a valid number silently hides the button, so a person must correct it.
        $add('active_organizations_without_public_phone', 'Active institutions without a public contact phone (no Call action on their QR pages)', self::SEVERITY_INFO,
            DB::table('organizations')->where('status', 'active')->whereNull('deleted_at')->whereNull('public_contact_phone')->pluck('id'));

        $add('organizations_with_invalid_public_phone', 'Institutions whose public contact phone is not a valid number (Call action hidden)', self::SEVERITY_REVIEW,
            DB::table('organizations')->whereNull('deleted_at')->whereNotNull('public_contact_phone')->get(['id', 'public_contact_phone'])
                ->filter(fn (object $row): bool => PublicContactPhone::parse((string) $row->public_contact_phone) === null)
                ->pluck('id'));

        $add('qr_without_organization', 'Feedback QR codes whose institution is missing or archived', self::SEVERITY_WARN,
            DB::table('organization_feedback_qr_codes as q')->leftJoin('organizations as o', 'o.id', '=', 'q.organization_id')
                ->where('q.status', 'active')->where(fn ($q) => $q->whereNull('o.id')->orWhereNotNull('o.deleted_at'))
                ->pluck('q.id'));

        $add('duplicate_active_qr', 'Institutions with more than one active feedback QR', self::SEVERITY_FAIL,
            DB::table('organization_feedback_qr_codes')->where('status', 'active')->groupBy('organization_id')
                ->havingRaw('COUNT(*) > 1')->pluck('organization_id'));

        $add('submissions_without_valid_target', 'Submissions whose target is missing', self::SEVERITY_REVIEW,
            DB::table('employee_service_feedback as s')
                ->leftJoin('employees as e', 'e.id', '=', 's.employee_id')
                ->leftJoin('organizations as o', 'o.id', '=', 's.organization_target_id')
                ->where(fn ($q) => $q
                    ->where(fn ($q) => $q->where(fn ($q) => $q->whereNull('s.target_type')->orWhere('s.target_type', 'employee'))->where(fn ($q) => $q->whereNull('s.employee_id')->orWhereNull('e.id')))
                    ->orWhere(fn ($q) => $q->where('s.target_type', 'organization')->where(fn ($q) => $q->whereNull('s.organization_target_id')->orWhereNull('o.id'))))
                ->pluck('s.id'));

        $add('invalid_source_target', 'Submissions whose source does not match their target', self::SEVERITY_FAIL,
            DB::table('employee_service_feedback')->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('target_type', 'employee')->where('source_type', '!=', 'employee_card_qr'))
                ->orWhere(fn ($q) => $q->where('target_type', 'organization')->where('source_type', '!=', 'organization_feedback_qr'))
                ->orWhere(fn ($q) => $q->where('target_type', 'employee')->whereNotNull('organization_target_id'))
                ->orWhere(fn ($q) => $q->where('target_type', 'organization')->whereNotNull('employee_id')))
                ->pluck('id'));

        $add('unrouted_legacy_submissions', 'Suggestions and complaints never routed (pending, no history)', self::SEVERITY_WARN,
            $this->legacyPending()->pluck('id'), true);

        $add('missing_submitted_at', 'Submissions without a submission time', self::SEVERITY_WARN,
            DB::table('employee_service_feedback')->whereNull('submitted_at')->pluck('id'), true);

        $add('missing_initial_history', 'Submissions without any status history', self::SEVERITY_WARN,
            DB::table('employee_service_feedback as s')
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('public_feedback_status_history as h')->whereColumn('h.submission_id', 's.id'))
                ->pluck('s.id'), true);

        // The database normally forbids this (pfa_one_open_per_submission); a server that refused the index relies on this check.
        $add('several_open_assignments', 'Submissions with more than one open assignment', self::SEVERITY_FAIL,
            DB::table('public_feedback_assignments')->whereNull('ended_at')->groupBy('submission_id')
                ->havingRaw('COUNT(*) > 1')->pluck('submission_id'));

        $add('invalid_handler', 'Open assignments whose handler no longer exists', self::SEVERITY_WARN,
            PublicFeedbackAssignment::query()->whereNull('ended_at')->get(['id', 'handler_type', 'handler_id'])
                ->filter(fn (PublicFeedbackAssignment $a): bool => ! $this->handlerExists($a->handler_type, $a->handler_id))->pluck('id'));

        $add('rules_with_missing_handler', 'Active routing rules whose handler no longer exists', self::SEVERITY_WARN,
            PublicFeedbackRoutingRule::query()->where('is_active', true)->get(['id', 'handler_type', 'handler_id'])
                ->filter(fn (PublicFeedbackRoutingRule $r): bool => ! $this->handlerExists($r->handler_type, $r->handler_id))->pluck('id'));

        $add('legacy_ambiguous_rows', 'Rows with no target type at all (classification needs a person)', self::SEVERITY_REVIEW,
            DB::table('employee_service_feedback')->whereNull('target_type')->pluck('id'));

        $add('failed_exports', 'Report exports that failed to build', self::SEVERITY_INFO,
            DB::table('public_feedback_report_exports')->where('status', 'failed')->pluck('id'));

        return $findings;
    }

    /**
     * Apply only the repairs that need no judgement: stamp a missing
     * submission time from created_at, write the missing initial history row,
     * and route suggestions and complaints that were stored before routing
     * existed (they become assigned by today's rules or unrouted with the
     * administrators told — never anything else).
     *
     * @return array<string, int>
     */
    public function repair(): array
    {
        $done = ['missing_submitted_at' => 0, 'missing_initial_history' => 0, 'unrouted_legacy_submissions' => 0];

        DB::transaction(function () use (&$done): void {
            $done['missing_submitted_at'] = DB::table('employee_service_feedback')->whereNull('submitted_at')->update(['submitted_at' => DB::raw('created_at')]);

            EmployeeServiceFeedback::query()
                ->whereDoesntHave('statusHistory')
                ->orderBy('created_at')
                ->chunkById(200, function ($rows) use (&$done): void {
                    foreach ($rows as $row) {
                        $this->workflow->recordInitial($row);
                        $done['missing_initial_history']++;
                    }
                });

            foreach ($this->legacyPending()->get() as $row) {
                $this->routing->route($row);
                $done['unrouted_legacy_submissions']++;
            }
        });

        return $done;
    }

    /** Suggestions and complaints still `pending` with no transition at all: stored before routing existed. */
    private function legacyPending()
    {
        return EmployeeServiceFeedback::query()
            ->whereIn('type', ['suggestion', 'complaint'])
            ->where('status', ServiceFeedbackStatus::Pending->value)
            ->whereDoesntHave('statusHistory', fn ($q) => $q->where('to_status', '!=', 'pending'))
            ->whereDoesntHave('assignments');
    }

    private function handlerExists(PublicFeedbackHandlerType $type, string $id): bool
    {
        return match ($type) {
            PublicFeedbackHandlerType::User => User::query()->whereKey($id)->exists(),
            PublicFeedbackHandlerType::OrganizationUnit => OrganizationUnit::query()->whereKey($id)->exists(),
            PublicFeedbackHandlerType::Role => Role::query()->whereKey($id)->exists(),
        };
    }
}
