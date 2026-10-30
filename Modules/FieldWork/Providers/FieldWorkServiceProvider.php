<?php

declare(strict_types=1);

namespace Modules\FieldWork\Providers;

use App\Support\NotificationPresenter;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\FieldWork\Application\Notifications\FieldWorkNotification;
use Modules\FieldWork\Application\Policies\FieldWorkRequestPolicy;
use Modules\FieldWork\Application\Services\FieldWorkSettings;
use Modules\FieldWork\Contracts\FieldWorkGpsPolicy;
use Modules\FieldWork\Domain\Models\FieldWorkHistory;
use Modules\FieldWork\Domain\Models\FieldWorkLocationEvent;
use Modules\FieldWork\Domain\Models\FieldWorkParticipant;
use Modules\FieldWork\Domain\Models\FieldWorkRequest;
use Modules\FieldWork\Domain\Models\FieldWorkType;

/**
 * Everything the application needs to know about the Field work module
 * (ADR 0017): its public contract, its policy, its notification renderer, and
 * the names its records keep in the database. Its routes stay in
 * routes/field-work.php, which names its controllers.
 */
final class FieldWorkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FieldWorkGpsPolicy::class, FieldWorkSettings::class);
    }

    public function boot(): void
    {
        // Moved models keep their old class names in the database (audit logs, notifications).
        Relation::morphMap([
            'App\Models\FieldWorkHistory' => FieldWorkHistory::class,
            'App\Models\FieldWorkLocationEvent' => FieldWorkLocationEvent::class,
            'App\Models\FieldWorkParticipant' => FieldWorkParticipant::class,
            'App\Models\FieldWorkRequest' => FieldWorkRequest::class,
            'App\Models\FieldWorkType' => FieldWorkType::class,
        ]);

        // Policy discovery only looks in App\Policies.
        Gate::policy(FieldWorkRequest::class, FieldWorkRequestPolicy::class);

        NotificationPresenter::registerRenderer('field_work', FieldWorkNotification::class);
    }
}
