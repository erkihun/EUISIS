<?php

declare(strict_types=1);

namespace Modules\Communication\Domain\Enums;

/** Channel kinds. PUBLIC_ORGANIZATION channels can be joined by anyone in their organization scope; PRIVATE and PROJECT only by invitation; SYSTEM_MANAGED ones are created and populated by the platform. */
enum ChannelType: string
{
    case PublicOrganization = 'PUBLIC_ORGANIZATION';
    case Private = 'PRIVATE';
    case SystemManaged = 'SYSTEM_MANAGED';
    case Project = 'PROJECT';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isJoinable(): bool
    {
        return $this === self::PublicOrganization;
    }
}
