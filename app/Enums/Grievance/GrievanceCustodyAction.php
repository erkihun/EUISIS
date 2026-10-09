<?php

declare(strict_types=1);

namespace App\Enums\Grievance;

/**
 * Chain-of-custody actions recorded for evidence.
 */
enum GrievanceCustodyAction: string
{
    case Uploaded = 'uploaded';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Viewed = 'viewed';
    case Downloaded = 'downloaded';
    case Classified = 'classified';
    case Superseded = 'superseded';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
