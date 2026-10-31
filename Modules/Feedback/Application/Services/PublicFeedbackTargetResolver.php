<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Services;

use App\Enums\AssignmentStatus;
use App\Enums\EmployeeStatus;
use App\Models\EmployeeAssignment;
use Modules\Feedback\Contracts\Data\PublicFeedbackTargetType;
use Modules\Feedback\Domain\Enums\PublicFeedbackSourceType;
use Modules\Feedback\Domain\Models\EmployeeFeedbackToken;

/**
 * The one place a scanned QR becomes a submission target.
 *
 * Employee QR → the employee's own feedback token (unchanged, never rotated
 * by this feature) → the employee, only while active (whatever the state of
 * their ID card), plus their current assignment, snapshotted so a later
 * transfer cannot move the submission. Organization QR → the
 * organization's feedback QR row (Phase 2). Both lookups are direct indexed
 * reads: nothing here scales with the number of employees (brief §98, 102).
 */
class PublicFeedbackTargetResolver
{
    public function __construct(
        private readonly EmployeeFeedbackTokenService $tokens,
        private readonly OrganizationFeedbackQrService $organizationCodes,
    ) {}

    public function resolveEmployeeToken(string $token): ?TargetContext
    {
        $record = $this->employeeTokenForPublic($token);

        if ($record === null) {
            return null;
        }

        $employee = $record->employee;

        $assignment = $this->currentAssignment((string) $record->employee_id);

        $employeeNumber = $employee->employee_number;

        $names = [
            // The ID card's own name rule, so the page shows the name printed on the card.
            'employee' => ['en' => $employee->fullNameEn(), 'am' => $employee->fullNameAm()],
            'organization' => ['en' => $assignment?->organization?->name_en, 'am' => $assignment?->organization?->name_am],
            'organization_unit' => ['en' => $assignment?->organizationUnit?->name_en, 'am' => $assignment?->organizationUnit?->name_am],
            'position' => ['en' => $assignment?->position?->title_en, 'am' => $assignment?->position?->title_am],
        ];

        return new TargetContext(
            targetType: PublicFeedbackTargetType::Employee,
            sourceType: PublicFeedbackSourceType::EmployeeCardQr,
            employeeId: (string) $record->employee_id,
            organizationTargetId: null,
            employeeAssignmentId: $assignment?->getKey(),
            contextOrganizationId: $assignment?->organization_id,
            contextUnitId: $assignment?->organization_unit_id,
            contextPositionId: $assignment?->position_id,
            sourceId: (string) $record->getKey(),
            // The masked role label the public page shows — never the person's name.
            targetNameSnapshot: self::firstFilled($names['position']['en'], $names['organization_unit']['en'], 'Service Officer'),
            targetCodeSnapshot: $employeeNumber !== null ? (string) $employeeNumber : null,
            names: $names,
            extras: [
                // Changes when the photo is replaced, so the page asks for the new one; reveals nothing of the path.
                'photo_version' => $employee->photo_path ? substr(hash('sha256', (string) $employee->photo_path), 0, 16) : null,
                // The Call action: the public number of the institution the employee serves now — never the employee's own.
                'public_contact_phone' => $assignment?->organization?->public_contact_phone,
            ],
        );
    }

    /**
     * A scanned employee token that may serve the public right now: the token
     * accepts feedback and the employee is active. The ID card's status plays
     * no part — the feedback QR follows the employee, not the card.
     *
     * Checked on every page view, photo request and submission, so an
     * employee who leaves service while a visitor fills in the form blocks the
     * send. Unknown, revoked, suspended and inactive cases all return null alike.
     */
    public function employeeTokenForPublic(string $token): ?EmployeeFeedbackToken
    {
        $record = $this->tokens->resolveForPublic($token);

        if ($record === null || $record->employee->status !== EmployeeStatus::Active) {
            return null;
        }

        return $record;
    }

    /**
     * The post the employee holds now: the current assignment in the ACTIVE
     * state — the pair transfers maintain (CompleteTransferAction closes the
     * old row, `closed` and not current, and opens the new one active and
     * current). A pending-transfer row is not yet a place of work and a closed
     * one is history, so neither names the employee's job or context here.
     * No such row → null: the page shows no job designation and the
     * submission carries no organization context.
     */
    private function currentAssignment(string $employeeId): ?EmployeeAssignment
    {
        return EmployeeAssignment::query()
            ->with([
                'organization:id,code,name_en,name_am,public_contact_phone',
                'organizationUnit:id,code,name_en,name_am',
                'position:id,job_position_code,title_en,title_am',
            ])
            ->where('employee_id', $employeeId)
            ->where('is_current', true)
            ->where('assignment_status', AssignmentStatus::Active->value)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    private static function firstFilled(?string ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            if ($candidate !== null && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return '';
    }

    /**
     * An organization's own feedback QR: the organization is the target and
     * its own context. Only an active code of an active organization resolves.
     */
    public function resolveOrganizationToken(string $token): ?TargetContext
    {
        $code = $this->organizationCodes->resolveForPublic($token);

        if ($code === null || $code->organization === null) {
            return null;
        }

        $organization = $code->organization;

        return new TargetContext(
            targetType: PublicFeedbackTargetType::Organization,
            sourceType: PublicFeedbackSourceType::OrganizationFeedbackQr,
            employeeId: null,
            organizationTargetId: (string) $organization->getKey(),
            employeeAssignmentId: null,
            contextOrganizationId: (string) $organization->getKey(),
            contextUnitId: null,
            contextPositionId: null,
            sourceId: (string) $code->getKey(),
            targetNameSnapshot: $organization->name_en,
            targetCodeSnapshot: $organization->code,
            names: [
                'organization' => ['en' => $organization->name_en, 'am' => $organization->name_am],
            ],
            extras: [
                // Through the token (PublicFeedbackOrganizationLogoController), never /storage/…/{organization id}/….
                // The version changes with the logo, so a replaced logo is fetched again.
                'logo_url' => filled($organization->logo_path)
                    ? route('public-feedback.organization.logo', ['token' => $token, 'v' => substr(hash('sha256', (string) $organization->logo_path), 0, 12)], false)
                    : null,
                'short_name' => null,
                'public_contact_phone' => $organization->public_contact_phone,
            ],
        );
    }
}
