<?php

declare(strict_types=1);

namespace Modules\Leave\Providers;

use App\Contracts\EmployeeLeaveProvider;
use App\Support\Features;
use App\Support\NotificationPresenter;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Leave\Application\Notifications\LeaveNotification;
use Modules\Leave\Application\Queries\ApprovedLeaveProvider;
use Modules\Leave\Application\Queries\EmployeeLeaveSummaryQuery;
use Modules\Leave\Application\Services\LeaveAccess;
use Modules\Leave\Application\Services\LeaveRules;
use Modules\Leave\Application\Services\LeaveSettings;
use Modules\Leave\Contracts\EmployeeLeaveSummary;
use Modules\Leave\Infrastructure\Console\GrantLeaveYearCommand;
use Modules\Leave\Infrastructure\Console\StartPlannedLeaveCommand;

/**
 * Everything the application needs to know about employee leave (ADR 0039):
 * its public contract, its tables, routes, translations, notice renderer and
 * yearly grant.
 *
 * It also gives the platform's leave seam its real source. While the "leave"
 * feature flag is on, App\Contracts\EmployeeLeaveProvider answers from
 * approved leave, so the daily-activity calendar and the field-work conflict
 * check honour it with no change on their side. With the flag off the
 * application's null provider stays bound.
 */
final class LeaveServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EmployeeLeaveSummary::class, EmployeeLeaveSummaryQuery::class);
        // Per request or job: remembered rule sets and scope answers never leak between users.
        $this->app->scoped(LeaveRules::class);
        $this->app->scoped(LeaveAccess::class);
        $this->app->scoped(LeaveSettings::class);

        if (Features::enabled('leave')) {
            $this->app->bind(EmployeeLeaveProvider::class, ApprovedLeaveProvider::class);
        }
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadTranslationsFrom(__DIR__.'/../Lang', 'leave');
        Route::middleware('web')->group(__DIR__.'/../Routes/web.php');

        NotificationPresenter::registerRenderer('leave', LeaveNotification::class);

        if ($this->app->runningInConsole()) {
            $this->commands([GrantLeaveYearCommand::class, StartPlannedLeaveCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            // Idempotent: a balance is also brought up to date whenever it is opened. The nightly run keeps reports whole.
            $schedule->command('leave:grant-year')->dailyAt('01:30')->timezone('Africa/Addis_Ababa')->withoutOverlapping()->onOneServer();
            // Approved planned leave is counted on its first day; the later runs catch a server that was down at midnight.
            foreach (['00:10', '06:00', '12:00'] as $time) {
                $schedule->command('leave:start-planned')->dailyAt($time)->timezone('Africa/Addis_Ababa')->withoutOverlapping()->onOneServer();
            }
        });
    }
}
