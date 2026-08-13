<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\CafeteriaDayRule;
use App\Models\CafeteriaProvider;
use App\Models\CafeteriaProviderUser;
use App\Models\CafeteriaReportRun;
use App\Models\CafeteriaSetting;
use App\Models\CafeteriaSpecialDay;
use App\Models\CafeteriaSubsidyLedger;
use App\Models\CafeteriaSubsidyRule;
use App\Models\CafeteriaTransaction;
use App\Models\CardRequest;
use App\Models\CodeRule;
use App\Models\Employee;
use App\Models\EmployeeCafeteriaExclusion;
use App\Models\EmployeeTransfer;
use App\Models\Entitlement;
use App\Models\EntitlementRule;
use App\Models\GradeLevel;
use App\Models\IdCard;
use App\Models\IsicActivity;
use App\Models\Occupation;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType;
use App\Models\Permission;
use App\Models\Position;
use App\Models\PositionEstablishment;
use App\Models\PublicHoliday;
use App\Models\Role;
use App\Models\ServiceProvider as ServiceProviderModel;
use App\Models\ServiceTransaction;
use App\Models\ServiceType; // alias to avoid clash with Illuminate\Support\ServiceProvider
use App\Models\SystemSetting;
use App\Models\TransferAnnouncement;
use App\Models\TransferApplication;
use App\Models\TransferSetting;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Models\VacancyAnnouncement;
use App\Models\VacancyApplication;
use App\Policies\AuditLogPolicy;
use App\Policies\CafeteriaDayRulePolicy;
use App\Policies\CafeteriaProviderPolicy;
use App\Policies\CafeteriaProviderUserPolicy;
use App\Policies\CafeteriaReportRunPolicy;
use App\Policies\CafeteriaSettingPolicy;
use App\Policies\CafeteriaSpecialDayPolicy;
use App\Policies\CafeteriaSubsidyLedgerPolicy;
use App\Policies\CafeteriaSubsidyRulePolicy;
use App\Policies\CafeteriaTransactionPolicy;
use App\Policies\CardRequestPolicy;
use App\Policies\CodeRulePolicy;
use App\Policies\EmployeeCafeteriaExclusionPolicy;
use App\Policies\EmployeePolicy;
use App\Policies\EmployeeTransferPolicy;
use App\Policies\EntitlementPolicy;
use App\Policies\EntitlementRulePolicy;
use App\Policies\GradeLevelPolicy;
use App\Policies\IdCardPolicy;
use App\Policies\IsicActivityPolicy;
use App\Policies\OccupationPolicy;
use App\Policies\OrganizationEdgePolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\OrganizationTypePolicy;
use App\Policies\OrganizationUnitPolicy;
use App\Policies\OrganizationUnitTypePolicy;
use App\Policies\PermissionPolicy;
use App\Policies\PositionEstablishmentPolicy;
use App\Policies\PositionPolicy;
use App\Policies\PublicHolidayPolicy;
use App\Policies\RolePolicy;
use App\Policies\ServiceProviderPolicy;
use App\Policies\ServiceTransactionPolicy;
use App\Policies\ServiceTypePolicy;
use App\Policies\SystemSettingPolicy;
use App\Policies\TransferAnnouncementPolicy;
use App\Policies\TransferApplicationPolicy;
use App\Policies\TransferSettingPolicy;
use App\Policies\UserOrganizationScopePolicy;
use App\Policies\UserPolicy;
use App\Policies\VacancyAnnouncementPolicy;
use App\Policies\VacancyApplicationPolicy;
use App\Services\Calendar\CalendarService;
use App\Services\Calendar\EthiopianCalendarService;
use App\Services\Calendar\LocalizedDateService;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(EthiopianCalendarService::class);
        $this->app->singleton(CalendarService::class);
        $this->app->singleton(LocalizedDateService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
        $this->applyRuntimeSystemSettings();

        Gate::policy(Organization::class, OrganizationPolicy::class);
        Gate::policy(OrganizationEdge::class, OrganizationEdgePolicy::class);
        Gate::policy(Employee::class, EmployeePolicy::class);
        Gate::policy(EmployeeTransfer::class, EmployeeTransferPolicy::class);
        Gate::policy(TransferSetting::class, TransferSettingPolicy::class);
        Gate::policy(TransferAnnouncement::class, TransferAnnouncementPolicy::class);
        Gate::policy(TransferApplication::class, TransferApplicationPolicy::class);
        Gate::policy(IdCard::class, IdCardPolicy::class);
        Gate::policy(CardRequest::class, CardRequestPolicy::class);
        Gate::policy(CodeRule::class, CodeRulePolicy::class);
        Gate::policy(Entitlement::class, EntitlementPolicy::class);
        Gate::policy(EntitlementRule::class, EntitlementRulePolicy::class);
        Gate::policy(ServiceTransaction::class, ServiceTransactionPolicy::class);
        Gate::policy(ServiceProviderModel::class, ServiceProviderPolicy::class);
        Gate::policy(ServiceType::class, ServiceTypePolicy::class);
        Gate::policy(AuditLog::class, AuditLogPolicy::class);
        Gate::policy(OrganizationType::class, OrganizationTypePolicy::class);
        Gate::policy(OrganizationUnit::class, OrganizationUnitPolicy::class);
        Gate::policy(OrganizationUnitType::class, OrganizationUnitTypePolicy::class);
        Gate::policy(Occupation::class, OccupationPolicy::class);
        Gate::policy(IsicActivity::class, IsicActivityPolicy::class);
        Gate::policy(GradeLevel::class, GradeLevelPolicy::class);
        Gate::policy(Position::class, PositionPolicy::class);
        Gate::policy(PositionEstablishment::class, PositionEstablishmentPolicy::class);
        Gate::policy(VacancyAnnouncement::class, VacancyAnnouncementPolicy::class);
        Gate::policy(VacancyApplication::class, VacancyApplicationPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(UserOrganizationScope::class, UserOrganizationScopePolicy::class);
        Gate::policy(Permission::class, PermissionPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(SystemSetting::class, SystemSettingPolicy::class);
        Gate::policy(CafeteriaProvider::class, CafeteriaProviderPolicy::class);
        Gate::policy(CafeteriaProviderUser::class, CafeteriaProviderUserPolicy::class);
        Gate::policy(CafeteriaSubsidyRule::class, CafeteriaSubsidyRulePolicy::class);
        Gate::policy(PublicHoliday::class, PublicHolidayPolicy::class);
        Gate::policy(CafeteriaTransaction::class, CafeteriaTransactionPolicy::class);
        Gate::policy(CafeteriaSubsidyLedger::class, CafeteriaSubsidyLedgerPolicy::class);
        Gate::policy(CafeteriaReportRun::class, CafeteriaReportRunPolicy::class);
        Gate::policy(CafeteriaSetting::class, CafeteriaSettingPolicy::class);
        Gate::policy(CafeteriaDayRule::class, CafeteriaDayRulePolicy::class);
        Gate::policy(CafeteriaSpecialDay::class, CafeteriaSpecialDayPolicy::class);
        Gate::policy(EmployeeCafeteriaExclusion::class, EmployeeCafeteriaExclusionPolicy::class);

        Gate::before(static fn ($user, string $_ability) => $user instanceof User && $user->hasRole('Super Admin') ? true : null);

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

    private function applyRuntimeSystemSettings(): void
    {
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
            $sessionLifetime = max(5, (int) $settingsService->get('security', 'session_timeout_minutes', (int) config('session.lifetime', 120)));
            $forceHttps = filter_var($settingsService->get('security', 'force_https', false), FILTER_VALIDATE_BOOLEAN);

            config([
                'app.name' => $appName,
                'app.locale' => $defaultLocale,
                'app.fallback_locale' => $fallbackLocale,
                'app.timezone' => $timezone,
                'session.lifetime' => $sessionLifetime,
                'mail.default' => (string) $settingsService->get('email', 'mail_mailer', config('mail.default', 'smtp')),
                'mail.mailers.smtp.host' => (string) $settingsService->get('email', 'mail_host', config('mail.mailers.smtp.host')),
                'mail.mailers.smtp.port' => (int) $settingsService->get('email', 'mail_port', config('mail.mailers.smtp.port', 587)),
                'mail.mailers.smtp.username' => $settingsService->get('email', 'mail_username', config('mail.mailers.smtp.username')),
                'mail.mailers.smtp.password' => $settingsService->get('email', 'mail_password', config('mail.mailers.smtp.password')),
                'mail.mailers.smtp.encryption' => $this->normalizeMailEncryption(
                    $settingsService->get('email', 'mail_encryption', config('mail.mailers.smtp.encryption')),
                ),
                'mail.from.address' => (string) $settingsService->get('email', 'mail_from_address', config('mail.from.address')),
                'mail.from.name' => (string) $settingsService->get('email', 'mail_from_name', config('mail.from.name', $appName)),
            ]);

            date_default_timezone_set($timezone);

            if ($forceHttps) {
                URL::forceScheme('https');
            }
        } catch (Throwable) {
            // Runtime settings should never block app boot.
        }
    }

    private function normalizeMailEncryption(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === 'none') {
            return null;
        }

        return (string) $value;
    }
}
