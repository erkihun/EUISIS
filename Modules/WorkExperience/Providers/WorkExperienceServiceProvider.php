<?php

declare(strict_types=1);

namespace Modules\WorkExperience\Providers;

use App\Support\NotificationPresenter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\WorkExperience\Application\Notifications\WorkExperienceNotification;
use Modules\WorkExperience\Application\Services\WorkExperienceAccess;

/**
 * Work experience (ADR 0040): the record of each employee's experience, the
 * employee's request for a written certificate, and the certificate itself.
 * Registers the module's tables, routes, translations, certificate view and
 * notice renderer.
 */
final class WorkExperienceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Per request or job: scope answers never leak between users.
        $this->app->scoped(WorkExperienceAccess::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadTranslationsFrom(__DIR__.'/../Lang', 'work_experience');
        $this->loadViewsFrom(__DIR__.'/../Infrastructure/Resources/views', 'work_experience');
        Route::middleware('web')->group(__DIR__.'/../Routes/web.php');

        NotificationPresenter::registerRenderer('work_experience', WorkExperienceNotification::class);
    }
}
