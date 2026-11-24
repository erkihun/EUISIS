<?php

declare(strict_types=1);

namespace Modules\Reporting\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Reporting\Application\Support\ReportingGuard;
use Modules\Reporting\Application\UseCases\ManageDeadlines;
use Modules\Reporting\Application\UseCases\ReviewReports;
use Modules\Reporting\Contracts\Data\ReportingRoles;
use Modules\Reporting\Domain\Enums\Applicability;
use Modules\Reporting\Http\Support\Present;
use Modules\Reporting\Infrastructure\Persistence\Models\ApplicabilityDecision;
use Modules\Reporting\Infrastructure\Persistence\Models\Artifact;
use Modules\Reporting\Infrastructure\Persistence\Models\Attachment;
use Modules\Reporting\Infrastructure\Persistence\Models\Comment;
use Modules\Reporting\Infrastructure\Persistence\Models\Extension;
use Modules\Reporting\Infrastructure\Persistence\Models\FollowUp;
use Modules\Reporting\Infrastructure\Persistence\Models\Obligation;
use Modules\Reporting\Infrastructure\Persistence\Models\ObligationEvent;
use Modules\Reporting\Infrastructure\Persistence\Models\ReviewStep;
use Modules\Reporting\Infrastructure\Persistence\Models\Submission;

/**
 * One report's page: its deadlines, every version with receipt and files,
 * the review steps, comments, extensions, follow-up actions and history; and
 * the decisions about the obligation itself (extension, exemption,
 * responsible officers, reopening).
 */
final class ObligationController extends ReportingController
{
    public function show(Request $request, string $obligation): Response
    {
        $actor = $this->actor($request);
        $record = Obligation::query()->with('cycle.reportType')->findOrFail($obligation);
        ReportingGuard::requireView($actor, $record);
        $type = $record->cycle->reportType;
        $content = ReportingGuard::canReadContent($actor, $record, (string) $type->confidentiality);
        $reporting = ReportingGuard::onReportingSide($actor, $record);
        $receiving = ReportingGuard::onReceivingSide($actor, $record);
        $reviewer = $receiving && ($actor->can(ReportingRoles::REVIEW) || $actor->can(ReportingRoles::APPROVE));

        $submissions = Submission::query()->where('obligation_id', $record->id)->orderByDesc('version_no')->get();
        $steps = ReviewStep::query()->whereIn('submission_id', $submissions->modelKeys())->orderBy('step_no')->get()->groupBy('submission_id');
        $files = $content ? Attachment::query()->where('obligation_id', $record->id)->whereNotNull('submission_id')->orderBy('created_at')->get()->groupBy('submission_id') : collect();
        $artifacts = $content ? Artifact::query()->where('owner_type', 'submission')->whereIn('owner_id', $submissions->modelKeys())->orderBy('created_at')->get()->groupBy('owner_id') : collect();

        return Inertia::render('modules/reporting/ObligationShow', [
            'obligation' => Present::obligation($record),
            'content_visible' => $content,
            'submissions' => $submissions->map(fn (Submission $s): array => [
                ...Present::submission($s, $content),
                'steps' => ($steps[$s->id] ?? collect())->map(fn (ReviewStep $step): array => Present::step($step))->values()->all(),
                'attachments' => ($files[$s->id] ?? collect())->map(fn (Attachment $a): array => Present::attachment($a))->values()->all(),
                'artifacts' => ($artifacts[$s->id] ?? collect())->map(fn (Artifact $a): array => Present::artifact($a))->values()->all(),
            ])->all(),
            // Internal reviewer notes go only to reviewers of the receiving side.
            'comments' => Comment::query()->where('obligation_id', $record->id)->when(! $reviewer, fn ($q) => $q->where('visibility', 'submitter'))
                ->orderBy('created_at')->get()->map(fn (Comment $c): array => Present::comment($c))->all(),
            'extensions' => Extension::query()->where('obligation_id', $record->id)->orderByDesc('requested_at')->get()->map(fn (Extension $e): array => Present::extension($e))->all(),
            'applicability_decisions' => ApplicabilityDecision::query()->where('obligation_id', $record->id)->orderByDesc('effective_at')->get()
                ->map(fn (ApplicabilityDecision $d): array => ['applicability' => $d->applicability, 'reason' => $d->reason, 'decided_by_name' => $d->decided_by_name, 'effective_at' => $d->effective_at->toIso8601String()])->all(),
            'follow_ups' => FollowUp::query()->with('updates')->where('obligation_id', $record->id)->orderBy('due_at')->get()->map(fn (FollowUp $f): array => Present::followUp($f, true))->all(),
            'history' => ObligationEvent::query()->where('obligation_id', $record->id)->orderByDesc('created_at')->limit(200)->get()
                ->map(fn (ObligationEvent $e): array => ['id' => $e->id, 'event' => $e->event, 'actor_name' => $e->actor_name, 'data' => $e->data, 'created_at' => $e->created_at->toIso8601String()])->all(),
            'can' => [
                'prepare' => $reporting && $actor->can(ReportingRoles::PREPARE) && $record->workflow_status->isEditable() && $record->applicability === Applicability::Required,
                'request_extension' => $reporting && ($actor->can(ReportingRoles::PREPARE) || $actor->can(ReportingRoles::SUBMIT)) && $record->applicability === Applicability::Required,
                'decide_extension' => $receiving && $actor->can(ReportingRoles::GRANT_EXTENSIONS),
                'set_applicability' => $receiving && $actor->can(ReportingRoles::GRANT_EXTENSIONS),
                'review' => $reviewer && $record->workflow_status->awaitsDecision(),
                'reopen' => $receiving && $actor->can(ReportingRoles::REOPEN) && $record->workflow_status->isFinal(),
                'reassign' => ($reporting && $actor->can(ReportingRoles::SUBMIT)) || ($receiving && $actor->can(ReportingRoles::PUBLISH_SCHEDULES)),
                'follow_up' => $reviewer,
                'comment_internal' => $reviewer,
                'comment' => $reviewer || ($reporting && ($actor->can(ReportingRoles::PREPARE) || $actor->can(ReportingRoles::SUBMIT))),
            ],
        ]);
    }

    public function requestExtension(Request $request, string $obligation, ManageDeadlines $deadlines): RedirectResponse
    {
        $data = $request->validate(['requested_due_at' => ['required', 'date'], 'reason' => ['required', 'string', 'max:2000']]);
        // The form gives local business time; it is stored in UTC.
        $deadlines->requestExtension($this->actor($request), $obligation, CarbonImmutable::parse($data['requested_due_at'], 'Africa/Addis_Ababa')->utc()->toDateTimeImmutable(), $data['reason']);

        return back()->with('success', __('reporting::messages.flash.extension_requested'));
    }

    public function decideExtension(Request $request, string $extension, ManageDeadlines $deadlines): RedirectResponse
    {
        $data = $request->validate(['approve' => ['required', 'boolean'], 'reason' => ['required', 'string', 'max:2000'], 'approved_due_at' => ['nullable', 'date']]);
        $deadlines->decideExtension($this->actor($request), $extension, (bool) $data['approve'], $data['reason'],
            isset($data['approved_due_at']) ? CarbonImmutable::parse($data['approved_due_at'], 'Africa/Addis_Ababa')->utc()->toDateTimeImmutable() : null);

        return back()->with('success', __('reporting::messages.flash.extension_decided'));
    }

    public function applicability(Request $request, string $obligation, ManageDeadlines $deadlines): RedirectResponse
    {
        $data = $request->validate(['applicability' => ['required', Rule::enum(Applicability::class)], 'reason' => ['required', 'string', 'max:2000']]);
        $deadlines->setApplicability($this->actor($request), $obligation, Applicability::from($data['applicability']), $data['reason']);

        return back()->with('success', __('reporting::messages.flash.applicability_changed'));
    }

    public function responsible(Request $request, string $obligation, ManageDeadlines $deadlines): RedirectResponse
    {
        $data = $request->validate(['preparer_user_id' => ['nullable', 'integer'], 'accountable_user_id' => ['nullable', 'integer'], 'reason' => ['required', 'string', 'max:2000']]);
        $deadlines->reassignResponsible($this->actor($request), $obligation, isset($data['preparer_user_id']) ? (int) $data['preparer_user_id'] : null, isset($data['accountable_user_id']) ? (int) $data['accountable_user_id'] : null, $data['reason']);

        return back()->with('success', __('reporting::messages.flash.reassigned'));
    }

    public function comment(Request $request, string $obligation, ReviewReports $reviews): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:5000'], 'section_key' => ['nullable', 'string', 'max:60'], 'visibility' => ['required', Rule::in(['submitter', 'internal'])]]);
        $reviews->comment($this->actor($request), $obligation, $data['body'], $data['section_key'] ?? null, $data['visibility']);

        return back()->with('success', __('reporting::messages.flash.saved'));
    }

    public function reopen(Request $request, string $obligation, ReviewReports $reviews): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $reviews->reopen($this->actor($request), $obligation, $data['reason']);

        return back()->with('success', __('reporting::messages.flash.reopened'));
    }
}
