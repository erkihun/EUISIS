<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Providers;

use App\Support\NotificationPresenter;
use App\Support\Rbac\RoleBypassExemptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\ServiceMonitoring\Application\Notifications\ServiceMonitoringNotification;
use Modules\ServiceMonitoring\Application\Policies\ActionItemPolicy;
use Modules\ServiceMonitoring\Application\Policies\ClarificationPolicy;
use Modules\ServiceMonitoring\Application\Policies\DigitalServicePolicy;
use Modules\ServiceMonitoring\Application\Policies\FormTemplatePolicy;
use Modules\ServiceMonitoring\Application\Policies\MonitoringAttachmentPolicy;
use Modules\ServiceMonitoring\Application\Policies\MonitoringExportPolicy;
use Modules\ServiceMonitoring\Application\Policies\MonitoringSettingPolicy;
use Modules\ServiceMonitoring\Application\Policies\MonitoringVisitPolicy;
use Modules\ServiceMonitoring\Application\Policies\ParticipantPolicy;
use Modules\ServiceMonitoring\Application\Policies\ReportingPeriodPolicy;
use Modules\ServiceMonitoring\Application\Policies\SubmissionPolicy;
use Modules\ServiceMonitoring\Application\Queries\ServiceMonitoringIndicatorsQuery;
use Modules\ServiceMonitoring\Application\Services\ConsolidationService;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAccess;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringSettings;
use Modules\ServiceMonitoring\Contracts\ServiceMonitoringIndicators;
use Modules\ServiceMonitoring\Domain\Models\ActionItem;
use Modules\ServiceMonitoring\Domain\Models\Clarification;
use Modules\ServiceMonitoring\Domain\Models\DigitalService;
use Modules\ServiceMonitoring\Domain\Models\FormTemplate;
use Modules\ServiceMonitoring\Domain\Models\MonitoringAttachment;
use Modules\ServiceMonitoring\Domain\Models\MonitoringExport;
use Modules\ServiceMonitoring\Domain\Models\MonitoringSetting;
use Modules\ServiceMonitoring\Domain\Models\MonitoringVisit;
use Modules\ServiceMonitoring\Domain\Models\Participant;
use Modules\ServiceMonitoring\Domain\Models\ReportingPeriod;
use Modules\ServiceMonitoring\Domain\Models\Submission;
use Modules\ServiceMonitoring\Infrastructure\Console\CreateFiscalYearCommand;
use Modules\ServiceMonitoring\Infrastructure\Console\SendRemindersCommand;
use Modules\ServiceMonitoring\Infrastructure\Console\SuggestParticipantsCommand;

/**
 * Everything the application needs to know about Service Delivery
 * Monitoring & Support (ADR 0017): its public contract, policies, notice
 * renderer and console commands. Routes live in routes/service-monitoring.php;
 * the daily reminder run is scheduled in routes/console.php.
 */
final class ServiceMonitoringServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ServiceMonitoringIndicators::class, ServiceMonitoringIndicatorsQuery::class);
        // Per request (or job): cached scope answers and settings never leak between users.
        $this->app->scoped(ServiceMonitoringAccess::class);
        $this->app->scoped(ServiceMonitoringSettings::class);
        $this->app->scoped(ConsolidationService::class);
    }

    public function boot(): void
    {
        // Policy discovery only looks in App\Policies.
        Gate::policy(ReportingPeriod::class, ReportingPeriodPolicy::class);
        Gate::policy(Participant::class, ParticipantPolicy::class);
        Gate::policy(Submission::class, SubmissionPolicy::class);
        Gate::policy(DigitalService::class, DigitalServicePolicy::class);
        Gate::policy(Clarification::class, ClarificationPolicy::class);
        Gate::policy(MonitoringVisit::class, MonitoringVisitPolicy::class);
        Gate::policy(ActionItem::class, ActionItemPolicy::class);
        Gate::policy(MonitoringAttachment::class, MonitoringAttachmentPolicy::class);
        Gate::policy(MonitoringExport::class, MonitoringExportPolicy::class);
        Gate::policy(MonitoringSetting::class, MonitoringSettingPolicy::class);
        Gate::policy(FormTemplate::class, FormTemplatePolicy::class);

        // Every rule here depends on the record's state (locked period, accepted
        // report, own submission) as much as on the role, so the Super Admin role
        // bypass must not skip them: the policies decide. Permissions and city-wide
        // reach still come from the role (OrganizationScopeService::isUnrestricted).
        RoleBypassExemptions::register([
            ReportingPeriod::class, Participant::class, Submission::class, DigitalService::class, Clarification::class,
            MonitoringVisit::class, ActionItem::class, MonitoringAttachment::class, MonitoringExport::class, MonitoringSetting::class,
        ]);

        NotificationPresenter::registerRenderer('service_monitoring', ServiceMonitoringNotification::class);

        if ($this->app->runningInConsole()) {
            $this->commands([CreateFiscalYearCommand::class, SuggestParticipantsCommand::class, SendRemindersCommand::class]);
        }
    }
}
