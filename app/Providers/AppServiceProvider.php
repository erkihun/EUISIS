<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\EmployeeLeaveProvider;
use App\Contracts\SmsGateway;
use App\Models\AuditLog;
use App\Models\CardRequest;
use App\Models\CodeRule;
use App\Models\DesignImportBatch;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeTransfer;
use App\Models\GradeLevel;
use App\Models\IdCard;
use App\Models\InstitutionMandate;
use App\Models\IsicActivity;
use App\Models\JobDescription;
use App\Models\LegacyBaseline;
use App\Models\LegalSource;
use App\Models\ManpowerPlan;
use App\Models\Occupation;
use App\Models\Organization;
use App\Models\OrganizationalChangeRequest;
use App\Models\OrganizationDesignReportExport;
use App\Models\OrganizationDesignStudy;
use App\Models\OrganizationEdge;
use App\Models\OrganizationFunction;
use App\Models\OrganizationPower;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType;
use App\Models\Permission; // alias to avoid clash with Illuminate\Support\ServiceProvider
use App\Models\Position;
use App\Models\PositionEstablishment;
use App\Models\PositionProfile;
use App\Models\PositionService;
use App\Models\Process;
use App\Models\PublicHoliday;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceClassification;
use App\Models\SystemSetting;
use App\Models\TransferAnnouncement;
use App\Models\TransferApplication;
use App\Models\TransferSetting;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Models\WorkforcePlanningMethodology;
use App\Observers\EmployeeAssignmentCardImpactObserver;
use App\Policies\AuditLogPolicy;
use App\Policies\CardRequestPolicy;
use App\Policies\CodeRulePolicy;
use App\Policies\DesignImportBatchPolicy;
use App\Policies\EmployeePolicy;
use App\Policies\EmployeeTransferPolicy;
use App\Policies\GradeLevelPolicy;
use App\Policies\IdCardPolicy;
use App\Policies\InstitutionMandatePolicy;
use App\Policies\IsicActivityPolicy;
use App\Policies\LegacyBaselinePolicy;
use App\Policies\LegalSourcePolicy;
use App\Policies\ManpowerPlanPolicy;
use App\Policies\OccupationPolicy;
use App\Policies\OrganizationalChangeRequestPolicy;
use App\Policies\OrganizationDesignReportExportPolicy;
use App\Policies\OrganizationDesignStudyPolicy;
use App\Policies\OrganizationEdgePolicy;
use App\Policies\OrganizationFunctionPolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\OrganizationTypePolicy;
use App\Policies\OrganizationUnitPolicy;
use App\Policies\OrganizationUnitTypePolicy;
use App\Policies\PermissionPolicy;
use App\Policies\PositionDesignPolicy;
use App\Policies\PositionEstablishmentPolicy;
use App\Policies\PositionPolicy;
use App\Policies\PositionServicePolicy;
use App\Policies\ProcessPolicy;
use App\Policies\PublicHolidayPolicy;
use App\Policies\RolePolicy;
use App\Policies\ServiceClassificationPolicy;
use App\Policies\ServicePolicy;
use App\Policies\SystemSettingPolicy;
use App\Policies\TransferAnnouncementPolicy;
use App\Policies\TransferApplicationPolicy;
use App\Policies\TransferSettingPolicy;
use App\Policies\UserOrganizationScopePolicy;
use App\Policies\UserPolicy;
use App\Policies\WorkforcePlanningMethodologyPolicy;
use App\Security\Hashing\MigratingArgon2IdHasher;
use App\Security\Passwords\CompromisedPasswordChecker;
use App\Security\Passwords\HibpCompromisedPasswordChecker;
use App\Services\Assistant\AssistantSettings;
use App\Services\Backup\Infrastructure\BackupInfrastructureAdapter;
use App\Services\Backup\Infrastructure\DisabledBackupAdapter;
use App\Services\Backup\Infrastructure\PgBackRestBackupAdapter;
use App\Services\Backup\Infrastructure\ReportBackupAdapter;
use App\Services\Calendar\CalendarService;
use App\Services\Calendar\EthiopianCalendarService;
use App\Services\Calendar\LocalizedDateService;
use App\Services\Employees\NullEmployeeLeaveProvider;
use App\Services\Integration\Events\IntegrationEventContext;
use App\Services\PublicAssistant\PublicAssistantSettings;
use App\Services\Security\SessionActivityService;
use App\Services\SituationRoom\SituationMemo;
use App\Services\Sms\BudgetedSmsGateway;
use App\Services\SystemSettings\SystemSettingsService;
use App\Support\Mail\RuntimeMailSettings;
use App\Support\Rbac\RoleBypassExemptions;
use App\Support\RichText\RichTextSanitizer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Situation Room: per-request memo of shared figures, never shared across requests or viewers.
        $this->app->scoped(SituationMemo::class);
        // Event integration: one correlation id and suppression set per request / job.
        $this->app->scoped(IntegrationEventContext::class);
        // One instance per request / job: holds the employees whose membership sync waits for the commit.

        $this->app->singleton(RuntimeMailSettings::class);
        $this->app->singleton(RichTextSanitizer::class, fn (): RichTextSanitizer => new RichTextSanitizer((array) config('richtext')));
        $this->app->singleton(EthiopianCalendarService::class);
        $this->app->singleton(CalendarService::class);
        $this->app->singleton(LocalizedDateService::class);

        // Backup status source (docs/backup-recovery-architecture.md). Disabled never reports healthy.
        $this->app->bind(BackupInfrastructureAdapter::class, fn ($app) => ! config('backup.enabled') ? new DisabledBackupAdapter
            : match (config('backup.driver')) {
                'pgbackrest' => $app->make(PgBackRestBackupAdapter::class),
                'report' => $app->make(ReportBackupAdapter::class),
                default => new DisabledBackupAdapter('INVALID_CONFIGURATION'),
            });

        // Password policy (docs/password-security-policy.md).
        $this->app->bind(CompromisedPasswordChecker::class, HibpCompromisedPasswordChecker::class);
        $this->app->afterResolving('hash', function ($manager): void {
            $manager->extend('argon2id_migrating', fn ($app) => new MigratingArgon2IdHasher((array) $app['config']->get('hashing.argon', [])));
        });

        // Every SMS passes the spend cap (SBH-003); HttpSmsGateway does the delivery.
        $this->app->bind(SmsGateway::class, BudgetedSmsGateway::class);

        // No leave module exists yet; bind the real provider here when one does.
        $this->app->bind(EmployeeLeaveProvider::class, NullEmployeeLeaveProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Core Stage 6 deployment safety: migrate:fresh, migrate:refresh,
        // migrate:reset and db:wipe refuse to run in production, whoever types them.
        DB::prohibitDestructiveCommands($this->app->isProduction());

        // TD-16: Sanctum checks a token's own expires_at; a token without one keeps
        // the default lifetime (8 hours) from its creation, as before.
        Sanctum::authenticateAccessTokensUsing(
            fn ($token, bool $isValid): bool => $isValid
                && ($token->expires_at !== null
                    || ($token->created_at !== null && $token->created_at->gt(now()->subMinutes(max(1, (int) config('sanctum.default_token_lifetime_minutes', 480)))))),
        );

        // Formatted text in server-rendered output (PDF, email): `@richtext($value)`
        // sanitizes against config/richtext.php and prints it; legacy plain text
        // becomes escaped paragraphs. The only sanctioned way to echo stored HTML.
        Blade::directive('richtext', fn (string $expression): string => "<?php echo app(\\App\\Support\\RichText\\RichTextSanitizer::class)->sanitize({$expression}) ?? ''; ?>");

        EmployeeAssignment::observe(EmployeeAssignmentCardImpactObserver::class);
        Organization::observe(EmployeeAssignmentCardImpactObserver::class);
        Position::observe(EmployeeAssignmentCardImpactObserver::class);
        $this->configureViteAssetMode();
        // No Vite::prefetch(): with 500+ page chunks it downloaded the whole 8 MB
        // bundle on every full page load, which is what made phones crawl. Pages
        // load their own chunk on demand (a few KB each, cached for a year).
        $this->applyRuntimeSystemSettings();

        // A worker boots once and runs for an hour or more: pick up email
        // settings an administrator saved since, before each job sends.
        Queue::before(function (): void {
            try {
                $this->app->make(RuntimeMailSettings::class)->apply();
            } catch (Throwable) {
                // The job still runs with the settings already in effect.
            }
        });

        // GET /up is the load balancer health check. It also proves the database
        // and the cache store answer: a listener that throws turns it into a 500,
        // so a node that lost its database is taken out of rotation.
        Event::listen(DiagnosingHealth::class, function (): void {
            DB::connection()->select('select 1');
            Cache::store()->get('health:probe');
        });

        Gate::policy(Organization::class, OrganizationPolicy::class);
        Gate::policy(OrganizationEdge::class, OrganizationEdgePolicy::class);
        Gate::policy(Employee::class, EmployeePolicy::class);
        Gate::policy(PositionService::class, PositionServicePolicy::class);
        Gate::policy(EmployeeTransfer::class, EmployeeTransferPolicy::class);
        Gate::policy(TransferSetting::class, TransferSettingPolicy::class);
        Gate::policy(TransferAnnouncement::class, TransferAnnouncementPolicy::class);
        Gate::policy(TransferApplication::class, TransferApplicationPolicy::class);
        Gate::policy(IdCard::class, IdCardPolicy::class);
        Gate::policy(CardRequest::class, CardRequestPolicy::class);
        Gate::policy(CodeRule::class, CodeRulePolicy::class);
        Gate::policy(AuditLog::class, AuditLogPolicy::class);
        Gate::policy(OrganizationType::class, OrganizationTypePolicy::class);
        Gate::policy(OrganizationalChangeRequest::class, OrganizationalChangeRequestPolicy::class);
        Gate::policy(OrganizationUnit::class, OrganizationUnitPolicy::class);
        Gate::policy(OrganizationUnitType::class, OrganizationUnitTypePolicy::class);
        Gate::policy(Occupation::class, OccupationPolicy::class);
        Gate::policy(InstitutionMandate::class, InstitutionMandatePolicy::class);
        Gate::policy(LegalSource::class, LegalSourcePolicy::class);
        Gate::policy(LegacyBaseline::class, LegacyBaselinePolicy::class);
        Gate::policy(DesignImportBatch::class, DesignImportBatchPolicy::class);
        Gate::policy(OrganizationFunction::class, OrganizationFunctionPolicy::class);
        Gate::policy(OrganizationPower::class, OrganizationFunctionPolicy::class);
        Gate::policy(Service::class, ServicePolicy::class);
        Gate::policy(Process::class, ProcessPolicy::class);
        Gate::policy(OrganizationDesignStudy::class, OrganizationDesignStudyPolicy::class);
        Gate::policy(ManpowerPlan::class, ManpowerPlanPolicy::class);
        Gate::policy(PositionProfile::class, PositionDesignPolicy::class);
        Gate::policy(JobDescription::class, PositionDesignPolicy::class);
        Gate::policy(OrganizationDesignReportExport::class, OrganizationDesignReportExportPolicy::class);
        Gate::policy(WorkforcePlanningMethodology::class, WorkforcePlanningMethodologyPolicy::class);
        Gate::policy(ServiceClassification::class, ServiceClassificationPolicy::class);
        Gate::policy(IsicActivity::class, IsicActivityPolicy::class);
        Gate::policy(GradeLevel::class, GradeLevelPolicy::class);
        Gate::policy(Position::class, PositionPolicy::class);
        Gate::policy(PositionEstablishment::class, PositionEstablishmentPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(UserOrganizationScope::class, UserOrganizationScopePolicy::class);
        Gate::policy(Permission::class, PermissionPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(SystemSetting::class, SystemSettingPolicy::class);
        Gate::policy(PublicHoliday::class, PublicHolidayPolicy::class);

        // Modules under Modules/ register their own policies and morph-map names (ADR 0017).

        /*
         * Super Admin passes every check, except the self-protection rules:
         * nobody (Super Admin included) deletes, deactivates, archives or
         * re-roles/re-scopes their own account. Those abilities fall through
         * to UserPolicy when the target is the acting user.
         */
        Gate::before(static function ($user, string $ability, array $arguments = []): ?bool {
            if (! $user instanceof User || ! $user->hasRole('Super Admin')) {
                return null;
            }
            $target = $arguments[0] ?? null;
            // Records never opened by role (private communication, reported content, investigation
            // access): their policies decide, by membership or an approved grant. Modules register
            // theirs (RoleBypassExemptions), whether the check is on a record or on the class.
            $private = RoleBypassExemptions::classes();
            if ((is_object($target) && in_array($target::class, $private, true)) || (is_string($target) && in_array($target, $private, true))) {
                return null;
            }
            if ($target instanceof User && $target->is($user)
                && in_array($ability, ['delete', 'forceDelete', 'archive', 'deactivate', 'assignRoles', 'assignOrganizationScope'], true)) {
                return null;
            }
            // Segregation of duties: nobody approves the card request they submitted.
            if ($ability === 'approve' && $target instanceof CardRequest && $target->isRequestedBy($user)) {
                return null;
            }
            // The card request workflow state binds a Super Admin too: a closed request is not reopened.
            if ($target instanceof CardRequest && in_array($ability, ['verify', 'approve', 'reject', 'cancel'], true) && ! $target->allowsStep($ability)) {
                return false;
            }
            // Same rule for transfer and vacancy applications: the policy refuses the
            // applicant every workflow decision, even a Super Admin applicant.
            if (($target instanceof TransferApplication || $target instanceof VacancyApplication)
                && $user->employee_id !== null && $target->employee_id === $user->employee_id) {
                return null;
            }

            return true;
        });

        /*
         * Public ID Checker. Keyed on card + IP so one abusive client cannot
         * spend another card's budget, and so a single card cannot be used to
         * spam its holder with codes from many addresses.
         */
        $perUser = static fn (Request $request): string => (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());
        // Addis One Assistant (docs/assistant/security.md): per person, both ceilings from System Settings › Assistant.
        RateLimiter::for('assistant', function (Request $request) use ($perUser): array {
            $settings = app(AssistantSettings::class);

            return [
                Limit::perMinute($settings->rateLimitPerMinute())->by('assistant:'.$perUser($request)),
                Limit::perDay($settings->dailyMessageCap())->by('assistant-d:'.$perUser($request)),
            ];
        });
        // Addis One Help, anonymous (docs/public-assistant/security.md): both ceilings per address, from settings —
        // a session key would let a cookieless client rotate its way around the per-minute limit.
        RateLimiter::for('public-assistant', function (Request $request): array {
            $settings = app(PublicAssistantSettings::class);

            return [
                Limit::perMinute($settings->rateLimitPerMinute())->by('pa:'.$request->ip()),
                Limit::perDay($settings->dailyCap())->by('pa-d:'.$request->ip()),
            ];
        });
        RateLimiter::for('id-checker-send-otp', fn (Request $request): array => [
            Limit::perMinutes(10, 3)->by('pic-send:'.$request->route('cardUuid').'|'.$request->ip()),
            // Second ceiling: one IP may not farm codes across many cards.
            Limit::perMinutes(10, 10)->by('pic-send-ip:'.$request->ip()),
        ]);

        RateLimiter::for('id-checker-verify-otp', fn (Request $request): array => [
            Limit::perMinute(5)->by('pic-verify:'.$request->route('cardUuid').'|'.$request->ip()),
        ]);

        RateLimiter::for('api', function (Request $request): array {
            $perMinute = 120;

            try {
                if (Schema::hasTable('system_settings')) {
                    /** @var SystemSettingsService $settingsService */
                    $settingsService = app(SystemSettingsService::class);
                    $perMinute = max(30, (int) $settingsService->get('security', 'api_rate_limit_per_minute', 120));
                }
            } catch (Throwable) {
                $perMinute = 120;
            }

            return [
                Limit::perMinute($perMinute)->by($request->user()?->getAuthIdentifier() ?: $request->ip()),
            ];
        });
    }

    private function configureViteAssetMode(): void
    {
        if ($this->app->isLocal() && config('app.debug')) {
            return;
        }

        // A stale public/hot file must never point production browsers at a
        // developer machine. Non-local environments always use built assets.
        Vite::useHotFile(storage_path('framework/vite.hot'));
    }

    private function applyRuntimeSystemSettings(): void
    {
        // Until settings load (or when they cannot), storage still outlives
        // the fallback idle timeout.
        config(['session.lifetime' => SessionActivityService::storageLifetimeMinutes((int) config('security.session.idle_timeout_minutes', 120))]);

        try {
            if (! Schema::hasTable('system_settings')) {
                return;
            }

            /** @var SystemSettingsService $settingsService */
            $settingsService = app(SystemSettingsService::class);

            $appName = (string) $settingsService->get('general', 'application_name', config('app.name'));
            $defaultLocale = (string) $settingsService->get('localization', 'default_locale', config('app.locale', 'en'));
            $fallbackLocale = (string) $settingsService->get('localization', 'fallback_locale', config('app.fallback_locale', 'en'));
            $timezone = (string) $settingsService->get('localization', 'timezone', config('app.timezone', 'UTC'));
            // Idle timeout is the setting; storage lifetime is derived from it
            // (idle + grace) so the two can never disagree. See
            // docs/session-management.md.
            $idleTimeout = max(5, min(1440, (int) $settingsService->get('security', 'session_timeout_minutes', (int) config('security.session.idle_timeout_minutes', 120))));
            $forceHttps = filter_var($settingsService->get('security', 'force_https', false), FILTER_VALIDATE_BOOLEAN);

            config([
                'app.name' => $appName,
                'app.locale' => $defaultLocale,
                'app.fallback_locale' => $fallbackLocale,
                'app.timezone' => $timezone,
                'security.session.idle_timeout_minutes' => $idleTimeout,
                'session.lifetime' => SessionActivityService::storageLifetimeMinutes($idleTimeout),
            ]);

            $this->app->make(RuntimeMailSettings::class)->apply(force: true);

            date_default_timezone_set($timezone);

            if ($forceHttps) {
                URL::forceScheme('https');
            }
        } catch (Throwable) {
            // Runtime settings should never block app boot.
        }
    }
}
