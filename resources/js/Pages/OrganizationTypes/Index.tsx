import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import EmptyState from '@/Components/EmptyState';
import { Plus } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';

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
                                className="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-slate-900"
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

            <div className="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                {types.length === 0 ? (
                    <div className="p-6">
                        <EmptyState
                            title={tr('organizationTypes.noTypes')}
                            description=""
                        />
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-left text-sm">
                            <thead className="bg-gray-50 dark:bg-slate-950">
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
                                            className="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500 first:pl-5 last:pr-5 dark:text-slate-400"
                                        >
                                            {h}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {types.map((type) => (
                                    <tr
                                        key={type.id}
                                        className="border-t border-gray-100 text-gray-700 hover:bg-gray-50 dark:border-slate-800 dark:text-slate-200 dark:hover:bg-slate-800/50"
                                    >
                                        <td className="py-3 pl-5 pr-4 font-mono text-xs font-medium text-gray-500 dark:text-slate-400">
                                            {type.code}
                                        </td>
                                        <td className="px-4 py-3">
                                            {type.prefix ? (
                                                <span className="rounded-md bg-orange-50 px-2 py-1 font-mono text-xs font-semibold text-orange-700 ring-1 ring-orange-200 dark:bg-orange-950/30 dark:text-orange-300 dark:ring-orange-900/50">
                                                    {type.prefix}
                                                </span>
                                            ) : (
                                                <span className="text-xs text-gray-400 dark:text-slate-500">
                                                    {tr('organizationTypes.noPrefix')}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            <p className="font-medium">{type.name_en}</p>
                                            {type.name_am && (
                                                <p className="text-xs text-gray-400 dark:text-slate-500">
                                                    {type.name_am}
                                                </p>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-xs text-gray-500 dark:text-slate-400">
                                            {categoryLabel(type.category)}
                                        </td>
                                        <td className="px-4 py-3 tabular-nums text-gray-500 dark:text-slate-400">
                                            {type.level_order}
                                        </td>
                                        <td className="px-4 py-3 max-w-[180px]">
                                            {type.parent_allowed_types.length === 0 ? (
                                                <span className="text-xs text-amber-600 dark:text-amber-400">
                                                    {tr('organizationTypes.rootOnly')}
                                                </span>
                                            ) : (
                                                <span className="font-mono text-xs text-gray-500 dark:text-slate-400">
                                                    {parentTypesLabel(type.parent_allowed_types)}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 tabular-nums text-gray-500 dark:text-slate-400">
                                            {type.organizations_count}
                                        </td>
                                        <td className="px-4 py-3">
                                            <StatusBadge status={type.is_active ? 'active' : 'inactive'} />
                                        </td>
                                        <td className="py-3 pl-4 pr-5">
                                            <div className="flex items-center justify-end gap-3">
                                                <Link
                                                    href={route('organization-types.show', type.id)}
                                                    className="text-xs font-medium text-gray-600 hover:text-gray-900 dark:text-slate-400 dark:hover:text-slate-200"
                                                >
                                                    {tr('common.view')}
                                                </Link>
                                                {type.can.update && (
                                                    <Link
                                                        href={route('organization-types.edit', type.id)}
                                                        className="text-xs font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
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
