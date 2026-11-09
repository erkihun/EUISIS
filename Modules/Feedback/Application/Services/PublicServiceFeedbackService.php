<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Services;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\EmployeeAssignment;
use App\Models\PositionService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Feedback\Contracts\Data\ServiceFeedbackType;
use Modules\Feedback\Domain\Models\EmployeeFeedbackToken;
use Modules\Feedback\Domain\Models\EmployeeServiceFeedback;

/**
 * The employee side of the anonymous public feedback flow.
 *
 * The governing rule is minimal disclosure. A scanned QR of an active
 * employee shows the visitor the employee's photo and full name —
 * enough to be sure they are writing about the person who served them — and
 * nothing more (PublicEmployeeFeedbackIdentity). The employee's number,
 * phone, email, national ID, salary, address and HR history never enter a
 * payload built here.
 *
 * This is a deliberate contrast with the ID Checker, which reveals the card
 * holder's details only after the holder relays an OTP. Feedback has no such
 * consent step, so it shows only what the card itself shows to anyone facing
 * the holder: the photo and the name.
 *
 * Submissions themselves are written by PublicFeedbackSubmissionService for
 * every target type; the methods here keep the employee token entry points.
 */
class PublicServiceFeedbackService
{
    public function __construct(
        private readonly WriteAuditLogAction $writeAuditLogAction,
        private readonly PublicFeedbackTargetResolver $resolver,
        private readonly PublicFeedbackSubmissionService $submissions,
    ) {}

    /**
     * Services offered in the public dropdown for one position.
     *
     * A service belongs to the POSITION that performs it, so the client sees
     * only what this officer's role actually delivers — the list that makes a
     * rating meaningful for performance evaluation. An officer is never rated
     * on work their post does not do.
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
     * Persist a rated submission about the scanned employee.
     *
     * @param  array{position_service_id: string, rating: int, comment?: string|null, client_name?: string|null, client_contact?: string|null}  $data
     */
    public function submit(EmployeeFeedbackToken $token, array $data, Request $request): EmployeeServiceFeedback
    {
        return $this->submissions->submit($this->contextFor($token), ServiceFeedbackType::Feedback, $data, $request);
    }

    /**
     * Suggestion and Complaint share the QR resolver, but remain separate
     * submission types. Neither creates a performance or disciplinary action.
     *
     * @param  array<string, mixed>  $data
     */
    public function submitEntry(EmployeeFeedbackToken $token, ServiceFeedbackType $type, array $data, Request $request): EmployeeServiceFeedback
    {
        if ($type === ServiceFeedbackType::Feedback) {
            throw new \InvalidArgumentException('Use the rated feedback submission path.');
        }

        return $this->submissions->submit($this->contextFor($token), $type, $data, $request);
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

    private function contextFor(EmployeeFeedbackToken $token): TargetContext
    {
        $context = $this->resolver->resolveEmployeeToken($token->token);

        if ($context === null) {
            throw new \RuntimeException('The feedback token no longer resolves to an employee.');
        }

        return $context;
    }

    private function currentAssignment(string $employeeId): ?EmployeeAssignment
    {
        return EmployeeAssignment::query()
            ->where('employee_id', $employeeId)
            ->where('is_current', true)
            ->latest('effective_from')
            ->first();
    }
}
