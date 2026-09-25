<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CafeteriaReportRun;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;

readonly class CafeteriaReportRunPolicy
{
    use DeniesNonAdminUsers;

    public function viewAny(User $user): bool
    {
        return $user->can('cafeteria_reports.view');
    }

    public function view(User $user, CafeteriaReportRun $report): bool
    {
        return $user->can('cafeteria_reports.view');
    }

    public function generate(User $user): bool
    {
        return $user->can('cafeteria_reports.generate');
    }

    public function export(User $user): bool
    {
        return $user->can('cafeteria_reports.export');
    }
}
