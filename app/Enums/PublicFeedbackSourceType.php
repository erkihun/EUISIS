<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which QR produced a submission. Distinct from the target: the source is
 * the credential that was scanned, the target is who the submission is about.
 * Each source resolves exactly one target type (brief §16, 24).
 */
enum PublicFeedbackSourceType: string
{
    /** The feedback QR printed on the back of an employee's ID card. */
    case EmployeeCardQr = 'employee_card_qr';

    /** An organization's own public feedback QR. */
    case OrganizationFeedbackQr = 'organization_feedback_qr';

    public function targetType(): PublicFeedbackTargetType
    {
        return match ($this) {
            self::EmployeeCardQr => PublicFeedbackTargetType::Employee,
            self::OrganizationFeedbackQr => PublicFeedbackTargetType::Organization,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
