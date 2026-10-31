<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\ServiceMonitoring\Application\Actions\AcceptSubmission;
use Modules\ServiceMonitoring\Application\Actions\AddReviewComment;
use Modules\ServiceMonitoring\Application\Actions\DecideCorrection;
use Modules\ServiceMonitoring\Application\Actions\RequestCorrection;
use Modules\ServiceMonitoring\Application\Actions\ReturnSubmission;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAccess;
use Modules\ServiceMonitoring\Domain\Enums\CorrectionStatus;
use Modules\ServiceMonitoring\Domain\Enums\SubmissionStatus;
use Modules\ServiceMonitoring\Domain\Models\CorrectionRequest;
use Modules\ServiceMonitoring\Domain\Models\Submission;
use Modules\ServiceMonitoring\Http\Requests\AddReviewCommentRequest;
use Modules\ServiceMonitoring\Http\Requests\DecideCorrectionRequest;
use Modules\ServiceMonitoring\Http\Requests\RequestCorrectionRequest;
use Modules\ServiceMonitoring\Http\Requests\ReturnSubmissionRequest;

/** The review queue: return with line comments, accept, and correction requests (D8, D9). */
final class ReviewController extends Controller
{
    public function __construct(private readonly ServiceMonitoringAccess $access) {}

    public function queue(Request $request): Response
    {
        abort_unless($request->user()->can('service_monitoring.review'), 403);
        $user = $request->user();

        $pending = $this->access->scopeSubmissions(Submission::query(), $user)
            ->where('status', SubmissionStatus::Submitted->value)
            ->with(['organization:id,name_en,name_am', 'period.fiscalYear'])
            ->orderBy('submitted_at')->limit(200)->get()
            ->filter(fn (Submission $s): bool => $this->access->hasReviewerReach($user, $s))->values();
        $corrections = CorrectionRequest::query()->where('status', CorrectionStatus::Pending->value)
            ->whereHas('submission', fn ($q) => $this->access->scopeSubmissions($q, $user))
            ->with(['submission.organization:id,name_en,name_am', 'submission.period.fiscalYear', 'requester:id,name'])->latest()->limit(100)->get()
            ->filter(fn (CorrectionRequest $c): bool => $this->access->canDecideCorrection($user, $c->submission))->values();

        return Inertia::render('ServiceMonitoring/Review/Queue', [
            'pending' => $pending->map(fn (Submission $s): array => [
                'id' => $s->id, 'level' => $s->level->value, 'form_type' => $s->form_type->value, 'revision_no' => $s->revision_no,
                'submitted_at' => $s->submitted_at?->toIso8601String(), 'submitted_late' => $s->submitted_late, 'period_label' => $s->period->label(),
                'organization' => ['name_en' => $s->organization?->name_en, 'name_am' => $s->organization?->name_am],
                'can_review' => $this->access->canReview($user, $s),
            ])->all(),
            'corrections' => $corrections->map(fn (CorrectionRequest $c): array => [
                'id' => $c->id, 'reason' => $c->reason, 'requested_by' => $c->requester?->name, 'created_at' => $c->created_at?->toIso8601String(),
                'submission_id' => $c->submission_id, 'period_label' => $c->submission->period->label(),
                'organization' => ['name_en' => $c->submission->organization?->name_en, 'name_am' => $c->submission->organization?->name_am],
            ])->all(),
        ]);
    }

    public function returnSubmission(ReturnSubmissionRequest $request, Submission $submission, ReturnSubmission $return): RedirectResponse
    {
        $return->execute($submission, $request->user(), (string) $request->validated('reason'), (array) ($request->validated('comments') ?? []));

        return redirect()->route('service-monitoring.review.queue')->with('success', __('service-monitoring.flash.returned'));
    }

    public function accept(Request $request, Submission $submission, AcceptSubmission $accept): RedirectResponse
    {
        $this->authorize('review', $submission);
        $accept->execute($submission, $request->user());

        return redirect()->route('service-monitoring.review.queue')->with('success', __('service-monitoring.flash.accepted'));
    }

    public function comment(AddReviewCommentRequest $request, Submission $submission, AddReviewComment $add): RedirectResponse
    {
        $add->execute($submission, (int) $request->validated('section'), $request->validated('line_reference'), (string) $request->validated('body'), $request->user());

        return back()->with('success', __('service-monitoring.flash.comment_added'));
    }

    public function requestCorrection(RequestCorrectionRequest $request, Submission $submission, RequestCorrection $requestCorrection): RedirectResponse
    {
        $requestCorrection->execute($submission, $request->user(), (string) $request->validated('reason'));

        return back()->with('success', __('service-monitoring.flash.correction_requested'));
    }

    public function decideCorrection(DecideCorrectionRequest $request, CorrectionRequest $correction, DecideCorrection $decide): RedirectResponse
    {
        $decided = $decide->execute($correction, $request->user(), (bool) $request->validated('approve'), $request->validated('note'));

        return back()->with('success', __('service-monitoring.flash.correction_decided'))->with('correction_submission_id', $decided->new_submission_id);
    }
}
