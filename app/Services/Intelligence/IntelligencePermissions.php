<?php

declare(strict_types=1);

namespace App\Services\Intelligence;

/**
 * The grants of Addis One Intelligence (docs/intelligence-architecture.md).
 *
 * Two families that never imply each other: the business grants open domain
 * aggregates inside the viewer's organization scope; the technical grants open
 * platform telemetry. `VIEW` opens the launcher only.
 */
final class IntelligencePermissions
{
    public const VIEW = 'intelligence.view';

    // Business / management intelligence
    public const EXECUTIVE = 'intelligence.executive.view';

    public const WORKFORCE = 'intelligence.workforce.view';

    public const SERVICE = 'intelligence.service.view';

    public const PUBLIC_VOICE = 'intelligence.public-voice.view';

    public const PERFORMANCE = 'intelligence.performance.view';

    public const FORECASTING = 'intelligence.forecasting.view';

    public const DATA_QUALITY = 'intelligence.data-quality.view';

    public const KNOWLEDGE = 'intelligence.knowledge.view';

    // System health / diagnostics
    public const SYSTEM_HEALTH = 'intelligence.system-health.view';

    public const INCIDENTS = 'intelligence.incidents.view';

    public const INCIDENTS_MANAGE = 'intelligence.incidents.manage';

    public const DIAGNOSTICS = 'intelligence.diagnostics.view';

    public const APPLICATION_HEALTH = 'intelligence.application-health.view';

    public const DATABASE_HEALTH = 'intelligence.database-health.view';

    public const QUEUE_HEALTH = 'intelligence.queue-health.view';

    public const INTEGRATION_HEALTH = 'intelligence.integration-health.view';

    public const DEPLOYMENT_ANALYSIS = 'intelligence.deployment-analysis.view';

    public const RECOMMENDATIONS = 'intelligence.recommendations.view';

    public const RECOMMENDATIONS_REVIEW = 'intelligence.recommendations.review';

    public const RUNBOOKS = 'intelligence.runbooks.view';

    public const RUNBOOKS_EXECUTE = 'intelligence.runbooks.execute';

    // Governance
    public const GOVERNANCE = 'intelligence.governance.view';

    public const GOVERNANCE_MANAGE = 'intelligence.governance.manage';

    public const SETTINGS = 'system-settings.manageIntelligence';

    /** @return list<string> */
    public static function business(): array
    {
        return [self::EXECUTIVE, self::WORKFORCE, self::SERVICE, self::PUBLIC_VOICE, self::PERFORMANCE, self::FORECASTING, self::DATA_QUALITY, self::KNOWLEDGE];
    }

    /** Technical grants: never implied by a business grant, withheld from the city-wide business roles. @return list<string> */
    public static function technical(): array
    {
        return [
            self::SYSTEM_HEALTH, self::INCIDENTS, self::INCIDENTS_MANAGE, self::DIAGNOSTICS, self::APPLICATION_HEALTH,
            self::DATABASE_HEALTH, self::QUEUE_HEALTH, self::INTEGRATION_HEALTH, self::DEPLOYMENT_ANALYSIS,
            self::RUNBOOKS, self::RUNBOOKS_EXECUTE,
        ];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::VIEW, ...self::business(), ...self::technical(),
            self::RECOMMENDATIONS, self::RECOMMENDATIONS_REVIEW, self::GOVERNANCE, self::GOVERNANCE_MANAGE,
        ];
    }
}
