<?php

declare(strict_types=1);

namespace Modules\Letters\Contracts\Data;

/**
 * The default roles that carry letter duties (ADR 0037), shared by
 * DefaultRoleMatrix and the registration migration so the two cannot drift.
 * A role makes a user eligible; the registry entry's institution must still
 * be inside the user's organization scope (LettersAccess).
 */
final class LettersRoles
{
    public const REGISTRAR_ROLE = 'Letter Registrar';

    public const OFFICER_ROLE = 'Correspondence Officer';

    public const SIGNATORY_ROLE = 'Letter Signatory';

    public const ADMINISTRATOR_ROLE = 'Letter Administrator';

    public const TEMPLATE_STEWARD_ROLE = 'Letter Template Steward';

    /** @var list<string> */
    public const ALL = [
        'letters.view', 'letters.register', 'letters.compose', 'letters.approve', 'letters.dispatch', 'letters.route', 'letters.handle',
        'letters.view_confidential', 'letters.templates.view', 'letters.templates.manage', 'letters.templates.manage_platform',
        'letters.numbering.manage', 'letters.letterhead.manage', 'letters.standards.manage', 'letters.reports.view', 'letters.export',
    ];

    /** The registry desk: registers incoming letters (confidential ones included), receives letters from other institutions, dispatches, routes and exports the register. */
    public const REGISTRAR_PERMISSIONS = [
        'letters.view', 'letters.register', 'letters.dispatch', 'letters.route', 'letters.handle', 'letters.view_confidential', 'letters.templates.view', 'letters.reports.view', 'letters.export',
    ];

    /** Drafts letters from templates and works the routings addressed to them. */
    public const OFFICER_PERMISSIONS = ['letters.view', 'letters.compose', 'letters.handle', 'letters.templates.view'];

    /** Signs (approves) outgoing letters; may also draft and dispatch; reads confidential letters of the institution. */
    public const SIGNATORY_PERMISSIONS = ['letters.view', 'letters.compose', 'letters.approve', 'letters.dispatch', 'letters.handle', 'letters.view_confidential', 'letters.templates.view'];

    /** Everything inside the institution, platform templates excepted. */
    public const ADMINISTRATOR_PERMISSIONS = [
        'letters.view', 'letters.register', 'letters.compose', 'letters.approve', 'letters.dispatch', 'letters.route', 'letters.handle', 'letters.view_confidential',
        'letters.templates.view', 'letters.templates.manage', 'letters.numbering.manage', 'letters.letterhead.manage', 'letters.standards.manage', 'letters.reports.view', 'letters.export',
    ];

    /** Keeps the platform-wide templates and the platform defaults for letterhead, reply standards and the file scheme (city-wide). */
    public const TEMPLATE_STEWARD_PERMISSIONS = ['letters.templates.view', 'letters.templates.manage_platform', 'letters.letterhead.manage', 'letters.standards.manage', 'letters.reports.view'];
}
