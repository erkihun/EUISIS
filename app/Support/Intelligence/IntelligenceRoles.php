<?php

declare(strict_types=1);

namespace App\Support\Intelligence;

use App\Services\Intelligence\IntelligencePermissions;

/**
 * How the default roles meet Addis One Intelligence (DefaultRoleMatrix).
 *
 * Super Admin and System Admin receive every grant. The city-wide business
 * roles (City Admin, Public Service Bureau Admin) receive the business and
 * governance-view grants but are withheld the technical ones: executive access
 * must not imply raw platform diagnostics (brief §6–7). Scoped roles receive
 * nothing by default — NEEDS_POLICY_DECISION_ROLE_GRANTS.
 */
final class IntelligenceRoles
{
    /** @var list<string> */
    public const CITY_ADMIN_WITHHELD = [
        IntelligencePermissions::SYSTEM_HEALTH,
        IntelligencePermissions::INCIDENTS,
        IntelligencePermissions::INCIDENTS_MANAGE,
        IntelligencePermissions::DIAGNOSTICS,
        IntelligencePermissions::APPLICATION_HEALTH,
        IntelligencePermissions::DATABASE_HEALTH,
        IntelligencePermissions::QUEUE_HEALTH,
        IntelligencePermissions::INTEGRATION_HEALTH,
        IntelligencePermissions::DEPLOYMENT_ANALYSIS,
        IntelligencePermissions::RUNBOOKS,
        IntelligencePermissions::RUNBOOKS_EXECUTE,
        IntelligencePermissions::GOVERNANCE_MANAGE,
        IntelligencePermissions::SETTINGS,
    ];
}
