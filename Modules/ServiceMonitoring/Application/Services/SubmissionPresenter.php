<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Services;

use App\Enums\AuditEventType;
use App\Models\Service;
use App\Models\User;
use Modules\ServiceMonitoring\Domain\Forms\FormDefinition;
use Modules\ServiceMonitoring\Domain\Models\AccountabilityEntry;
use Modules\ServiceMonitoring\Domain\Models\DigitalService;
use Modules\ServiceMonitoring\Domain\Models\MonitoringAttachment;
use Modules\ServiceMonitoring\Domain\Models\Submission;

/**
 * The props of the submission screens. Section I names, reasons and action
 * details are included only for users who may see named data (D27); every
 * such view is audited. Everyone else sees the role and the action.
 */
class SubmissionPresenter
{
    public function __construct(
        private readonly ServiceMonitoringAccess $access,
        private readonly SubmissionValidator $validator,
        private readonly ServiceMonitoringSettings $settings,
        private readonly SubmissionGuard $guard,
        private readonly ConsolidationService $consolidation,
        private readonly ServiceMonitoringAudit $audit,
        private readonly FiscalCalendar $calendar,
    ) {}

    /** @return array<string, mixed> */
    public function present(Submission $submission, User $user, bool $forEdit): array
    {
        $submission->load([
            'period.fiscalYear', 'participant.organization:id,name_en,name_am', 'organization:id,name_en,name_am', 'extension',
            'accountabilityEntries', 'institutionBuilding', 'prefillValues', 'digitalServices', 'serviceTotals', 'standardPerformance',
            'histories.actor:id,name', 'reviewComments.author:id,name', 'attachments', 'correctionRequests',
        ]);
        $named = $this->access->canViewNamed($user, $submission);
        if ($named && $submission->accountabilityEntries->isNotEmpty()) {
            $this->audit->record(AuditEventType::ServiceMonitoringNamedDataViewed, $user, $submission, $submission->organization_id, ['entries' => $submission->accountabilityEntries->count()]);
        }
        $period = $submission->period;
        $problems = $this->validator->problems($submission);
        $repeats = $this->validator->repeatEmployeeIds($submission)->all();
        $locale = app()->getLocale();
        $activeExtension = $this->guard->activeExtension($period, $submission->participant_id);

        return [
            'submission' => [
                'id' => $submission->id,
                'status' => $submission->status->value,
                'revision_no' => $submission->revision_no,
                'is_current' => $submission->is_current,
                'level' => $submission->level->value,
                'form_type' => $submission->form_type->value,
                'form_version' => $submission->form_version,
                'organization' => ['id' => $submission->organization_id, 'name_en' => $submission->organization?->name_en, 'name_am' => $submission->organization?->name_am],
                'participant_id' => $submission->participant_id,
                'period' => [
                    'id' => $period->id, 'quarter' => $period->quarter, 'ec_year' => $period->fiscalYear->ec_year, 'label' => $period->label(),
                    'starts_on' => $period->starts_on->toDateString(), 'ends_on' => $period->ends_on->toDateString(),
                    'submission_due_on' => $period->submission_due_on->toDateString(), 'deadline_at' => $period->submissionDeadline()->toIso8601String(),
                    'locked' => $period->isLocked(), 'status' => $period->effectiveStatus()->value,
                ],
                'nothing' => [1 => $submission->section_1_nothing, 2 => $submission->section_2_nothing, 3 => $submission->section_3_nothing, 4 => $submission->section_4_nothing],
                'special_support_narrative' => $submission->special_support_narrative,
                'submitted_at' => $submission->submitted_at?->toIso8601String(),
                'submitted_late' => $submission->submitted_late,
                'accepted_at' => $submission->accepted_at?->toIso8601String(),
                'extension_due_at' => $activeExtension?->due_at?->toIso8601String(),
                'deadline_passed' => now()->greaterThan($period->submissionDeadline()),
                'attestation' => $submission->attested_at === null ? null : [
                    'name' => $submission->attester_name_snapshot, 'title' => $submission->attester_title_snapshot,
                    'organization' => $submission->attester_organization_snapshot, 'at' => $submission->attested_at->toIso8601String(),
                ],
            ],
            'definition' => FormDefinition::describe($submission->form_type, $submission->form_version, (int) $period->fiscalYear->ec_year, $locale),
            'section1' => [
                'named' => $named,
                'entries' => $submission->accountabilityEntries->map(fn (AccountabilityEntry $e): array => [
                    'id' => $e->id,
                    'sequence' => $e->sequence,
                    'subject_type' => $e->subject_type->value,
                    'employee_id' => $named ? $e->employee_id : null,
                    'full_name' => $named ? $e->full_name : null,
                    'office_name' => $e->office_name,
                    'role_category' => $e->role_category->value,
                    'reason_category' => $e->reason_category->value,
                    'reason_detail' => $named ? $e->reason_detail : null,
                    'final_action' => $e->final_action->value,
                    'action_detail' => $named ? $e->action_detail : null,
                    'action_date' => $e->action_date->toDateString(),
                    'remark' => $e->remark,
                    'source' => $e->source->value,
                    'repeat_confirmed' => $e->repeat_confirmed,
                    'repeat_warning' => $e->employee_id !== null && in_array($e->employee_id, $repeats, true),
                ])->values()->all(),
                'summary' => $this->consolidation->summary($submission->accountabilityEntries->map(fn (AccountabilityEntry $e): array => [
                    'employee_id' => $e->employee_id, 'action_date' => $e->action_date->toDateString(), 'quarter' => $period->quarter,
                    'sequence' => $e->sequence, 'level' => $submission->level->value, 'final_action' => $e->final_action->value, 'role_category' => $e->role_category->value,
                ])->all()),
            ],
            'section2' => [
                'values' => collect(FormDefinition::section2Keys())->mapWithKeys(fn (string $f): array => [$f => $submission->institutionBuilding?->{$f}])->all(),
                'prefill' => $submission->prefillValues->mapWithKeys(fn ($p): array => [$p->field => [
                    'system_value' => $p->system_value, 'source' => $p->source, 'notice' => $p->notice,
                    'override_reason' => $p->override_reason, 'captured_at' => $p->captured_at?->toIso8601String(),
                ]])->all(),
                'driver_list_empty' => $this->settings->driverOccupationIds() === [],
                'notices' => collect(['employees_excl_drivers', 'chest_badges_excl_drivers', 'cafeterias', 'driver_list_empty'])->mapWithKeys(fn (string $k): array => [$k => (string) __("service-monitoring.prefill.{$k}")])->all(),
            ],
            'section3' => [
                'totals' => [
                    'main_total' => $submission->serviceTotals?->main_total, 'sub_total' => $submission->serviceTotals?->sub_total,
                    'catalog_main_total' => Service::query()->where('organization_id', $submission->organization_id)->count(),
                ],
                'services' => $submission->digitalServices->map(fn (DigitalService $d): array => [
                    'id' => $d->id, 'sequence' => $d->sequence, 'level' => $d->level->value, 'parent_entry_id' => $d->parent_entry_id,
                    'service_id' => $d->service_id, 'service_name_am' => $d->service_name_am, 'service_name_en' => $d->service_name_en,
                    'is_catalog_gap' => $d->is_catalog_gap, 'technology_type' => $d->technology_type->value, 'technology_other' => $d->technology_other,
                    'system_name' => $d->system_name, 'system_count' => $d->system_count, 'go_live_on' => $d->go_live_on->toDateString(),
                    'remark' => $d->remark, 'verification_status' => $d->verification_status->value, 'rejection_reason' => $d->rejection_reason,
                ])->values()->all(),
                'catalog' => $forEdit ? Service::query()->where('organization_id', $submission->organization_id)->orderBy('name_en')->limit(500)
                    ->get(['id', 'name_en', 'name_am'])->map(fn (Service $s): array => ['id' => $s->id, 'name_en' => $s->name_en, 'name_am' => $s->name_am])->all() : [],
                'fiscal_year' => (fn (array $b): array => ['from' => $b[0]->toDateString(), 'to' => $b[1]->toDateString()])($this->calendar->fiscalYear((int) $period->fiscalYear->ec_year)),
            ],
            'section4' => [
                'values' => collect([...FormDefinition::SECTION_4, 'below_reasons', 'actions_taken'])->mapWithKeys(fn (string $f): array => [$f => $submission->standardPerformance?->{$f}])->all(),
                'min_text' => FormDefinition::MIN_BELOW_STANDARD_TEXT,
            ],
            'problems' => array_map(fn (array $messages): array => array_values($messages), $problems),
            'completeness' => [1 => ! isset($problems[1]), 2 => ! isset($problems[2]), 3 => ! isset($problems[3]), 4 => ! isset($problems[4])],
            'comments' => $submission->reviewComments->map(fn ($c): array => [
                'id' => $c->id, 'section' => $c->section, 'line_reference' => $c->line_reference, 'body' => $c->body,
                'author' => $c->author?->name, 'created_at' => $c->created_at?->toIso8601String(), 'resolved' => $c->resolved_at !== null,
            ])->values()->all(),
            'attachments' => $submission->attachments->map(fn (MonitoringAttachment $a): array => [
                'id' => $a->id, 'kind' => $a->kind, 'original_name' => $a->original_name, 'mime' => $a->mime, 'size' => $a->size,
                'created_at' => $a->created_at?->toIso8601String(), 'download_url' => route('service-monitoring.attachments.download', $a),
            ])->values()->all(),
            'history' => $submission->histories->map(fn ($h): array => [
                'from' => $h->from_status, 'to' => $h->to_status, 'actor' => $h->actor?->name, 'reason' => $h->reason, 'at' => $h->created_at?->toIso8601String(),
            ])->values()->all(),
            'revisions' => Submission::query()->where('period_id', $submission->period_id)->where('participant_id', $submission->participant_id)
                ->orderBy('revision_no')->get(['id', 'revision_no', 'status'])
                ->map(fn (Submission $s): array => ['id' => $s->id, 'revision_no' => $s->revision_no, 'status' => $s->status->value])->all(),
            'corrections' => $submission->correctionRequests->map(fn ($c): array => [
                'id' => $c->id, 'status' => $c->status->value, 'reason' => $c->reason, 'decision_note' => $c->decision_note,
                'created_at' => $c->created_at?->toIso8601String(), 'new_submission_id' => $c->new_submission_id,
            ])->values()->all(),
            'declaration' => $this->settings->declaration($locale),
            'require_stamped_copy' => $this->settings->requiresStampedCopy(),
            'can' => [
                'edit' => $forEdit && $this->access->canEdit($user, $submission),
                'submit' => $forEdit && $this->access->canSubmit($user, $submission),
                'review' => $this->access->canReview($user, $submission),
                'comment' => $this->access->canComment($user, $submission),
                'view_named' => $named,
                'request_correction' => $this->access->canRequestCorrection($user, $submission),
                'decide_correction' => $this->access->canDecideCorrection($user, $submission),
                'export' => $this->access->canExport($user),
                'open_clarification' => $submission->participant !== null && $this->access->canOpenClarification($user, $submission->participant),
            ],
        ];
    }

    /**
     * What changed from the previous revision: Section II and IV figures,
     * service totals, and the number of Section I and III rows.
     *
     * @return list<array{section: int, field: string, before: mixed, after: mixed}>
     */
    public function diff(Submission $submission): array
    {
        $previous = $submission->supersedes_submission_id !== null ? Submission::query()->find($submission->supersedes_submission_id) : null;
        if ($previous === null) {
            return [];
        }
        $changes = [];
        $compare = function (int $section, string $field, mixed $before, mixed $after) use (&$changes): void {
            if ($before !== $after) {
                $changes[] = ['section' => $section, 'field' => $field, 'before' => $before, 'after' => $after];
            }
        };
        $compare(1, 'rows', $previous->accountabilityEntries()->count(), $submission->accountabilityEntries()->count());
        foreach (FormDefinition::section2Keys() as $field) {
            $compare(2, $field, $previous->institutionBuilding?->{$field}, $submission->institutionBuilding?->{$field});
        }
        $compare(3, 'rows', $previous->digitalServices()->count(), $submission->digitalServices()->count());
        foreach (FormDefinition::SERVICE_TOTALS as $field) {
            $compare(3, $field, $previous->serviceTotals?->{$field}, $submission->serviceTotals?->{$field});
        }
        foreach (FormDefinition::SECTION_4 as $field) {
            $compare(4, $field, $previous->standardPerformance?->{$field}, $submission->standardPerformance?->{$field});
        }

        return $changes;
    }
}
