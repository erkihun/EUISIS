<?php

declare(strict_types=1);

namespace App\Services\Intelligence;

use App\Models\User;

/**
 * The Intelligence area registry: which areas exist, in what order, which
 * grant opens each, which launcher section and icon it uses, and whether it
 * belongs to the business or the technical domain. The launcher, the routes,
 * the sidebar tests and the controllers read this list, so adding an area is
 * one case here plus its analysis class.
 */
enum IntelligenceArea: string
{
    case Overview = 'overview';
    case Executive = 'executive';
    case Workforce = 'workforce';
    case Service = 'service';
    case PublicVoice = 'public_voice';
    case Performance = 'performance';
    case Forecasting = 'forecasting';
    case DataQuality = 'data_quality';
    case Alerts = 'alerts';
    case Knowledge = 'knowledge';
    case SystemHealth = 'system_health';
    case Incidents = 'incidents';
    case Diagnostics = 'diagnostics';
    case Errors = 'errors';
    case ApplicationHealth = 'application_health';
    case DatabaseHealth = 'database_health';
    case QueueHealth = 'queue_health';
    case IntegrationHealth = 'integration_health';
    case DeploymentAnalysis = 'deployment_analysis';
    case Predictive = 'predictive';
    case Recommendations = 'recommendations';
    case Runbooks = 'runbooks';
    case Governance = 'governance';

    /** The grant that opens the area, on top of intelligence.view. */
    public function permission(): string
    {
        return match ($this) {
            self::Overview, self::Alerts => IntelligencePermissions::VIEW,
            self::Executive => IntelligencePermissions::EXECUTIVE,
            self::Workforce => IntelligencePermissions::WORKFORCE,
            self::Service => IntelligencePermissions::SERVICE,
            self::PublicVoice => IntelligencePermissions::PUBLIC_VOICE,
            self::Performance => IntelligencePermissions::PERFORMANCE,
            self::Forecasting => IntelligencePermissions::FORECASTING,
            self::DataQuality => IntelligencePermissions::DATA_QUALITY,
            self::Knowledge => IntelligencePermissions::KNOWLEDGE,
            self::SystemHealth => IntelligencePermissions::SYSTEM_HEALTH,
            self::Incidents => IntelligencePermissions::INCIDENTS,
            self::Diagnostics, self::Errors, self::Predictive => IntelligencePermissions::DIAGNOSTICS,
            self::ApplicationHealth => IntelligencePermissions::APPLICATION_HEALTH,
            self::DatabaseHealth => IntelligencePermissions::DATABASE_HEALTH,
            self::QueueHealth => IntelligencePermissions::QUEUE_HEALTH,
            self::IntegrationHealth => IntelligencePermissions::INTEGRATION_HEALTH,
            self::DeploymentAnalysis => IntelligencePermissions::DEPLOYMENT_ANALYSIS,
            self::Recommendations => IntelligencePermissions::RECOMMENDATIONS,
            self::Runbooks => IntelligencePermissions::RUNBOOKS,
            self::Governance => IntelligencePermissions::GOVERNANCE,
        };
    }

    /** `business`, `technical` or `governance`: the security domain the area belongs to. */
    public function domain(): string
    {
        return match ($this->section()) {
            'business', 'alerts', 'knowledge' => 'business',
            'system', 'diagnostics' => 'technical',
            default => 'governance',
        };
    }

    /** The launcher's group heading. */
    public function section(): string
    {
        return match ($this) {
            self::Overview, self::Executive, self::Workforce, self::Service, self::PublicVoice,
            self::Performance, self::Forecasting, self::DataQuality => 'business',
            self::Alerts => 'alerts',
            self::Knowledge => 'knowledge',
            self::SystemHealth, self::Incidents, self::ApplicationHealth, self::DatabaseHealth,
            self::QueueHealth, self::IntegrationHealth => 'system',
            self::Diagnostics, self::Errors, self::DeploymentAnalysis, self::Predictive,
            self::Recommendations, self::Runbooks => 'diagnostics',
            self::Governance => 'governance',
        };
    }

    /** Icon name from resources/js/Components/NavIcons.tsx. */
    public function icon(): string
    {
        return match ($this) {
            self::Overview => 'LayoutDashboard',
            self::Alerts => 'BellIcon',
            self::Executive => 'TargetIcon',
            self::Workforce => 'Users',
            self::Service => 'HandshakeIcon',
            self::PublicVoice => 'UserSpeakIcon',
            self::Performance => 'TrendingUpIcon',
            self::Forecasting => 'ChartIcon',
            self::DataQuality => 'MagnifierCheckIcon',
            self::Knowledge => 'BookIcon',
            self::SystemHealth => 'ActivityIcon',
            self::Incidents => 'AlarmIcon',
            self::Diagnostics => 'ToolboxIcon',
            self::Errors => 'DangerCircleIcon',
            self::ApplicationHealth => 'SpeedometerIcon',
            self::DatabaseHealth => 'DatabaseIcon',
            self::QueueHealth => 'Layers',
            self::IntegrationHealth => 'LinkIcon',
            self::DeploymentAnalysis => 'UploadIcon',
            self::Predictive => 'RadarIcon',
            self::Recommendations => 'LightbulbIcon',
            self::Runbooks => 'NotebookIcon',
            self::Governance => 'ShieldUserIcon',
        };
    }

    /** The launcher sections, in display order. @return list<string> */
    public static function sections(): array
    {
        return ['business', 'alerts', 'knowledge', 'system', 'diagnostics', 'governance'];
    }

    public function visibleTo(User $user): bool
    {
        return $user->can(IntelligencePermissions::VIEW) && $user->can($this->permission());
    }

    /** @return list<self> */
    public static function visibleFor(User $user): array
    {
        return array_values(array_filter(self::cases(), fn (self $area): bool => $area->visibleTo($user)));
    }

    /** Areas with their own resource pages open there; the rest open the generic analysis frame. */
    public function href(): string
    {
        return match ($this) {
            self::Overview => route('intelligence.index'),
            self::Knowledge => route('intelligence.knowledge.index'),
            self::Governance => route('intelligence.governance.index'),
            self::Alerts => route('intelligence.alerts.index'),
            self::Incidents => route('intelligence.incidents.index'),
            self::Recommendations => route('intelligence.recommendations.index'),
            self::Runbooks => route('intelligence.runbooks.index'),
            default => route('intelligence.area', ['area' => $this->value]),
        };
    }

    /** @return array{key: string, section: string, domain: string, icon: string, href: string} */
    public function toArray(): array
    {
        return [
            'key' => $this->value,
            'section' => $this->section(),
            'domain' => $this->domain(),
            'icon' => $this->icon(),
            'href' => $this->href(),
        ];
    }
}
