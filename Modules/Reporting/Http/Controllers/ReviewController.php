<?php

declare(strict_types=1);

namespace Modules\Reporting\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Reporting\Application\Ports\ReportingAccessResolver;
use Modules\Reporting\Application\Queries\WorkQueues;
use Modules\Reporting\Application\Support\ReportingAccessDenied;
use Modules\Reporting\Application\Support\ReportingGuard;
use Modules\Reporting\Application\UseCases\ReviewReports;
use Modules\Reporting\Contracts\Data\ReportingRoles;
use Modules\Reporting\Domain\Enums\ReviewDecision;
use Modules\Reporting\Http\Support\Present;
use Modules\Reporting\Infrastructure\Persistence\Models\Artifact;
use Modules\Reporting\Infrastructure\Persistence\Models\Attachment;
use Modules\Reporting\Infrastructure\Persistence\Models\Comment;
use Modules\Reporting\Infrastructure\Persistence\Models\Extension;
use Modules\Reporting\Infrastructure\Persistence\Models\Obligation;
use Modules\Reporting\Infrastructure\Persistence\Models\ReviewAssignment;
use Modules\Reporting\Infrastructure\Persistence\Models\ReviewStep;
use Modules\Reporting\Infrastructure\Persistence\Models\Submission;

/** The review inbox and the review of one submitted version. */
final class ReviewController extends ReportingController
{
    public function index(Request $request, WorkQueues $queues): Response
    {
        $actor = $this->actor($request);
        if (! $actor->can(ReportingRoles::REVIEW) && ! $actor->can(ReportingRoles::APPROVE) && ! $actor->can(ReportingRoles::GRANT_EXTENSIONS)) {
            throw new ReportingAccessDenied(ReportingRoles::REVIEW);
        }
        $mine = $request->boolean('mine');
        $page = $queues->reviewInbox($actor, $mine)->paginate(20)->withQueryString();
        $steps = ReviewStep::query()->whereIn('obligation_id', $page->getCollection()->modelKeys())->where('status', 'pending')->get()->keyBy('obligation_id');
        $extensions = $queues->pendingExtensions($actor)->limit(50)->get();
        $extensionObligations = Obligation::query()->with('cycle.reportType')->whereKey($extensions->pluck('obligation_id'))->get()->keyBy('id');

        return Inertia::render('modules/reporting/ReviewInbox', [
            'mine' => $mine,
            'rows' => $page->through(fn (Obligation $o): array => [
                ...Present::obligation($o),
                'step' => isset($steps[$o->id]) ? Present::step($steps[$o->id]) : null,
                'waiting_days' => $o->latest_submitted_at === null ? 0 : (int) $o->latest_submitted_at->diffInDays(now('UTC')),
            ]),
            'extensions' => $extensions->filter(fn (Extension $e): bool => isset($extensionObligations[$e->obligation_id]))
                ->map(fn (Extension $e): array => [...Present::extension($e), 'obligation' => Present::obligation($extensionObligations[$e->obligation_id])])->values()->all(),
        ]);
    }

    public function show(Request $request, string $submission, ReviewReports $reviews, ReportingAccessResolver $access): Response
    {
        $actor = $this->actor($request);
        $record = Submission::query()->findOrFail($submission);
        $obligation = Obligation::query()->with('cycle.reportType')->findOrFail($record->obligation_id);
        ReportingGuard::requireView($actor, $obligation);
        if (! ReportingGuard::canReadContent($actor, $obligation, (string) $obligation->cycle->reportType->confidentiality)) {
            throw new ReportingAccessDenied(ReportingRoles::VIEW_CONFIDENTIAL);
        }
        // A reviewer who can decide the open step taking it up is what "under review" means.
        $reviews->begin($actor, $record->id);
        $obligation->refresh();
        $record->refresh();

        $steps = ReviewStep::query()->where('submission_id', $record->id)->orderBy('step_no')->get();
        $open = $steps->firstWhere('status', 'pending');
        $receiving = ReportingGuard::onReceivingSide($actor, $obligation);
        $reviewer = $receiving && ($actor->can(ReportingRoles::REVIEW) || $actor->can(ReportingRoles::APPROVE));
        $names = $access->users($steps->pluck('reviewer_user_id')->filter()->map(intval(...))->unique()->values()->all());

        return Inertia::render('modules/reporting/ReviewShow', [
            'obligation' => Present::obligation($obligation),
            'submission' => Present::submission($record, true),
            'is_current' => $obligation->latest_submission_id === $record->id,
            'steps' => $steps->map(fn (ReviewStep $s): array => [...Present::step($s), 'reviewer_name' => $s->reviewer_user_id === null ? null : ($names[(int) $s->reviewer_user_id]->name ?? null)])->all(),
            'assignments' => ReviewAssignment::query()->whereIn('review_step_id', $steps->modelKeys())->orderBy('created_at')->get()
                ->map(fn (ReviewAssignment $a): array => ['kind' => $a->kind, 'reason' => $a->reason, 'to_user_id' => $a->to_user_id, 'created_at' => $a->created_at->toIso8601String()])->all(),
            'attachments' => Attachment::query()->where('submission_id', $record->id)->orderBy('created_at')->get()->map(fn (Attachment $a): array => Present::attachment($a))->all(),
            'artifacts' => Artifact::query()->where('owner_type', 'submission')->where('owner_id', $record->id)->get()->map(fn (Artifact $a): array => Present::artifact($a))->all(),
            'previous_versions' => Submission::query()->where('obligation_id', $obligation->id)->where('id', '!=', $record->id)->orderByDesc('version_no')->get()
                ->map(fn (Submission $s): array => Present::submission($s, false))->all(),
            'comments' => Comment::query()->where('obligation_id', $obligation->id)->when(! $reviewer, fn ($q) => $q->where('visibility', 'submitter'))->orderBy('created_at')->get()
                ->map(fn (Comment $c): array => Present::comment($c))->all(),
            'can' => [
                'decide' => $open !== null && $obligation->latest_submission_id === $record->id && $receiving && $actor->can($open->required_permission)
                    && ((int) $record->submitted_by !== $actor->userId || (bool) $obligation->cycle->reportType->allow_self_approval),
                'is_own_submission' => (int) $record->submitted_by === $actor->userId,
                'reassign' => $open !== null && ($receiving && $actor->can($open->required_permission) || (int) $open->reviewer_user_id === $actor->userId),
                'comment_internal' => $reviewer,
                'follow_up' => $reviewer,
            ],
        ]);
    }

    public function decide(Request $request, string $submission, ReviewReports $reviews): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::enum(ReviewDecision::class)],
            'reason' => ['nullable', 'string', 'max:5000'],
            'step_no' => ['required', 'integer', 'min:1'],
        ]);
        $reviews->decide($this->actor($request), $submission, ReviewDecision::from($data['decision']), $data['reason'] ?? null, (int) $data['step_no']);

        return redirect()->route('reporting.review.index')->with('success', __('reporting::messages.flash.decided'));
    }

    public function reassign(Request $request, string $step, ReviewReports $reviews): RedirectResponse
    {
        $data = $request->validate(['to_user_id' => ['required', 'integer'], 'reason' => ['required', 'string', 'max:2000'], 'kind' => ['required', Rule::in(['delegation', 'reassignment'])]]);
        $reviews->reassign($this->actor($request), $step, (int) $data['to_user_id'], $data['reason'], $data['kind']);

        return back()->with('success', __('reporting::messages.flash.reassigned'));
    }
}
