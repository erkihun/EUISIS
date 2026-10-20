import { useState, useEffect } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatCard from '@/Components/StatCard';
import StatusBadge from '@/Components/StatusBadge';
import OrganizationTreePreview from '@/Components/organization-units/OrganizationTreePreview';
import OrganizationUnitTree, { type UnitFilters } from '@/Components/organization-units/OrganizationUnitTree';
import { Building2, CheckCircle, Layers, Plus, XCircle } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { localizedName } from '@/utils/localizedName';
import type { OrganizationSummary, OrganizationTreeNode, OrganizationUnitTreeNode, OrganizationUnitTypeModel } from '@/types/organizationUnit';
import CopyStructureModal from './CopyStructureModal';

import { FilterField, FilterPanel, Input, Select, buttonClassName } from '@/Components/ui';
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
    stats?: { total: number; active: number; inactive: number; archived: number; organizations: number };
    organizationTree: OrganizationTreeNode[];
    hasPublishedHierarchy: boolean;
    usingDraftFallback?: boolean;
    usingFlatFallback?: boolean;
    selectedVersion?: VersionInfo | null;
    availableVersions?: VersionInfo[];
    selectedOrganization: OrganizationSummary | null;
    organizationUnits: OrganizationUnitTreeNode[];
    unitTypes?: OrganizationUnitTypeModel[];
    can: { viewAny: boolean; create: boolean };
    organizations?: OrgOption[];
}

const emptyFilters: UnitFilters = { search: '', unitType: '', status: '' };

/**
 * Organization units, laid out like the organizations and positions pages:
 * KPI tiles, the organization structure on the left to pick from, and on the
 * right the selected organization, the filter panel and its units as a tree
 * inside the shared list frame.
 */
export default function OrganizationUnitsIndex({
    stats = { total: 0, active: 0, inactive: 0, archived: 0, organizations: 0 },
    organizationTree,
    hasPublishedHierarchy,
    usingDraftFallback = false,
    usingFlatFallback = false,
    selectedVersion = null,
    availableVersions = [],
    selectedOrganization,
    organizationUnits,
    unitTypes = [],
    can,
    organizations = [],
}: Props) {
    const { t, locale } = useLocale();

    const [localSelected, setLocalSelected] = useState<OrganizationSummary | null>(selectedOrganization ?? null);
    const [showCopyModal, setShowCopyModal] = useState(false);
    // Filters are applied on Filter (not per keystroke), as on every other list, and act on the loaded tree.
    const [values, setValues] = useState<UnitFilters>(emptyFilters);
    const [filters, setFilters] = useState<UnitFilters>(emptyFilters);

    useEffect(() => {
        setLocalSelected(selectedOrganization ?? null);
        setValues(emptyFilters);
        setFilters(emptyFilters);
    }, [selectedOrganization]);

    function selectOrganization(node: OrganizationTreeNode) {
        const params: Record<string, string> = { organization_id: node.id };
        if (selectedVersion) params.hierarchy_version_id = selectedVersion.id;
        router.get(route('organization-units.index'), params, { preserveState: false, preserveScroll: false });
    }

    function switchVersion(versionId: string) {
        router.get(route('organization-units.index'), { hierarchy_version_id: versionId }, { preserveState: false, preserveScroll: false });
    }

    /** Localized status label, falling back to StatusBadge's own default when a key is missing. */
    function statusLabel(status: string): string | undefined {
        const key = `common.${status}`;
        const translated = t(key);
        return translated === key ? undefined : translated;
    }

    const displayOrg = localSelected ?? selectedOrganization;
    const displayOrgName = displayOrg ? localizedName(displayOrg.name_en, displayOrg.name_am, locale) : '';
    const filtersActive = Boolean(filters.search.trim() || filters.unitType || filters.status);
    const createHref = route('organization-units.create', displayOrg ? { organization_id: displayOrg.id } : {});

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('nav.organizationUnits')}
                    description={selectedVersion
                        ? `${t('organizations.hierarchyVersion')}: ${selectedVersion.name}${selectedVersion.is_draft ? ` (${t('hierarchyVersions.draft')})` : ''}`
                        : t('organizations.noHierarchy')}
                    actions={can.create ? (
                        <>
                            <button type="button" onClick={() => setShowCopyModal(true)} className={buttonClassName({ variant: 'outline' })}>
                                <Layers className="h-4 w-4" aria-hidden="true" />
                                {t('organizationUnits.copyStructure')}
                            </button>
                            <Link href={createHref} className={buttonClassName({ variant: 'primary' })}>
                                <Plus className="h-4 w-4" aria-hidden="true" />
                                {t('organizationUnits.createOrganizationUnit')}
                            </Link>
                        </>
                    ) : undefined}
                />
            }
        >
            <Head title={t('nav.organizationUnits')} />
            <CopyStructureModal show={showCopyModal} onClose={() => setShowCopyModal(false)} organizations={organizations} />

            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard label={t('organizationUnits.kpiTotalUnits')} value={stats.total} tone="primary" icon={<Layers className="h-4 w-4" />} />
                <StatCard label={t('organizationUnits.kpiActiveUnits')} value={stats.active} tone="success" icon={<CheckCircle className="h-4 w-4" />} />
                <StatCard label={t('organizationUnits.kpiInactiveUnits')} value={stats.inactive} tone="warning" icon={<XCircle className="h-4 w-4" />} hint={`${stats.archived} ${t('common.archived').toLowerCase()}`} />
                <StatCard label={t('organizationUnits.kpiOrganizationsWithUnits')} value={stats.organizations} tone="neutral" icon={<Building2 className="h-4 w-4" />} />
            </div>

            <div className="mt-4 flex flex-col gap-4 lg:flex-row lg:items-stretch">
                <div className="w-full lg:w-[34%] lg:min-h-[600px]">
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

                <div className="w-full lg:flex-1">
                    {displayOrg ? (
                        <div className="space-y-4">
                            <div className="flex flex-wrap items-center justify-between gap-3 rounded-panel border border-(--app-border) bg-(--app-surface) px-4 py-3">
                                <div className="flex min-w-0 items-center gap-3">
                                    {displayOrg.has_logo && displayOrg.logo_url ? (
                                        <img src={displayOrg.logo_url} alt="" className="h-9 w-9 shrink-0 rounded-card object-cover" />
                                    ) : (
                                        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-card bg-blue-100 text-sm font-bold text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">
                                            {displayOrgName.charAt(0).toUpperCase()}
                                        </span>
                                    )}
                                    <div className="min-w-0">
                                        <p className="flex flex-wrap items-center gap-1.5 text-xs text-(--app-muted-foreground)">
                                            <span>{t('organizationUnits.selectedOrganization')}</span>
                                            <span className="font-mono">{displayOrg.code}</span>
                                            <StatusBadge status={displayOrg.status} label={statusLabel(displayOrg.status)} />
                                        </p>
                                        <p className="truncate text-sm font-semibold text-(--app-foreground)">{displayOrgName}</p>
                                        <p className="truncate text-xs text-(--app-muted-foreground)">
                                            {[
                                                displayOrg.type ? localizedName(displayOrg.type.name_en, displayOrg.type.name_am, locale) : null,
                                                `${displayOrg.organization_units_count ?? 0} ${t('organizationUnits.unitCount').toLowerCase()}`,
                                            ].filter(Boolean).join(' · ')}
                                        </p>
                                    </div>
                                </div>
                                {can.create && (
                                    <Link href={createHref} className={buttonClassName({ variant: 'primary', size: 'sm' })}>
                                        <Plus className="h-3.5 w-3.5" aria-hidden="true" />
                                        {t('organizationUnits.addOrganizationUnit')}
                                    </Link>
                                )}
                            </div>

                            <FilterPanel
                                onSubmit={() => setFilters(values)}
                                onReset={() => { setValues(emptyFilters); setFilters(emptyFilters); }}
                                hasActiveFilters={filtersActive}
                                label={t('organizationUnits.organizationUnits')}
                            >
                                <FilterField label={t('common.search')} wide>
                                    <Input type="search" value={values.search} placeholder={t('organizationUnits.searchByCodeOrName')} onChange={(e) => setValues({ ...values, search: e.target.value })} />
                                </FilterField>
                                <FilterField label={t('organizationUnits.unitType')}>
                                    <Select value={values.unitType} onChange={(e) => setValues({ ...values, unitType: e.target.value })}>
                                        <option value="">{t('organizationUnits.allTypes')}</option>
                                        {unitTypes.map((type) => <option key={type.id} value={type.id}>{localizedName(type.name_en, type.name_am, locale)}</option>)}
                                    </Select>
                                </FilterField>
                                <FilterField label={t('common.status')}>
                                    <Select value={values.status} onChange={(e) => setValues({ ...values, status: e.target.value })}>
                                        <option value="">{t('common.allStatuses')}</option>
                                        <option value="active">{t('common.active')}</option>
                                        <option value="inactive">{t('common.inactive')}</option>
                                        <option value="draft">{t('common.draft')}</option>
                                        <option value="archived">{t('common.archived')}</option>
                                    </Select>
                                </FilterField>
                            </FilterPanel>

                            <OrganizationUnitTree
                                title={t('organizationUnits.unitsInOrganization')}
                                units={organizationUnits}
                                filters={filters}
                                canCreate={can.create}
                                canUpdate={can.create}
                                canDelete={can.create}
                                canRestore={can.create}
                                selectedOrgId={displayOrg.id}
                            />
                        </div>
                    ) : (
                        <div className="flex h-full min-h-[300px] flex-col items-center justify-center rounded-panel border border-dashed border-(--app-border) bg-(--app-surface) px-6 py-16 text-center">
                            <Building2 className="h-10 w-10 text-(--app-muted-foreground)" aria-hidden="true" />
                            <p className="mt-3 text-sm font-medium text-(--app-muted-foreground)">{t('organizationUnits.selectOrganizationToViewUnits')}</p>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
