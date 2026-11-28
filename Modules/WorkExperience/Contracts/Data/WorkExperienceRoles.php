<?php

declare(strict_types=1);

namespace Modules\WorkExperience\Contracts\Data;

/**
 * Permission sets for the default roles that carry work-experience duties
 * (ADR 0040). Shared by DefaultRoleMatrix so the two cannot drift apart. A
 * permission alone is never enough: an employee acts on their own record, an
 * officer inside their organization scope.
 */
final class WorkExperienceRoles
{
    /** @var array<int, string> */
    public const EMPLOYEE_PERMISSIONS = [
        'work_experience.request_own',
    ];

    /** HR officers of an institution: record experience, decide requests, print and send. */
    public const HR_PERMISSIONS = [
        'work_experience.view',
        'work_experience.records.manage',
        'work_experience.requests.manage',
        'work_experience.settings.manage',
    ];

    /** Organizational administrators and the head of the institution. */
    public const ORGANIZATIONAL_ADMIN_PERMISSIONS = [
        'work_experience.view',
        'work_experience.settings.manage',
    ];
}
