<?php

declare(strict_types=1);

namespace App\Services\SituationRoom;

/**
 * Situation Room grants. Permission says WHAT a viewer may see; their
 * organization scope says WHERE. Aggregate access never implies detail:
 * names of employees need EMPLOYEE_BREAKDOWN, and a submitter's identity or
 * narrative is never in the Situation Room at all — it stays behind the
 * public-feedback identity grant in the submission inbox.
 */
final class SituationPermissions
{
    public const VIEW = 'situation_room.view';

    public const WALL = 'situation_room.wall.view';

    public const REPORTS = 'situation_room.reports.view';

    public const WORKFORCE = 'situation_room.workforce.view';

    public const PUBLIC_VOICE = 'situation_room.public_voice.view';

    public const EMPLOYEE_BREAKDOWN = 'situation_room.employee_breakdown.view';

    public const OPERATIONS = 'situation_room.operations.view';

    public const PERFORMANCE = 'situation_room.performance.view';

    public const CAFETERIA = 'situation_room.cafeteria.view';

    public const CASES = 'situation_room.cases.view';

    public const ACTIONS_VIEW = 'situation_room.actions.view';

    public const ACTIONS_MANAGE = 'situation_room.actions.manage';

    /** Grants that change what a payload contains (cache keys include them). */
    public const DETAIL_GRANTS = [self::EMPLOYEE_BREAKDOWN, self::CAFETERIA];

    /** @return list<string> */
    public static function all(): array
    {
        return [self::VIEW, self::WALL, self::REPORTS, self::WORKFORCE, self::PUBLIC_VOICE, self::EMPLOYEE_BREAKDOWN, self::OPERATIONS, self::PERFORMANCE, self::CAFETERIA, self::CASES, self::ACTIONS_VIEW, self::ACTIONS_MANAGE];
    }
}
