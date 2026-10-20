<?php

declare(strict_types=1);

namespace App\Services\SituationRoom;

use App\Models\User;

/**
 * Situation Room grants — one set for the whole room. Permission says WHAT a
 * viewer may see; their organization scope says WHERE. The first six are the
 * room's core grants (shared with the Community Voice view and the Action
 * Register, SituationRoomMetrics); the per-domain grants open the categories
 * that read other modules' data.
 *
 * Aggregate access never implies detail: naming employees needs
 * EMPLOYEE_BREAKDOWN (plus service_feedback.view_employee_target), submission
 * text needs DETAIL, and a public submitter's identity never reaches the room.
 */
final class SituationPermissions
{
    public const VIEW = SituationRoomMetrics::PERMISSION_VIEW;

    public const WALL = SituationRoomMetrics::PERMISSION_WALL;

    public const REPORTS = SituationRoomMetrics::PERMISSION_EXPORT;

    public const EMPLOYEE_BREAKDOWN = SituationRoomMetrics::PERMISSION_EMPLOYEE_TARGETED;

    public const DETAIL = SituationRoomMetrics::PERMISSION_DETAIL;

    public const ACTIONS_MANAGE = SituationRoomMetrics::PERMISSION_ACTIONS;

    public const WORKFORCE = 'situation_room.workforce.view';

    public const PUBLIC_VOICE = 'situation_room.public_voice.view';

    public const OPERATIONS = 'situation_room.operations.view';

    public const PERFORMANCE = 'situation_room.performance.view';

    public const CAFETERIA = 'situation_room.cafeteria.view';

    public const CASES = 'situation_room.cases.view';

    public const TRANSPORT = 'situation_room.transport.view';

    /** Needed alongside EMPLOYEE_BREAKDOWN to name employees (the public-feedback rule for employee targets). */
    public const EMPLOYEE_TARGET = 'service_feedback.view_employee_target';

    /** Grants that change what a payload contains (cache keys include them). */
    public const DETAIL_GRANTS = [self::EMPLOYEE_BREAKDOWN, self::EMPLOYEE_TARGET, self::CAFETERIA];

    /** The per-domain grants this module adds (database/seeders/data/situation-room-permissions.php). */
    public const DOMAIN = [self::WORKFORCE, self::PUBLIC_VOICE, self::OPERATIONS, self::PERFORMANCE, self::CAFETERIA, self::CASES, self::TRANSPORT];

    /** @return list<string> */
    public static function all(): array
    {
        return [self::VIEW, self::WALL, self::REPORTS, self::EMPLOYEE_BREAKDOWN, self::DETAIL, self::ACTIONS_MANAGE, ...self::DOMAIN];
    }

    public static function canNameEmployees(User $user): bool
    {
        return $user->can(self::EMPLOYEE_BREAKDOWN) && $user->can(self::EMPLOYEE_TARGET);
    }
}
