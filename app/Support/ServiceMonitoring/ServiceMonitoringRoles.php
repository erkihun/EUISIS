<?php

declare(strict_types=1);

namespace App\Support\ServiceMonitoring;

/**
 * Permission sets for the default roles that carry service monitoring duties.
 * Shared by DefaultRoleMatrix and the registration migration so they cannot
 * drift apart. City-wide administrators receive every permission through the
 * City Admin set; an institution's administrator fills and reads its own.
 */
final class ServiceMonitoringRoles
{
    /** @var list<string> */
    public const ORGANIZATIONAL_ADMIN_PERMISSIONS = [
        'service_monitoring.reports.submit',
        'service_monitoring.reports.view',
    ];
}
