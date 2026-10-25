<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\ApiClient;
use App\Models\CardRequest;
use App\Models\Employee;
use App\Models\EmployeeDuplicateReview;
use App\Models\IdCard;
use App\Models\IdCardTemplate;
use App\Models\ImportBatch;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\PositionEstablishment;
use App\Models\User;
use App\Services\BreakGlassAccess;
use App\Services\CardTemplateMirror;
use App\Services\CoreSettings;
use App\Services\DomainEvents;
use App\Services\MailSettings;
use App\Services\Observability;
use App\Services\OrganizationScope;
use App\Services\SystemHealth;
use App\Support\CorePermissions;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One scope cache per request or job.
        $this->app->scoped(OrganizationScope::class);
        $this->app->scoped(DomainEvents::class);
        $this->app->scoped(BreakGlassAccess::class);
    }

    public function boot(): void
    {
        // migrate:fresh / refresh / reset and db:wipe refuse to run in production.
        DB::prohibitDestructiveCommands($this->app->isProduction());

        // The default active ID card template (the EUISIS designer) becomes the active print template version.
        IdCardTemplate::saved(fn (IdCardTemplate $template) => app(CardTemplateMirror::class)->sync($template));

        // Settings › Email replaces the .env mail values, read when mail is first sent.
        $this->app->afterResolving('mail.manager', fn () => rescue(fn () => $this->app->make(MailSettings::class)->apply(), null, false));

        // Record identifiers are UUIDs (accounts are numbered): anything else is a
        // 404 before it reaches the database, where PostgreSQL would reject it.
        foreach (['organization', 'organizationUnit', 'employee', 'position', 'positionEstablishment', 'unit', 'card', 'cardRequest', 'establishment', 'hierarchyVersion', 'organizationEdge', 'document', 'duplicateReview', 'importBatch', 'apiClient', 'webhook', 'delivery', 'id', 'printBatch', 'cardTemplate', 'version', 'grant', 'issue', 'exportRun', 'notification'] as $parameter) {
            Route::pattern($parameter, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
        }
        Route::pattern('user', '[0-9]+');
        Route::pattern('role', '[0-9]+');

        // A record outside the signed-in person's organization scope is never loaded:
        // its route answers 404, whatever page or API reached it (ADR 0008).
        $inScope = fn (string $model, \Closure $limit): \Closure => function (string $value) use ($model, $limit): mixed {
            $principal = request()->user();
            if ($principal instanceof ApiClient) {
                return $value; // the integration routes resolve and scope their own records
            }
            $query = $model::query();
            if (request()->route()?->allowsTrashedBindings() && in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
                $query->withTrashed();
            }
            if ($principal instanceof User && ($ids = app(OrganizationScope::class)->organizationIds($principal)) !== null) {
                $limit($query, $ids);
            }

            return $query->findOrFail($value);
        };
        $byOrganization = fn (Builder $query, array $ids) => $query->whereIn('organization_id', $ids);
        $byPlacement = fn (string $path): \Closure => fn (Builder $query, array $ids) => $query->whereHas($path, fn (Builder $a) => $a->whereIn('organization_id', $ids));
        Route::bind('organization', $inScope(Organization::class, fn (Builder $query, array $ids) => $query->whereIn('id', $ids)));
        foreach (['organizationUnit', 'unit'] as $name) {
            Route::bind($name, $inScope(OrganizationUnit::class, $byOrganization));
        }
        Route::bind('position', $inScope(Position::class, $byOrganization));
        foreach (['positionEstablishment', 'establishment'] as $name) {
            Route::bind($name, $inScope(PositionEstablishment::class, $byOrganization));
        }
        Route::bind('employee', $inScope(Employee::class, $byPlacement('currentAssignment')));
        Route::bind('card', $inScope(IdCard::class, $byPlacement('employee.currentAssignment')));
        Route::bind('cardRequest', $inScope(CardRequest::class, $byPlacement('employee.currentAssignment')));
        Route::bind('duplicateReview', $inScope(EmployeeDuplicateReview::class, $byPlacement('employee.currentAssignment')));
        // An import uploaded city-wide is out of every scoped reader's reach.
        Route::bind('importBatch', $inScope(ImportBatch::class, fn (Builder $query, array $ids) => $query->whereIn('scope_organization_id', $ids)));

        // Per-account limits from Core settings › Security.
        RateLimiter::for('core-api', fn (Request $request): Limit => Limit::perMinute(app(CoreSettings::class)->int('security.api_rate_limit_per_minute'))
            ->by((string) ($request->user()?->getKey() ?? $request->ip())));
        RateLimiter::for('core-verify', fn (Request $request): Limit => Limit::perMinute(app(CoreSettings::class)->int('security.verification_rate_limit_per_minute'))
            ->by('verify:'.($request->user()?->getKey() ?? $request->ip())));

        // The screens' EUISIS permission names, granted by the Core role.
        Gate::before(fn (User $user, string $ability): ?bool => in_array($ability, CorePermissions::for($user), true) ? true : null);

        // System Health (ADR 0011): every scheduled Core command records its run; job durations are counted.
        Event::listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event): void {
            if (preg_match('/core:[a-z-]+/', (string) $event->task->command, $match) === 1) {
                SystemHealth::recordRun($match[0]);
            }
        });
        $started = [];
        Event::listen(JobProcessing::class, function (JobProcessing $event) use (&$started): void {
            $started[$event->job->getJobId()] = hrtime(true);
        });
        $finished = function (string $class, ?string $id, bool $failed) use (&$started): void {
            $ms = isset($started[$id]) ? (int) ((hrtime(true) - $started[$id]) / 1_000_000) : 0;
            unset($started[$id]);
            Observability::job($class, $ms, $failed);
        };
        Event::listen(JobProcessed::class, fn (JobProcessed $event) => $finished((string) $event->job->resolveName(), $event->job->getJobId(), false));
        Event::listen(JobFailed::class, fn (JobFailed $event) => $finished((string) $event->job->resolveName(), $event->job->getJobId(), true));
    }
}
