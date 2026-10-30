<?php

declare(strict_types=1);

namespace Modules\Reception\Contracts\Data;

final class ReceptionPermissions
{
    public const VIEW = 'reception.view';

    public const OPERATE = 'reception.operate';

    public const SUPERVISE = 'reception.supervise';

    public const CONFIGURE = 'reception.configure';

    public const APPOINTMENTS = 'reception.appointments.manage';

    public const REPORTS = 'reception.reports.view';

    public const EXPORT = 'reception.reports.export';

    public const AUDIT = 'reception.audit';

    public const ALL = [self::VIEW, self::OPERATE, self::SUPERVISE, self::CONFIGURE, self::APPOINTMENTS, self::REPORTS, self::EXPORT, self::AUDIT];
}
