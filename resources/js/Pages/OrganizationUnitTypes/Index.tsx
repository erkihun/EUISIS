import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import StatusBadge from '@/Components/StatusBadge';
import { Plus } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';

type UnitType = {
    id: string;
    code: string;
    prefix: string | null;
    name_en: string;
    name_am: string | null;
    description_en: string | null;
    is_active: boolean;
    sort_order: number;
    deleted_at: string | null;
    can: { update: boolean; archive: boolean; restore: boolean };
};

export default function OrganizationUnitTypesIndex({
    types,
    can,
}: {
    types: UnitType[];
    can: { create: boolean };
}) {
    const { t } = useLocale();
    const { confirm } = useConfirm();

    async function archive(id: string) {
        const { confirmed } = await confirm({
            title: t('confirmations.confirmDeleteTitle'),
            description: t('confirmations.thisRecordWillMoveToRecycleBin'),
            confirmLabel: t('confirmations.delete'),
            cancelLabel: t('confirmations.cancel'),
            variant: 'danger',
        });
        if (!confirmed) return;
        router.post(route('organization-unit-types.archive', id), {}, { preserveScroll: true });
    }

    async function restore(id: string) {
        const { confirmed } = await confirm({
            title: t('confirmations.confirmRestoreTitle'),
            description: t('confirmations.thisActionCannotBeUndone'),
            confirmLabel: t('confirmations.restore'),
            cancelLabel: t('confirmations.cancel'),
            variant: 'default',
        });
        if (!confirmed) return;
        router.post(route('organization-unit-types.restore', id), {}, { preserveScroll: true });
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('organizationUnitTypes.organizationUnitTypes')}
                    description=""
                    actions={
                        can.create ? (
                            <Link
                                href={route('organization-unit-types.create')}
                                className="inline-flex items-center gap-1.5 rounded-lg bg-[color:var(--color-primary)] px-3 py-1.5 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)] focus-visible:ring-offset-2 dark:focus-visible:ring-offset-slate-900"
                            >
                                <Plus className="h-3.5 w-3.5" aria-hidden="true" />
                                {t('organizationUnitTypes.createOrganizationUnitType')}
                            </Link>
                        ) : undefined
                    }
                />
            }
        >
            <Head title={t('organizationUnitTypes.organizationUnitTypes')} />

            <div className="rounded-card border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                {types.length === 0 ? (
                    <div className="p-6">
                        <EmptyState
                            title={t('organizationUnitTypes.noOrganizationUnitTypesFound')}
                            description=""
                        />
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-left text-sm">
                            <thead className="bg-gray-50 dark:bg-slate-950">
                                <tr>
                                    {[
                                        t('common.code'),
                                        t('organizationUnitTypes.prefix'),
                                        t('common.name'),
                                        t('organizationUnitTypes.sortOrder'),
                                        t('common.status'),
                                        '',
                                    ].map((h) => (
                                        <th
                                            key={h}
                                            className="px-4 py-3 text-xs font-semibold text-gray-500 first:pl-5 last:pr-5 dark:text-slate-400"
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
                                        <td className="px-4 py-3 font-mono text-xs font-medium text-indigo-600 dark:text-indigo-400">
                                            {type.prefix ?? '—'}
                                        </td>
                                        <td className="px-4 py-3">
                                            <p className="font-medium">{type.name_en}</p>
                                            {type.name_am && (
                                                <p className="text-xs text-gray-400 dark:text-slate-500">
                                                    {type.name_am}
                                                </p>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 tabular-nums text-gray-500 dark:text-slate-400">
                                            {type.sort_order}
                                        </td>
                                        <td className="px-4 py-3">
                                            <StatusBadge status={type.is_active ? 'active' : 'archived'} />
                                        </td>
                                        <td className="py-3 pl-4 pr-5">
                                            <div className="flex items-center justify-end gap-3">
                                                {type.can.update && !type.deleted_at && (
                                                    <Link
                                                        href={route('organization-unit-types.edit', type.id)}
                                                        className="text-xs font-medium text-[color:var(--color-primary)] hover:text-[color:var(--color-primary-hover)] dark:text-[color:var(--color-primary)] dark:hover:text-[color:var(--color-primary-hover)]"
                                                    >
                                                        {t('common.edit')}
                                                    </Link>
                                                )}
                                                {type.can.archive && type.is_active && !type.deleted_at && (
                                                    <button
                                                        type="button"
                                                        onClick={() => archive(type.id)}
                                                        className="text-xs font-medium text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300"
                                                    >
                                                        {t('common.delete')}
                                                    </button>
                                                )}
                                                {type.can.restore && (type.deleted_at || !type.is_active) && (
                                                    <button
                                                        type="button"
                                                        onClick={() => restore(type.id)}
                                                        className="text-xs font-medium text-green-600 hover:text-green-800 dark:text-green-400 dark:hover:text-green-300"
                                                    >
                                                        {t('common.restore')}
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
