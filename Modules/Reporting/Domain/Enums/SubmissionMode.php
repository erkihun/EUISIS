<?php

declare(strict_types=1);

namespace Modules\Reporting\Domain\Enums;

enum SubmissionMode: string
{
    /** Section-based narrative written in the system; the Word file is generated. */
    case Structured = 'structured';
    /** A Word file prepared offline from the template and uploaded. */
    case Upload = 'upload';

    /** @return list<self> the modes a report type configured as $configured accepts */
    public static function acceptedBy(string $configured): array
    {
        return match ($configured) {
            'structured' => [self::Structured],
            'upload' => [self::Upload],
            default => [self::Structured, self::Upload],
        };
    }
}
