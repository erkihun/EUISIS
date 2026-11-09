<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of an organization-design record (mandate, structure, manpower
 * plan, job description). A PUBLISHED record is never edited: a change is a
 * new version, which supersedes the one in force on its effective date.
 */
enum OrganizationDesignStatus: string
{
    case Draft = 'draft';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Published = 'published';
    case Superseded = 'superseded';
    case Cancelled = 'cancelled';

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Superseded, self::Cancelled], true);
    }
}
