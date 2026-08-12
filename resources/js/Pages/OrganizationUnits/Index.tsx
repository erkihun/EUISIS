import { useState, useEffect } from 'react';
import { Head, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import OrganizationTreePreview from '@/Components/organization-units/OrganizationTreePreview';
import OrganizationUnitTree from '@/Components/organization-units/OrganizationUnitTree';
import { Building2 } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { localizedName } from '@/utils/localizedName';
import type { OrganizationSummary, OrganizationTreeNode, OrganizationUnitTreeNode } from '@/types/organizationUnit';
import CopyStructureModal from './CopyStructureModal';

interface OrgOption {
    id: string;
    code: string;
    name_en: string;
    name_am: string | null;
}

interface VersionInfo {
    id: string;
    name: string;
    status: string;
    is_draft: boolean;
    effective_from: string | null;
}

interface Props {
    organizationTree: OrganizationTreeNode[];
    hasPublishedHierarchy: boolean;
    usingDraftFallback?: boolean;
    usingFlatFallback?: boolean;
    selectedVersion?: VersionInfo | null;
    availableVersions?: VersionInfo[];
    selectedOrganization: OrganizationSummary | null;
    organizationUnits: OrganizationUnitTreeNode[];
    can: { viewAny: boolean; create: boolean };
    organizations?: OrgOption[];
}

export default function OrganizationUnitsIndex({
    organizationTree,
    hasPublishedHierarchy,
    usingDraftFallback = false,
    usingFlatFallback = false,
    selectedVersion = null,
    availableVersions = [],
    selectedOrganization,
    organizationUnits,
    can,
    organizations = [],
}: Props) {
    const { t, locale } = useLocale();

    const [localSelected, setLocalSelected] = useState<OrganizationSummary | null>(
        selectedOrganization ?? null,
    );
    const [showCopyModal, setShowCopyModal] = useState(false);

    useEffect(() => {
        setLocalSelected(selectedOrganization ?? null);
    }, [selectedOrganization]);

    function selectOrganization(node: OrganizationTreeNode) {
        const params: Record<string, string> = { organization_id: node.id };
        if (selectedVersion) {
            params.hierarchy_version_id = selectedVersion.id;
        }
        router.get(
            route('organization-units.index'),
            params,
            { preserveState: false, preserveScroll: false },
        );
    }

    function switchVersion(versionId: string) {
        router.get(
            route('organization-units.index'),
            { hierarchy_version_id: versionId },
            { preserveState: false, preserveScroll: false },
        );
    }

    const displayOrg = localSelected ?? selectedOrganization;
    const displayOrgName = displayOrg
        ? localizedName(displayOrg.name_en, displayOrg.name_am, locale)
        : '';

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('nav.organizationUnits')}
                    actions={
                        can.create ? (
                            <button
                                type="button"
                                onClick={() => setShowCopyModal(true)}
                                className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500"
                            >
                                {t('organizationUnits.copyStructure')}
                            </button>
                        ) : undefined
                    }
                />
            }
        >
            <Head title={t('nav.organizationUnits')} />
            <CopyStructureModal
                show={showCopyModal}
                onClose={() => setShowCopyModal(false)}
                organizations={organizations}
            />

            {/* Two-panel layout: org tree left, unit tree right */}
            <div className="flex flex-col gap-5 lg:flex-row lg:items-stretch">
                {/* Left: Organization Tree Preview (40%) */}
                <div className="w-full lg:w-[38%] lg:min-h-[600px]">
                    <OrganizationTreePreview
                        tree={organizationTree}
                        selectedId={displayOrg?.id ?? null}
                        hasPublishedHierarchy={hasPublishedHierarchy}
                        usingDraftFallback={usingDraftFallback}
                        usingFlatFallback={usingFlatFallback}
                        selectedVersion={selectedVersion}
                        availableVersions={availableVersions}
                        onSelect={selectOrganization}
                        onVersionChange={switchVersion}
                    />
                </div>

                {/* Right: Organization Unit Tree (60%) */}
                <div className="w-full lg:flex-1">
                    {displayOrg ? (
                        <div className="space-y-4">
                            {/* Selected org header card */}
                            <section className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                                <div className="flex flex-wrap items-center gap-4">
                                    {displayOrg.has_logo && displayOrg.logo_url ? (
                                        <img
                                            src={displayOrg.logo_url}
                                            alt=""
                                            className="h-12 w-12 rounded-xl object-cover"
                                        />
                                    ) : (
                                        <span className="flex h-12 w-12 items-center justify-center rounded-xl bg-blue-100 text-lg font-bold text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">
                                            {displayOrgName.charAt(0).toUpperCase()}
                                        </span>
                                    )}
                                    <div className="flex-1 min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <h2 className="text-base font-semibold text-gray-900 dark:text-slate-100">
                                                {displayOrgName}
                                            </h2>
                                            <StatusBadge
                                                status={displayOrg.status}
                                                label={t(`common.${displayOrg.status}`)}
                                            />
                                        </div>
                                        <div className="mt-1 flex flex-wrap items-center gap-3 text-xs text-gray-500 dark:text-slate-400">
                                            {displayOrg.type && (
                                                <span>
                                                    {localizedName(
                                                        displayOrg.type.name_en,
                                                        displayOrg.type.name_am,
                                                        locale,
                                                    )}
                                                </span>
                                            )}
                                            <span>
                                                {displayOrg.organization_units_count ?? 0}{' '}
                                                {t('organizationUnits.unitCount').toLowerCase()}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            {/* Unit tree */}
                            <OrganizationUnitTree
                                units={organizationUnits}
                                canCreate={can.create}
                                canUpdate={can.create}
                                canDelete={can.create}
                                canRestore={can.create}
                                selectedOrgId={displayOrg.id}
                            />
                        </div>
                    ) : (
                        <div className="flex h-full min-h-[300px] flex-col items-center justify-center rounded-2xl border border-dashed border-gray-200 bg-white px-6 py-16 text-center dark:border-slate-700 dark:bg-slate-900">
                            <Building2 className="h-10 w-10 text-gray-300 dark:text-slate-600" />
                            <p className="mt-3 text-sm font-medium text-gray-500 dark:text-slate-400">
                                {t('organizationUnits.selectOrganizationToViewUnits')}
                            </p>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
