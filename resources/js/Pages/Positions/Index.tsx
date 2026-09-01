import { useState, useEffect, FormEvent } from 'react';
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

    const inputCls = 'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';
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
    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        router.get(route('positions.index'), {
            ...form.data,
            organization_id: displayOrg?.id ?? '',
            ...(selectedUnit ? { organization_unit_id: selectedUnit.id } : {}),
        }, { preserveState: true });
    }

    async function handleApproveEstablishment(position: PositionRow) {
        const { confirmed } = await confirm({
            title: t('positionEstablishments.approve'),
            description: `${localizedName(position.title_en, position.title_am, locale)}  ·  ${position.job_position_code}`,
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
                            <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-gray-200 bg-white px-4 py-3 dark:border-slate-800 dark:bg-slate-900">
                                <div className="flex min-w-0 items-center gap-3">
                                    {displayOrg && (
                                        displayOrg.has_logo && displayOrg.logo_url ? (
                                            <img src={displayOrg.logo_url} alt="" className="h-9 w-9 flex-shrink-0 rounded-xl object-cover" />
                                        ) : (
                                            <span className="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl bg-blue-100 text-sm font-bold text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">
                                                {localizedName(displayOrg.name_en, displayOrg.name_am, locale).charAt(0).toUpperCase()}
                                            </span>
                                        )
                                    )}
                                    <div className="min-w-0">
                                        {displayOrg && (
                                            <p className="flex flex-wrap items-center gap-1.5 text-xs text-gray-500 dark:text-slate-400">
                                                <span className="truncate">{localizedName(displayOrg.name_en, displayOrg.name_am, locale)}</span>
                                                <span className="font-mono">{displayOrg.code}</span>
                                                <StatusBadge status={displayOrg.status} label={statusLabel(displayOrg.status)} />
                                            </p>
                                        )}
                                        <p className="text-xs text-gray-500 dark:text-slate-400">
                                            {selectedUnit ? t('positions.selectedOrganizationUnit') : t('positions.selectedOrganization')}
                                        </p>
                                        <p className="truncate text-sm font-semibold text-gray-900 dark:text-slate-100">
                                            {selectedUnit
                                                ? localizedName(selectedUnit.name_en, selectedUnit.name_am, locale)
                                                : localizedName(displayOrg.name_en, displayOrg.name_am, locale)}
                                        </p>
                                        <p className="truncate text-xs text-gray-400 dark:text-slate-500">
                                            {selectedUnit ? t('positions.positionsInOrganizationUnit') : t('positions.positionsInOrganization')}
                                        </p>
                                    </div>
                                </div>
                                {can.create && (
                                    <Link href={createHref} className="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700">
                                        <Plus className="h-3.5 w-3.5" />
                                        {t('positions.createPosition')}
                                    </Link>
                                )}
                            </div>

                            {/* Filters */}
                            <section className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                                <form className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" onSubmit={submit}>
                                    <input className={inputCls} value={form.data.search} placeholder={t('positions.searchPositions')} onChange={(e) => form.setData('search', e.target.value)} />
                                    <input className={inputCls} value={form.data.job_family} placeholder={t('positions.jobFamily')} onChange={(e) => form.setData('job_family', e.target.value)} />
                                    <input className={inputCls} value={form.data.grade_level} placeholder={t('positions.gradeLevel')} onChange={(e) => form.setData('grade_level', e.target.value)} />
                                    <div className="flex gap-2">
                                        <select className={`${inputCls} flex-1`} value={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.value)}>
                                            <option value="">{t('common.status')}</option>
                                            <option value="1">{t('common.active')}</option>
                                            <option value="0">{t('common.inactive')}</option>
                                        </select>
                                        <button className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700" type="submit">
                                            {t('common.filter')}
                                        </button>
                                    </div>
                                </form>
                            </section>

                            {/* Table */}
                            <section className="rounded-2xl border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                                {positions.length === 0 ? (
                                    <div className="p-6"><EmptyState title={t('positions.noPositionsFound')} /></div>
                                ) : (
                                    <div className="overflow-x-auto">
                                        <table className="min-w-full text-left text-sm">
                                            <thead className="bg-gray-50 dark:bg-slate-950">
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
                                                        <th key={i} className="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                                                            {heading}
                                                        </th>
                                                    ))}
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {positions.map((position) => (
                                                    <tr key={position.id} className="border-t border-gray-100 text-gray-700 dark:border-slate-800 dark:text-slate-200">
                                                        <td className="px-4 py-3 font-mono text-xs">
                                                            <Link href={route('positions.show', position.id)} className="text-blue-600 hover:text-blue-800 dark:text-blue-400">
                                                                {position.job_position_code}
                                                            </Link>
                                                        </td>
                                                        <td className="px-4 py-3 font-mono text-xs">{position.old_code ?? '—'}</td>
                                                        <td className="px-4 py-3">
                                                            {localizedName(position.title_en, position.title_am, locale)}
                                                        </td>
                                                        <td className="px-4 py-3">{position.bpr_name ?? '—'}</td>
                                                        <td className="px-4 py-3">{position.grade_level ?? '—'}</td>
                                                        <td className="px-4 py-3">
                                                            <StatusBadge
                                                                status={position.is_active ? 'active' : 'inactive'}
                                                                label={statusLabel(position.is_active ? 'active' : 'inactive')}
                                                            />
                                                        </td>

                                                        {/* Establishment status + Approve button */}
                                                        <td className="px-4 py-3">
                                                            {position.establishment?.status === 'approved' ? (
                                                                <span className="inline-flex items-center gap-1 rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-700 dark:bg-green-900/30 dark:text-green-400">
                                                                    <span className="h-1.5 w-1.5 rounded-full bg-green-500" />
                                                                    {t('positionEstablishments.statusApproved')}
                                                                </span>
                                                            ) : can.approve_establishment ? (
                                                                <button
                                                                    type="button"
                                                                    onClick={() => handleApproveEstablishment(position)}
                                                                    className="inline-flex items-center gap-1 rounded-lg border border-amber-300 px-2.5 py-1 text-xs font-medium text-amber-700 hover:bg-amber-50 dark:border-amber-700 dark:text-amber-400 dark:hover:bg-amber-950/30"
                                                                >
                                                                    {t('positionEstablishments.approve')}
                                                                </button>
                                                            ) : (
                                                                <span className="text-xs text-gray-400 dark:text-slate-500">—</span>
                                                            )}
                                                        </td>

                                                        {/* Row actions */}
                                                        <td className="px-4 py-3">
                                                            <div className="flex justify-end gap-3">
                                                                {position.can.move && (
                                                                    <Link href={route('positions.move', position.id)} className="text-xs font-medium text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300">
                                                                        {t('positions.move')}
                                                                    </Link>
                                                                )}
                                                                {position.can.update && (
                                                                    <Link href={route('positions.edit', position.id)} className="text-xs font-medium text-blue-600 hover:text-blue-800">
                                                                        {t('common.edit')}
                                                                    </Link>
                                                                )}
                                                                {position.can.archive && position.is_active && (
                                                                    <button
                                                                        type="button"
                                                                        onClick={async () => {
                                                                            const { confirmed } = await confirm({
                                                                                title: t('confirmations.confirmDeleteTitle'),
                                                                                description: t('confirmations.thisRecordWillMoveToRecycleBin'),
                                                                                confirmLabel: t('confirmations.delete'),
                                                                                cancelLabel: t('confirmations.cancel'),
                                                                                variant: 'danger',
                                                                            });
                                                                            if (confirmed) router.delete(route('positions.archive', position.id));
                                                                        }}
                                                                        className="text-xs font-medium text-red-600 hover:text-red-800"
                                                                    >
                                                                        {t('positions.archivePosition')}
                                                                    </button>
                                                                )}
                                                                {position.can.restore && !position.is_active && (
                                                                    <button
                                                                        type="button"
                                                                        onClick={async () => {
                                                                            const { confirmed } = await confirm({
                                                                                title: t('confirmations.confirmRestoreTitle'),
                                                                                description: t('confirmations.thisActionCannotBeUndone'),
                                                                                confirmLabel: t('confirmations.restore'),
                                                                                cancelLabel: t('confirmations.cancel'),
                                                                                variant: 'default',
                                                                            });
                                                                            if (confirmed) router.post(route('positions.restore', position.id));
                                                                        }}
                                                                        className="text-xs font-medium text-green-600 hover:text-green-800"
                                                                    >
                                                                        {t('positions.restorePosition')}
                                                                    </button>
                                                                )}
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
                        <div className="flex h-full min-h-[200px] flex-col items-center justify-center rounded-2xl border border-dashed border-gray-200 bg-white px-6 py-12 text-center dark:border-slate-700 dark:bg-slate-900">
                            <p className="text-sm text-gray-500 dark:text-slate-400">
                                {t('positions.selectOrganizationToViewPositions')}
                            </p>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
