<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Contracts\Data;

/**
 * The default roles that carry Service Delivery Monitoring duties, shared by
 * DefaultRoleMatrix and the registration migration so the two cannot drift.
 * A role makes a user eligible; the participant's organization must still be
 * inside the user's scope (ServiceMonitoringAccess).
 */
final class ServiceMonitoringRoles
{
    public const REPORTER_ROLE = 'Service Monitoring Reporter';

    public const HEAD_ROLE = 'Service Monitoring Head';

    public const REVIEWER_ROLE = 'Service Monitoring Reviewer';

    public const COORDINATOR_ROLE = 'Service Monitoring Coordinator';

    public const VERIFIER_ROLE = 'Innovation & Technology Verifier';

    public const SUPPORT_OFFICER_ROLE = 'Support Officer';

    /** @var list<string> */
    public const ALL = [
        'service_monitoring.view', 'service_monitoring.submit', 'service_monitoring.attest', 'service_monitoring.review',
        'service_monitoring.consolidate', 'service_monitoring.periods.manage', 'service_monitoring.periods.reopen',
        'service_monitoring.participants.manage', 'service_monitoring.extensions.grant',
        'service_monitoring.accountability.view_named', 'service_monitoring.accountability.export_named',
        'service_monitoring.technology.verify', 'service_monitoring.support.respond', 'service_monitoring.visits.manage',
        'service_monitoring.reports.view', 'service_monitoring.reports.export', 'service_monitoring.settings.manage',
        'service_monitoring.forms.manage',
    ];

    /** @var list<string> */
    public const REPORTER_PERMISSIONS = ['service_monitoring.view', 'service_monitoring.submit'];

    /** @var list<string> */
    public const HEAD_PERMISSIONS = ['service_monitoring.view', 'service_monitoring.submit', 'service_monitoring.attest', 'service_monitoring.accountability.view_named'];

    /** @var list<string> */
    public const REVIEWER_PERMISSIONS = [
        'service_monitoring.view', 'service_monitoring.review', 'service_monitoring.consolidate', 'service_monitoring.reports.view',
        'service_monitoring.reports.export', 'service_monitoring.accountability.view_named', 'service_monitoring.support.respond',
    ];

    /**
     * Every permission except technology verification (the Innovation & Technology Bureau's own duty).
     *
     * @var list<string>
     */
    public const COORDINATOR_PERMISSIONS = [
        'service_monitoring.view', 'service_monitoring.submit', 'service_monitoring.attest', 'service_monitoring.review',
        'service_monitoring.consolidate', 'service_monitoring.periods.manage', 'service_monitoring.periods.reopen',
        'service_monitoring.participants.manage', 'service_monitoring.extensions.grant',
        'service_monitoring.accountability.view_named', 'service_monitoring.accountability.export_named',
        'service_monitoring.support.respond', 'service_monitoring.visits.manage',
        'service_monitoring.reports.view', 'service_monitoring.reports.export', 'service_monitoring.settings.manage',
        'service_monitoring.forms.manage',
    ];

    /** @var list<string> */
    public const VERIFIER_PERMISSIONS = ['service_monitoring.view', 'service_monitoring.technology.verify', 'service_monitoring.reports.view'];

    /** @var list<string> */
    public const SUPPORT_OFFICER_PERMISSIONS = ['service_monitoring.view', 'service_monitoring.support.respond', 'service_monitoring.visits.manage', 'service_monitoring.reports.view'];

    /**
     * Withheld from City Admin and Public Service Bureau Admin, as named
     * discipline data is elsewhere: who was held accountable is personal data,
     * not an operational necessity of city-wide administration.
     *
     * @var list<string>
     */
    public const CITY_ADMIN_WITHHELD = ['service_monitoring.accountability.view_named', 'service_monitoring.accountability.export_named'];
}
