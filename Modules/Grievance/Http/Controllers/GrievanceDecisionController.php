<?php

declare(strict_types=1);

namespace App\Http\Controllers\Grievances;

use App\Enums\Grievance\GrievanceDecisionType;
use App\Enums\Grievance\GrievanceVoteType;
use App\Http\Controllers\Controller;
use App\Models\Grievance;
use App\Models\GrievanceDecision;
use App\Services\Grievances\GrievanceDecisionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Decision drafting, internal review, executive approval and finalization. */
class GrievanceDecisionController extends Controller
{
    public function __construct(private readonly GrievanceDecisionService $decisions) {}

    public function store(Request $request, Grievance $grievance): RedirectResponse
    {
        $this->decisions->createDraft($grievance, $request->user(), $this->validateContent($request));

        return back()->with('flash', ['message' => __('grievances.flash.decision_drafted'), 'type' => 'success']);
    }

    public function update(Request $request, Grievance $grievance, GrievanceDecision $decision): RedirectResponse
    {
        $this->owned($grievance, $decision);
        $this->decisions->updateDraft($decision, $request->user(), $this->validateContent($request));

        return back()->with('flash', ['message' => __('grievances.flash.saved'), 'type' => 'success']);
    }

    public function revise(Request $request, Grievance $grievance, GrievanceDecision $decision): RedirectResponse
    {
        $this->owned($grievance, $decision);
        $this->decisions->createRevision($decision, $request->user());

        return back()->with('flash', ['message' => __('grievances.flash.decision_revised'), 'type' => 'success']);
    }

    public function transition(Request $request, Grievance $grievance, GrievanceDecision $decision): RedirectResponse
    {
        $this->owned($grievance, $decision);
        $data = $request->validate([
            'action' => ['required', 'in:submit_for_review,endorse,return_internal,submit_for_approval,approve,return_for_correction,reject,finalize'],
            'comment' => ['nullable', 'string', 'max:4000'],
        ]);
        $user = $request->user();
        $comment = $data['comment'] ?? null;

        match ($data['action']) {
            'submit_for_review' => $this->decisions->submitForReview($decision, $user),
            'endorse' => $this->decisions->internalReview($decision, $user, true, $comment),
            'return_internal' => $this->decisions->internalReview($decision, $user, false, $comment),
            'submit_for_approval' => $this->decisions->submitForApproval($decision, $user),
            'approve' => $this->decisions->approve($decision, $user, $comment),
            'return_for_correction' => $this->decisions->returnForCorrection($decision, $user, (string) $comment),
            'reject' => $this->decisions->reject($decision, $user, (string) $comment),
            'finalize' => $this->decisions->finalize($decision, $user),
        };

        return back()->with('flash', ['message' => __('grievances.flash.decision_'.$data['action']), 'type' => 'success']);
    }

    public function vote(Request $request, Grievance $grievance, GrievanceDecision $decision): RedirectResponse
    {
        $this->owned($grievance, $decision);
        $data = $request->validate(['vote' => ['required', Rule::in(GrievanceVoteType::values())], 'opinion' => ['nullable', 'string', 'max:4000']]);
        $this->decisions->castVote($decision, $request->user(), GrievanceVoteType::from($data['vote']), $data['opinion'] ?? null);

        return back()->with('flash', ['message' => __('grievances.flash.vote_recorded'), 'type' => 'success']);
    }

    private function owned(Grievance $grievance, GrievanceDecision $decision): void
    {
        abort_unless($decision->grievance_id === $grievance->getKey(), 404);
    }

    /** @return array<string, mixed> */
    private function validateContent(Request $request): array
    {
        $data = $request->validate([
            'decision_type' => ['nullable', Rule::in(GrievanceDecisionType::values())],
            'findings' => ['nullable', 'string', 'max:20000'],
            'facts_considered' => ['nullable', 'string', 'max:20000'],
            'legal_basis' => ['nullable', 'string', 'max:10000'],
            'analysis' => ['nullable', 'string', 'max:20000'],
            'decision_text' => ['nullable', 'string', 'max:20000'],
            'recommendations' => ['nullable', 'string', 'max:10000'],
            'corrective_action_required' => ['nullable', 'boolean'],
            'disciplinary_referral_recommended' => ['nullable', 'boolean'],
        ]);
        $data['decision_text'] = (string) ($data['decision_text'] ?? '');
        $data['corrective_action_required'] = (bool) ($data['corrective_action_required'] ?? false);
        $data['disciplinary_referral_recommended'] = (bool) ($data['disciplinary_referral_recommended'] ?? false);

        return $data;
    }
}
