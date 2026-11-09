<?php

declare(strict_types=1);

namespace Modules\Feedback\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Feedback\Application\Services\PublicComplaintStageService;
use Modules\Feedback\Contracts\Data\ServiceFeedbackStatus;
use Modules\Feedback\Contracts\Data\ServiceFeedbackType;
use Modules\Feedback\Contracts\Extensions\PublicSubmissionType;
use Modules\Feedback\Contracts\PublicSubmissionTypes;
use Modules\Feedback\Domain\Models\EmployeeServiceFeedback;

/**
 * Public tracking of a Tip-off or a complaint by its reference (owner request
 * 2026-11-08). References are sequential, so the answer says only where the
 * submission stands and when: never the institution, the person, the content
 * or any handler. A complaint also needs the phone number it was filed with;
 * a Tip-off needs only its reference, so an anonymous tipster can follow it.
 * A wrong reference, a wrong phone and a feedback reference get the same
 * answer, so the page cannot be used to learn which references exist.
 *
 * ADR 0035: a complaint also shows each stage's response, and the
 * complainant who looked it up answers the latest one here — accept it, or
 * appeal to the appeal committee and then to the head of the institution.
 * Answering needs a lookup with the phone in the same browser session in
 * the last 30 minutes; staff names are never shown.
 */
class PublicSubmissionTrackingController extends Controller
{
    private const VERIFIED = 'tracking_verified';

    public function __construct(
        private readonly PublicSubmissionTypes $types,
        private readonly PublicComplaintStageService $stages,
    ) {}

    public function show(Request $request): Response
    {
        return Inertia::render('Public/TrackSubmission', [
            'reference' => (string) $request->query('reference', ''),
            'result' => $request->session()->get('tracking_result'),
        ]);
    }

    public function lookup(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:40'],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);
        $reference = strtoupper(trim($data['reference']));
        // Each type's module says whether the public may track it, and with what (ADR 0032).
        $tracking = collect($this->types->all())->mapWithKeys(fn (PublicSubmissionType $type) => [$type->type()->value => $type->tracking()])->filter();
        $entry = EmployeeServiceFeedback::query()
            ->where('reference_number', $reference)
            ->whereIn('type', $tracking->keys()->all())
            ->first();

        if ($entry === null || ($tracking[$entry->type->value] === 'reference_and_phone' && ! $this->samePhone($entry->client_contact, $data['phone'] ?? null))) {
            throw ValidationException::withMessages(['reference' => __('service-feedback.tracking.not_found')]);
        }

        if ($entry->type === ServiceFeedbackType::Complaint) {
            $request->session()->put(self::VERIFIED, ['reference' => $entry->reference_number, 'until' => now()->addMinutes(30)->getTimestamp()]);
        }

        return $this->showResult($entry);
    }

    /** The complainant accepts the latest decision or appeals it (ADR 0035). */
    public function respond(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:40'],
            'action' => ['required', Rule::in(['accept', 'appeal'])],
            'reason' => ['nullable', 'required_if:action,appeal', 'string', 'min:10', 'max:2000'],
        ], [
            'reason.required_if' => __('service-feedback.stages.appeal_reason_required'),
            'reason.min' => __('service-feedback.stages.appeal_reason_required'),
        ]);
        $reference = strtoupper(trim($data['reference']));
        $verified = $request->session()->get(self::VERIFIED);
        $entry = is_array($verified) && ($verified['reference'] ?? null) === $reference && (int) ($verified['until'] ?? 0) >= now()->getTimestamp()
            ? EmployeeServiceFeedback::query()->where('reference_number', $reference)->where('type', ServiceFeedbackType::Complaint->value)->first()
            : null;
        if ($entry === null) {
            throw ValidationException::withMessages(['reference' => __('service-feedback.stages.verify_again')]);
        }

        if ($data['action'] === 'accept') {
            $this->stages->accept($entry, $request);
        } else {
            $this->stages->appeal($entry, (string) $data['reason'], $request);
        }

        return $this->showResult($entry->refresh())->with('success', __('service-feedback.stages.'.($data['action'] === 'accept' ? 'accepted_done' : 'appealed_done')));
    }

    private function showResult(EmployeeServiceFeedback $entry): RedirectResponse
    {
        $lastChange = $entry->statusHistory()->latest('transitioned_at')->value('transitioned_at');
        $hearing = null;
        if ($entry->type === ServiceFeedbackType::Complaint) {
            // Only what the complainant was told, never who decided.
            $hearing = $this->stages->present($entry, forPublic: true) + [
                'can_accept' => $this->stages->canAccept($entry),
                'can_appeal' => $this->stages->canAppeal($entry),
            ];
        }

        return redirect()->route('public-submissions.track', ['reference' => $entry->reference_number])->with('tracking_result', [
            'reference' => $entry->reference_number,
            'type' => $entry->type->value,
            'stage' => self::stage($entry->status),
            'received_at' => $entry->submitted_at?->toIso8601String(),
            'updated_at' => ($lastChange ? Carbon::parse($lastChange) : $entry->updated_at)?->toIso8601String(),
            'hearing' => $hearing,
        ]);
    }

    /** The four stages a member of the public sees; internal steps are not shown. */
    public static function stage(ServiceFeedbackStatus $status): string
    {
        return match ($status) {
            ServiceFeedbackStatus::Pending, ServiceFeedbackStatus::Unrouted => 'received',
            ServiceFeedbackStatus::Assigned, ServiceFeedbackStatus::UnderReview, ServiceFeedbackStatus::Reviewed => 'in_review',
            ServiceFeedbackStatus::Resolved, ServiceFeedbackStatus::Accepted, ServiceFeedbackStatus::NotAdopted => 'decided',
            ServiceFeedbackStatus::Closed, ServiceFeedbackStatus::Hidden => 'closed',
        };
    }

    /** The same number when the last nine digits match (0911…, +251 911…, 251911…). */
    private function samePhone(?string $stored, ?string $given): bool
    {
        $digits = fn (?string $value): string => substr(preg_replace('/\D+/', '', (string) $value) ?? '', -9);

        return $digits($stored) !== '' && strlen($digits($given)) === 9 && hash_equals($digits($stored), $digits($given));
    }
}
