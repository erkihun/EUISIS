import { Head, Link, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';

/** Safely decode Laravel pagination HTML entities without dangerouslySetInnerHTML. */
function paginationLabel(label: string): string {
    return label.replace(/&laquo;/g, '«').replace(/&raquo;/g, '»').replace(/&amp;/g, '&');
}
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import EmptyState from '@/Components/EmptyState';
import { Plus } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';

type Version = {
    id: string;
    version_name: string;
    status: string;
    approval_date: string | null;
    effective_from: string | null;
    effective_to: string | null;
    created_at: string;
    edges_count: number;
    approver?: {
        name: string;
    } | null;
    can: {
        view: boolean;
        update: boolean;
        archive: boolean;
        publish: boolean;
        manageTree: boolean;
    };
};

export default function HierarchyVersionsIndex({
    versions,
    filters,
    can,
}: {
    versions: {
        data: Version[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        meta: { total: number };
    };
    filters: {
        search: string | null;
        status: string | null;
        effective_from: string | null;
    };
    can: { create: boolean };
}) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const filterForm = useForm({
        search: filters.search ?? '',
        status: filters.status ?? '',
        effective_from: filters.effective_from ?? '',
    });

    function submitFilters(event: React.FormEvent) {
        event.preventDefault();
        router.get(route('hierarchy-versions.index'), filterForm.data, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('hierarchyVersions.hierarchyVersions')}
                    description={t('hierarchyVersions.description')}
                    actions={
                        can.create ? (
                            <Link
                                href={route('hierarchy-versions.create')}
                                className="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-slate-900"
                            >
                                <Plus className="h-3.5 w-3.5" aria-hidden="true" />
                                {t('hierarchyVersions.createHierarchyVersion')}
                            </Link>
                        ) : undefined
                    }
                />
            }
        >
            <Head title={t('hierarchyVersions.hierarchyVersions')} />

            <form
                onSubmit={submitFilters}
                className="mb-4 grid gap-3 rounded-xl border border-gray-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900 md:grid-cols-4"
            >
                <input
                    className="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                    placeholder={t('hierarchyVersions.searchHierarchyVersions')}
                    value={filterForm.data.search}
                    onChange={(event) => filterForm.setData('search', event.target.value)}
                />
                <select
                    className="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                    value={filterForm.data.status}
                    onChange={(event) => filterForm.setData('status', event.target.value)}
                >
                    <option value="">{t('common.status')}</option>
                    <option value="draft">{t('hierarchyVersions.draft')}</option>
                    <option value="published">{t('hierarchyVersions.published')}</option>
                    <option value="archived">{t('hierarchyVersions.archived')}</option>
                </select>
                <LocalizedDatePicker
                    className="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                    value={filterForm.data.effective_from}
                    onChange={(iso) => filterForm.setData('effective_from', iso)}
                />
                <div className="flex items-center justify-end gap-2">
                    <button
                        type="button"
                        onClick={() => {
                            filterForm.setData({
                                search: '',
                                status: '',
                                effective_from: '',
                            });
                            router.get(route('hierarchy-versions.index'));
                        }}
                        className="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                    >
                        {t('common.reset')}
                    </button>
                    <button
                        type="submit"
                        className="rounded-lg bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700"
                    >
                        {t('common.filter')}
                    </button>
                </div>
            </form>

            <section className="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div className="flex items-center justify-between px-5 py-4">
                    <div className="flex items-center gap-3">
                        <h3 className="font-semibold text-gray-900 dark:text-slate-100">
                            {t('hierarchyVersions.hierarchyVersions')}
                        </h3>
                        <span className="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-500 dark:bg-slate-800 dark:text-slate-400">
                            {versions.meta.total}
                        </span>
                    </div>
                </div>

                {versions.data.length === 0 ? (
                    <div className="p-6">
                        <EmptyState
                            title={t('hierarchyVersions.noHierarchyVersionsFound')}
                            description={t('hierarchyVersions.description')}
                            action={can.create ? (
                                <Link
                                    href={route('hierarchy-versions.create')}
                                    className="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700"
                                >
                                    <Plus className="h-3.5 w-3.5" aria-hidden="true" />
                                    {t('hierarchyVersions.createHierarchyVersion')}
                                </Link>
                            ) : undefined}
                        />
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-left text-sm">
                            <thead className="bg-gray-50 dark:bg-slate-950">
                                <tr>
                                    {[
                                        t('hierarchyVersions.versionName'),
                                        t('common.status'),
                                        t('hierarchyVersions.edgeCount'),
                                        t('hierarchyVersions.publishedBy'),
                                        t('hierarchyVersions.publishedAt'),
                                        t('hierarchyVersions.effectiveFrom'),
                                        '',
                                    ].map((heading) => (
                                        <th
                                            key={heading}
                                            className="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400"
                                        >
                                            {heading}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {versions.data.map((version) => (
                                    <tr
                                        key={version.id}
                                        className="border-t border-gray-100 text-gray-700 dark:border-slate-800 dark:text-slate-200"
                                    >
                                        <td className="px-4 py-3">
                                            <div className="font-medium text-gray-900 dark:text-slate-100">
                                                {version.version_name}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3">
                                            <StatusBadge status={version.status} />
                                        </td>
                                        <td className="px-4 py-3">{version.edges_count}</td>
                                        <td className="px-4 py-3 text-sm text-gray-500 dark:text-slate-400">
                                            {version.approver?.name ?? '-'}
                                        </td>
                                        <td className="px-4 py-3 text-sm text-gray-500 dark:text-slate-400">
                                            <LocalizedDateDisplay value={version.approval_date} withTime />
                                        </td>
                                        <td className="px-4 py-3 text-sm text-gray-500 dark:text-slate-400">
                                            <LocalizedDateDisplay value={version.effective_from} />
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <div className="flex flex-wrap justify-end gap-2">
                                                <Link
                                                    href={route('hierarchy-versions.show', { hierarchyVersion: version.id })}
                                                    className="rounded-lg border border-gray-200 px-3 py-1.5 text-sm font-medium text-blue-600 hover:border-blue-300 hover:text-blue-700 dark:border-slate-700 dark:text-blue-400 dark:hover:border-blue-500 dark:hover:text-blue-300"
                                                >
                                                    {t('common.view')}
                                                </Link>
                                                {version.can.update && (
                                                    <Link
                                                        href={route('hierarchy-versions.edit', { hierarchyVersion: version.id })}
                                                        className="rounded-lg border border-gray-200 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                                                    >
                                                        {t('common.edit')}
                                                    </Link>
                                                )}
                                                {version.can.manageTree && (
                                                    <Link
                                                        href={route('hierarchy-versions.tree.edit', { hierarchyVersion: version.id })}
                                                        className="rounded-lg border border-gray-200 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                                                    >
                                                        {t('hierarchyVersions.editTree')}
                                                    </Link>
                                                )}
                                                {version.can.publish && (
                                                    <button
                                                        type="button"
                                                        onClick={async () => {
                                                            const { confirmed } = await confirm({
                                                                title: t('confirmations.confirmPublishTitle'),
                                                                description: t('confirmations.publishedVersionsCannotBeEdited'),
                                                                confirmLabel: t('confirmations.publish'),
                                                                cancelLabel: t('confirmations.cancel'),
                                                                variant: 'warning',
                                                            });
                                                            if (confirmed) router.post(route('hierarchy-versions.publish', { hierarchyVersion: version.id }));
                                                        }}
                                                        className="rounded-lg bg-amber-500 px-3 py-1.5 text-sm font-medium text-slate-900 hover:bg-amber-600"
                                                    >
                                                        {t('hierarchyVersions.publishVersion')}
                                                    </button>
                                                )}
                                                {version.can.archive && (
                                                    <button
                                                        type="button"
                                                        onClick={async () => {
                                                            const { confirmed } = await confirm({
                                                                title: t('confirmations.confirmArchiveTitle'),
                                                                description: t('confirmations.thisActionCannotBeUndone'),
                                                                confirmLabel: t('confirmations.archive'),
                                                                cancelLabel: t('confirmations.cancel'),
                                                                variant: 'danger',
                                                            });
                                                            if (confirmed) router.post(route('hierarchy-versions.archive', { hierarchyVersion: version.id }));
                                                        }}
                                                        className="rounded-lg border border-red-200 px-3 py-1.5 text-sm font-medium text-red-600 hover:bg-red-50 dark:border-red-800 dark:text-red-400 dark:hover:bg-red-950/20"
                                                    >
                                                        {t('hierarchyVersions.archiveVersion')}
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

                {versions.links.length > 3 && (
                    <div className="flex flex-wrap items-center justify-end gap-2 border-t border-gray-100 px-5 py-4 dark:border-slate-800">
                        {versions.links.map((link, index) => (
                            <Link
                                key={`${link.label}-${index}`}
                                href={link.url ?? '#'}
                                className={`rounded-lg px-3 py-1.5 text-sm ${link.active ? 'bg-blue-600 text-white' : 'border border-gray-200 text-gray-700 dark:border-slate-700 dark:text-slate-300'} ${link.url ? '' : 'pointer-events-none opacity-50'}`}
                            >{paginationLabel(link.label)}</Link>
                        ))}
                    </div>
                )}
            </section>
        </AuthenticatedLayout>
    );
}
