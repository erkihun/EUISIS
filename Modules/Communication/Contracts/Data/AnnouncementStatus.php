<?php

declare(strict_types=1);

namespace Modules\Communication\Contracts\Data;

/**
 * The values an announcement's status takes, published for reports that count
 * announcements by status (the Situation Room). The lifecycle rules stay inside
 * the module; ModulePublicApiTest keeps this list equal to the module's own enum.
 */
final class AnnouncementStatus
{
    public const DRAFT = 'DRAFT';

    public const SCHEDULED = 'SCHEDULED';

    public const PUBLISHED = 'PUBLISHED';

    public const EXPIRED = 'EXPIRED';

    public const CANCELLED = 'CANCELLED';

    public const ALL = [self::DRAFT, self::SCHEDULED, self::PUBLISHED, self::EXPIRED, self::CANCELLED];
}
