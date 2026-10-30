<?php

declare(strict_types=1);

namespace App\Http\Controllers\Grievances;

use App\Enums\Grievance\GrievanceHearingMode;
use App\Enums\Grievance\GrievanceHearingStatus;
use App\Enums\Grievance\GrievanceInformationTarget;
use App\Enums\Grievance\GrievanceParticipantRole;
use App\Http\Controllers\Controller;
use App\Models\Grievance;
use App\Models\GrievanceHearing;
use App\Models\GrievanceInformationRequest;
use App\Models\GrievanceMinutes;
use App\Services\Grievances\GrievanceHearingService;
use App\Services\Grievances\GrievanceInformationService;
use App\Services\Grievances\GrievanceSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Information requests, hearings and minutes on a case. */
class GrievanceProceedingsController extends Controller
{
    public function __construct(
        private readonly GrievanceInformationService $information,
        private readonly GrievanceHearingService $hearings,
        private readonly GrievanceSettings $settings,
    ) {}

    public function storeInformationRequest(Request $request, Grievance $grievance): RedirectResponse
    {
        $data = $request->validate([
            'requested_from_type' => ['required', Rule::in(GrievanceInformationTarget::values())],
            'requested_from_id' => ['nullable', 'string', 'max:64'],
            'requested_from_name' => ['required_if:requested_from_type,external,hr', 'nullable', 'string', 'max:255'],
            'request_text' => ['required', 'string', 'max:10000'],
            'due_at' => ['nullable', 'date', 'after:now'],
            'pauses_sla' => ['nullable', 'boolean'],
        ]);
        $this->information->request($grievance, $request->user(), $data);

        return back()->with('flash', ['message' => __('grievances.flash.information_requested'), 'type' => 'success']);
    }

    public function recordResponse(Request $request, Grievance $grievance, GrievanceInformationRequest $informationRequest): RedirectResponse
    {
        abort_unless($informationRequest->grievance_id === $grievance->getKey(), 404);
        $data = $request->validate([
            'response_text' => ['required', 'string', 'max:10000'],
            'files' => ['nullable', 'array', 'max:5'],
            'files.*' => ['file', 'max:'.$this->settings->evidenceMaxSizeKb()],
        ]);
        $this->information->respond($informationRequest, $request->user(), $data['response_text'], $request->file('files', []));

        return back()->with('flash', ['message' => __('grievances.flash.response_recorded'), 'type' => 'success']);
    }

    public function closeInformationRequest(Request $request, Grievance $grievance, GrievanceInformationRequest $informationRequest): RedirectResponse
    {
        abort_unless($informationRequest->grievance_id === $grievance->getKey(), 404);
        $data = $request->validate(['cancel' => ['nullable', 'boolean']]);
        $this->information->close($informationRequest, $request->user(), (bool) ($data['cancel'] ?? false));

        return back()->with('flash', ['message' => __('grievances.flash.saved'), 'type' => 'success']);
    }

    public function storeHearing(Request $request, Grievance $grievance): RedirectResponse
    {
        $data = $request->validate([
            'scheduled_at' => ['required', 'date', 'after:now'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:600'],
            'location' => ['required_unless:mode,virtual', 'nullable', 'string', 'max:255'],
            'mode' => ['required', Rule::in(GrievanceHearingMode::values())],
            'meeting_link' => ['required_if:mode,virtual', 'nullable', 'string', 'max:500'],
            'agenda' => ['nullable', 'string', 'max:4000'],
            'participants' => ['nullable', 'array', 'max:30'],
            'participants.*.role' => ['required', Rule::in(GrievanceParticipantRole::values())],
            'participants.*.employee_id' => ['nullable', 'uuid', 'exists:employees,id'],
            'participants.*.name' => ['nullable', 'string', 'max:255'],
            'participants.*.affiliation' => ['nullable', 'string', 'max:255'],
            'participants.*.contact' => ['nullable', 'string', 'max:255'],
        ]);
        $this->hearings->schedule($grievance, $request->user(), $data);

        return back()->with('flash', ['message' => __('grievances.flash.hearing_scheduled'), 'type' => 'success']);
    }

    public function updateHearing(Request $request, Grievance $grievance, GrievanceHearing $hearing): RedirectResponse
    {
        abort_unless($hearing->grievance_id === $grievance->getKey(), 404);
        $data = $request->validate([
            'status' => ['required', Rule::in(GrievanceHearingStatus::values())],
            'scheduled_at' => ['nullable', 'date'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'cancellation_reason' => ['nullable', 'string', 'max:2000'],
            'attendance' => ['nullable', 'array'],
            'attendance.*' => ['in:invited,attended,absent,excused'],
        ]);
        $this->hearings->update($hearing, $request->user(), $data);

        return back()->with('flash', ['message' => __('grievances.flash.saved'), 'type' => 'success']);
    }

    public function storeMinutes(Request $request, Grievance $grievance): RedirectResponse
    {
        $this->hearings->draftMinutes($grievance, $request->user(), $this->validateMinutes($request));

        return back()->with('flash', ['message' => __('grievances.flash.saved'), 'type' => 'success']);
    }

    public function updateMinutes(Request $request, Grievance $grievance, GrievanceMinutes $minutes): RedirectResponse
    {
        abort_unless($minutes->grievance_id === $grievance->getKey(), 404);
        $action = $request->string('action')->value();
        match ($action) {
            'confirm' => $this->hearings->confirmMinutes($minutes, $request->user()),
            'amend' => $this->hearings->amendMinutes($minutes, $request->user(), (string) $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason']),
            default => $this->hearings->updateMinutes($minutes, $request->user(), $this->validateMinutes($request)),
        };

        return back()->with('flash', ['message' => __('grievances.flash.saved'), 'type' => 'success']);
    }

    /** @return array<string, mixed> */
    private function validateMinutes(Request $request): array
    {
        return $request->validate([
            'hearing_id' => ['nullable', 'uuid'],
            'meeting_date' => ['required', 'date', 'before_or_equal:today'],
            'summary' => ['required', 'string', 'max:10000'],
            'discussion' => ['nullable', 'string', 'max:20000'],
            'resolutions' => ['nullable', 'string', 'max:10000'],
            'attendees' => ['nullable', 'array', 'max:50'],
            'attendees.*' => ['string', 'max:255'],
        ]);
    }
}
