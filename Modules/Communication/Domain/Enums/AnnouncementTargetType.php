<?php

declare(strict_types=1);

namespace Modules\Communication\Domain\Enums;

/** Who an announcement reaches. Matched per reader from their audience keys. */
enum AnnouncementTargetType: string
{
    case City = 'CITY';
    case Organization = 'ORGANIZATION';
    case OrganizationSubtree = 'ORGANIZATION_SUBTREE';
    case OrganizationUnit = 'ORGANIZATION_UNIT';
    case Employee = 'EMPLOYEE';
    case Role = 'ROLE';
    case Group = 'GROUP';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
