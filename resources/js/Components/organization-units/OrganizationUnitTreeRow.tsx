import { Link, router } from '@inertiajs/react';
import { ChevronRight, ChevronDown, Plus } from '@/Components/Icons';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import StatusBadge from '@/Components/StatusBadge';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';
import { localizedName } from '@/utils/localizedName';
import type { OrganizationUnitTreeNode } from '@/types/organizationUnit';

import { badgeClassName, cx, tableCellClassName, tableRowClassName } from '@/Components/ui';
import AppActionMenu from '@/Components/ui/AppActionMenu';
interface Props {
    node: OrganizationUnitTreeNode;
    expanded: boolean;
    onToggle: (id: string) => void;
    canCreate: boolean;
    canUpdate: boolean;
    canDelete: boolean;
    canRestore: boolean;
    selectedOrgId: string;
}

/** One unit of the tree as a table row: indented by depth, with the same columns and row menu as the other registers. */
export default function OrganizationUnitTreeRow({ node, expanded, onToggle, canCreate, canUpdate, canDelete, canRestore, selectedOrgId }: Props) {
    const { t, locale } = useLocale();
    const { confirm } = useConfirm();
    const deleted = Boolean(node.is_deleted);
    const hasChildren = node.has_children || (node.children?.length ?? 0) > 0;

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

    async function handleRestore() {
        const { confirmed } = await confirm({
            title: t('confirmations.confirmRestoreTitle'),
            description: t('confirmations.thisActionCannotBeUndone'),
            confirmLabel: t('confirmations.restore'),
            cancelLabel: t('confirmations.cancel'),
            variant: 'default',
        });
        if (confirmed) router.post(route('organization-units.restore', node.id));
    }

    const status = deleted ? 'archived' : node.status;
    const statusKey = `common.${status}`;
    const statusLabel = t(statusKey) === statusKey ? undefined : t(statusKey);

    return (
        <tr className={cx(tableRowClassName, deleted && 'text-(--app-muted-foreground)')}>
            <td className={cx(tableCellClassName, 'font-mono text-xs')}>
                <Link href={route('organization-units.show', node.id)} className={cx('hover:underline', deleted ? 'line-through' : 'text-primary dark:text-primary')}>
                    {node.code}
                </Link>
            </td>
            <td className={tableCellClassName}>
                <div className="flex items-center gap-1.5" style={{ paddingLeft: `${node.depth * 20}px` }}>
                    {hasChildren ? (
                        <button
                            type="button"
                            onClick={() => onToggle(node.id)}
                            className="flex h-5 w-5 shrink-0 items-center justify-center text-(--app-muted-foreground)"
                            aria-label={expanded ? t('organizationUnits.collapseAll') : t('organizationUnits.expandAll')}
                            aria-expanded={expanded}
                        >
                            {expanded ? <ChevronDown className="h-3.5 w-3.5" /> : <ChevronRight className="h-3.5 w-3.5" />}
                        </button>
                    ) : (
                        <span className="h-5 w-5 shrink-0" aria-hidden="true" />
                    )}
                    <Link href={route('organization-units.show', node.id)} className={cx('font-medium hover:underline', deleted && 'line-through')}>
                        {localizedName(node.name_en, node.name_am, locale)}
                    </Link>
                </div>
            </td>
            <td className={tableCellClassName}>
                {node.unit_type_label ? <span className={badgeClassName('neutral')}>{localizedName(node.unit_type_label, node.unit_type_name_am, locale)}</span> : '—'}
            </td>
            <td className={tableCellClassName}>
                <StatusBadge status={status} label={statusLabel} />
            </td>
            <td className={cx(tableCellClassName, 'text-right tabular-nums text-(--app-muted-foreground)')}>
                {node.children_count ?? node.children?.length ?? 0}
            </td>
            <td className={cx(tableCellClassName, 'whitespace-nowrap text-(--app-muted-foreground)')}>
                {node.effective_from ? <LocalizedDateDisplay value={node.effective_from} /> : '—'}
            </td>
            <td className={tableCellClassName}>
                <div className="flex justify-end">
                    <AppActionMenu
                        items={[
                            {
                                label: t('organizations.addChild'),
                                icon: <Plus className="h-4 w-4" />,
                                href: route('organization-units.create', { organization_id: selectedOrgId, parent_unit_id: node.id }),
                                show: canCreate && !deleted,
                            },
                            { label: t('common.view'), href: route('organization-units.show', node.id) },
                            { label: t('common.edit'), href: route('organization-units.edit', node.id), show: (canUpdate || node.can?.update) && !deleted },
                            { label: t('common.delete'), onClick: handleDelete, variant: 'danger', show: (canDelete || node.can?.archive) && !deleted },
                            { label: t('common.restore'), onClick: handleRestore, show: (canRestore || node.can?.restore) && deleted },
                        ]}
                    />
                </div>
            </td>
        </tr>
    );
}
