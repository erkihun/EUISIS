<?php

declare(strict_types=1);

namespace App\Enums\Grievance;

/**
 * Kind of evidence, declared by the uploader and checked against the detected MIME type.
 */
enum GrievanceEvidenceType: string
{
    case Document = 'document';
    case Image = 'image';
    case Audio = 'audio';
    case Video = 'video';
    case Letter = 'letter';
    case Report = 'report';
    case Email = 'email';
    case Minutes = 'minutes';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
