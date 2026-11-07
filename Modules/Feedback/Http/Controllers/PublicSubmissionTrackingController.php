<?php

declare(strict_types=1);

namespace Modules\Feedback\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Feedback\Contracts\Data\ServiceFeedbackStatus;
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
 */
class PublicSubmissionTrackingController extends Controller
{
    public function __construct(private readonly PublicSubmissionTypes $types) {}

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

        $lastChange = $entry->statusHistory()->latest('transitioned_at')->value('transitioned_at');

        return redirect()->route('public-submissions.track', ['reference' => $reference])->with('tracking_result', [
            'reference' => $entry->reference_number,
            'type' => $entry->type->value,
            'stage' => self::stage($entry->status),
            'received_at' => $entry->submitted_at?->toIso8601String(),
            'updated_at' => ($lastChange ? Carbon::parse($lastChange) : $entry->updated_at)?->toIso8601String(),
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
