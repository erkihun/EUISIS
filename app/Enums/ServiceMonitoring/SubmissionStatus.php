<?php

declare(strict_types=1);

namespace App\Enums\ServiceMonitoring;

enum SubmissionStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Returned = 'returned';
    case Accepted = 'accepted';

    /** The institution may still change the content. */
    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Returned;
    }

    /** Counted in consolidated summaries: the institution has stood behind it. */
    public function isReported(): bool
    {
        return $this === self::Submitted || $this === self::Accepted;
    }
}
