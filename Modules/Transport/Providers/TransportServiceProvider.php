<?php

declare(strict_types=1);

namespace Modules\Transport\Providers;

use App\Backbone\Events\AssignmentChanged;
use App\Backbone\Events\EmployeeChanged;
use App\Models\Provider;
use App\Support\NotificationPresenter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Modules\Transport\Application\Notifications\TransportNotification;
use Modules\Transport\Application\Policies\TransportDriverPolicy;
use Modules\Transport\Application\Policies\TransportPassPolicy;
use Modules\Transport\Application\Policies\TransportRoutePolicy;
use Modules\Transport\Application\Policies\TransportTransactionPolicy;
use Modules\Transport\Application\Policies\TransportTripPolicy;
use Modules\Transport\Application\Policies\TransportVehiclePolicy;
use Modules\Transport\Application\Queries\EmployeeTransportActivityQuery;
use Modules\Transport\Application\Queries\TransportOperationalMetricsQuery;
use Modules\Transport\Application\Services\TransportDataQualityService;
use Modules\Transport\Application\Services\TransportEligibilitySync;
use Modules\Transport\Contracts\EmployeeTransportActivity;
use Modules\Transport\Contracts\TransportDataQuality;
use Modules\Transport\Contracts\TransportOperationalMetrics;
use Modules\Transport\Domain\Models\TransportContract;
use Modules\Transport\Domain\Models\TransportDevice;
use Modules\Transport\Domain\Models\TransportDriver;
use Modules\Transport\Domain\Models\TransportEligibility;
use Modules\Transport\Domain\Models\TransportException;
use Modules\Transport\Domain\Models\TransportInvoice;
use Modules\Transport\Domain\Models\TransportPass;
use Modules\Transport\Domain\Models\TransportProviderProfile;
use Modules\Transport\Domain\Models\TransportRoute;
use Modules\Transport\Domain\Models\TransportRouteStop;
use Modules\Transport\Domain\Models\TransportSchedule;
use Modules\Transport\Domain\Models\TransportServiceCalendarDay;
use Modules\Transport\Domain\Models\TransportSettlement;
use Modules\Transport\Domain\Models\TransportSettlementAdjustment;
use Modules\Transport\Domain\Models\TransportTransaction;
use Modules\Transport\Domain\Models\TransportTrip;
use Modules\Transport\Domain\Models\TransportTripPosition;
use Modules\Transport\Domain\Models\TransportTripVehicleChange;
use Modules\Transport\Domain\Models\TransportVehicle;
use Modules\Transport\Http\Middleware\AuthenticateTransportDevice;
use Modules\Transport\Infrastructure\Console\PlanTransportTrips;

/**
 * Everything the application needs to know about the Transport module
 * (ADR 0017): the implementations of its public contracts, its policies,
 * its reactions to Core events, and the names its records keep in the
 * database. Nothing outside the module wires Transport internals.
 */
final class TransportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EmployeeTransportActivity::class, EmployeeTransportActivityQuery::class);
        $this->app->bind(TransportOperationalMetrics::class, TransportOperationalMetricsQuery::class);
        $this->app->bind(TransportDataQuality::class, TransportDataQualityService::class);
    }

    public function boot(): void
    {
        // Moved models keep their old class names in the database (roles, audit logs, tokens).
        Relation::morphMap([
            'App\Models\TransportContract' => TransportContract::class,
            'App\Models\TransportDevice' => TransportDevice::class,
            'App\Models\TransportDriver' => TransportDriver::class,
            'App\Models\TransportEligibility' => TransportEligibility::class,
            'App\Models\TransportException' => TransportException::class,
            'App\Models\TransportInvoice' => TransportInvoice::class,
            'App\Models\TransportPass' => TransportPass::class,
            'App\Models\TransportProviderProfile' => TransportProviderProfile::class,
            'App\Models\TransportRoute' => TransportRoute::class,
            'App\Models\TransportRouteStop' => TransportRouteStop::class,
            'App\Models\TransportSchedule' => TransportSchedule::class,
            'App\Models\TransportServiceCalendarDay' => TransportServiceCalendarDay::class,
            'App\Models\TransportSettlement' => TransportSettlement::class,
            'App\Models\TransportSettlementAdjustment' => TransportSettlementAdjustment::class,
            'App\Models\TransportTransaction' => TransportTransaction::class,
            'App\Models\TransportTrip' => TransportTrip::class,
            'App\Models\TransportTripPosition' => TransportTripPosition::class,
            'App\Models\TransportTripVehicleChange' => TransportTripVehicleChange::class,
            'App\Models\TransportVehicle' => TransportVehicle::class,
        ]);

        // Policy discovery only looks in App\Policies.
        Gate::policy(TransportDriver::class, TransportDriverPolicy::class);
        Gate::policy(TransportPass::class, TransportPassPolicy::class);
        Gate::policy(TransportRoute::class, TransportRoutePolicy::class);
        Gate::policy(TransportTransaction::class, TransportTransactionPolicy::class);
        Gate::policy(TransportTrip::class, TransportTripPolicy::class);
        Gate::policy(TransportVehicle::class, TransportVehiclePolicy::class);

        // A provider's transport licence and service area belong to Transport, so
        // Transport attaches the relation; the Provider model knows nothing of it.
        Provider::resolveRelationUsing('transportProfile', fn (Provider $provider): HasOne => $provider->hasOne(TransportProviderProfile::class));

        // Route assignments follow placement and status (docs/transport/eligibility.md).
        Event::listen(AssignmentChanged::class, [TransportEligibilitySync::class, 'onAssignmentChanged']);
        Event::listen(EmployeeChanged::class, [TransportEligibilitySync::class, 'onEmployeeChanged']);

        NotificationPresenter::registerRenderer('transport', TransportNotification::class);

        $this->app->make(Router::class)->aliasMiddleware('transport.device', AuthenticateTransportDevice::class);

        /*
         * Scanner devices (docs/transport/device-security.md): a bus door boards at
         * most a few people a second, so 300 scans a minute per device never
         * throttles a legitimate queue, while a provider's whole fleet shares a
         * wider ceiling and unknown credentials are capped by IP.
         */
        RateLimiter::for('transport-device', function (Request $request): array {
            $device = $request->attributes->get(AuthenticateTransportDevice::ATTRIBUTE);
            if ($device === null) {
                return [Limit::perMinute(60)->by('transport-device-ip:'.$request->ip())];
            }

            return [
                Limit::perMinute(300)->by('transport-device:'.$device->id),
                Limit::perMinute(3000)->by('transport-provider:'.$device->provider_id),
            ];
        });

        if ($this->app->runningInConsole()) {
            $this->commands([PlanTransportTrips::class]);
        }
    }
}
