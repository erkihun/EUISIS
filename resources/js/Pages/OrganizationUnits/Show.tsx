import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import type { ComponentProps } from 'react';
import PageHeader from '@/Components/PageHeader';
import OrganizationUnitStatusBadge from '@/Components/organization-units/OrganizationUnitStatusBadge';
import OrganizationUnitTypeBadge from '@/Components/organization-units/OrganizationUnitTypeBadge';
import { Head, Link, useForm } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import type { OrganizationUnit } from '@/types/organizationUnit';
import RelationshipPanel, { type RelationshipRow } from '@/Components/relationships/RelationshipPanel';
import ReportingLinesPanel from '@/Components/relationships/ReportingLinesPanel';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { localizedName } from '@/utils/localizedName';

type HostedOrgRef = { id: string; code: string; name_en: string; name_am: string | null };

interface Props {
    unit: OrganizationUnit;
    relationships: RelationshipRow[];
    relationshipOptions: ComponentProps<typeof RelationshipPanel>['options'];
    /** Present only when the unit belongs functionally to another organization. */
    hostedContext?: {
        owner_organization: HostedOrgRef | null;
        host_organization: HostedOrgRef | null;
    } | null;
    can: {
        manageRelationships: boolean;
        updateRelationships: boolean;
        deleteRelationships: boolean;
    };
}

export default function OrganizationUnitsShow({ unit, relationships, relationshipOptions, hostedContext = null, can }: Props) {
    const { t, locale } = useLocale();
    const { post, processing } = useForm();
    const unitName = localizedName(unit.name_en, unit.name_am, locale);
    const description = localizedName(unit.description_en, unit.description_am, locale);

    function handleArchive() {
        if (!confirm(t('common.cannotUndo'))) return;
        post(route('organization-units.archive', unit.id));
    }

    function handleRestore() {
        post(route('organization-units.restore', unit.id));
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    backHref={route('organizations.show', unit.organization_id)}
                    title={unitName}
                    actions={
                        <div className="flex gap-2">
                            {unit.can.update && (
                                <Link
                                    href={route('organization-units.edit', unit.id)}
                                    className="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"
                                >
                                    {t('common.edit')}
                                </Link>
                            )}
                            {unit.can.archive && unit.status !== 'archived' && (
                                <button
                                    type="button"
                                    onClick={handleArchive}
                                    disabled={processing}
                                    className="inline-flex items-center gap-1.5 rounded-lg border border-red-300 bg-white px-3 py-1.5 text-sm font-medium text-red-600 hover:bg-red-50 dark:border-red-800 dark:bg-slate-900 dark:text-red-400"
                                >
                                    {t('common.delete')}
                                </button>
                            )}
                            {unit.can.restore && unit.status === 'archived' && (
                                <button
                                    type="button"
                                    onClick={handleRestore}
                                    disabled={processing}
                                    className="inline-flex items-center gap-1.5 rounded-lg border border-emerald-300 bg-white px-3 py-1.5 text-sm font-medium text-emerald-600 hover:bg-emerald-50 dark:border-emerald-800 dark:bg-slate-900 dark:text-emerald-400"
                                >
                                    {t('common.restore')}
                                </button>
                            )}
                        </div>
                    }
                />
            }
        >
            <Head title={unitName} />

            <div className="mx-auto max-w-5xl space-y-6">
                {/* Header badges */}
                <div className="flex flex-wrap items-center gap-2">
                    <span className="font-mono text-sm text-gray-500 dark:text-slate-400">{unit.code}</span>
                    <OrganizationUnitTypeBadge unitType={unit.unit_type} />
                    <OrganizationUnitStatusBadge status={unit.status} />
                </div>

                {/* Hosted unit — owner vs. operating organization */}
                {hostedContext?.host_organization && (
                    <section className="rounded-2xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-950/30">
                        <div className="grid gap-4 text-sm sm:grid-cols-2">
                            <div>
                                <p className="text-xs font-medium uppercase tracking-wide text-blue-700 dark:text-blue-300">
                                    {t('organizationUnits.ownerOrganization')}
                                </p>
                                <p className="mt-1 font-medium text-gray-900 dark:text-slate-100">
                                    <span className="font-mono text-xs text-gray-500 dark:text-slate-400">{hostedContext.owner_organization?.code}</span>
                                    {' — '}
                                    {hostedContext.owner_organization
                                        ? localizedName(hostedContext.owner_organization.name_en, hostedContext.owner_organization.name_am, locale)
                                        : '—'}
                                </p>
                            </div>
                            <div>
                                <p className="text-xs font-medium uppercase tracking-wide text-blue-700 dark:text-blue-300">
                                    {t('organizationUnits.operatingOrganization')}
                                </p>
                                <p className="mt-1 font-medium text-gray-900 dark:text-slate-100">
                                    <span className="font-mono text-xs text-gray-500 dark:text-slate-400">{hostedContext.host_organization.code}</span>
                                    {' — '}
                                    {localizedName(hostedContext.host_organization.name_en, hostedContext.host_organization.name_am, locale)}
                                </p>
                            </div>
                        </div>
                        <p className="mt-3 text-xs text-blue-700 dark:text-blue-300">
                            {t('organizationUnits.hostedUnitHelp')}
                        </p>
                    </section>
                )}

                {/* Details Card */}
                <section className="rounded-2xl border border-gray-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                    <h2 className="mb-4 text-base font-semibold text-gray-900 dark:text-slate-100">
                        {t('organizationUnits.organizationUnitDetails')}
                    </h2>
                    <dl className="grid grid-cols-2 gap-x-8 gap-y-4 text-sm">
                        <div>
                            <dt className="text-gray-500 dark:text-slate-400">{t('organizationUnits.organization')}</dt>
                            <dd className="font-medium text-gray-900 dark:text-slate-100">
                                {unit.organization ? (
                                    <Link
                                        href={route('organizations.show', unit.organization.id)}
                                        className="text-blue-600 hover:underline dark:text-blue-400"
                                    >
                                        {localizedName(unit.organization.name_en, unit.organization.name_am, locale)}
                                    </Link>
                                ) : '—'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-gray-500 dark:text-slate-400">
                                {t('organizationUnits.parentUnit')}
                            </dt>
                            <dd className="font-medium text-gray-900 dark:text-slate-100">
                                {unit.parent ? (
                                    <Link
                                        href={route('organization-units.show', unit.parent.id)}
                                        className="text-blue-600 hover:underline dark:text-blue-400"
                                    >
                                        {localizedName(unit.parent.name_en, unit.parent.name_am, locale)}
                                    </Link>
                                ) : '—'}
                            </dd>
                        </div>
                        {unit.effective_from && (
                            <div>
                                <dt className="text-gray-500 dark:text-slate-400">
                                    {t('organizationUnits.effectiveFrom')}
                                </dt>
                                <dd className="font-medium text-gray-900 dark:text-slate-100">
                                    <LocalizedDateDisplay value={unit.effective_from} />
                                </dd>
                            </div>
                        )}
                        {unit.effective_to && (
                            <div>
                                <dt className="text-gray-500 dark:text-slate-400">
                                    {t('organizationUnits.effectiveTo')}
                                </dt>
                                <dd className="font-medium text-gray-900 dark:text-slate-100">
                                    <LocalizedDateDisplay value={unit.effective_to} />
                                </dd>
                            </div>
                        )}
                        {description && (
                            <div className="col-span-2">
                                <dt className="text-gray-500 dark:text-slate-400">
                                    {t('organizationUnits.description')}
                                </dt>
                                <dd className="font-medium text-gray-900 dark:text-slate-100">
                                    {description}
                                </dd>
                            </div>
                        )}
                    </dl>
                </section>

                {/* Child Units */}
                {(unit.children && unit.children.length > 0) && (
                    <section className="rounded-2xl border border-gray-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                        <h2 className="mb-4 text-base font-semibold text-gray-900 dark:text-slate-100">
                            {t('organizationUnits.childUnits')} ({unit.children.length})
                        </h2>
                        <ul className="divide-y divide-gray-100 dark:divide-slate-800">
                            {unit.children.map((child) => (
                                <li key={child.id} className="flex items-center justify-between py-2">
                                    <div>
                                        <Link
                                            href={route('organization-units.show', child.id)}
                                            className="text-sm font-medium text-blue-600 hover:underline dark:text-blue-400"
                                        >
                                            {localizedName(child.name_en, child.name_am, locale)}
                                        </Link>
                                        <span className="ml-2 font-mono text-xs text-gray-400">{child.code}</span>
                                    </div>
                                    <div className="flex gap-2">
                                        <OrganizationUnitTypeBadge unitType={child.unit_type} />
                                        <OrganizationUnitStatusBadge status={child.status} />
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <RelationshipPanel
                    rows={relationships}
                    options={relationshipOptions}
                    storeRoute={route('organization-units.relationships.store', unit.id)}
                    updateRoute={(id) => route('organization-units.relationships.update', { organizationUnit: unit.id, relationship: id })}
                    deleteRoute={(id) => route('organization-units.relationships.destroy', { organizationUnit: unit.id, relationship: id })}
                    canManage={can.manageRelationships}
                    canUpdate={can.updateRelationships}
                    canDelete={can.deleteRelationships}
                />

                <ReportingLinesPanel rows={relationships.filter((relationship) => relationship.relationship_type !== 'structural_parent')} />

                <div className="flex gap-2">
                    <Link
                        href={route('organization-units.index')}
                        className="text-sm text-gray-500 hover:text-gray-700 dark:text-slate-400"
                    >
                        {t('organizationUnits.backToList')}
                    </Link>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
