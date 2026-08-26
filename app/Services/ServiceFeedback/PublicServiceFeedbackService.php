<?php

declare(strict_types=1);

namespace App\Services\ServiceFeedback;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\ServiceFeedbackStatus;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeFeedbackToken;
use App\Models\EmployeeServiceFeedback;
use App\Models\PositionService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The anonymous client feedback flow.
 *
 * The governing rule is that NOTHING identifying about the employee crosses
 * this boundary. A scanned QR tells the client which office and role served
 * them — enough to be sure they are rating the right desk — and nothing more.
 * The employee's name, phone, email, national ID, salary and address never
 * enter a payload built here.
 *
 * This is a deliberate contrast with the ID Checker, which reveals identity
 * only after the holder relays an OTP. Feedback has no such consent step, so
 * it compensates by revealing no person at all.
 */
class PublicServiceFeedbackService
{
    public function __construct(
        private readonly WriteAuditLogAction $writeAuditLogAction,
        private readonly EmployeeFeedbackTokenService $tokens,
    ) {}

    /**
     * Build the safe, public-facing context for a scanned token.
     *
     * @return array{
     *     display_name: string,
     *     organization: string|null,
     *     organization_unit: string|null,
     *     position: string|null
     * }
     */
    public function publicContext(EmployeeFeedbackToken $token, string $locale = 'en'): array
    {
        $assignment = $this->currentAssignment($token->employee_id);

        return [
            /*
             * A masked label, never the employee's name. The client already
             * knows who served them; printing the name here would turn a
             * public URL into an unauthenticated name lookup for anyone who
             * photographs a QR on a desk.
             */
            'display_name' => $this->maskedDisplayName($assignment, $locale),
            'organization' => $this->localized($assignment?->organization, 'name', $locale),
            'organization_unit' => $this->localized($assignment?->organizationUnit, 'name', $locale),
            'position' => $this->localized($assignment?->position, 'title', $locale),
        ];
    }

    /**
     * Services offered in the public dropdown for one position.
     *
     * A service belongs to the POSITION that performs it, so the client sees
     * only what this officer's role actually delivers — the list that makes a
     * rating meaningful for performance evaluation. An officer is never rated
     * on work their post does not do.
     *
     * Returns an empty collection when the position offers nothing, which the
     * page renders as an explanatory message rather than a dead select.
     *
     * @return Collection<int, array{id: string, service_no: string, name: string}>
     */
    public function serviceOptions(?string $positionId, string $locale = 'en'): Collection
    {
        return PositionService::query()
            ->forPosition($positionId)
            ->get()
            ->map(fn (PositionService $service): array => [
                'id' => (string) $service->getKey(),
                'service_no' => (string) $service->service_no,
                'name' => $locale === 'am' && $service->name_am !== null && $service->name_am !== ''
                    ? $service->name_am
                    : $service->name_en,
            ])
            ->values();
    }

    /** The position an employee currently holds, or null when unassigned. */
    public function positionIdForToken(EmployeeFeedbackToken $token): ?string
    {
        return $this->currentAssignment($token->employee_id)?->position_id;
    }

    /**
     * Is this service actually delivered by the scanned employee's position?
     *
     * The dropdown narrows the choices, but a submission is just a POST: a
     * caller can name any id, including one belonging to a different post.
     * The server decides what this officer may be rated on.
     */
    public function serviceIsAvailable(string $positionServiceId, ?string $positionId): bool
    {
        return PositionService::query()
            ->forPosition($positionId)
            ->whereKey($positionServiceId)
            ->exists();
    }

    /**
     * Persist a client submission.
     *
     * Organization, unit and position are snapshotted from the employee's
     * assignment AT THIS MOMENT rather than joined later, so a subsequent
     * transfer cannot retroactively move this feedback to a different office.
     *
     * @param  array{position_service_id: string, rating: int, comment?: string|null, client_name?: string|null, client_contact?: string|null}  $data
     */
    public function submit(EmployeeFeedbackToken $token, array $data, Request $request): EmployeeServiceFeedback
    {
        $assignment = $this->currentAssignment($token->employee_id);
        $service = PositionService::query()->find($data['position_service_id']);

        $feedback = EmployeeServiceFeedback::query()->create([
            'employee_id' => $token->employee_id,
            'employee_feedback_token_id' => $token->getKey(),
            'organization_id' => $assignment?->organization_id,
            'organization_unit_id' => $assignment?->organization_unit_id,
            'position_id' => $assignment?->position_id,
            'position_service_id' => $data['position_service_id'],
            /*
             * Snapshot the service identity. It can be renamed, renumbered or
             * deactivated long after a client rated it, and a performance
             * report must keep describing what was actually rated.
             */
            'service_no_snapshot' => $service?->service_no,
            'service_name_snapshot' => $service?->name_en,
            'rating' => $data['rating'],
            'comment' => $this->trimmedOrNull($data['comment'] ?? null),
            'client_name' => $this->trimmedOrNull($data['client_name'] ?? null),
            'client_contact' => $this->trimmedOrNull($data['client_contact'] ?? null),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'status' => ServiceFeedbackStatus::Pending,
        ]);

        $this->writeAuditLogAction->execute(
            eventType: AuditEventType::ServiceFeedbackSubmitted,
            // Anonymous by design: there is no actor to attribute this to.
            actor: null,
            auditable: $feedback,
            organizationId: $assignment?->organization_id,
            reason: 'Client service feedback submitted (rating '.$data['rating'].')',
            request: $request,
        );

        return $feedback;
    }

    /** Log a scan against a token that cannot accept feedback. */
    public function auditBlockedScan(?Request $request = null): void
    {
        $this->writeAuditLogAction->execute(
            eventType: AuditEventType::ServiceFeedbackBlocked,
            actor: null,
            reason: 'Service feedback scan for unknown or inactive token',
            request: $request,
        );
    }

    private function currentAssignment(string $employeeId): ?EmployeeAssignment
    {
        return EmployeeAssignment::query()
            ->with(['organization', 'organizationUnit', 'position'])
            ->where('employee_id', $employeeId)
            ->where('is_current', true)
            ->latest('effective_from')
            ->first();
    }

    /**
     * A role-and-place label that identifies the desk, not the person.
     *
     * Falls back progressively so the page always has something meaningful to
     * show even for an employee with no assignment on file.
     */
    private function maskedDisplayName(?EmployeeAssignment $assignment, string $locale): string
    {
        $position = $this->localized($assignment?->position, 'title', $locale);

        if ($position !== null && $position !== '') {
            return $position;
        }

        $unit = $this->localized($assignment?->organizationUnit, 'name', $locale);

        if ($unit !== null && $unit !== '') {
            return $unit;
        }

        return $locale === 'am' ? 'የአገልግሎት ሰጪ' : 'Service Officer';
    }

    /** Pick the `{field}_am` / `{field}_en` variant, falling back to the other. */
    private function localized(mixed $model, string $field, string $locale): ?string
    {
        if ($model === null) {
            return null;
        }

        $preferred = $locale === 'am' ? $field.'_am' : $field.'_en';
        $fallback = $locale === 'am' ? $field.'_en' : $field.'_am';

        $value = trim((string) ($model->{$preferred} ?? ''));

        if ($value !== '') {
            return $value;
        }

        $value = trim((string) ($model->{$fallback} ?? ''));

        return $value !== '' ? $value : null;
    }

    private function trimmedOrNull(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
