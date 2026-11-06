<?php

declare(strict_types=1);

namespace Modules\Communication\Contracts\Data;

/**
 * The values a channel access request's status takes, published for reports
 * that count pending requests (the Situation Room). ModulePublicApiTest keeps
 * this list equal to the module's own enum.
 */
final class AccessRequestStatus
{
    public const REQUESTED = 'REQUESTED';

    public const APPROVED = 'APPROVED';

    public const REJECTED = 'REJECTED';

    public const ACTIVE = 'ACTIVE';

    public const EXPIRED = 'EXPIRED';

    public const REVOKED = 'REVOKED';

    public const ALL = [self::REQUESTED, self::APPROVED, self::REJECTED, self::ACTIVE, self::EXPIRED, self::REVOKED];
}
