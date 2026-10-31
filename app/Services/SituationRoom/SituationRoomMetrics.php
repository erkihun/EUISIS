<?php

declare(strict_types=1);

namespace App\Services\SituationRoom;

/**
 * The metric catalog of the Executive Situation Room
 * (docs/situation-room-metrics.md). Every status set, metric code and
 * permission the module uses is defined here once, so no page counts the
 * same thing two ways.
 */
final class SituationRoomMetrics
{
    /** Bumped whenever a definition changes, so cached figures are not reused across definitions. */
    public const VERSION = 'v1';

    /** Still being worked: received, waiting for routing, with a handler or under review. */
    public const UNRESOLVED_STATUSES = ['pending', 'unrouted', 'assigned', 'reviewed', 'under_review'];

    /** An outcome has been recorded (each type's word for it), or the item is closed. */
    public const RESOLVED_STATUSES = ['resolved', 'accepted', 'not_adopted', 'closed'];

    /** Moderated out; excluded from every Situation Room figure. */
    public const EXCLUDED_STATUSES = ['hidden'];

    /** Status buckets for the resolution chart, in workflow order. */
    public const STATUS_BUCKETS = [
        'pending' => ['pending'],
        'unrouted' => ['unrouted'],
        'assigned' => ['assigned'],
        'under_review' => ['reviewed', 'under_review'],
        'resolved' => ['resolved', 'accepted', 'not_adopted'],
        'closed' => ['closed'],
    ];

    public const TYPES = ['feedback', 'suggestion', 'complaint'];

    public const TARGETS = ['employee', 'organization'];

    public const PERIODS = ['today', '7d', '30d', 'month', 'quarter', 'year', 'custom'];

    public const DEFAULT_PERIOD = '30d';

    public const TOP_N = 10;

    // Permissions. Narrative needs VIEW_DETAIL; submitter identity and attachments keep the
    // existing service_feedback grants (FeedbackVisibility).
    public const PERMISSION_VIEW = 'situation_room.view';

    public const PERMISSION_EMPLOYEE_TARGETED = 'situation_room.view_employee_targeted';

    public const PERMISSION_DETAIL = 'situation_room.view_detail';

    public const PERMISSION_EXPORT = 'situation_room.export';

    public const PERMISSION_WALL = 'situation_room.wall_mode';

    public const PERMISSION_ACTIONS = 'situation_room.manage_actions';

    /** Seconds an aggregate may be served from cache; shown as "Last updated". */
    public const CACHE_SECONDS = 120;

    public const WALL_CACHE_SECONDS = 300;

    /** SQL list for an IN clause; values are the constants above, never user input. */
    public static function sqlList(array $values): string
    {
        return "'".implode("','", $values)."'";
    }
}
