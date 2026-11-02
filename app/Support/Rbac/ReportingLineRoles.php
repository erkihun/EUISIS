<?php

declare(strict_types=1);

namespace App\Support\Rbac;

/**
 * Reporting-line permission sets (ADR 0025). The Supervisor role is created
 * by the role seeder (never by a migration) and assigned to nobody: an
 * administrator gives it to the people who hold head positions. Holding the
 * position alone shows no team; holding the role alone shows no team either —
 * both are needed.
 */
final class ReportingLineRoles
{
    public const SUPERVISOR_ROLE = 'Supervisor';

    /** @var list<string> */
    public const SUPERVISOR_PERMISSIONS = [
        'reporting-lines.view_team',
        'reporting-lines.delegate',
    ];

    /** @var list<string> */
    public const HR_PERMISSIONS = [
        'reporting-lines.view_any',
    ];

    /** @var list<string> */
    public const ORGANIZATIONAL_ADMIN_PERMISSIONS = [
        'reporting-lines.view_any',
        'reporting-lines.manage',
    ];
}
