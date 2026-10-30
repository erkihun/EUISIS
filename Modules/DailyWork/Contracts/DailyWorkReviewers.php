<?php

declare(strict_types=1);

namespace Modules\DailyWork\Contracts;

use App\Models\User;
use Modules\DailyWork\Contracts\Data\DailyActivityCoverage;

/**
 * Who a user reviews (ADR 0017): the organizations, units and employees their
 * daily-work reviewer assignments cover. Performance and Field work scope
 * their team views with it.
 */
interface DailyWorkReviewers
{
    public function coverage(User $user): DailyActivityCoverage;
}
