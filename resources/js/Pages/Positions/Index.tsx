import { useState, useEffect, FormEvent } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import OrganizationTreePreview from '@/Components/organization-units/OrganizationTreePreview';
import { Building2, ChevronDown, ChevronRight, Plus } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';
import { localizedName } from '@/utils/localizedName';
import type { OrganizationTreeNode, OrganizationSummary } from '@/types/organizationUnit';

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
    can: { view: boolean; update: boolean; archive: boolean; restore: boolean };
};

type UnitTreeNode = {
    id: string;
    code: string;
    name_en: string;
    name_am: string | null;
    depth: number;
    children: UnitTreeNode[];
};

type UnitSummary = { id: string; name_en: string; name_am: string | null };

interface Props {
    organizationTree: OrganizationTreeNode[];
    hasPublishedHierarchy: boolean;
    selectedOrganization: OrganizationSummary | null;
    organizationUnits: UnitTreeNode[];
    selectedUnit: UnitSummary | null;
    positions: PositionRow[];
    filters: Record<string, string>;
    can: { create: boolean; approve_establishment: boolean };
}

function UnitNodeItem({
    unit, hierarchyNumber, selectedId, onSelect,
}: {
    unit: UnitTreeNode;
    hierarchyNumber: string;
    selectedId: string | null;
    onSelect: (id: string) => void;
}) {
    const { locale, t } = useLocale();
    const [expanded, setExpanded] = useState(true);
    const hasChildren = unit.children.length > 0;
    const isSelected = selectedId === unit.id;
    const levelColor = [
        'bg-blue-100 text-blue-700 ring-blue-200 dark:bg-blue-900/40 dark:text-blue-300 dark:ring-blue-800',
        'bg-violet-100 text-violet-700 ring-violet-200 dark:bg-violet-900/40 dark:text-violet-300 dark:ring-violet-800',
        'bg-emerald-100 text-emerald-700 ring-emerald-200 dark:bg-emerald-900/40 dark:text-emerald-300 dark:ring-emerald-800',
        'bg-amber-100 text-amber-700 ring-amber-200 dark:bg-amber-900/40 dark:text-amber-300 dark:ring-amber-800',
        'bg-rose-100 text-rose-700 ring-rose-200 dark:bg-rose-900/40 dark:text-rose-300 dark:ring-rose-800',
    ][unit.depth % 5];

    return (
        <div>
            <div
                className={`flex cursor-pointer items-center gap-1.5 rounded-lg px-2 py-1.5 transition-colors ${
                    isSelected
                        ? 'bg-violet-50 ring-1 ring-violet-300 dark:bg-violet-900/20 dark:ring-violet-600'
                        : 'hover:bg-gray-50 dark:hover:bg-slate-800/40'
                }`}
                style={{ paddingLeft: `${unit.depth * 14 + 8}px` }}
                onClick={() => onSelect(unit.id)}
            >
                <button
                    type="button"
                    className="flex h-4 w-4 flex-shrink-0 items-center justify-center text-gray-400 dark:text-slate-500"
                    onClick={(e) => { e.stopPropagation(); if (hasChildren) setExpanded((v) => !v); }}
                    tabIndex={-1}
                >
                    {hasChildren
                        ? (expanded ? <ChevronDown className="h-3 w-3" /> : <ChevronRight className="h-3 w-3" />)
                        : <span className="h-3 w-3" />}
                </button>
                <span
                    className={`inline-flex h-6 min-w-6 flex-shrink-0 items-center justify-center rounded-full px-1.5 text-[10px] font-bold tabular-nums ring-1 ${levelColor}`}
                    aria-label={`${t('hierarchyVersions.hierarchyLevel')} ${hierarchyNumber}`}
                    title={`${t('hierarchyVersions.hierarchyLevel')} ${hierarchyNumber}`}
                >
                    {hierarchyNumber}
                </span>
                <span className={`truncate text-xs ${isSelected ? 'font-semibold text-violet-700 dark:text-violet-300' : 'text-gray-800 dark:text-slate-200'}`}>
                    {localizedName(unit.name_en, unit.name_am, locale)}
                </span>
            </div>
            {hasChildren && expanded && unit.children.map((child, index) => (
                <UnitNodeItem
                    key={child.id}
                    unit={child}
                    hierarchyNumber={`${hierarchyNumber}.${index + 1}`}
                    selectedId={selectedId}
                    onSelect={onSelect}
                />
            ))}
        </div>
    );
}

export default function PositionsIndex({
    organizationTree,
    hasPublishedHierarchy,
    selectedOrganization,
    organizationUnits,
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

    function selectOrganization(node: OrganizationTreeNode) {
        router.get(route('positions.index'), { organization_id: node.id }, { preserveState: false });
    }
    function selectUnit(unitId: string) {
        router.get(route('positions.index'), { organization_id: displayOrg?.id ?? '', organization_unit_id: unitId }, { preserveState: true });
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

                {/* Column 1: Organization Tree */}
                <div className="w-full lg:w-[26%] lg:min-h-[600px]">
                    <OrganizationTreePreview
                        tree={organizationTree}
                        selectedId={displayOrg?.id ?? null}
                        hasPublishedHierarchy={hasPublishedHierarchy}
                        onSelect={selectOrganization}
                    />
                </div>

                {/* Column 2: Org Unit Tree */}
                <div className="w-full lg:w-[26%]">
                    {displayOrg ? (
                        <div className="flex h-full flex-col gap-3">
                            <div className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                                <div className="flex items-center gap-3">
                                    {displayOrg.has_logo && displayOrg.logo_url ? (
                                        <img src={displayOrg.logo_url} alt="" className="h-10 w-10 flex-shrink-0 rounded-xl object-cover" />
                                    ) : (
                                        <span className="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl bg-blue-100 text-sm font-bold text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">
                                            {localizedName(displayOrg.name_en, displayOrg.name_am, locale).charAt(0).toUpperCase()}
                                        </span>
                                    )}
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-1.5">
                                            <h2 className="truncate text-sm font-semibold text-gray-900 dark:text-slate-100">{localizedName(displayOrg.name_en, displayOrg.name_am, locale)}</h2>
                                            <StatusBadge status={displayOrg.status} label={statusLabel(displayOrg.status)} />
                                        </div>
                                        <div className="flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-slate-400">
                                            <span className="font-mono">{displayOrg.code}</span>
                                            {displayOrg.type && <span>{localizedName(displayOrg.type.name_en, displayOrg.type.name_am, locale)}</span>}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {!hasUnits ? (
                                <div className="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-center dark:border-amber-800 dark:bg-amber-950/30">
                                    <p className="text-sm font-semibold text-amber-800 dark:text-amber-300">{t('positions.noOrganizationUnits')}</p>
                                    <p className="mt-1 text-xs text-amber-700 dark:text-amber-400">{t('positions.noOrganizationUnitsHint')}</p>
                                </div>
                            ) : (
                                <div className="flex flex-1 flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                                    <div className="flex items-center justify-between border-b border-gray-100 px-4 py-3 dark:border-slate-800">
                                        <h3 className="text-sm font-semibold text-gray-900 dark:text-slate-100">{t('positions.organizationUnit')}</h3>
                                        {selectedUnit && (
                                            <button type="button" onClick={clearUnit} className="text-xs text-blue-600 hover:text-blue-800 dark:text-blue-400">
                                                {t('common.clear')}
                                            </button>
                                        )}
                                    </div>
                                    <div className="flex-1 overflow-y-auto p-2">
                                        {organizationUnits.map((unit, index) => (
                                            <UnitNodeItem
                                                key={unit.id}
                                                unit={unit}
                                                hierarchyNumber={`${index + 1}`}
                                                selectedId={selectedUnit?.id ?? null}
                                                onSelect={selectUnit}
                                            />
                                        ))}
                                    </div>
                                </div>
                            )}
                        </div>
                    ) : (
                        <div className="flex h-full min-h-[300px] flex-col items-center justify-center rounded-2xl border border-dashed border-gray-200 bg-white px-6 py-16 text-center dark:border-slate-700 dark:bg-slate-900">
                            <Building2 className="h-8 w-8 text-gray-300 dark:text-slate-600" />
                            <p className="mt-2 text-sm text-gray-500 dark:text-slate-400">{t('positions.selectOrganizationToViewPositions')}</p>
                        </div>
                    )}
                </div>

                {/* Column 3: Positions Table */}
                <div className="w-full lg:flex-1">
                    {selectedUnit ? (
                        <div className="space-y-4">
                            {/* Unit header */}
                            <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-gray-200 bg-white px-4 py-3 dark:border-slate-800 dark:bg-slate-900">
                                <div>
                                    <p className="text-xs text-gray-500 dark:text-slate-400">{t('positions.organizationUnit')}</p>
                                    <p className="text-sm font-semibold text-gray-900 dark:text-slate-100">
                                        {localizedName(selectedUnit.name_en, selectedUnit.name_am, locale)}
                                    </p>
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
                                {displayOrg && hasUnits ? t('positions.selectUnitToViewPositions') : t('positions.selectOrganizationToViewPositions')}
                            </p>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
