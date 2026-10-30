<?php

declare(strict_types=1);

namespace App\Http\Controllers\Grievances;

use App\Enums\Grievance\GrievanceConfidentiality;
use App\Enums\Grievance\GrievanceCustodyAction;
use App\Enums\Grievance\GrievanceEvidenceType;
use App\Http\Controllers\Controller;
use App\Models\Grievance;
use App\Models\GrievanceEvidence;
use App\Services\Grievances\GrievanceCaseAccessService;
use App\Services\Grievances\GrievanceEvidenceService;
use App\Services\Grievances\GrievanceSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Handler-side evidence: upload, accept/reject, classify, new version, custody, download. */
class GrievanceEvidenceController extends Controller
{
    public function __construct(
        private readonly GrievanceEvidenceService $evidence,
        private readonly GrievanceCaseAccessService $access,
        private readonly GrievanceSettings $settings,
    ) {}

    public function store(Request $request, Grievance $grievance): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.$this->settings->evidenceMaxSizeKb()],
            'evidence_type' => ['required', Rule::in(GrievanceEvidenceType::values())],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'classification' => ['nullable', Rule::in(GrievanceConfidentiality::values())],
        ]);
        $this->evidence->upload($grievance, $request->file('file'), $data, $request->user());

        return back()->with('flash', ['message' => __('grievances.flash.evidence_uploaded'), 'type' => 'success']);
    }

    public function decide(Request $request, Grievance $grievance, GrievanceEvidence $evidence): RedirectResponse
    {
        abort_unless($evidence->grievance_id === $grievance->getKey(), 404);
        $data = $request->validate(['action' => ['required', 'in:accept,reject'], 'reason' => ['required_if:action,reject', 'nullable', 'string', 'max:2000']]);
        $data['action'] === 'accept'
            ? $this->evidence->accept($evidence, $request->user())
            : $this->evidence->reject($evidence, $request->user(), (string) $data['reason']);

        return back()->with('flash', ['message' => __('grievances.flash.saved'), 'type' => 'success']);
    }

    public function classify(Request $request, Grievance $grievance, GrievanceEvidence $evidence): RedirectResponse
    {
        abort_unless($evidence->grievance_id === $grievance->getKey(), 404);
        $data = $request->validate(['classification' => ['required', Rule::in(GrievanceConfidentiality::values())]]);
        $this->evidence->classify($evidence, $request->user(), GrievanceConfidentiality::from($data['classification']));

        return back()->with('flash', ['message' => __('grievances.flash.saved'), 'type' => 'success']);
    }

    public function supersede(Request $request, Grievance $grievance, GrievanceEvidence $evidence): RedirectResponse
    {
        abort_unless($evidence->grievance_id === $grievance->getKey(), 404);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.$this->settings->evidenceMaxSizeKb()],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->evidence->supersede($evidence, $request->file('file'), $request->user(), $data['description'] ?? null);

        return back()->with('flash', ['message' => __('grievances.flash.evidence_uploaded'), 'type' => 'success']);
    }

    public function custody(Request $request, Grievance $grievance, GrievanceEvidence $evidence): JsonResponse
    {
        abort_unless($evidence->grievance_id === $grievance->getKey() && $this->access->canViewEvidence($request->user(), $evidence) && $this->access->canSeeInternal($request->user(), $grievance), 404);
        $this->evidence->custody($evidence, GrievanceCustodyAction::Viewed, $request->user(), 'custody log');

        return response()->json($evidence->custody()->with('actor:id,name')->orderBy('occurred_at')->get()->map(fn ($c) => [
            'action' => $c->action->value, 'actor' => $c->actor?->name, 'ip_address' => $c->ip_address, 'notes' => $c->notes, 'occurred_at' => $c->occurred_at?->toIso8601String(),
        ]));
    }

    public function download(Request $request, Grievance $grievance, GrievanceEvidence $evidence): StreamedResponse
    {
        abort_unless($evidence->grievance_id === $grievance->getKey(), 404);

        return $this->evidence->download($evidence, $request->user());
    }
}
