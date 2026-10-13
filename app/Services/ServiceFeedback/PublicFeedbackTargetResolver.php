<?php

declare(strict_types=1);

namespace App\Services\ServiceFeedback;

use App\Enums\PublicFeedbackSourceType;
use App\Enums\PublicFeedbackTargetType;
use App\Models\Employee;
use App\Models\EmployeeAssignment;

/**
 * The one place a scanned QR becomes a submission target.
 *
 * Employee QR → the employee's own feedback token (unchanged, never rotated
 * by this feature) → the employee plus their current assignment, snapshotted
 * so a later transfer cannot move the submission. Organization QR → the
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
        $record = $this->tokens->resolveForPublic($token);

        if ($record === null) {
            return null;
        }

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

        $employeeNumber = $record->employee?->employee_number ?? Employee::query()->whereKey($record->employee_id)->value('employee_number');

        $names = [
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
        );
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
