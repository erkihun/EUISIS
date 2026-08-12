import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import StatusBadge from '@/Components/StatusBadge';
import PageHeader from '@/Components/PageHeader';
import { PencilIcon, TrashIcon, ArchiveIcon, XCircle, Plus } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import ReportingLinesPanel from '@/Components/relationships/ReportingLinesPanel';
import type { RelationshipRow } from '@/Components/relationships/RelationshipPanel';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { localizedName } from '@/utils/localizedName';
import OrganizationStructureTree, { type OrganizationStructureTreeData } from '@/Components/organizations/OrganizationStructureTree';
import { useState } from 'react';

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
};

type InstitutionOfficePreview = {
    id: string;
    office_code: string;
    name_en: string | null;
    office_level: string;
    status: string;
};

export default function OrganizationShow({
    organization,
    parentOrganizationId,
    parentOrganization = null,
    structureSummary,
    structureTree,
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
    };
    parentOrganizationId: string | null;
    parentOrganization?: ParentOrganization;
    currentAssignmentsCount: number;
    structureSummary: StructureSummary;
    structureTree: OrganizationStructureTreeData;
    descendants: Descendant[];
    can: CanProps;
    deletionBlockers?: DeletionBlockerKey[];
    institutionOffices?: InstitutionOfficePreview[];
    reportingOffices?: RelationshipRow[];
    reportingUnits?: RelationshipRow[];
}) {
    const { t, locale } = useLocale();
    const deleteForm = useForm({});
    const archiveForm = useForm({});
    const deactivateForm = useForm({});
    const [showDeletionBlockers, setShowDeletionBlockers] = useState(false);

    const isBlocked = deletionBlockers.length > 0;
    const organizationName = localizedName(organization.name_en, organization.name_am, locale);

    function handleDelete() {
        if (isBlocked) {
            setShowDeletionBlockers(true);
            return;
        }
        if (!confirm(t('organizations.deleteConfirm'))) return;
        deleteForm.delete(route('organizations.destroy', organization.id));
    }

    function handleArchive() {
        if (!confirm(t('organizations.archiveConfirm'))) return;
        archiveForm.delete(route('organizations.archive', organization.id));
    }

    function handleDeactivate() {
        if (!confirm(t('organizations.deactivateConfirm'))) return;
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
                                    className="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-800"
                                >
                                    <Plus className="h-3.5 w-3.5" />
                                    {t('organizations.addChild')}
                                </Link>
                            )}
                            {can.update && (
                                <Link
                                    href={route('organizations.edit', organization.id)}
                                    className="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-800"
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
                                    className="inline-flex items-center gap-1.5 rounded-lg border border-amber-200 px-3 py-1.5 text-sm font-medium text-amber-700 hover:bg-amber-50 disabled:opacity-60 dark:border-amber-800 dark:text-amber-400 dark:hover:bg-amber-900/20"
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
                                    className="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-60 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-800"
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
                                    className="inline-flex items-center gap-1.5 rounded-lg border border-red-200 px-3 py-1.5 text-sm font-medium text-red-600 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent dark:border-red-800 dark:text-red-400 dark:hover:bg-red-900/20"
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

            <section className="relative mb-6 overflow-hidden rounded-3xl border border-blue-100 bg-gradient-to-br from-blue-700 via-blue-600 to-indigo-700 p-6 text-white shadow-sm dark:border-blue-900 dark:from-blue-950 dark:via-slate-900 dark:to-indigo-950 sm:p-8">
                <div className="absolute -right-16 -top-20 h-56 w-56 rounded-full bg-white/10 blur-2xl" />
                <div className="relative flex flex-col gap-6 xl:flex-row xl:items-end xl:justify-between">
                    <div className="flex min-w-0 items-center gap-4">
                        {organization.has_logo && organization.logo_url ? (
                            <img src={organization.logo_url} alt="" className="h-16 w-16 rounded-2xl border border-white/20 bg-white object-contain p-2 shadow-sm" />
                        ) : (
                            <div className="flex h-16 w-16 shrink-0 items-center justify-center rounded-2xl border border-white/20 bg-white/10 text-2xl font-bold backdrop-blur">
                                {organizationName.charAt(0).toUpperCase()}
                            </div>
                        )}
                        <div className="min-w-0">
                            <div className="mb-2 flex flex-wrap items-center gap-2">
                                <span className="rounded-full bg-white/15 px-2.5 py-1 font-mono text-xs">{organization.code}</span>
                                <StatusBadge status={organization.status} label={t(`common.${organization.status}`)} />
                            </div>
                            <h1 className="truncate text-2xl font-bold tracking-tight sm:text-3xl">{organizationName}</h1>
                            <p className="mt-1 text-sm text-blue-100">
                                {organization.type ? localizedName(organization.type.name_en, organization.type.name_am, locale) : t('organizations.organization')}
                            </p>
                        </div>
                    </div>
                    <dl className="grid grid-cols-2 gap-2 sm:grid-cols-5 xl:min-w-[620px]">
                        {[
                            { label: t('organizations.organizationUnits'), value: structureTree.counters.units },
                            { label: t('organizations.positions'), value: structureTree.counters.positions },
                            { label: t('organizations.occupied'), value: structureTree.counters.occupied_positions },
                            { label: t('organizations.vacant'), value: structureTree.counters.vacant_positions },
                            { label: t('organizations.assignedEmployees'), value: structureTree.counters.employees },
                        ].map(({ label, value }) => (
                            <div key={label} className="rounded-2xl border border-white/15 bg-white/10 px-3 py-3 text-center backdrop-blur-sm">
                                <dd className="text-xl font-bold tabular-nums">{value}</dd>
                                <dt className="mt-0.5 text-[11px] leading-tight text-blue-100">{label}</dt>
                            </div>
                        ))}
                    </dl>
                </div>
            </section>

            {showDeletionBlockers && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm" role="dialog" aria-modal="true">
                    <div className="w-full max-w-lg rounded-2xl border border-amber-200 bg-white p-6 shadow-2xl dark:border-amber-800 dark:bg-slate-900">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <h2 className="text-lg font-semibold text-gray-900 dark:text-slate-100">
                                    {t('organizations.cannotBeDeleted')}
                                </h2>
                                <p className="mt-1 text-sm text-gray-600 dark:text-slate-400">
                                    {t('organizations.cannotDeleteUsedMessage')}
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setShowDeletionBlockers(false)}
                                className="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-slate-800 dark:hover:text-slate-200"
                                aria-label={t('common.close')}
                            >
                                ×
                            </button>
                        </div>
                        <ul className="mt-5 space-y-2 rounded-xl bg-amber-50 p-4 text-sm text-amber-800 dark:bg-amber-950/30 dark:text-amber-300">
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
                                className="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white"
                            >
                                {t('common.close')}
                            </button>
                        </div>
                    </div>
                </div>
            )}

            <div className="grid gap-6 xl:grid-cols-[1.65fr_1fr]">
                {/* Basic Information */}
                <section className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <p className="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-slate-500">
                        {t('organizations.basicInformation')}
                    </p>
                    <dl className="mt-3 grid gap-4 sm:grid-cols-2">
                        {[
                            { label: t('organizations.type'), value: organization.type ? localizedName(organization.type.name_en, organization.type.name_am, locale) : '—' },
                            { label: t('organizations.legalBasis'), value: organization.legal_basis_ref ?? '—' },
                            { label: t('organizations.mergedInto'), value: organization.merged_into ? localizedName(organization.merged_into.name_en, organization.merged_into.name_am, locale) : '—' },
                        ].map(({ label, value }) => (
                            <div key={label}>
                                <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">{label}</dt>
                                <dd className="mt-1 text-sm text-gray-800 dark:text-slate-200">{value}</dd>
                            </div>
                        ))}

                        <div>
                            <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">
                                {t('common.effectiveFrom')}
                            </dt>
                            <dd className="mt-1 text-sm text-gray-800 dark:text-slate-200">
                                <LocalizedDateDisplay value={organization.effective_from} />
                            </dd>
                        </div>

                        <div>
                            <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">
                                {t('common.effectiveTo')}
                            </dt>
                            <dd className="mt-1 text-sm text-gray-800 dark:text-slate-200">
                                {organization.effective_to
                                    ? <LocalizedDateDisplay value={organization.effective_to} />
                                    : <span className="text-gray-400 dark:text-slate-500">{t('common.current')}</span>
                                }
                            </dd>
                        </div>
                    </dl>
                </section>

                {/* Right column: hierarchy + structure */}
                <div>
                    {/* Hierarchy & Placement */}
                    <section className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <h3 className="font-semibold text-gray-900 dark:text-slate-100">
                            {t('organizations.hierarchyAndPlacement')}
                        </h3>
                        <dl className="mt-4 space-y-4">
                            <div>
                                <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">
                                    {t('organizations.parentOrganization')}
                                </dt>
                                <dd className="mt-1 text-sm">
                                    {parentOrganization ? (
                                        <Link
                                            href={route('organizations.show', parentOrganization.id)}
                                            className="font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                                        >
                                            {parentOrganization.code} — {localizedName(parentOrganization.name_en, parentOrganization.name_am, locale)}
                                        </Link>
                                    ) : (
                                        <span className="text-gray-400 dark:text-slate-500">{t('organizations.noParentRoot')}</span>
                                    )}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">
                                    {t('organizations.subtreeReach')}
                                </dt>
                                <dd className="mt-1 text-sm text-gray-800 dark:text-slate-200 tabular-nums">
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
                <section className="mt-6 rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h3 className="font-semibold text-gray-900 dark:text-slate-100">
                        {t('organizations.subtreeReach')}
                    </h3>
                    <ul className="mt-4 divide-y divide-gray-100 dark:divide-slate-800">
                        {descendants.map((d) => (
                            <li key={d.descendant_organization_id} className="flex items-center justify-between py-2 text-sm">
                                <span className="flex items-center gap-2">
                                    <span className="font-mono text-xs text-gray-400 dark:text-slate-500">{d.code ?? '—'}</span>
                                    <Link
                                        href={route('organizations.show', d.descendant_organization_id)}
                                        className="text-gray-700 hover:text-blue-600 dark:text-slate-200 dark:hover:text-blue-400"
                                    >
                                        {localizedName(d.name_en, d.name_am, locale) || d.descendant_organization_id}
                                    </Link>
                                </span>
                                <span className="text-xs text-gray-400 dark:text-slate-500">
                                    {t('common.depth')} {d.depth}
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {(organization.has_logo || organization.branding_primary_color || organization.branding_secondary_color) && (
                <section className="mt-6 rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h3 className="font-semibold text-gray-900 dark:text-slate-100">
                        {t('organizations.branding')}
                    </h3>
                    <div className="mt-4 flex flex-wrap items-start gap-6">
                        {organization.has_logo && organization.logo_url && (
                            <div>
                                <p className="mb-1.5 text-xs font-medium text-gray-500 dark:text-slate-400">
                                    {t('organizations.logo')}
                                </p>
                                <img
                                    src={organization.logo_url}
                                    alt={`${organization.name_en} logo`}
                                    className="h-16 w-16 rounded-xl border border-gray-200 object-contain p-1.5 dark:border-slate-700"
                                />
                            </div>
                        )}
                        {(organization.branding_primary_color || organization.branding_secondary_color) && (
                            <div>
                                <p className="mb-1.5 text-xs font-medium text-gray-500 dark:text-slate-400">
                                    {t('organizations.colorPreview')}
                                </p>
                                <div className="flex items-center gap-3">
                                    {organization.branding_primary_color && (
                                        <div className="flex items-center gap-1.5">
                                            <span
                                                className="h-6 w-6 rounded-full border border-white shadow"
                                                style={{ backgroundColor: organization.branding_primary_color }}
                                            />
                                            <span className="font-mono text-xs text-gray-600 dark:text-slate-300">
                                                {organization.branding_primary_color}
                                            </span>
                                        </div>
                                    )}
                                    {organization.branding_secondary_color && (
                                        <div className="flex items-center gap-1.5">
                                            <span
                                                className="h-6 w-6 rounded-full border border-white shadow"
                                                style={{ backgroundColor: organization.branding_secondary_color }}
                                            />
                                            <span className="font-mono text-xs text-gray-600 dark:text-slate-300">
                                                {organization.branding_secondary_color}
                                            </span>
                                        </div>
                                    )}
                                </div>
                                {organization.branding_primary_color && (
                                    <div
                                        className="mt-3 rounded-lg border border-l-4 border-gray-200 px-3 py-2 text-xs text-gray-500 dark:border-slate-700 dark:text-slate-400"
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
                <section className="mt-6 rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <div className="flex items-center justify-between">
                        <h3 className="font-semibold text-gray-900 dark:text-slate-100">
                            {t('institutionOffices.title')} ({institutionOffices.length})
                        </h3>
                        <Link
                            href={`${route('institution-offices.create')}?institution_id=${organization.id}`}
                            className="text-xs text-blue-600 hover:underline dark:text-blue-400"
                        >
                            + {t('institutionOffices.addOffice')}
                        </Link>
                    </div>
                    <div className="mt-4 overflow-hidden rounded-xl border border-gray-100 dark:border-slate-800">
                        <table className="min-w-full text-left text-sm">
                            <thead className="bg-gray-50 dark:bg-slate-950">
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
                                            className="px-4 py-2 text-xs font-semibold uppercase text-gray-500 dark:text-slate-400"
                                        >
                                            {h}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {institutionOffices.map((office) => (
                                    <tr
                                        key={office.id}
                                        className="border-t border-gray-100 dark:border-slate-800"
                                    >
                                        <td className="px-4 py-2 font-mono text-xs text-gray-500 dark:text-slate-400">
                                            {office.office_code}
                                        </td>
                                        <td className="px-4 py-2 text-sm text-gray-700 dark:text-slate-200">
                                            {office.name_en ?? '—'}
                                        </td>
                                        <td className="px-4 py-2">
                                            <span className="rounded-full bg-blue-100 px-1.5 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">
                                                {office.office_level}
                                            </span>
                                        </td>
                                        <td className="px-4 py-2">
                                            <span className="text-xs text-gray-500 dark:text-slate-400">
                                                {office.status}
                                            </span>
                                        </td>
                                        <td className="px-4 py-2">
                                            <Link
                                                href={route('institution-offices.show', office.id)}
                                                className="text-xs text-blue-600 hover:underline dark:text-blue-400"
                                            >
                                                {t('common.view')}
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            )}

            <section className="mt-6">
                <ReportingLinesPanel rows={[...reportingOffices, ...reportingUnits]} />
            </section>

            <section className="mt-6 rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h3 className="font-semibold text-gray-900 dark:text-slate-100">{t('organizations.nameHistory')}</h3>
                <div className="mt-4 overflow-hidden rounded-xl border border-gray-100 dark:border-slate-800">
                    <table className="min-w-full text-left text-sm">
                        <thead className="bg-gray-50 dark:bg-slate-950">
                            <tr>
                                {[t('common.name'), t('common.effectiveFrom'), t('common.effectiveTo')].map((h) => (
                                    <th
                                        key={h}
                                        className="px-4 py-3 text-xs font-semibold uppercase text-gray-500 dark:text-slate-400"
                                    >
                                        {h}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {organization.name_histories.map((history) => (
                                <tr
                                    key={history.id}
                                    className="border-t border-gray-100 text-gray-700 dark:border-slate-800 dark:text-slate-200"
                                >
                                    <td className="px-4 py-3">
                                        {(locale === 'am' && history.name_am) ? history.name_am : history.name_en}
                                    </td>
                                    <td className="px-4 py-3"><LocalizedDateDisplay value={history.effective_from} /></td>
                                    <td className="px-4 py-3">
                                        {history.effective_to
                                            ? <LocalizedDateDisplay value={history.effective_to} />
                                            : (
                                            <span className="text-gray-400 dark:text-slate-500">
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
