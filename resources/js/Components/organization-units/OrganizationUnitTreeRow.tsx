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
    canRequestCreate?: boolean;
    /** The requester's "add unit" link (a prefilled change request). */
    addHref?: string;
    canUpdate: boolean;
    canDelete: boolean;
    canRestore: boolean;
    selectedOrgId: string;
}

/** One unit of the tree as a table row: indented by depth, with the same columns and row menu as the other registers. */
export default function OrganizationUnitTreeRow({ node, expanded, onToggle, canCreate, canRequestCreate = false, addHref, canUpdate, canDelete, canRestore, selectedOrgId }: Props) {
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
    // The other language's name under the main one, as the unit types register shows it.
    const primaryName = localizedName(node.name_en, node.name_am, locale);
    const otherName = locale === 'am' ? node.name_en : node.name_am;
    const secondaryName = otherName && otherName !== primaryName ? otherName : null;
    const statusKey = `common.${status}`;
    const statusLabel = t(statusKey) === statusKey ? undefined : t(statusKey);

    return (
        <tr className={cx(tableRowClassName, deleted && 'text-(--app-muted-foreground)')}>
            <td className={cx(tableCellClassName, 'font-mono text-xs font-medium text-(--app-muted-foreground)', deleted && 'line-through')}>
                {node.code}
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
                    <div className="min-w-0">
                        <Link href={route('organization-units.show', node.id)} className={cx('font-medium hover:underline', deleted && 'line-through')}>
                            {localizedName(node.name_en, node.name_am, locale)}
                        </Link>
                        {secondaryName && <p className="text-xs text-(--app-muted-foreground)">{secondaryName}</p>}
                    </div>
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
                                href: canCreate || !addHref
                                    ? route('organization-units.create', { organization_id: selectedOrgId, parent_unit_id: node.id })
                                    : addHref,
                                show: (canCreate || canRequestCreate) && !deleted,
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
