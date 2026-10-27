<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Cafeteria service policy lifecycle:
 * draft → under_review → approved → active → superseded | expired, or cancelled.
 */
enum CafeteriaPolicyStatus: string
{
    case Draft = 'draft';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Active = 'active';
    case Superseded = 'superseded';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    /**
     * Statuses whose effective dates are binding. An approved policy applies
     * from its effective date without waiting for someone to mark it active;
     * superseded/expired ones still govern the dates they covered.
     *
     * @return list<string>
     */
    public static function binding(): array
    {
        return [self::Approved->value, self::Active->value, self::Superseded->value, self::Expired->value];
    }

    public function isBinding(): bool
    {
        return in_array($this->value, self::binding(), true);
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
