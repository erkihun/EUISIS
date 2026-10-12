import { useState, useEffect } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import ScopedOrganizationStructure, { type ScopedOrganization } from '@/Components/organization-structure/ScopedOrganizationStructure';
import { Plus } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';
import { localizedName } from '@/utils/localizedName';
import type { OrganizationSummary } from '@/types/organizationUnit';

import { FilterField, FilterPanel, Input, Select, badgeClassName, buttonClassName, cx, tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
import AppActionMenu from '@/Components/ui/AppActionMenu';
type EstablishmentSummary = {
    id: string;
    status: 'draft' | 'approved' | 'archived';
    approved_slots: number;
    establishment_number: string;
} | null;

type PositionRow = {
    id: string;
    job_position_code: string;
    old_code: string | null;
    bpr_name: string | null;
    title_en: string;
    title_am: string | null;
    organization_id: string | null;
    organization_unit_id: string | null;
    organization?: { id: string; name_en: string } | null;
    grade_level: string | null;
    job_family: string | null;
    is_active: boolean;
    effective_from: string | null;
    effective_to: string | null;
    establishment: EstablishmentSummary;
    can: { view: boolean; update: boolean; move: boolean; archive: boolean; restore: boolean };
};

type UnitSummary = { id: string; name_en: string; name_am: string | null };

interface Props {
    organizationStructure: ScopedOrganization[];
    isOrganizationScoped: boolean;
    selectedOrganization: OrganizationSummary | null;
    selectedUnit: UnitSummary | null;
    positions: PositionRow[];
    filters: Record<string, string>;
    can: { create: boolean; approve_establishment: boolean };
}

export default function PositionsIndex({
    organizationStructure,
    isOrganizationScoped,
    selectedOrganization,
    selectedUnit,
    positions,
    filters,
    can,
}: Props) {
    const { t, locale } = useLocale();
    const { confirm } = useConfirm();

    /** Localized status label, falling back to StatusBadge's own default when a key is missing. */
    function statusLabel(status: string): string | undefined {
        const key = `common.${status}`;
        const translated = t(key);
        return translated === key ? undefined : translated;
    }

    const [localSelected, setLocalSelected] = useState<OrganizationSummary | null>(selectedOrganization ?? null);
    useEffect(() => { setLocalSelected(selectedOrganization ?? null); }, [selectedOrganization]);

    const form = useForm({
        search:      filters.search ?? '',
        job_family:  filters.job_family ?? '',
        grade_level: filters.grade_level ?? '',
        is_active:   filters.is_active ?? '',
    });

    const displayOrg = localSelected ?? selectedOrganization;
    const hasUnits = (displayOrg?.organization_units_count ?? 0) > 0;

    const createHref =
        route('positions.create') +
        '?organization_id=' + (displayOrg?.id ?? '') +
        (selectedUnit ? '&organization_unit_id=' + selectedUnit.id : '');

    function selectOrganizationById(organizationId: string) {
        router.get(route('positions.index'), { organization_id: organizationId }, { preserveState: false });
    }

    /** Clicking a unit narrows the positions list to that unit. */
    function selectUnitInStructure(organizationId: string, unitId: string) {
        router.get(
            route('positions.index'),
            { organization_id: organizationId, organization_unit_id: unitId },
            { preserveState: true },
        );
    }

    /** Clicking a position in the tree drills into its owning unit. */
    function selectPositionInStructure(organizationId: string, positionId: string) {
        const unitId = organizationStructure
            .find((organization) => organization.id === organizationId)
            ?.units.flatMap(function flatten(unit): typeof unit[] {
                return [unit, ...unit.children.flatMap(flatten)];
            })
            .find((unit) => unit.positions.some((position) => position.id === positionId))?.id;

        router.get(
            route('positions.index'),
            { organization_id: organizationId, ...(unitId ? { organization_unit_id: unitId } : {}) },
            { preserveState: true },
        );
    }
    function clearUnit() {
        router.get(route('positions.index'), { organization_id: displayOrg?.id ?? '' }, { preserveState: true });
    }
    function submit() {
        router.get(route('positions.index'), {
            ...form.data,
            organization_id: displayOrg?.id ?? '',
            ...(selectedUnit ? { organization_unit_id: selectedUnit.id } : {}),
        }, { preserveState: true });
    }

    async function handleApproveEstablishment(position: PositionRow) {
        const { confirmed } = await confirm({
            title: t('positionEstablishments.approve'),
            description: `${localizedName(position.title_en, position.title_am, locale)} ·  ${position.job_position_code}`,
            confirmLabel: t('positionEstablishments.approve'),
            cancelLabel: t('common.cancel'),
            variant: 'default',
        });
        if (confirmed) {
            router.post(route('positions.approve-establishment', position.id), {}, { preserveScroll: true });
        }
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={t('positions.title')} />}>
            <Head title={t('positions.title')} />

            <div className="flex flex-col gap-4 lg:flex-row lg:items-stretch">

                {/* Organization -> Units -> Positions structure */}
                <div className="w-full lg:w-[34%] lg:min-h-[600px]">
                    <ScopedOrganizationStructure
                        organizations={organizationStructure}
                        isScoped={isOrganizationScoped}
                        selectedOrganizationId={displayOrg?.id ?? null}
                        selectedPositionId={null}
                        selectedUnitId={selectedUnit?.id ?? null}
                        onSelectOrganization={selectOrganizationById}
                        onSelectPosition={selectPositionInStructure}
                        onSelectUnit={selectUnitInStructure}
                        onClearPosition={clearUnit}
                    />
                </div>

                {/* Positions panel — units now live in the structure tree above */}
                <div className="w-full lg:flex-1">
                    {displayOrg ? (
                        <div className="space-y-4">
                            {/* Contextual header: reflects the unit when one is selected, otherwise the organization */}
                            <div className="flex flex-wrap items-center justify-between gap-3 rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] px-4 py-3">
                                <div className="flex min-w-0 items-center gap-3">
                                    {displayOrg && (
                                        displayOrg.has_logo && displayOrg.logo_url ? (
                                            <img src={displayOrg.logo_url} alt="" className="h-9 w-9 flex-shrink-0 rounded-card object-cover" />
                                        ) : (
                                            <span className="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-card bg-blue-100 text-sm font-bold text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">
                                                {localizedName(displayOrg.name_en, displayOrg.name_am, locale).charAt(0).toUpperCase()}
                                            </span>
                                        )
                                    )}
                                    <div className="min-w-0">
                                        {displayOrg && (
                                            <p className="flex flex-wrap items-center gap-1.5 text-xs text-[color:var(--app-muted-foreground)]">
                                                <span className="truncate">{localizedName(displayOrg.name_en, displayOrg.name_am, locale)}</span>
                                                <span className="font-mono">{displayOrg.code}</span>
                                                <StatusBadge status={displayOrg.status} label={statusLabel(displayOrg.status)} />
                                            </p>
                                        )}
                                        <p className="text-xs text-[color:var(--app-muted-foreground)]">
                                            {selectedUnit ? t('positions.selectedOrganizationUnit') : t('positions.selectedOrganization')}
                                        </p>
                                        <p className="truncate text-sm font-semibold text-[color:var(--app-foreground)]">
                                            {selectedUnit
                                                ? localizedName(selectedUnit.name_en, selectedUnit.name_am, locale)
                                                : localizedName(displayOrg.name_en, displayOrg.name_am, locale)}
                                        </p>
                                        <p className="truncate text-xs text-[color:var(--app-muted-foreground)]">
                                            {selectedUnit ? t('positions.positionsInOrganizationUnit') : t('positions.positionsInOrganization')}
                                        </p>
                                    </div>
                                </div>
                                {can.create && (
                                    <Link href={createHref} className={buttonClassName({ variant: 'primary', size: 'sm' })}>
                                        <Plus className="h-3.5 w-3.5" />
                                        {t('positions.createPosition')}
                                    </Link>
                                )}
                            </div>

                            <FilterPanel
                                onSubmit={submit}
                                onReset={() => router.get(route('positions.index'), { organization_id: displayOrg?.id ?? '', ...(selectedUnit ? { organization_unit_id: selectedUnit.id } : {}) }, { preserveState: true })}
                                hasActiveFilters={Boolean(filters.search || filters.job_family || filters.grade_level || filters.is_active)}
                            >
                                <FilterField label={t('common.search')} wide>
                                    <Input type="search" value={form.data.search} placeholder={t('positions.searchPositions')} onChange={(e) => form.setData('search', e.target.value)} />
                                </FilterField>
                                <FilterField label={t('positions.jobFamily')}>
                                    <Input value={form.data.job_family} onChange={(e) => form.setData('job_family', e.target.value)} />
                                </FilterField>
                                <FilterField label={t('positions.gradeLevel')}>
                                    <Input value={form.data.grade_level} onChange={(e) => form.setData('grade_level', e.target.value)} />
                                </FilterField>
                                <FilterField label={t('common.status')}>
                                    <Select value={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.value)}>
                                        <option value="">{t('common.allStatuses')}</option>
                                        <option value="1">{t('common.active')}</option>
                                        <option value="0">{t('common.inactive')}</option>
                                    </Select>
                                </FilterField>
                            </FilterPanel>

                            {/* Table */}
                            <section className={tableContainerClassName}>
                                {positions.length === 0 ? (
                                    <EmptyState title={t('positions.noPositionsFound')} />
                                ) : (
                                    <div className="overflow-x-auto">
                                        <table className={cx(tableClassName, 'min-w-full text-left')}>
                                            <thead className={tableHeadClassName}>
                                                <tr>
                                                    {[
                                                        t('positions.jobPositionCode'),
                                                        t('positions.oldCode'),
                                                        t('positions.standardName'),
                                                        t('positions.bprName'),
                                                        t('positions.gradeLevel'),
                                                        t('common.status'),
                                                        t('positionEstablishments.establishment'),
                                                        '',
                                                    ].map((heading, i) => (
                                                        <th key={i} className={cx(tableHeaderCellClassName, 'text-left')}>
                                                            {heading}
                                                        </th>
                                                    ))}
                                                </tr>
                                            </thead>
                                            <tbody className={tableBodyClassName}>
                                                {positions.map((position) => (
                                                    <tr key={position.id} className={tableRowClassName}>
                                                        <td className={cx(tableCellClassName, 'font-mono text-xs')}>
                                                            <Link href={route('positions.show', position.id)} className="text-[color:var(--color-primary)] hover:text-[color:var(--color-primary-hover)] dark:text-[color:var(--color-primary)]">
                                                                {position.job_position_code}
                                                            </Link>
                                                        </td>
                                                        <td className={cx(tableCellClassName, 'font-mono text-xs')}>{position.old_code ?? '—'}</td>
                                                        <td className={tableCellClassName}>
                                                            {localizedName(position.title_en, position.title_am, locale)}
                                                        </td>
                                                        <td className={tableCellClassName}>{position.bpr_name ?? '—'}</td>
                                                        <td className={tableCellClassName}>{position.grade_level ?? '—'}</td>
                                                        <td className={tableCellClassName}>
                                                            <StatusBadge
                                                                status={position.is_active ? 'active' : 'inactive'}
                                                                label={statusLabel(position.is_active ? 'active' : 'inactive')}
                                                            />
                                                        </td>

                                                        {/* Establishment status + Approve button */}
                                                        <td className={tableCellClassName}>
                                                            {position.establishment?.status === 'approved' ? (
                                                                <span className={badgeClassName('success')}>
                                                                    <span className="h-1.5 w-1.5 rounded-full bg-green-500" />
                                                                    {t('positionEstablishments.statusApproved')}
                                                                </span>
                                                            ) : can.approve_establishment ? (
                                                                <button
                                                                    type="button"
                                                                    onClick={() => handleApproveEstablishment(position)}
                                                                    className={buttonClassName({ variant: 'outline', size: 'sm' })}
                                                                >
                                                                    {t('positionEstablishments.approve')}
                                                                </button>
                                                            ) : (
                                                                <span className="text-xs text-[color:var(--app-muted-foreground)]">—</span>
                                                            )}
                                                        </td>

                                                        {/* Row actions */}
                                                        <td className={tableCellClassName}>
                                                            <div className="flex justify-end">
                                                                <AppActionMenu
                                                                    items={[
                                                                        { label: t('positions.move'), href: route('positions.move', position.id), show: position.can.move },
                                                                        { label: t('common.edit'), href: route('positions.edit', position.id), show: position.can.update },
                                                                        { label: t('common.delete'), onClick: async () => {
                                                                            const { confirmed } = await confirm({
                                                                                title: t('confirmations.confirmDeleteTitle'),
                                                                                description: t('confirmations.thisRecordWillMoveToRecycleBin'),
                                                                                confirmLabel: t('confirmations.delete'),
                                                                                cancelLabel: t('confirmations.cancel'),
                                                                                variant: 'danger',
                                                                            });
                                                                            if (confirmed) router.delete(route('positions.archive', position.id));
                                                                        }, variant: 'danger', show: position.can.archive && position.is_active },
                                                                        { label: t('common.restore'), onClick: async () => {
                                                                            const { confirmed } = await confirm({
                                                                                title: t('confirmations.confirmRestoreTitle'),
                                                                                description: t('confirmations.thisActionCannotBeUndone'),
                                                                                confirmLabel: t('confirmations.restore'),
                                                                                cancelLabel: t('confirmations.cancel'),
                                                                                variant: 'default',
                                                                            });
                                                                            if (confirmed) router.post(route('positions.restore', position.id));
                                                                        }, show: position.can.restore && !position.is_active },
                                                                    ]}
                                                                />
                                                            </div>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </section>
                        </div>
                    ) : (
                        <div className="flex h-full min-h-[200px] flex-col items-center justify-center rounded-panel border border-dashed border-[color:var(--app-border)] bg-[color:var(--app-surface)] px-6 py-12 text-center">
                            <p className="text-sm text-[color:var(--app-muted-foreground)]">
                                {t('positions.selectOrganizationToViewPositions')}
                            </p>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
