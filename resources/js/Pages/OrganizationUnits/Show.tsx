import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import type { ComponentProps } from 'react';
import PageHeader from '@/Components/PageHeader';
import OrganizationUnitStatusBadge from '@/Components/organization-units/OrganizationUnitStatusBadge';
import OrganizationUnitTypeBadge from '@/Components/organization-units/OrganizationUnitTypeBadge';
import { Head, Link, useForm } from '@inertiajs/react';
import { useConfirm } from '@/Components/ConfirmProvider';
import { useLocale } from '@/hooks/useLocale';
import type { OrganizationUnit } from '@/types/organizationUnit';
import RelationshipPanel, { type RelationshipRow } from '@/Components/relationships/RelationshipPanel';
import ReportingLinesPanel from '@/Components/relationships/ReportingLinesPanel';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { localizedName } from '@/utils/localizedName';

import { actionLinkClassName, buttonClassName } from '@/Components/ui';
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
    const { confirm } = useConfirm();
    const { post, processing } = useForm();
    const unitName = localizedName(unit.name_en, unit.name_am, locale);
    const description = localizedName(unit.description_en, unit.description_am, locale);

    async function handleArchive() {
        if (!(await confirm({ title: t('common.cannotUndo'), variant: 'danger' })).confirmed) return;
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
                                    className={buttonClassName({ variant: 'outline', size: 'sm' })}
                                >
                                    {t('common.edit')}
                                </Link>
                            )}
                            {unit.can.archive && unit.status !== 'archived' && (
                                <button
                                    type="button"
                                    onClick={handleArchive}
                                    disabled={processing}
                                    className={buttonClassName({ variant: 'outline', size: 'sm' })}
                                >
                                    {t('common.delete')}
                                </button>
                            )}
                            {unit.can.restore && unit.status === 'archived' && (
                                <button
                                    type="button"
                                    onClick={handleRestore}
                                    disabled={processing}
                                    className={buttonClassName({ variant: 'outline', size: 'sm' })}
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

            <div className="space-y-6">
                {/* Header badges */}
                <div className="flex flex-wrap items-center gap-2">
                    <span className="font-mono text-sm text-(--app-muted-foreground)">{unit.code}</span>
                    <OrganizationUnitTypeBadge unitType={unit.unit_type} catalog={unit.unitType} />
                    <OrganizationUnitStatusBadge status={unit.status} />
                </div>

                {/* Hosted unit — owner vs. operating organization */}
                {hostedContext?.host_organization && (
                    <section className="rounded-panel border border-blue-200 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-950/30">
                        <div className="grid gap-4 text-sm sm:grid-cols-2">
                            <div>
                                <p className="text-xs font-medium text-blue-700 dark:text-blue-300">
                                    {t('organizationUnits.ownerOrganization')}
                                </p>
                                <p className="mt-1 font-medium text-(--app-foreground)">
                                    <span className="font-mono text-xs text-(--app-muted-foreground)">{hostedContext.owner_organization?.code}</span>
                                    {' — '}
                                    {hostedContext.owner_organization
                                        ? localizedName(hostedContext.owner_organization.name_en, hostedContext.owner_organization.name_am, locale)
                                        : '—'}
                                </p>
                            </div>
                            <div>
                                <p className="text-xs font-medium text-blue-700 dark:text-blue-300">
                                    {t('organizationUnits.operatingOrganization')}
                                </p>
                                <p className="mt-1 font-medium text-(--app-foreground)">
                                    <span className="font-mono text-xs text-(--app-muted-foreground)">{hostedContext.host_organization.code}</span>
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
                <section className="rounded-panel border border-(--app-border) bg-(--app-surface) p-6">
                    <h2 className="mb-4 text-base font-semibold text-(--app-foreground)">
                        {t('organizationUnits.organizationUnitDetails')}
                    </h2>
                    <dl className="grid grid-cols-2 gap-x-8 gap-y-4 text-sm">
                        <div>
                            <dt className="text-(--app-muted-foreground)">{t('organizationUnits.organization')}</dt>
                            <dd className="font-medium text-(--app-foreground)">
                                {unit.organization ? (
                                    <Link
                                        href={route('organizations.show', unit.organization.id)}
                                        className="text-primary hover:underline dark:text-primary"
                                    >
                                        {localizedName(unit.organization.name_en, unit.organization.name_am, locale)}
                                    </Link>
                                ) : '—'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-(--app-muted-foreground)">
                                {t('organizationUnits.parentUnit')}
                            </dt>
                            <dd className="font-medium text-(--app-foreground)">
                                {unit.parent ? (
                                    <Link
                                        href={route('organization-units.show', unit.parent.id)}
                                        className="text-primary hover:underline dark:text-primary"
                                    >
                                        {localizedName(unit.parent.name_en, unit.parent.name_am, locale)}
                                    </Link>
                                ) : '—'}
                            </dd>
                        </div>
                        {unit.effective_from && (
                            <div>
                                <dt className="text-(--app-muted-foreground)">
                                    {t('organizationUnits.effectiveFrom')}
                                </dt>
                                <dd className="font-medium text-(--app-foreground)">
                                    <LocalizedDateDisplay value={unit.effective_from} />
                                </dd>
                            </div>
                        )}
                        {unit.effective_to && (
                            <div>
                                <dt className="text-(--app-muted-foreground)">
                                    {t('organizationUnits.effectiveTo')}
                                </dt>
                                <dd className="font-medium text-(--app-foreground)">
                                    <LocalizedDateDisplay value={unit.effective_to} />
                                </dd>
                            </div>
                        )}
                        {description && (
                            <div className="col-span-2">
                                <dt className="text-(--app-muted-foreground)">
                                    {t('organizationUnits.description')}
                                </dt>
                                <dd className="font-medium text-(--app-foreground)">
                                    {description}
                                </dd>
                            </div>
                        )}
                    </dl>
                </section>

                {/* Child Units */}
                {(unit.children && unit.children.length > 0) && (
                    <section className="rounded-panel border border-(--app-border) bg-(--app-surface) p-6">
                        <h2 className="mb-4 text-base font-semibold text-(--app-foreground)">
                            {t('organizationUnits.childUnits')} ({unit.children.length})
                        </h2>
                        <ul className="divide-y divide-(--app-border)">
                            {unit.children.map((child) => (
                                <li key={child.id} className="flex items-center justify-between py-2">
                                    <div>
                                        <Link
                                            href={route('organization-units.show', child.id)}
                                            className={actionLinkClassName({ size: 'sm' })}
                                        >
                                            {localizedName(child.name_en, child.name_am, locale)}
                                        </Link>
                                        <span className="ml-2 font-mono text-xs text-(--app-muted-foreground)">{child.code}</span>
                                    </div>
                                    <div className="flex gap-2">
                                        <OrganizationUnitTypeBadge unitType={child.unit_type} catalog={child.unitType} />
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
                        className={actionLinkClassName({ tone: 'neutral', size: 'sm' })}
                    >
                        {t('organizationUnits.backToList')}
                    </Link>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
