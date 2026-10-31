<?php

declare(strict_types=1);

namespace Modules\Court\Providers;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\RequestGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Court\Application\Listeners\RecordAuthEvent;
use Modules\Court\Application\Listeners\RecordModelMutation;
use Modules\Court\Application\Policies\CourtCasePolicy;
use Modules\Court\Application\Policies\UserPolicy;
use Modules\Court\Domain\Models;
use Modules\Court\Http\Middleware\ActAsRespondent;
use Modules\Court\Http\Middleware\AdminOnly;
use Modules\Court\Http\Middleware\Authenticate;
use Modules\Court\Http\Middleware\AuthenticateIntegrationClient;
use Modules\Court\Http\Middleware\RedirectIfAuthenticated;
use Modules\Court\Http\Middleware\RequirePermission;
use Modules\Court\Http\Middleware\RequireRole;
use Modules\Court\Http\Middleware\ResolveAdminRole;
use Modules\Court\Http\Middleware\SystemAuditMiddleware;
use Modules\Court\Http\Middleware\UseGuard;
use Modules\Court\Infrastructure\Console\AccessUpgradeReport;
use Modules\Court\Infrastructure\Console\CheckSrsCompliance;
use Modules\Court\Infrastructure\Console\EuisisIntegrationTokenCommand;
use Modules\Court\Infrastructure\Console\EuisisSyncCommand;
use Modules\Court\Infrastructure\Console\ExportClientTranslations;

/**
 * The court (administrative tribunal) module, brought in from court-ms (ADR 0020):
 * its config, guards, views, translations, middleware, morph names, policies,
 * audit listeners, API routes and commands. The web routes are routes/court.php.
 *
 * Staff are platform accounts with a `court_staff` row: they sign in to EUISIS
 * and court routes resolve their court record on the `court` guard
 * (ResolveCourtStaff). Applicants and respondents keep their own sign-in on
 * the `applicant` and `respondent` guards.
 */
final class CourtServiceProvider extends ServiceProvider
{
    /** Court records under their own morph names (court-ms stored App\Models\* names). */
    public const MORPH_MAP = [
        'court_about_page' => Models\AboutPage::class,
        'court_announcement' => Models\Announcement::class,
        'court_applicant' => Models\Applicant::class,
        'court_applicant_response_reply' => Models\ApplicantResponseReply::class,
        'court_applicant_sign_in_ticket' => Models\ApplicantSignInTicket::class,
        'court_applicant_term_acceptance' => Models\ApplicantTermAcceptance::class,
        'court_bench_note' => Models\BenchNote::class,
        'court_case_applicant' => Models\CaseApplicant::class,
        'court_case_evidence' => Models\CaseEvidence::class,
        'court_case_file' => Models\CaseFile::class,
        'court_case_hearing' => Models\CaseHearing::class,
        'court_case_inspection_finding' => Models\CaseInspectionFinding::class,
        'court_case_inspection_request' => Models\CaseInspectionRequest::class,
        'court_case_representative' => Models\CaseRepresentative::class,
        'court_case_type' => Models\CaseType::class,
        'court_case_witness' => Models\CaseWitness::class,
        'court_case' => Models\CourtCase::class,
        'court_member' => Models\CourtMember::class,
        'court_staff_member' => Models\CourtStaffMember::class,
        'court_decision' => Models\Decision::class,
        'court_decision_review' => Models\DecisionReview::class,
        'court_decision_template' => Models\DecisionTemplate::class,
        'court_euisis_case_update' => Models\EuisisCaseUpdate::class,
        'court_euisis_employee' => Models\EuisisEmployee::class,
        'court_euisis_organization' => Models\EuisisOrganization::class,
        'court_euisis_organization_unit' => Models\EuisisOrganizationUnit::class,
        'court_euisis_position' => Models\EuisisPosition::class,
        'court_euisis_referral' => Models\EuisisReferral::class,
        'court_euisis_staff_notice' => Models\EuisisStaffNotice::class,
        'court_euisis_staff_unit' => Models\EuisisStaffUnit::class,
        'court_file_security_record' => Models\FileSecurityRecord::class,
        'court_home_faq' => Models\HomeFaq::class,
        'court_home_resource' => Models\HomeResource::class,
        'court_home_service' => Models\HomeService::class,
        'court_home_setting' => Models\HomeSetting::class,
        'court_home_slide' => Models\HomeSlide::class,
        'court_home_timeline_step' => Models\HomeTimelineStep::class,
        'court_integration_client' => Models\IntegrationClient::class,
        'court_legal_hold' => Models\LegalHold::class,
        'court_letter' => Models\Letter::class,
        'court_letter_category' => Models\LetterCategory::class,
        'court_letter_template' => Models\LetterTemplate::class,
        'court_performance_criterion' => Models\PerformanceCriterion::class,
        'court_performance_evaluation' => Models\PerformanceEvaluation::class,
        'court_performance_evaluation_category' => Models\PerformanceEvaluationCategory::class,
        'court_performance_evaluation_score' => Models\PerformanceEvaluationScore::class,
        'court_permission' => Models\Permission::class,
        'court_respondent' => Models\Respondent::class,
        'court_respondent_case_view' => Models\RespondentCaseView::class,
        'court_respondent_response' => Models\RespondentResponse::class,
        'court_role' => Models\Role::class,
        'court_system_setting' => Models\SystemSetting::class,
        'court_team' => Models\Team::class,
        'court_terms_and_condition' => Models\TermsAndCondition::class,
    ];

    /** The guards court people sign in on; the audit trail records only these. */
    public const GUARDS = ['court', 'applicant', 'respondent'];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/court.php', 'court');

        $this->app['config']->set([
            'auth.guards.court' => ['driver' => 'court-staff'],
            'auth.guards.applicant' => ['driver' => 'session', 'provider' => 'court_applicants'],
            'auth.guards.respondent' => ['driver' => 'session', 'provider' => 'court_respondents'],
            'auth.providers.court_applicants' => ['driver' => 'eloquent', 'model' => Models\Applicant::class],
            'auth.providers.court_respondents' => ['driver' => 'eloquent', 'model' => Models\Respondent::class],
            'auth.passwords.applicants' => ['provider' => 'court_applicants', 'table' => 'court_password_reset_tokens', 'expire' => 60, 'throttle' => 60],
            'auth.passwords.respondents' => ['provider' => 'court_respondents', 'table' => 'court_password_reset_tokens', 'expire' => 60, 'throttle' => 60],
            // The court's files, apart from the platform's (court-ms's `private` and `public` disks).
            'filesystems.disks.court_private' => ['driver' => 'local', 'root' => storage_path('app/private/court'), 'visibility' => 'private', 'throw' => false, 'report' => false],
            'filesystems.disks.court_public' => ['driver' => 'local', 'root' => storage_path('app/public/court'), 'url' => rtrim((string) config('app.url'), '/').'/storage/court', 'visibility' => 'public', 'throw' => false, 'report' => false],
        ]);
    }

    public function boot(): void
    {
        // The staff guard holds the court record of the signed-in platform account; ResolveCourtStaff sets it per request.
        Auth::extend('court-staff', fn ($app) => new RequestGuard(static fn () => null, $app['request']));

        Relation::morphMap(self::MORPH_MAP);

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'court');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'court');
        // Only the sentences EUISIS does not translate itself (the port left the rest out).
        $this->loadJsonTranslationsFrom(__DIR__.'/../resources/lang');
        Blade::componentNamespace('Modules\\Court\\Http\\View\\Components', 'court');
        Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'court');

        $router = $this->app->make(Router::class);
        foreach ([
            'court.perm' => RequirePermission::class,
            'court.role' => RequireRole::class,
            'court.active-role' => ResolveAdminRole::class,
            'court.act-respondent' => ActAsRespondent::class,
            'court.use-guard' => UseGuard::class,
            'court.admin-only' => AdminOnly::class,
            'court.integration-client' => AuthenticateIntegrationClient::class,
            'court.auth' => Authenticate::class,
            'court.guest' => RedirectIfAuthenticated::class,
            'court.audit' => SystemAuditMiddleware::class,
        ] as $alias => $class) {
            $router->aliasMiddleware($alias, $class);
        }
        // Flow 6: with the portal in EUISIS a guest gets not found, not a sign-in redirect (before the portals' own auth).
        $this->app->make(\Illuminate\Contracts\Http\Kernel::class)->prependToMiddlewarePriority(\Modules\Court\Http\Middleware\CourtPortalInEuisis::class);

        Gate::policy(Models\CourtStaffMember::class, UserPolicy::class);
        Gate::policy(Models\CourtCase::class, CourtCasePolicy::class);

        RateLimiter::for('court-api-ip-hourly', static fn (Request $request) => Limit::perHour(100)->by($request->ip() ?: 'unknown-ip'));

        // The mobile and integration API under /court/api (court-ms served it under /api).
        Route::middleware(['api', 'court.audit'])->prefix('court/api')->name('court.')->group(base_path('routes/court-api.php'));

        $this->registerAuditTrail();

        // The court's settings secrets never come back to a form after a validation error (court-ms bootstrap/app.php).
        $handler = $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class);
        if (method_exists($handler, 'dontFlash')) {
            $handler->dontFlash(['mail_password', 'telegram_bot_token', 'sms_api_key', 'sms_api_secret']);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                AccessUpgradeReport::class, CheckSrsCompliance::class, EuisisIntegrationTokenCommand::class,
                EuisisSyncCommand::class, ExportClientTranslations::class,
            ]);
        }

        // The EUISIS directory link, while the court still reads it over the partner API (ADR 0020, step 2 replaces it).
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            if (config('court.euisis.enabled')) {
                $schedule->command('court:euisis-sync')->everyFiveMinutes()->withoutOverlapping()->runInBackground();
            }
        });
    }

    /** court-ms's system audit: sign-ins on the court guards and changes to court records only. */
    private function registerAuditTrail(): void
    {
        $courtGuard = static fn (?string $guard): bool => in_array($guard, self::GUARDS, true);
        Event::listen(Login::class, static function (Login $event) use ($courtGuard): void {
            if ($courtGuard($event->guard)) {
                app(RecordAuthEvent::class)->handleLogin($event);
            }
        });
        Event::listen(Logout::class, static function (Logout $event) use ($courtGuard): void {
            if ($courtGuard($event->guard)) {
                app(RecordAuthEvent::class)->handleLogout($event);
            }
        });
        Event::listen(Failed::class, static function (Failed $event) use ($courtGuard): void {
            if ($courtGuard($event->guard)) {
                app(RecordAuthEvent::class)->handleFailed($event);
            }
        });
        Event::listen(PasswordReset::class, static function (PasswordReset $event): void {
            if ($event->user instanceof Models\Applicant || $event->user instanceof Models\Respondent) {
                app(RecordAuthEvent::class)->handlePasswordReset($event);
            }
        });

        foreach (['created', 'updated', 'deleted', 'restored'] as $modelEvent) {
            Event::listen("eloquent.{$modelEvent}: *", static function (string $eventName, array $payload): void {
                if (($payload[0] ?? null) instanceof Model && str_starts_with($payload[0]::class, 'Modules\\Court\\')) {
                    app(RecordModelMutation::class)->handle($eventName, $payload);
                }
            });
        }
    }
}
