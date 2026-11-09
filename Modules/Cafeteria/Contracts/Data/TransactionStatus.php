<?php

declare(strict_types=1);

namespace Modules\Cafeteria\Contracts\Data;

/**
 * The values a cafeteria transaction's status takes, published for reports
 * that count meals by status. ModulePublicApiTest keeps this list equal to
 * the module's own enum.
 */
final class TransactionStatus
{
    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public const REVERSED = 'reversed';

    public const PENDING_REVIEW = 'pending_review';

    public const ALL = [self::ACCEPTED, self::REJECTED, self::REVERSED, self::PENDING_REVIEW];
}
