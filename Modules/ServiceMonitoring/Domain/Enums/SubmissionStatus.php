<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Enums;

/** draft → submitted → returned → (resubmitted) → accepted → superseded (D9). */
enum SubmissionStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Returned = 'returned';
    case Accepted = 'accepted';
    case Superseded = 'superseded';

    public function label(?string $locale = null): string
    {
        return (string) __('service-monitoring.enums.submission_status.'.$this->value, [], $locale);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** The institution may change its figures only while drafting or after a return. */
    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Returned;
    }
}
