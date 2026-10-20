import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import StatusBadge from '@/Components/StatusBadge';
import PageHeader from '@/Components/PageHeader';
import { PencilIcon, TrashIcon, ArchiveIcon, XCircle, Plus } from '@/Components/Icons';
import { useConfirm } from '@/Components/ConfirmProvider';
import { useLocale } from '@/hooks/useLocale';
import ReportingLinesPanel from '@/Components/relationships/ReportingLinesPanel';
import type { RelationshipRow } from '@/Components/relationships/RelationshipPanel';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { localizedName } from '@/utils/localizedName';
import OrganizationStructureTree, { type OrganizationStructureTreeData } from '@/Components/organizations/OrganizationStructureTree';
import StatusDistribution from '@/Components/dashboard/StatusDistribution';
import { useState } from 'react';

import { actionLinkClassName, badgeClassName, buttonClassName, cx, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
import AppActionMenu from '@/Components/ui/AppActionMenu';
/** Keys returned by OrganizationDeletionGuard::reasons(), mapped to i18n labels below. */
type DeletionBlockerKey =
    | 'usedInPublishedHierarchy'
    | 'hasChildOrganizations'
    | 'hasOrganizationUnits'
    | 'hasPositions'
    | 'hasEmployeeAssignments'
    | 'hasOtherReferences';

type NameHistory = {
    id: string;
    name_en: string;
    name_am: string | null;
    effective_from: string;
    effective_to?: string | null;
};

type Descendant = {
    descendant_organization_id: string;
    depth: number;
    code: string | null;
    name_en: string | null;
    name_am: string | null;
};

type ParentOrganization = {
    id: string;
    code: string;
    name_en: string;
    name_am?: string | null;
} | null;

type StructureSummary = {
    units: number;
    positions: number;
    descendants: number;
};

type CanProps = {
    update: boolean;
    delete: boolean;
    archive: boolean;
    deactivate: boolean;
    createChild: boolean;
    feedbackQr?: boolean;
};

type InstitutionOfficePreview = {
    id: string;
    office_code: string;
    name_en: string | null;
    office_level: string;
    status: string;
};

type OrganizationStatistics = {
    units: { total: number; active: number; inactive: number; draft: number; archived: number };
    positions: { total: number; active: number; inactive: number; occupied: number; vacant: number; by_status: Record<string, number> };
    employees: { total: number; active: number; suspended: number; retired: number; terminated: number; by_status: Record<string, number> };
    id_cards: { total: number; active: number; pending: number; inactive: number; by_status: Record<string, number> };
    child_organizations: number;
    scoped_users: number;
    employees_by_unit: Array<{
        id: string;
        code: string | null;
        name_en: string | null;
        name_am: string | null;
        employees_count: number;
    }>;
};

export default function OrganizationShow({
    organization,
    parentOrganizationId,
    parentOrganization = null,
    structureSummary,
    structureTree,
    statistics = null,
    descendants,
    can,
    deletionBlockers = [],
    institutionOffices = [],
    reportingOffices = [],
    reportingUnits = [],
}: {
    organization: {
        id: string;
        code: string;
        name_en: string;
        name_am?: string | null;
        status: string;
        effective_from?: string | null;
        effective_to?: string | null;
        legal_basis_ref?: string | null;
        type?: { name_en: string; name_am?: string | null } | null;
        merged_into?: { name_en: string; name_am?: string | null } | null;
        name_histories: NameHistory[];
        logo_url: string | null;
        has_logo: boolean;
        branding_primary_color: string | null;
        branding_secondary_color: string | null;
        public_contact_phone?: string | null;
    };
    parentOrganizationId: string | null;
    parentOrganization?: ParentOrganization;
    currentAssignmentsCount: number;
    structureSummary: StructureSummary;
    structureTree: OrganizationStructureTreeData;
    statistics?: OrganizationStatistics | null;
    descendants: Descendant[];
    can: CanProps;
    deletionBlockers?: DeletionBlockerKey[];
    institutionOffices?: InstitutionOfficePreview[];
    reportingOffices?: RelationshipRow[];
    reportingUnits?: RelationshipRow[];
}) {
    const { t, locale } = useLocale();
    const { confirm } = useConfirm();

    /**
     * Localized label for a status key, falling back to the raw key when no
     * translation exists so a new enum case degrades to something readable
     * rather than an empty slice label.
     */
    function statusLabel(status: string): string {
        const key = `common.${status}`;
        const translated = t(key);
        return translated === key ? status.replace(/_/g, ' ') : translated;
    }
    const deleteForm = useForm({});
    const archiveForm = useForm({});
    const deactivateForm = useForm({});
    const [showDeletionBlockers, setShowDeletionBlockers] = useState(false);

    const isBlocked = deletionBlockers.length > 0;
    const organizationName = localizedName(organization.name_en, organization.name_am, locale);

    async function handleDelete() {
        if (isBlocked) {
            setShowDeletionBlockers(true);
            return;
        }
        if (!(await confirm({ title: t('organizations.deleteConfirm'), variant: 'danger' })).confirmed) return;
        deleteForm.delete(route('organizations.destroy', organization.id));
    }

    async function handleArchive() {
        if (!(await confirm({ title: t('organizations.archiveConfirm'), variant: 'danger' })).confirmed) return;
        archiveForm.delete(route('organizations.archive', organization.id));
    }

    async function handleDeactivate() {
        if (!(await confirm({ title: t('organizations.deactivateConfirm'), variant: 'danger' })).confirmed) return;
        deactivateForm.patch(route('organizations.deactivate', organization.id));
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    backHref={parentOrganizationId ? route('organizations.show', parentOrganizationId) : route('organizations.index')}
                    title={organization.name_en}
                    description={organization.code}
                    actions={
                        <div className="flex flex-wrap items-center justify-end gap-2">
                            {can.createChild && (
                                <Link
                                    href={route('organizations.create') + `?parent=${organization.id}`}
                                    className={buttonClassName({ variant: 'outline', size: 'sm' })}
                                >
                                    <Plus className="h-3.5 w-3.5" />
                                    {t('organizations.addChild')}
                                </Link>
                            )}
                            {can.feedbackQr && (
                                <Link
                                    href={route('organizations.feedback-qr.show', organization.id)}
                                    className={buttonClassName({ variant: 'outline', size: 'sm' })}
                                >
                                    {t('serviceFeedback.organizationFeedbackQr')}
                                </Link>
                            )}
                            {/* Read-only view: gated by `view`, which the user already holds here. */}
                            <Link
                                href={route('organizations.organogram', organization.id)}
                                className={buttonClassName({ variant: 'outline', size: 'sm' })}
                            >
                                {t('organizations.generateOrganogram')}
                            </Link>
                            {can.update && (
                                <Link
                                    href={route('organizations.edit', organization.id)}
                                    className={buttonClassName({ variant: 'outline', size: 'sm' })}
                                >
                                    <PencilIcon className="h-3.5 w-3.5" />
                                    {t('common.edit')}
                                </Link>
                            )}
                            {can.deactivate && (
                                <button
                                    type="button"
                                    disabled={deactivateForm.processing}
                                    onClick={handleDeactivate}
                                    className={buttonClassName({ variant: 'outline', size: 'sm' })}
                                >
                                    <XCircle className="h-3.5 w-3.5" />
                                    {t('organizations.deactivate')}
                                </button>
                            )}
                            {can.archive && (
                                <button
                                    type="button"
                                    disabled={archiveForm.processing}
                                    onClick={handleArchive}
                                    className={buttonClassName({ variant: 'outline', size: 'sm' })}
                                >
                                    <ArchiveIcon className="h-3.5 w-3.5" />
                                    {t('organizations.archive')}
                                </button>
                            )}
                            {can.delete && (
                                <button
                                    type="button"
                                    disabled={deleteForm.processing}
                                    onClick={handleDelete}
                                    className={buttonClassName({ variant: 'outline', size: 'sm' })}
                                >
                                    <TrashIcon className="h-3.5 w-3.5" />
                                    {t('common.delete')}
                                </button>
                            )}
                        </div>
                    }
                />
            }
        >
            <Head title={organization.name_en} />

            <section className="mb-6 overflow-hidden rounded-panel border border-(--app-border) bg-(--app-surface) p-5 sm:p-6">
                <div className="flex flex-col gap-6 xl:flex-row xl:items-end xl:justify-between">
                    <div className="flex min-w-0 items-center gap-4">
                        {organization.has_logo && organization.logo_url ? (
                            <img src={organization.logo_url} alt="" className="h-14 w-14 rounded-card border border-(--app-border) bg-(--app-surface) object-contain p-1.5" />
                        ) : (
                            <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-card border border-(--app-border) bg-(--app-surface-muted) text-xl font-semibold text-(--app-muted-foreground)">
                                {organizationName.charAt(0).toUpperCase()}
                            </div>
                        )}
                        <div className="min-w-0">
                            <div className="mb-1.5 flex flex-wrap items-center gap-2">
                                <span className="rounded-control bg-(--app-surface-muted) px-2 py-0.5 font-mono text-xs text-(--app-foreground)">{organization.code}</span>
                                <StatusBadge status={organization.status} label={t(`common.${organization.status}`)} />
                            </div>
                            <h1 className="truncate text-xl font-semibold text-(--app-foreground)">{organizationName}</h1>
                            <p className="mt-1 text-sm text-(--app-muted-foreground)">
                                {organization.type ? localizedName(organization.type.name_en, organization.type.name_am, locale) : t('organizations.organization')}
                            </p>
                        </div>
                    </div>
                    {/*
                     * Establishment counts, read straight off the structure
                     * tree. One bordered strip rather than five separate cards:
                     * they are facets of the same figure and belong together.
                     */}
                    <dl className="grid grid-cols-2 overflow-hidden rounded-card border border-(--app-border) sm:grid-cols-5 xl:min-w-[560px]">
                        {[
                            { label: t('organizations.organizationUnits'), value: structureTree.counters.units },
                            { label: t('organizations.positions'), value: structureTree.counters.positions },
                            { label: t('organizations.occupied'), value: structureTree.counters.occupied_positions },
                            { label: t('organizations.vacant'), value: structureTree.counters.vacant_positions },
                            { label: t('organizations.assignedEmployees'), value: structureTree.counters.employees },
                        ].map(({ label, value }) => (
                            <div key={label} className="border-b border-r border-(--app-border) px-3 py-2.5 text-center last:border-r-0 sm:border-b-0">
                                <dd className="text-lg font-semibold tabular-nums text-(--app-foreground)">{value}</dd>
                                <dt className="mt-0.5 text-xs leading-tight text-(--app-muted-foreground)">{label}</dt>
                            </div>
                        ))}
                    </dl>
                </div>
            </section>

            {showDeletionBlockers && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4 backdrop-blur-xs" role="dialog" aria-modal="true">
                    <div className="w-full max-w-lg rounded-panel border border-amber-200 bg-(--app-surface) p-6 shadow-2xl dark:border-amber-800">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <h2 className="text-lg font-semibold text-(--app-foreground)">
                                    {t('organizations.cannotBeDeleted')}
                                </h2>
                                <p className="mt-1 text-sm text-(--app-muted-foreground)">
                                    {t('organizations.cannotDeleteUsedMessage')}
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setShowDeletionBlockers(false)}
                                className="rounded-lg p-1.5 text-(--app-muted-foreground) hover:bg-(--app-surface-muted) hover:text-(--app-foreground)"
                                aria-label={t('common.close')}
                            >
                                ×
                            </button>
                        </div>
                        <ul className="mt-5 space-y-2 rounded-card bg-amber-50 p-4 text-sm text-amber-800 dark:bg-amber-950/30 dark:text-amber-300">
                            {deletionBlockers.map((key) => (
                                <li key={key} className="flex gap-2">
                                    <span aria-hidden="true">•</span>
                                    <span>{t(`organizations.deletionBlockers.${key}`)}</span>
                                </li>
                            ))}
                        </ul>
                        <div className="mt-6 flex justify-end">
                            <button
                                type="button"
                                onClick={() => setShowDeletionBlockers(false)}
                                className="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700 dark:bg-(--app-surface-muted) dark:text-(--app-foreground) dark:hover:bg-(--app-surface)"
                            >
                                {t('common.close')}
                            </button>
                        </div>
                    </div>
                </div>
            )}

            <div className="grid gap-6 xl:grid-cols-[1.65fr_1fr]">
                {/* Basic Information */}
                <section className="rounded-panel border border-(--app-border) bg-(--app-surface) p-5">
                    <p className="text-xs font-semibold text-(--app-muted-foreground)">
                        {t('organizations.basicInformation')}
                    </p>
                    <dl className="mt-3 grid gap-4 sm:grid-cols-2">
                        {[
                            { label: t('organizations.type'), value: organization.type ? localizedName(organization.type.name_en, organization.type.name_am, locale) : '—' },
                            { label: t('organizations.legalBasis'), value: organization.legal_basis_ref ?? '—' },
                            { label: t('organizations.publicContactPhone'), value: organization.public_contact_phone || '—' },
                            { label: t('organizations.mergedInto'), value: organization.merged_into ? localizedName(organization.merged_into.name_en, organization.merged_into.name_am, locale) : '—' },
                        ].map(({ label, value }) => (
                            <div key={label}>
                                <dt className="text-xs font-medium text-(--app-muted-foreground)">{label}</dt>
                                <dd className="mt-1 text-sm text-(--app-foreground)">{value}</dd>
                            </div>
                        ))}

                        <div>
                            <dt className="text-xs font-medium text-(--app-muted-foreground)">
                                {t('common.effectiveFrom')}
                            </dt>
                            <dd className="mt-1 text-sm text-(--app-foreground)">
                                <LocalizedDateDisplay value={organization.effective_from} />
                            </dd>
                        </div>

                        <div>
                            <dt className="text-xs font-medium text-(--app-muted-foreground)">
                                {t('common.effectiveTo')}
                            </dt>
                            <dd className="mt-1 text-sm text-(--app-foreground)">
                                {organization.effective_to
                                    ? <LocalizedDateDisplay value={organization.effective_to} />
                                    : <span className="text-(--app-muted-foreground)">{t('common.current')}</span>
                                }
                            </dd>
                        </div>
                    </dl>
                </section>

                {/* Right column: hierarchy + structure */}
                <div>
                    {/* Hierarchy & Placement */}
                    <section className="rounded-panel border border-(--app-border) bg-(--app-surface) p-5">
                        <h3 className="font-semibold text-(--app-foreground)">
                            {t('organizations.hierarchyAndPlacement')}
                        </h3>
                        <dl className="mt-4 space-y-4">
                            <div>
                                <dt className="text-xs font-medium text-(--app-muted-foreground)">
                                    {t('organizations.parentOrganization')}
                                </dt>
                                <dd className="mt-1 text-sm">
                                    {parentOrganization ? (
                                        <Link
                                            href={route('organizations.show', parentOrganization.id)}
                                            className="font-medium text-primary hover:text-primary-hover dark:text-primary dark:hover:text-primary-hover"
                                        >
                                            {parentOrganization.code} — {localizedName(parentOrganization.name_en, parentOrganization.name_am, locale)}
                                        </Link>
                                    ) : (
                                        <span className="text-(--app-muted-foreground)">{t('organizations.noParentRoot')}</span>
                                    )}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs font-medium text-(--app-muted-foreground)">
                                    {t('organizations.subtreeReach')}
                                </dt>
                                <dd className="mt-1 text-sm text-(--app-foreground) tabular-nums">
                                    {structureSummary.descendants}
                                </dd>
                            </div>
                        </dl>
                    </section>

                </div>
            </div>

            <OrganizationStructureTree tree={structureTree} t={t} locale={locale} />

            {/* Subtree descendants — readable names, not raw UUIDs */}
            {descendants.length > 0 && (
                <section className="mt-6 rounded-panel border border-(--app-border) bg-(--app-surface) p-5">
                    <h3 className="font-semibold text-(--app-foreground)">
                        {t('organizations.subtreeReach')}
                    </h3>
                    <ul className="mt-4 divide-y divide-(--app-border)">
                        {descendants.map((d) => (
                            <li key={d.descendant_organization_id} className="flex items-center justify-between py-2 text-sm">
                                <span className="flex items-center gap-2">
                                    <span className="font-mono text-xs text-(--app-muted-foreground)">{d.code ?? '—'}</span>
                                    <Link
                                        href={route('organizations.show', d.descendant_organization_id)}
                                        className="text-(--app-foreground) hover:text-primary dark:hover:text-blue-400"
                                    >
                                        {localizedName(d.name_en, d.name_am, locale) || d.descendant_organization_id}
                                    </Link>
                                </span>
                                <span className="text-xs text-(--app-muted-foreground)">
                                    {t('common.depth')} {d.depth}
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {(organization.has_logo || organization.branding_primary_color || organization.branding_secondary_color) && (
                <section className="mt-6 rounded-panel border border-(--app-border) bg-(--app-surface) p-5">
                    <h3 className="font-semibold text-(--app-foreground)">
                        {t('organizations.branding')}
                    </h3>
                    <div className="mt-4 flex flex-wrap items-start gap-6">
                        {organization.has_logo && organization.logo_url && (
                            <div>
                                <p className="mb-1.5 text-xs font-medium text-(--app-muted-foreground)">
                                    {t('organizations.logo')}
                                </p>
                                <img
                                    src={organization.logo_url}
                                    alt={`${organization.name_en} logo`}
                                    className="h-16 w-16 rounded-card border border-(--app-border) object-contain p-1.5"
                                />
                            </div>
                        )}
                        {(organization.branding_primary_color || organization.branding_secondary_color) && (
                            <div>
                                <p className="mb-1.5 text-xs font-medium text-(--app-muted-foreground)">
                                    {t('organizations.colorPreview')}
                                </p>
                                <div className="flex items-center gap-3">
                                    {organization.branding_primary_color && (
                                        <div className="flex items-center gap-1.5">
                                            <span
                                                className="h-6 w-6 rounded-full border border-white shadow-sm"
                                                style={{ backgroundColor: organization.branding_primary_color }}
                                            />
                                            <span className="font-mono text-xs text-(--app-muted-foreground)">
                                                {organization.branding_primary_color}
                                            </span>
                                        </div>
                                    )}
                                    {organization.branding_secondary_color && (
                                        <div className="flex items-center gap-1.5">
                                            <span
                                                className="h-6 w-6 rounded-full border border-white shadow-sm"
                                                style={{ backgroundColor: organization.branding_secondary_color }}
                                            />
                                            <span className="font-mono text-xs text-(--app-muted-foreground)">
                                                {organization.branding_secondary_color}
                                            </span>
                                        </div>
                                    )}
                                </div>
                                {organization.branding_primary_color && (
                                    <div
                                        className="mt-3 rounded-lg border border-l-4 border-(--app-border) px-3 py-2 text-xs text-(--app-muted-foreground)"
                                        style={{ borderLeftColor: organization.branding_primary_color }}
                                    >
                                        {t('organizations.brandingPreview')}
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                </section>
            )}

            {institutionOffices.length > 0 && (
                <section className="mt-6 rounded-panel border border-(--app-border) bg-(--app-surface) p-5">
                    <div className="flex items-center justify-between">
                        <h3 className="font-semibold text-(--app-foreground)">
                            {t('institutionOffices.title')} ({institutionOffices.length})
                        </h3>
                        <Link
                            href={`${route('institution-offices.create')}?institution_id=${organization.id}`}
                            className={actionLinkClassName()}
                        >
                            + {t('institutionOffices.addOffice')}
                        </Link>
                    </div>
                    <div className="mt-4 overflow-hidden rounded-card border border-(--app-border)">
                        <table className={cx(tableClassName, 'min-w-full text-left')}>
                            <thead className={tableHeadClassName}>
                                <tr>
                                    {[
                                        t('institutionOffices.officeCode'),
                                        t('institutionOffices.officeName'),
                                        t('institutionOffices.officeLevel'),
                                        t('institutionOffices.status'),
                                        '',
                                    ].map((h) => (
                                        <th
                                            key={h}
                                            className={cx(tableHeaderCellClassName, 'text-left')}
                                        >
                                            {h}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className={tableBodyClassName}>
                                {institutionOffices.map((office) => (
                                    <tr
                                        key={office.id}
                                        className={tableRowClassName}
                                    >
                                        <td className={cx(tableCellClassName, 'font-mono text-xs text-(--app-muted-foreground)')}>
                                            {office.office_code}
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-(--app-foreground)')}>
                                            {office.name_en ?? '—'}
                                        </td>
                                        <td className={tableCellClassName}>
                                            <span className={badgeClassName('info')}>
                                                {office.office_level}
                                            </span>
                                        </td>
                                        <td className={tableCellClassName}>
                                            <span className="text-xs text-(--app-muted-foreground)">
                                                {office.status}
                                            </span>
                                        </td>
                                        <td className={tableCellClassName}>
                                            <div className="flex justify-end">
                                                <AppActionMenu
                                                    items={[
                                                        { label: t('common.view'), href: route('institution-offices.show', office.id) },
                                                    ]}
                                                />
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            )}

            {statistics && (
                <section className="mt-6 rounded-panel border border-(--app-border) bg-(--app-surface) p-5">
                    <h3 className="font-semibold text-(--app-foreground)">{t('organizations.statistics')}</h3>

                    <dl className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5">
                        {[
                            { label: t('organizations.totalUnits'), value: statistics.units.total },
                            { label: t('organizations.activeUnits'), value: statistics.units.active },
                            { label: t('organizations.inactiveUnits'), value: statistics.units.inactive },
                            { label: t('organizations.totalPositions'), value: statistics.positions.total },
                            { label: t('organizations.occupiedPositions'), value: statistics.positions.occupied },
                            { label: t('organizations.vacantPositions'), value: statistics.positions.vacant },
                            { label: t('organizations.totalEmployees'), value: statistics.employees.total },
                            { label: t('organizations.activeEmployees'), value: statistics.employees.active },
                            { label: t('organizations.activeCards'), value: statistics.id_cards.active },
                            { label: t('organizations.pendingCards'), value: statistics.id_cards.pending },
                            { label: t('organizations.inactiveCards'), value: statistics.id_cards.inactive },
                            { label: t('organizations.childOrganizations'), value: statistics.child_organizations },
                            { label: t('organizations.scopedUsers'), value: statistics.scoped_users },
                        ].map(({ label, value }) => (
                            <div key={label} className="rounded-card border border-(--app-border) bg-(--app-surface-muted) px-3 py-3 text-center">
                                <dd className="text-lg font-bold tabular-nums text-(--app-foreground)">{value}</dd>
                                <dt className="mt-0.5 text-[11px] leading-tight text-(--app-muted-foreground)">{label}</dt>
                            </div>
                        ))}
                    </dl>

                    <div className="mt-6 grid gap-6 xl:grid-cols-3">
                        {[
                            { title: t('organizations.positionsByStatus'), data: statistics.positions.by_status },
                            { title: t('organizations.employeesByStatus'), data: statistics.employees.by_status },
                            { title: t('organizations.idCardsByStatus'), data: statistics.id_cards.by_status },
                        ].map(({ title, data }) => {
                            const series = Object.entries(data)
                                .filter(([, value]) => value > 0)
                                .map(([key, value]) => ({ key, value }));

                            return (
                                <div key={title} className="min-w-0 rounded-card border border-(--app-border) p-4">
                                    <h4 className="text-sm font-semibold text-(--app-foreground)">{title}</h4>
                                    <div className="mt-3">
                                        {series.length === 0 ? (
                                            <p className="py-8 text-center text-xs text-(--app-muted-foreground)">
                                                {t('organizations.noStatistics')}
                                            </p>
                                        ) : (
                                            <StatusDistribution
                                                data={series}
                                                labelFor={(key) => statusLabel(key)}
                                            />
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                    </div>

                    {statistics.employees_by_unit.length > 0 && (
                        <div className="mt-6">
                            <h4 className="text-sm font-semibold text-(--app-foreground)">{t('organizations.employeesByUnit')}</h4>
                            <div className="mt-3 overflow-x-auto rounded-card border border-(--app-border)">
                                <table className={cx(tableClassName, 'min-w-full text-left')}>
                                    <thead className={tableHeadClassName}>
                                        <tr>
                                            <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('organizations.organizationUnits')}</th>
                                            <th className={cx(tableHeaderCellClassName, 'text-right')}>{t('organizations.assignedEmployees')}</th>
                                        </tr>
                                    </thead>
                                    <tbody className={tableBodyClassName}>
                                        {statistics.employees_by_unit.map((row) => (
                                            <tr key={row.id}>
                                                <td className={cx(tableCellClassName, 'text-(--app-foreground)')}>
                                                    <span className="font-mono text-[11px] text-(--app-muted-foreground)">{row.code}</span>{' '}
                                                    {localizedName(row.name_en, row.name_am, locale)}
                                                </td>
                                                <td className={cx(tableCellClassName, 'text-right tabular-nums text-(--app-foreground)')}>{row.employees_count}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}
                </section>
            )}

            <section className="mt-6">
                <ReportingLinesPanel rows={[...reportingOffices, ...reportingUnits]} />
            </section>

            <section className="mt-6 rounded-panel border border-(--app-border) bg-(--app-surface) p-5">
                <h3 className="font-semibold text-(--app-foreground)">{t('organizations.nameHistory')}</h3>
                <div className="mt-4 overflow-hidden rounded-card border border-(--app-border)">
                    <table className={cx(tableClassName, 'min-w-full text-left')}>
                        <thead className={tableHeadClassName}>
                            <tr>
                                {[t('common.name'), t('common.effectiveFrom'), t('common.effectiveTo')].map((h) => (
                                    <th
                                        key={h}
                                        className={cx(tableHeaderCellClassName, 'text-left')}
                                    >
                                        {h}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className={tableBodyClassName}>
                            {organization.name_histories.map((history) => (
                                <tr
                                    key={history.id}
                                    className={tableRowClassName}
                                >
                                    <td className={tableCellClassName}>
                                        {(locale === 'am' && history.name_am) ? history.name_am : history.name_en}
                                    </td>
                                    <td className={tableCellClassName}><LocalizedDateDisplay value={history.effective_from} /></td>
                                    <td className={tableCellClassName}>
                                        {history.effective_to
                                            ? <LocalizedDateDisplay value={history.effective_to} />
                                            : (
                                            <span className="text-(--app-muted-foreground)">
                                                {t('common.current')}
                                            </span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </section>
        </AuthenticatedLayout>
    );
}
