<?php

declare(strict_types=1);

namespace Modules\Communication\Contracts\Data;

/**
 * The values an announcement's priority takes, published for reports that
 * bucket announcements by priority (the Situation Room). ModulePublicApiTest
 * keeps this list equal to the module's own enum.
 */
final class AnnouncementPriority
{
    public const NORMAL = 'NORMAL';

    public const IMPORTANT = 'IMPORTANT';

    public const URGENT = 'URGENT';

    public const EMERGENCY = 'EMERGENCY';

    public const ALL = [self::NORMAL, self::IMPORTANT, self::URGENT, self::EMERGENCY];
}
