<?php

declare(strict_types=1);

namespace App\Providers;

use App\Backbone\Events\AssignmentChanged;
use App\Backbone\Events\EmployeeChanged;
use App\Contracts\EmployeeLeaveProvider;
use App\Contracts\SmsGateway;
use App\Http\Middleware\AuthenticateTransportDevice;
use App\Models\AssessmentForm;
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
use App\Models\Communication\Announcement;
use App\Models\Communication\Channel;
use App\Models\Communication\CommunicationAccessRequest;
use App\Models\Communication\CommunicationReport;
use App\Models\Communication\CommunicationTask;
use App\Models\Communication\Conversation;
use App\Models\Communication\Message;
use App\Models\Communication\MessageAttachment;
use App\Models\DailyActivityLog;
use App\Models\DesignImportBatch;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeCafeteriaExclusion;
use App\Models\EmployeeServiceFeedback;
use App\Models\EmployeeTransfer;
use App\Models\Entitlement;
use App\Models\EntitlementRule;
use App\Models\FieldWorkRequest;
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
use App\Models\OrganizationUnit; // alias to avoid clash with Illuminate\Support\ServiceProvider
use App\Models\OrganizationUnitType;
use App\Models\Permission;
use App\Models\Position;
use App\Models\PositionEstablishment;
use App\Models\PositionProfile;
use App\Models\PositionService;
use App\Models\Process;
use App\Models\ProviderUser;
use App\Models\PublicFeedbackReportExport;
use App\Models\PublicHoliday;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceClassification;
use App\Models\ServiceMonitoringForm;
use App\Models\ServiceMonitoringPeriod;
use App\Models\ServiceMonitoringSubmission;
use App\Models\ServiceProvider as ServiceProviderModel;
use App\Models\ServiceTransaction;
use App\Models\ServiceType;
use App\Models\SystemSetting;
use App\Models\TransferAnnouncement;
use App\Models\TransferApplication;
use App\Models\TransferSetting;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Models\VacancyAnnouncement;
use App\Models\VacancyApplication;
use App\Models\WorkforcePlanningMethodology;
use App\Observers\EmployeeAssignmentCardImpactObserver;
use App\Policies\AssessmentFormPolicy;
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
use App\Policies\Communication\AnnouncementPolicy;
use App\Policies\Communication\ChannelPolicy;
use App\Policies\Communication\CommunicationAccessRequestPolicy;
use App\Policies\Communication\CommunicationReportPolicy;
use App\Policies\Communication\CommunicationTaskPolicy;
use App\Policies\Communication\ConversationPolicy;
use App\Policies\Communication\MessageAttachmentPolicy;
use App\Policies\Communication\MessagePolicy;
use App\Policies\DailyActivityLogPolicy;
use App\Policies\DesignImportBatchPolicy;
use App\Policies\EmployeeCafeteriaExclusionPolicy;
use App\Policies\EmployeePolicy;
use App\Policies\EmployeeServiceFeedbackPolicy;
use App\Policies\EmployeeTransferPolicy;
use App\Policies\EntitlementPolicy;
use App\Policies\EntitlementRulePolicy;
use App\Policies\FieldWorkRequestPolicy;
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
use App\Policies\ProviderUserPolicy;
use App\Policies\PublicFeedbackReportExportPolicy;
use App\Policies\PublicHolidayPolicy;
use App\Policies\RolePolicy;
use App\Policies\ServiceClassificationPolicy;
use App\Policies\ServiceMonitoringFormPolicy;
use App\Policies\ServiceMonitoringPeriodPolicy;
use App\Policies\ServiceMonitoringSubmissionPolicy;
use App\Policies\ServicePolicy;
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
use App\Services\Communication\AttachmentScanner;
use App\Services\Communication\CommunicationMembershipSync;
use App\Services\Communication\PendingAttachmentScanner;
use App\Services\DailyActivity\NullEmployeeLeaveProvider;
use App\Services\Integration\Events\IntegrationEventContext;
use App\Services\PublicAssistant\PublicAssistantSettings;
use App\Services\Security\SessionActivityService;
use App\Services\ServiceFeedback\PublicFeedbackQuestionnaire;
use App\Services\ServiceFeedback\PublicFeedbackSettings;
use App\Services\SituationRoom\SituationMemo;
use App\Services\Sms\BudgetedSmsGateway;
use App\Services\SystemSettings\SystemSettingsService;
use App\Services\Transport\TransportEligibilitySync;
use App\Support\Mail\RuntimeMailSettings;
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
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Communication malware-scan hook: replace with a real scanner binding in production.
        $this->app->bindIf(AttachmentScanner::class, PendingAttachmentScanner::class);
        // Situation Room: per-request memo of shared figures, never shared across requests or viewers.
        $this->app->scoped(SituationMemo::class);
        // Event integration: one correlation id and suppression set per request / job.
        $this->app->scoped(IntegrationEventContext::class);
        // One instance per request: validation, rendering and storage read the same questionnaire version.
        $this->app->scoped(PublicFeedbackQuestionnaire::class);
        // One instance per request / job: holds the employees whose membership sync waits for the commit.
        $this->app->scoped(CommunicationMembershipSync::class);

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

        // Formatted text in server-rendered output (PDF, email): `@richtext($value)`
        // sanitizes against config/richtext.php and prints it; legacy plain text
        // becomes escaped paragraphs. The only sanctioned way to echo stored HTML.
        Blade::directive('richtext', fn (string $expression): string => "<?php echo app(\\App\\Support\\RichText\\RichTextSanitizer::class)->sanitize({$expression}) ?? ''; ?>");

        EmployeeAssignment::observe(EmployeeAssignmentCardImpactObserver::class);
        Organization::observe(EmployeeAssignmentCardImpactObserver::class);
        Position::observe(EmployeeAssignmentCardImpactObserver::class);
        // Service modules follow the backbone through its domain events (master-backbone.md):
        // Communication channel membership follows assignments and account status,
        // Transport route assignments follow placement and status (docs/transport/eligibility.md).
        Event::listen(AssignmentChanged::class, [CommunicationMembershipSync::class, 'onAssignmentChanged']);
        Event::listen(EmployeeChanged::class, [CommunicationMembershipSync::class, 'onEmployeeChanged']);
        Event::listen(AssignmentChanged::class, [TransportEligibilitySync::class, 'onAssignmentChanged']);
        Event::listen(EmployeeChanged::class, [TransportEligibilitySync::class, 'onEmployeeChanged']);
        User::saved(function (User $user): void {
            if ($user->employee_id !== null && $user->wasChanged('status')) {
                app(CommunicationMembershipSync::class)->queue($user->employee_id);
            }
        });
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

        Gate::policy(AssessmentForm::class, AssessmentFormPolicy::class);
        Gate::policy(ServiceMonitoringForm::class, ServiceMonitoringFormPolicy::class);
        Gate::policy(ServiceMonitoringPeriod::class, ServiceMonitoringPeriodPolicy::class);
        Gate::policy(ServiceMonitoringSubmission::class, ServiceMonitoringSubmissionPolicy::class);
        Gate::policy(Organization::class, OrganizationPolicy::class);
        Gate::policy(OrganizationEdge::class, OrganizationEdgePolicy::class);
        Gate::policy(Employee::class, EmployeePolicy::class);
        Gate::policy(EmployeeServiceFeedback::class, EmployeeServiceFeedbackPolicy::class);
        Gate::policy(PositionService::class, PositionServicePolicy::class);
        Gate::policy(EmployeeTransfer::class, EmployeeTransferPolicy::class);
        Gate::policy(FieldWorkRequest::class, FieldWorkRequestPolicy::class);
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
        Gate::policy(OrganizationalChangeRequest::class, OrganizationalChangeRequestPolicy::class);
        Gate::policy(DailyActivityLog::class, DailyActivityLogPolicy::class);
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
        Gate::policy(PublicFeedbackReportExport::class, PublicFeedbackReportExportPolicy::class);
        Gate::policy(WorkforcePlanningMethodology::class, WorkforcePlanningMethodologyPolicy::class);
        Gate::policy(ServiceClassification::class, ServiceClassificationPolicy::class);
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
        Gate::policy(ProviderUser::class, ProviderUserPolicy::class);
        Gate::policy(CafeteriaSubsidyRule::class, CafeteriaSubsidyRulePolicy::class);
        Gate::policy(PublicHoliday::class, PublicHolidayPolicy::class);
        Gate::policy(CafeteriaTransaction::class, CafeteriaTransactionPolicy::class);
        Gate::policy(CafeteriaSubsidyLedger::class, CafeteriaSubsidyLedgerPolicy::class);
        Gate::policy(CafeteriaReportRun::class, CafeteriaReportRunPolicy::class);
        Gate::policy(CafeteriaSetting::class, CafeteriaSettingPolicy::class);
        Gate::policy(CafeteriaDayRule::class, CafeteriaDayRulePolicy::class);
        Gate::policy(CafeteriaSpecialDay::class, CafeteriaSpecialDayPolicy::class);
        Gate::policy(EmployeeCafeteriaExclusion::class, EmployeeCafeteriaExclusionPolicy::class);

        // Communication & Collaboration (docs/communication-module.md).
        Gate::policy(Conversation::class, ConversationPolicy::class);
        Gate::policy(Message::class, MessagePolicy::class);
        Gate::policy(MessageAttachment::class, MessageAttachmentPolicy::class);
        Gate::policy(Channel::class, ChannelPolicy::class);
        Gate::policy(Announcement::class, AnnouncementPolicy::class);
        Gate::policy(CommunicationTask::class, CommunicationTaskPolicy::class);
        Gate::policy(CommunicationReport::class, CommunicationReportPolicy::class);
        Gate::policy(CommunicationAccessRequest::class, CommunicationAccessRequestPolicy::class);

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
            // Private communication is never opened by role (docs/communication-module.md §Privacy):
            // the policy decides, by membership or an approved break-glass grant.
            // Reported content and investigation access follow the same rule, whether the check is on a
            // record or on the class (viewAny, create).
            $private = [
                Conversation::class,
                Message::class,
                MessageAttachment::class,
                CommunicationAccessRequest::class,
                CommunicationReport::class,
            ];
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
        /*
         * Communication (docs/communication-module.md §Abuse protection): per person,
         * so one account cannot flood a conversation, the directory or the moderators.
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
        RateLimiter::for('communication-send', fn (Request $request): array => [
            Limit::perMinute(40)->by('comm-send:'.$perUser($request)),
            Limit::perHour(600)->by('comm-send-h:'.$perUser($request)),
        ]);
        RateLimiter::for('communication-start', fn (Request $request): Limit => Limit::perMinute(20)->by('comm-start:'.$perUser($request)));
        RateLimiter::for('communication-upload', fn (Request $request): Limit => Limit::perMinute(20)->by('comm-upload:'.$perUser($request)));
        RateLimiter::for('communication-search', fn (Request $request): Limit => Limit::perMinute(40)->by('comm-search:'.$perUser($request)));
        RateLimiter::for('communication-react', fn (Request $request): Limit => Limit::perMinute(90)->by('comm-react:'.$perUser($request)));
        RateLimiter::for('communication-typing', fn (Request $request): Limit => Limit::perMinute(30)->by('comm-typing:'.$perUser($request)));
        RateLimiter::for('communication-group', fn (Request $request): Limit => Limit::perHour(15)->by('comm-group:'.$perUser($request)));
        RateLimiter::for('communication-channel', fn (Request $request): Limit => Limit::perHour(15)->by('comm-channel:'.$perUser($request)));
        RateLimiter::for('communication-report', fn (Request $request): Limit => Limit::perHour(20)->by('comm-report:'.$perUser($request)));

        RateLimiter::for('id-checker-send-otp', fn (Request $request): array => [
            Limit::perMinutes(10, 3)->by('pic-send:'.$request->route('cardUuid').'|'.$request->ip()),
            // Second ceiling: one IP may not farm codes across many cards.
            Limit::perMinutes(10, 10)->by('pic-send-ip:'.$request->ip()),
        ]);

        RateLimiter::for('id-checker-verify-otp', fn (Request $request): array => [
            Limit::perMinute(5)->by('pic-verify:'.$request->route('cardUuid').'|'.$request->ip()),
        ]);

        /*
         * Public service feedback submission.
         *
         * Two ceilings on purpose. The first stops one client hammering a
         * single employee's QR to bury them in ratings; the second stops one
         * address spraying fabricated feedback across many employees. Without
         * the second, a script could rate every desk in a building from one
         * machine and still stay under a per-token limit.
         */
        // Brief §37–38: per QR code and per address, thresholds from the public feedback policy.
        RateLimiter::for('service-feedback-submit', function (Request $request): array {
            $perSource = 3;
            $perIp = 8;

            try {
                if (Schema::hasTable('system_settings')) {
                    $policy = app(PublicFeedbackSettings::class);
                    $perSource = $policy->rateLimitPerSource();
                    $perIp = $policy->rateLimitPerIp();
                }
            } catch (Throwable) {
                // Defaults stand while the database is unavailable.
            }

            return [
                Limit::perMinutes(10, $perSource)->by('sf-submit:'.$request->route('token').'|'.$request->ip()),
                Limit::perMinutes(10, $perIp)->by('sf-submit-ip:'.$request->ip()),
            ];
        });

        /*
         * The photo on the employee feedback page: about one request per screen
         * of a visit, and the browser reuses it, so this only stops a script
         * pulling photos in bulk. The per-IP ceiling stays generous because a
         * whole service hall can share one mobile carrier address.
         */
        RateLimiter::for('service-feedback-photo', fn (Request $request): array => [
            Limit::perMinute(30)->by('sf-photo:'.$request->route('token').'|'.$request->ip()),
            Limit::perMinute(120)->by('sf-photo-ip:'.$request->ip()),
        ]);

        /*
         * Transport scanner devices (docs/transport/device-security.md): a bus
         * door boards at most a few people a second, so 300 scans a minute per
         * device never throttles a legitimate queue, while a provider's whole
         * fleet shares a wider ceiling and unknown credentials are capped by IP.
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
