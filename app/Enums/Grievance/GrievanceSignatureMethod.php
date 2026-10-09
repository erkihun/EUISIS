<?php

declare(strict_types=1);

namespace App\Enums\Grievance;

/**
 * How a letter was signed. An image on a PDF is NOT a cryptographic digital signature.
 */
enum GrievanceSignatureMethod: string
{
    case SignatureImage = 'signature_image';
    case ElectronicApproval = 'electronic_approval';
    case QualifiedDigitalSignature = 'qualified_digital_signature';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
