<?php

declare(strict_types=1);

namespace Modules\DailyWork\Providers;

use App\Support\NotificationPresenter;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\DailyWork\Application\Notifications\DailyActivityNotification;
use Modules\DailyWork\Application\Policies\DailyActivityLogPolicy;
use Modules\DailyWork\Application\Queries\DailyWorkOutputsQuery;
use Modules\DailyWork\Application\Queries\EmployeeDailyWorkQuery;
use Modules\DailyWork\Application\Services\DailyActivityReviewerResolver;
use Modules\DailyWork\Application\Services\DailyActivitySettings;
use Modules\DailyWork\Application\Services\NoPerformanceItems;
use Modules\DailyWork\Contracts\DailyWorkCalendarSettings;
use Modules\DailyWork\Contracts\DailyWorkOutputs;
use Modules\DailyWork\Contracts\DailyWorkReviewers;
use Modules\DailyWork\Contracts\EmployeeDailyWork;
use Modules\DailyWork\Contracts\PerformanceItemSource;
use Modules\DailyWork\Domain\Models\DailyActivityAttachment;
use Modules\DailyWork\Domain\Models\DailyActivityHistory;
use Modules\DailyWork\Domain\Models\DailyActivityItem;
use Modules\DailyWork\Domain\Models\DailyActivityLog;
use Modules\DailyWork\Domain\Models\DailyActivityReminder;
use Modules\DailyWork\Domain\Models\DailyActivityReviewerAssignment;
use Modules\DailyWork\Domain\Models\DailyActivityReviewPolicy;
use Modules\DailyWork\Infrastructure\Console\SendDailyActivityReminders;

/**
 * Everything the application needs to know about the Daily work module
 * (ADR 0017): its public contracts, its policy, its notification renderer,
 * its reminder command, and the names its records keep in the database.
 * Its routes stay in routes/web.php, which names its controllers.
 */
final class DailyWorkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DailyWorkReviewers::class, DailyActivityReviewerResolver::class);
        $this->app->bind(EmployeeDailyWork::class, EmployeeDailyWorkQuery::class);
        $this->app->bind(DailyWorkOutputs::class, DailyWorkOutputsQuery::class);
        $this->app->bind(DailyWorkCalendarSettings::class, DailyActivitySettings::class);
        // The Performance module binds its own (AppServiceProvider until it moves).
        $this->app->bindIf(PerformanceItemSource::class, NoPerformanceItems::class);
    }

    public function boot(): void
    {
        // Moved models keep their old class names in the database (audit logs, notifications).
        Relation::morphMap([
            'App\Models\DailyActivityAttachment' => DailyActivityAttachment::class,
            'App\Models\DailyActivityHistory' => DailyActivityHistory::class,
            'App\Models\DailyActivityItem' => DailyActivityItem::class,
            'App\Models\DailyActivityLog' => DailyActivityLog::class,
            'App\Models\DailyActivityReminder' => DailyActivityReminder::class,
            'App\Models\DailyActivityReviewPolicy' => DailyActivityReviewPolicy::class,
            'App\Models\DailyActivityReviewerAssignment' => DailyActivityReviewerAssignment::class,
        ]);

        // Policy discovery only looks in App\Policies.
        Gate::policy(DailyActivityLog::class, DailyActivityLogPolicy::class);

        NotificationPresenter::registerRenderer('daily_activity', DailyActivityNotification::class);

        if ($this->app->runningInConsole()) {
            $this->commands([SendDailyActivityReminders::class]);
        }
    }
}
