import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import EmptyState from '@/Components/EmptyState';
import { Plus } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';

import { badgeClassName, buttonClassName, cx, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
type OrgType = {
    id: string;
    code: string;
    prefix: string | null;
    name_en: string;
    name_am: string | null;
    description_en: string | null;
    is_active: boolean;
    sort_order: number;
    level_order: number;
    category: string | null;
    parent_allowed_types: string[];
    organizations_count: number;
    can: { update: boolean; delete: boolean };
};

export default function OrganizationTypesIndex({
    types,
    can,
}: {
    types: OrgType[];
    can: { create: boolean };
}) {
    const { t: tr } = useLocale();
    const { confirm } = useConfirm();

    async function destroy(id: string) {
        const { confirmed } = await confirm({
            title: tr('confirmations.confirmDeleteTitle'),
            description: tr('confirmations.thisRecordWillMoveToRecycleBin'),
            confirmLabel: tr('confirmations.delete'),
            cancelLabel: tr('confirmations.cancel'),
            variant: 'danger',
        });
        if (!confirmed) return;
        router.delete(route('organization-types.destroy', id), { preserveScroll: true });
    }

    function categoryLabel(cat: string | null): string {
        if (!cat) return tr('organizationTypes.noCategory');
        const map: Record<string, string> = {
            root: tr('organizationTypes.categoryRoot'),
            functional: tr('organizationTypes.categoryFunctional'),
            geographic: tr('organizationTypes.categoryGeographic'),
            service_provider: tr('organizationTypes.categoryServiceProvider'),
            independent: tr('organizationTypes.categoryIndependent'),
            other: tr('organizationTypes.categoryOther'),
        };
        return map[cat] ?? cat;
    }

    function parentTypesLabel(codes: string[]): string {
        if (codes.length === 0) return tr('organizationTypes.rootOnly');
        return codes.join(', ');
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={tr('organizationTypes.title')}
                    description=""
                    actions={
                        can.create ? (
                            <Link
                                href={route('organization-types.create')}
                                className={buttonClassName({ variant: 'primary', size: 'sm' })}
                            >
                                <Plus className="h-3.5 w-3.5" aria-hidden="true" />
                                {tr('organizationTypes.createTitle')}
                            </Link>
                        ) : undefined
                    }
                />
            }
        >
            <Head title={tr('organizationTypes.title')} />

            <div className="rounded-card border border-[color:var(--app-border)] bg-[color:var(--app-surface)]">
                {types.length === 0 ? (
                    <div className="p-6">
                        <EmptyState
                            title={tr('organizationTypes.noTypes')}
                            description=""
                        />
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className={cx(tableClassName, 'min-w-full text-left')}>
                            <thead className={tableHeadClassName}>
                                <tr>
                                    {[
                                        tr('common.code'),
                                        tr('organizationTypes.prefix'),
                                        tr('common.name'),
                                        tr('organizationTypes.category'),
                                        tr('organizationTypes.levelOrder'),
                                        tr('organizationTypes.parentAllowedTypes'),
                                        tr('organizationTypes.organizationsCount'),
                                        tr('common.status'),
                                        '',
                                    ].map((h) => (
                                        <th
                                            key={h}
                                            className={cx(tableHeaderCellClassName, 'text-left')}
                                        >
                                            {h}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className={tableBodyClassName}>
                                {types.map((type) => (
                                    <tr
                                        key={type.id}
                                        className={tableRowClassName}
                                    >
                                        <td className={cx(tableCellClassName, 'font-mono text-xs font-medium text-[color:var(--app-muted-foreground)]')}>
                                            {type.code}
                                        </td>
                                        <td className={tableCellClassName}>
                                            {type.prefix ? (
                                                <span className={badgeClassName('warning', 'font-mono')}>
                                                    {type.prefix}
                                                </span>
                                            ) : (
                                                <span className="text-xs text-[color:var(--app-muted-foreground)]">
                                                    {tr('organizationTypes.noPrefix')}
                                                </span>
                                            )}
                                        </td>
                                        <td className={tableCellClassName}>
                                            <p className="font-medium">{type.name_en}</p>
                                            {type.name_am && (
                                                <p className="text-xs text-[color:var(--app-muted-foreground)]">
                                                    {type.name_am}
                                                </p>
                                            )}
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-xs text-[color:var(--app-muted-foreground)]')}>
                                            {categoryLabel(type.category)}
                                        </td>
                                        <td className={cx(tableCellClassName, 'tabular-nums text-[color:var(--app-muted-foreground)]')}>
                                            {type.level_order}
                                        </td>
                                        <td className={cx(tableCellClassName, 'max-w-[180px]')}>
                                            {type.parent_allowed_types.length === 0 ? (
                                                <span className="text-xs text-amber-600 dark:text-amber-400">
                                                    {tr('organizationTypes.rootOnly')}
                                                </span>
                                            ) : (
                                                <span className="font-mono text-xs text-[color:var(--app-muted-foreground)]">
                                                    {parentTypesLabel(type.parent_allowed_types)}
                                                </span>
                                            )}
                                        </td>
                                        <td className={cx(tableCellClassName, 'tabular-nums text-[color:var(--app-muted-foreground)]')}>
                                            {type.organizations_count}
                                        </td>
                                        <td className={tableCellClassName}>
                                            <StatusBadge status={type.is_active ? 'active' : 'inactive'} />
                                        </td>
                                        <td className={tableCellClassName}>
                                            <div className="flex items-center justify-end gap-3">
                                                <Link
                                                    href={route('organization-types.show', type.id)}
                                                    className="text-xs font-medium text-[color:var(--app-muted-foreground)] hover:text-[color:var(--app-foreground)]"
                                                >
                                                    {tr('common.view')}
                                                </Link>
                                                {type.can.update && (
                                                    <Link
                                                        href={route('organization-types.edit', type.id)}
                                                        className="text-xs font-medium text-[color:var(--color-primary)] hover:text-[color:var(--color-primary-hover)] dark:text-[color:var(--color-primary)] dark:hover:text-[color:var(--color-primary-hover)]"
                                                    >
                                                        {tr('common.edit')}
                                                    </Link>
                                                )}
                                                {type.can.delete && (
                                                    <button
                                                        type="button"
                                                        onClick={() => destroy(type.id)}
                                                        className="text-xs font-medium text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300"
                                                    >
                                                        {tr('common.delete')}
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
            </div>
        </AuthenticatedLayout>
    );
}
