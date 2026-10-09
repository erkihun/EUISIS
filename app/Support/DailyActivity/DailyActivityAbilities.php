<?php

declare(strict_types=1);

namespace App\Support\DailyActivity;

use App\Models\User;

/**
 * Which Daily Activity management pages a user may open, for the tab row
 * shown on every one of them. Each page re-checks its own permission; this
 * only decides which links to offer.
 *
 * Settings (city-wide rules) and reviewer assignments (organization-scoped
 * operational data) are separate pages with separate permissions.
 */
final class DailyActivityAbilities
{
    /** @return array<string, bool> */
    public static function for(User $user): array
    {
        return [
            'viewRegister' => $user->can('daily_activities.view_scoped') || $user->can('daily_activities.view_team'),
            'review' => $user->can('daily_activities.review'),
            'viewReports' => $user->can('daily_activities.view_reports'),
            'export' => $user->can('daily_activities.export'),
            'manageSettings' => $user->can('daily_activity_settings.view') || $user->can('daily_activity_settings.update'),
            'manageReviewers' => $user->can('daily_activities.manage_reviewers'),
        ];
    }
}
