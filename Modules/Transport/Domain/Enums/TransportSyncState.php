<?php

declare(strict_types=1);

namespace Modules\Transport\Domain\Enums;

/**
 * Where a boarding stands between the device and the server. Only ONLINE and
 * OFFLINE_RECONCILED boardings enter a settlement basis.
 */
enum TransportSyncState: string
{
    case Online = 'online';
    case OfflinePending = 'offline_pending';
    case OfflineReconciled = 'offline_reconciled';
    case OfflineRejected = 'offline_rejected';

    /** @return list<string> states whose boardings are settled */
    public static function settleable(): array
    {
        return [self::Online->value, self::OfflineReconciled->value];
    }
}
