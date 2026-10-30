<?php

declare(strict_types=1);

namespace Modules\Communication\Contracts\Data;

/**
 * Communication permission sets used by DefaultRoleMatrix (docs/communication-module.md).
 *
 * No set opens a private conversation: reading one needs membership, or an
 * approved, time-boxed break-glass grant. Break-glass request and approval are
 * kept off the city-wide operational admins and live on two separate roles, so
 * the person who asks is never the person who approves.
 */
final class CommunicationRoles
{
    public const MODERATOR_ROLE = 'Communication Moderator';

    public const INVESTIGATOR_ROLE = 'Communication Investigator';

    public const PRIVACY_OFFICER_ROLE = 'Communication Privacy Officer';

    /** Every employee: chat, groups, public channels, announcements addressed to them. */
    public const EMPLOYEE_PERMISSIONS = [
        'communication.access', 'messages.send', 'messages.edit_own', 'messages.delete_own', 'messages.forward',
        'groups.create', 'channels.view', 'announcements.view', 'attachments.upload', 'attachments.download',
        'communication.tasks.create', 'communication.reports.create',
    ];

    /** HR drafts announcements for their scope; publishing stays with the organization's admin. */
    public const HR_PERMISSIONS = [
        ...self::EMPLOYEE_PERMISSIONS,
        'announcements.create', 'announcements.edit',
    ];

    /** Organizational Admin: channels and the full announcement workflow, in their scope. */
    public const ORGANIZATIONAL_ADMIN_PERMISSIONS = [
        ...self::EMPLOYEE_PERMISSIONS,
        'groups.manage', 'channels.create', 'channels.manage', 'channels.manage_members',
        'announcements.create', 'announcements.edit', 'announcements.publish', 'announcements.cancel', 'announcements.export_acknowledgements',
        'communication.tasks.manage',
    ];

    /** Reviews reports: only the reported content and its immediate context. */
    public const MODERATOR_PERMISSIONS = [
        ...self::EMPLOYEE_PERMISSIONS,
        'communication.moderate', 'messages.delete_any',
    ];

    /** Asks for investigation access; cannot approve it. */
    public const INVESTIGATOR_PERMISSIONS = [
        'communication.access', 'communication.break_glass.request',
    ];

    /** Approves, rejects and revokes investigation access and reads the communication audit trail. */
    public const PRIVACY_OFFICER_PERMISSIONS = [
        'communication.access', 'communication.break_glass.approve', 'communication.audit',
    ];

    /** Withheld from City Admin and the Public Service Bureau Admin. */
    public const CITY_ADMIN_WITHHELD = [
        'communication.break_glass.request', 'communication.break_glass.approve', 'communication.audit',
    ];
}
