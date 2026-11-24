<?php

declare(strict_types=1);

namespace Modules\Reporting\Http\Support;

use Carbon\CarbonImmutable;
use Modules\Reporting\Domain\Compliance\TimelinessRule;
use Modules\Reporting\Domain\Enums\Applicability;
use Modules\Reporting\Infrastructure\Persistence\Models\Artifact;
use Modules\Reporting\Infrastructure\Persistence\Models\Attachment;
use Modules\Reporting\Infrastructure\Persistence\Models\Comment;
use Modules\Reporting\Infrastructure\Persistence\Models\Cycle;
use Modules\Reporting\Infrastructure\Persistence\Models\Extension;
use Modules\Reporting\Infrastructure\Persistence\Models\FollowUp;
use Modules\Reporting\Infrastructure\Persistence\Models\FollowUpUpdate;
use Modules\Reporting\Infrastructure\Persistence\Models\Obligation;
use Modules\Reporting\Infrastructure\Persistence\Models\ReportType;
use Modules\Reporting\Infrastructure\Persistence\Models\ReviewStep;
use Modules\Reporting\Infrastructure\Persistence\Models\Submission;

/**
 * Shapes the module's records for the pages. Moments leave the server as
 * ISO 8601 in UTC; the pages show them in Africa/Addis_Ababa, labelled.
 */
final class Present
{
    /** @return array<string, mixed> */
    public static function obligation(Obligation $o, ?Cycle $cycle = null, ?ReportType $type = null): array
    {
        $cycle ??= $o->cycle;
        $type ??= $cycle?->reportType;
        $now = CarbonImmutable::now('UTC')->toDateTimeImmutable();
        $facts = $o->facts();
        $required = $o->applicability === Applicability::Required;

        return [
            'id' => $o->id,
            'organization' => ['id' => $o->organization_id, 'code' => $o->organization_code, 'name_en' => $o->organization_name_en, 'name_am' => $o->organization_name_am],
            'unit' => $o->organization_unit_id === null ? null : ['id' => $o->organization_unit_id, 'code' => $o->unit_code, 'name_en' => $o->unit_name_en, 'name_am' => $o->unit_name_am],
            'is_personal' => $o->person_user_id !== null,
            'report_type' => ['id' => $o->report_type_id, 'name_en' => $type?->name_en, 'name_am' => $type?->name_am, 'confidentiality' => $type?->confidentiality],
            'period' => ['label_en' => $cycle?->period_label_en, 'label_am' => $cycle?->period_label_am, 'start' => $cycle?->period_start?->toDateString(), 'end' => $cycle?->period_end?->toDateString()],
            'receiving' => ['id' => $o->receiving_organization_id, 'name_en' => $cycle?->receiving_name_en, 'name_am' => $cycle?->receiving_name_am],
            'opens_at' => $o->opens_at->toIso8601String(),
            'original_due_at' => $o->original_due_at->toIso8601String(),
            'effective_due_at' => $o->effective_due_at->toIso8601String(),
            'deadline_extended' => $o->effective_due_at->ne($o->original_due_at),
            'revision_due_at' => $o->revision_due_at?->toIso8601String(),
            'review_due_at' => $o->review_due_at?->toIso8601String(),
            'first_submitted_at' => $o->first_submitted_at?->toIso8601String(),
            'latest_submitted_at' => $o->latest_submitted_at?->toIso8601String(),
            'workflow' => $o->workflow_status->value,
            'applicability' => $o->applicability->value,
            'timeliness' => $required ? TimelinessRule::of($facts->firstSubmittedAt, $facts->effectiveDueAt, $now)->value : null,
            'timeliness_original' => $required ? TimelinessRule::of($facts->firstSubmittedAt, $facts->originalDueAt, $now)->value : null,
            'days_overdue' => $required ? TimelinessRule::daysOverdue($facts->firstSubmittedAt, $facts->effectiveDueAt, $now) : 0,
            'review_overdue' => $o->workflow_status->awaitsDecision() && $o->review_due_at !== null && $o->review_due_at->lt($now),
            'version' => $o->current_version,
            'preparer_name' => $o->preparer_name,
            'accountable_name' => $o->accountable_name,
            'latest_submission_id' => $o->latest_submission_id,
            'hierarchy' => $o->hierarchy_snapshot['ancestors'] ?? [],
        ];
    }

    /** @return array<string, mixed> */
    public static function submission(Submission $s, bool $withContent): array
    {
        return [
            'id' => $s->id, 'version_no' => $s->version_no, 'reference_no' => $s->reference_no, 'mode' => $s->mode, 'status' => $s->status,
            'is_nil' => $s->is_nil, 'is_late' => $s->is_late, 'late_reason' => $s->late_reason,
            'submitted_by_name' => $s->submitted_by_name, 'submitted_at' => $s->submitted_at->toIso8601String(), 'decided_at' => $s->decided_at?->toIso8601String(),
            'content_hash' => $s->content_hash,
            ...($withContent ? [
                'sections' => array_map(fn (array $section): array => [...$section, 'html' => (string) ($s->content[$section['key']] ?? '')], $s->mode === 'upload' || $s->is_nil ? [] : $s->sections_snapshot),
                'summary' => $s->summary, 'nil_reason' => $s->nil_reason, 'completeness_declared' => $s->completeness_declared,
            ] : []),
        ];
    }

    /** @return array<string, mixed> */
    public static function step(ReviewStep $step): array
    {
        return [
            'id' => $step->id, 'step_no' => $step->step_no, 'name_en' => $step->name_en, 'name_am' => $step->name_am, 'required_permission' => $step->required_permission,
            'status' => $step->status, 'decision' => $step->decision, 'reason' => $step->reason, 'reviewer_user_id' => $step->reviewer_user_id,
            'due_at' => $step->due_at?->toIso8601String(), 'decided_by_name' => $step->decided_by_name, 'decided_at' => $step->decided_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function attachment(Attachment $a): array
    {
        return [
            'id' => $a->id, 'kind' => $a->kind, 'requirement_key' => $a->requirement_key, 'name' => $a->original_name, 'size' => $a->size,
            'version_no' => $a->version_no, 'scan_status' => $a->scan_status, 'submitted' => $a->submission_id !== null, 'url' => route('reporting.files.attachment', $a->id, false),
        ];
    }

    /** @return array<string, mixed> */
    public static function artifact(Artifact $a): array
    {
        return [
            'id' => $a->id, 'format' => $a->format, 'label' => $a->label, 'status' => $a->status, 'size' => $a->size, 'attempts' => $a->attempts,
            'completed_at' => $a->completed_at?->toIso8601String(), 'url' => $a->status === 'completed' ? route('reporting.files.artifact', $a->id, false) : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function comment(Comment $c): array
    {
        return ['id' => $c->id, 'section_key' => $c->section_key, 'visibility' => $c->visibility, 'body' => $c->body, 'author_name' => $c->author_name, 'created_at' => $c->created_at->toIso8601String(), 'submission_id' => $c->submission_id];
    }

    /** @return array<string, mixed> */
    public static function extension(Extension $e): array
    {
        return [
            'id' => $e->id, 'obligation_id' => $e->obligation_id, 'status' => $e->status, 'reason' => $e->reason,
            'requested_due_at' => $e->requested_due_at->toIso8601String(), 'requested_by_name' => $e->requested_by_name, 'requested_at' => $e->requested_at->toIso8601String(),
            'decided_by_name' => $e->decided_by_name, 'decided_at' => $e->decided_at?->toIso8601String(), 'decision_reason' => $e->decision_reason,
            'previous_due_at' => $e->previous_due_at?->toIso8601String(), 'approved_due_at' => $e->approved_due_at?->toIso8601String(), 'is_retrospective' => (bool) $e->is_retrospective,
        ];
    }

    /** @return array<string, mixed> */
    public static function followUp(FollowUp $f, bool $withUpdates = false): array
    {
        return [
            'id' => $f->id, 'obligation_id' => $f->obligation_id, 'title' => $f->title, 'description' => $f->description, 'owner_name' => $f->owner_name,
            'owner_user_id' => $f->owner_user_id, 'priority' => $f->priority, 'status' => $f->status, 'progress' => $f->progress,
            'due_at' => $f->due_at->toIso8601String(), 'overdue' => in_array($f->status, ['open', 'in_progress'], true) && $f->due_at->isPast(),
            'created_by_name' => $f->created_by_name, 'created_at' => $f->created_at?->toIso8601String(), 'closed_at' => $f->closed_at?->toIso8601String(), 'closure_note' => $f->closure_note,
            ...($withUpdates ? ['updates' => $f->updates->map(fn (FollowUpUpdate $u): array => [
                'id' => $u->id, 'progress' => $u->progress, 'status' => $u->status, 'note' => $u->note, 'author_name' => $u->author_name, 'created_at' => $u->created_at->toIso8601String(),
                'evidence_name' => $u->evidence_name, 'evidence_url' => $u->evidence_path === null ? null : route('reporting.files.evidence', $u->id, false),
            ])->all()] : []),
        ];
    }
}
