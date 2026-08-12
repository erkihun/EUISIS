import { Link, router } from '@inertiajs/react';
import { ChevronRight, ChevronDown, Plus } from '@/Components/Icons';
import OrganizationUnitStatusBadge from '@/Components/organization-units/OrganizationUnitStatusBadge';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';
import { localizedName } from '@/utils/localizedName';
import type { OrganizationUnitTreeNode } from '@/types/organizationUnit';

interface Props {
    node: OrganizationUnitTreeNode;
    hierarchyNumber: string;
    expanded: boolean;
    onToggle: (id: string) => void;
    canCreate: boolean;
    canUpdate: boolean;
    canDelete: boolean;
    canRestore: boolean;
    selectedOrgId: string;
}

export default function OrganizationUnitTreeRow({
    node,
    hierarchyNumber,
    expanded,
    onToggle,
    canCreate,
    canUpdate,
    canDelete,
    canRestore,
    selectedOrgId,
}: Props) {
    const { t, locale } = useLocale();
    const { confirm } = useConfirm();

    const indent = node.depth * 24;
    const levelColor = [
        'bg-blue-100 text-blue-700 ring-blue-200 dark:bg-blue-900/40 dark:text-blue-300 dark:ring-blue-800',
        'bg-violet-100 text-violet-700 ring-violet-200 dark:bg-violet-900/40 dark:text-violet-300 dark:ring-violet-800',
        'bg-emerald-100 text-emerald-700 ring-emerald-200 dark:bg-emerald-900/40 dark:text-emerald-300 dark:ring-emerald-800',
        'bg-amber-100 text-amber-700 ring-amber-200 dark:bg-amber-900/40 dark:text-amber-300 dark:ring-amber-800',
        'bg-rose-100 text-rose-700 ring-rose-200 dark:bg-rose-900/40 dark:text-rose-300 dark:ring-rose-800',
    ][node.depth % 5];

    async function handleDelete() {
        const { confirmed } = await confirm({
            title: t('confirmations.confirmDeleteTitle'),
            description: t('confirmations.thisRecordWillMoveToRecycleBin'),
            confirmLabel: t('confirmations.delete'),
            cancelLabel: t('confirmations.cancel'),
            variant: 'danger',
        });
        if (!confirmed) return;
        router.post(route('organization-units.archive', node.id));
    }

    function handleRestore() {
        router.post(route('organization-units.restore', node.id));
    }

    const rowCls = node.is_deleted
        ? 'text-gray-400 bg-gray-50 dark:text-slate-500 dark:bg-slate-800/30'
        : 'text-gray-700 hover:bg-gray-50 dark:text-slate-200 dark:hover:bg-slate-800/40';

    return (
        <tr className={`border-b border-gray-100 dark:border-slate-800 ${rowCls}`}>
            <td className="px-4 py-2.5" style={{ paddingLeft: `${indent + 16}px` }}>
                <div className="flex items-center gap-1.5">
                    {node.has_children || (node.children && node.children.length > 0) ? (
                        <button
                            type="button"
                            onClick={() => onToggle(node.id)}
                            className="flex h-5 w-5 flex-shrink-0 items-center justify-center rounded text-gray-400 hover:text-gray-600 dark:text-slate-500 dark:hover:text-slate-300"
                        >
                            {expanded ? (
                                <ChevronDown className="h-3.5 w-3.5" />
                            ) : (
                                <ChevronRight className="h-3.5 w-3.5" />
                            )}
                        </button>
                    ) : (
                        <span className="h-5 w-5 flex-shrink-0" />
                    )}
                    <span
                        className={`inline-flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full text-xs font-bold tabular-nums ring-1 ${levelColor}`}
                        title={`${t('hierarchyVersions.hierarchyLevel')} ${hierarchyNumber}`}
                        aria-label={`${t('hierarchyVersions.hierarchyLevel')} ${hierarchyNumber}`}
                    >
                        {hierarchyNumber}
                    </span>
                    <Link
                        href={route('organization-units.show', node.id)}
                        className={`font-medium hover:underline ${node.is_deleted ? 'line-through text-gray-400 dark:text-slate-500' : 'text-blue-600 dark:text-blue-400'}`}
                    >
                        {localizedName(node.name_en, node.name_am, locale)}
                    </Link>
                </div>
            </td>
            <td className="px-4 py-2.5">
                {node.unit_type_label && (
                    <span className="inline-flex items-center rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300">
                        {localizedName(node.unit_type_label, node.unit_type_name_am, locale)}
                    </span>
                )}
            </td>
            <td className="px-4 py-2.5">
                <OrganizationUnitStatusBadge status={node.status} />
            </td>
            <td className="px-4 py-2.5 text-center text-sm text-gray-500 dark:text-slate-400">
                {node.children_count ?? node.children?.length ?? 0}
            </td>
            <td className="py-2.5 pl-2 pr-4">
                <div className="flex items-center justify-end gap-2">
                    {canCreate && !node.is_deleted && (
                        <Link
                            href={route('organization-units.create', {
                                organization_id: selectedOrgId,
                                parent_unit_id: node.id,
                            })}
                            className="flex items-center gap-0.5 text-xs font-medium text-emerald-600 hover:text-emerald-800 dark:text-emerald-400 dark:hover:text-emerald-300"
                            title={t('organizationUnits.addOrganizationUnit')}
                        >
                            <Plus className="h-3 w-3" />
                        </Link>
                    )}
                    <Link
                        href={route('organization-units.show', node.id)}
                        className="text-xs font-medium text-gray-500 hover:text-gray-800 dark:text-slate-400 dark:hover:text-slate-200"
                    >
                        {t('common.view')}
                    </Link>
                    {(canUpdate || node.can?.update) && !node.is_deleted && (
                        <Link
                            href={route('organization-units.edit', node.id)}
                            className="text-xs font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                        >
                            {t('common.edit')}
                        </Link>
                    )}
                    {(canDelete || node.can?.archive) && !node.is_deleted && (
                        <button
                            type="button"
                            onClick={handleDelete}
                            className="text-xs font-medium text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300"
                        >
                            {t('common.delete')}
                        </button>
                    )}
                    {(canRestore || node.can?.restore) && node.is_deleted && (
                        <button
                            type="button"
                            onClick={handleRestore}
                            className="text-xs font-medium text-emerald-600 hover:text-emerald-800 dark:text-emerald-400 dark:hover:text-emerald-300"
                        >
                            {t('common.restore')}
                        </button>
                    )}
                </div>
            </td>
        </tr>
    );
}
