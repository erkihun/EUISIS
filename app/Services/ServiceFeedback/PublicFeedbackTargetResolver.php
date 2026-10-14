<?php

declare(strict_types=1);

namespace App\Services\ServiceFeedback;

use App\Enums\EmployeeStatus;
use App\Enums\PublicFeedbackSourceType;
use App\Enums\PublicFeedbackTargetType;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeFeedbackToken;

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

        $assignment = EmployeeAssignment::query()
            ->with([
                'organization:id,code,name_en,name_am',
                'organizationUnit:id,code,name_en,name_am',
                'position:id,job_position_code,title_en,title_am',
            ])
            ->where('employee_id', $record->employee_id)
            ->where('is_current', true)
            ->latest('effective_from')
            ->first();

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
                'logo_url' => $organization->logo_url,
                'short_name' => null,
            ],
        );
    }
}
