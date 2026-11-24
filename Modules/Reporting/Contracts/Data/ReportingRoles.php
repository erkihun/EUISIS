<?php

declare(strict_types=1);

namespace Modules\Reporting\Contracts\Data;

/**
 * The module's permissions and the sets its default roles receive. Published
 * so the application's role matrix can grant them without knowing the module's
 * internals.
 */
final class ReportingRoles
{
    public const VIEW = 'reporting.view';

    public const PREPARE = 'reporting.prepare';

    public const SUBMIT = 'reporting.submit';

    public const REVIEW = 'reporting.review';

    public const APPROVE = 'reporting.approve';

    public const REOPEN = 'reporting.reopen';

    public const PUBLISH_SCHEDULES = 'reporting.schedules.publish';

    public const MANAGE_TEMPLATES = 'reporting.templates.manage';

    public const GRANT_EXTENSIONS = 'reporting.extensions.grant';

    public const CONSOLIDATE = 'reporting.consolidate';

    public const EXPORT = 'reporting.export';

    public const VIEW_CONFIDENTIAL = 'reporting.view_confidential';

    public const INSPECT_AUDIT = 'reporting.audit.view';

    public const MANAGE_SETTINGS = 'reporting.settings.manage';

    public const ALL = [
        self::VIEW, self::PREPARE, self::SUBMIT, self::REVIEW, self::APPROVE, self::REOPEN, self::PUBLISH_SCHEDULES,
        self::MANAGE_TEMPLATES, self::GRANT_EXTENSIONS, self::CONSOLIDATE, self::EXPORT, self::VIEW_CONFIDENTIAL,
        self::INSPECT_AUDIT, self::MANAGE_SETTINGS,
    ];

    public const PREPARER_ROLE = 'Report Preparer';

    public const OFFICER_ROLE = 'Reporting Officer';

    public const REVIEWER_ROLE = 'Report Reviewer';

    public const APPROVER_ROLE = 'Report Approver';

    public const OVERSIGHT_ROLE = 'Reporting Oversight Officer';

    public const ADMINISTRATOR_ROLE = 'Reporting Administrator';

    /** Drafts the institution's report; cannot submit it. */
    public const PREPARER = [self::VIEW, self::PREPARE];

    /** The institution's accountable officer: prepares and submits, asks for extensions. */
    public const OFFICER = [self::VIEW, self::PREPARE, self::SUBMIT, self::EXPORT];

    /** First-line review at the receiving institution; creates follow-up actions. */
    public const REVIEWER = [self::VIEW, self::REVIEW, self::VIEW_CONFIDENTIAL];

    /** Final approval at the receiving institution; may reopen a decided report. */
    public const APPROVER = [self::VIEW, self::REVIEW, self::APPROVE, self::REOPEN, self::VIEW_CONFIDENTIAL];

    /** Leadership's view: compliance, consolidation, extensions and exemptions, exports. */
    public const OVERSIGHT = [self::VIEW, self::CONSOLIDATE, self::EXPORT, self::GRANT_EXTENSIONS, self::VIEW_CONFIDENTIAL, self::INSPECT_AUDIT];

    /** Configuration: report types, templates, schedules and the module's settings. */
    public const ADMINISTRATOR = [self::VIEW, self::MANAGE_TEMPLATES, self::PUBLISH_SCHEDULES, self::MANAGE_SETTINGS, self::EXPORT, self::INSPECT_AUDIT];
}
