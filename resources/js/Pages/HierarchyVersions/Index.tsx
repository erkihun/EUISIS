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

import { buttonClassName, cx, inputClassName, selectClassName, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
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
                                className={buttonClassName({ variant: 'primary', size: 'sm' })}
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
                className="mb-4 grid gap-3 rounded-card border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-4 md:grid-cols-4"
            >
                <input
                    className={inputClassName}
                    placeholder={t('hierarchyVersions.searchHierarchyVersions')}
                    value={filterForm.data.search}
                    onChange={(event) => filterForm.setData('search', event.target.value)}
                />
                <select
                    className={selectClassName}
                    value={filterForm.data.status}
                    onChange={(event) => filterForm.setData('status', event.target.value)}
                >
                    <option value="">{t('common.status')}</option>
                    <option value="draft">{t('hierarchyVersions.draft')}</option>
                    <option value="published">{t('hierarchyVersions.published')}</option>
                    <option value="archived">{t('hierarchyVersions.archived')}</option>
                </select>
                <LocalizedDatePicker
                    className="rounded-lg border border-[color:var(--app-border-strong)] bg-[color:var(--app-surface)] px-3 py-2 text-sm text-[color:var(--app-foreground)] focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[color:var(--color-primary)]"
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
                        className={buttonClassName({ variant: 'outline' })}
                    >
                        {t('common.reset')}
                    </button>
                    <button
                        type="submit"
                        className={buttonClassName({ variant: 'primary' })}
                    >
                        {t('common.filter')}
                    </button>
                </div>
            </form>

            <section className="rounded-card border border-[color:var(--app-border)] bg-[color:var(--app-surface)]">
                <div className="flex items-center justify-between px-5 py-4">
                    <div className="flex items-center gap-3">
                        <h3 className="font-semibold text-[color:var(--app-foreground)]">
                            {t('hierarchyVersions.hierarchyVersions')}
                        </h3>
                        <span className="rounded-full bg-[color:var(--app-surface-muted)] px-2 py-0.5 text-xs font-medium text-[color:var(--app-muted-foreground)]">
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
                                    className={buttonClassName({ variant: 'primary', size: 'sm' })}
                                >
                                    <Plus className="h-3.5 w-3.5" aria-hidden="true" />
                                    {t('hierarchyVersions.createHierarchyVersion')}
                                </Link>
                            ) : undefined}
                        />
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className={cx(tableClassName, 'min-w-full text-left')}>
                            <thead className={tableHeadClassName}>
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
                                            className={cx(tableHeaderCellClassName, 'text-left')}
                                        >
                                            {heading}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className={tableBodyClassName}>
                                {versions.data.map((version) => (
                                    <tr
                                        key={version.id}
                                        className={tableRowClassName}
                                    >
                                        <td className={tableCellClassName}>
                                            <div className="font-medium text-[color:var(--app-foreground)]">
                                                {version.version_name}
                                            </div>
                                        </td>
                                        <td className={tableCellClassName}>
                                            <StatusBadge status={version.status} />
                                        </td>
                                        <td className={tableCellClassName}>{version.edges_count}</td>
                                        <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}>
                                            {version.approver?.name ?? '-'}
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}>
                                            <LocalizedDateDisplay value={version.approval_date} withTime />
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}>
                                            <LocalizedDateDisplay value={version.effective_from} />
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-right')}>
                                            <div className="flex flex-wrap justify-end gap-2">
                                                <Link
                                                    href={route('hierarchy-versions.show', { hierarchyVersion: version.id })}
                                                    className="rounded-lg border border-[color:var(--app-border)] px-3 py-1.5 text-sm font-medium text-[color:var(--color-primary)] hover:border-blue-300 hover:text-[color:var(--color-primary-hover)] dark:text-[color:var(--color-primary)] dark:hover:border-[color:var(--color-primary)] dark:hover:text-[color:var(--color-primary-hover)]"
                                                >
                                                    {t('common.view')}
                                                </Link>
                                                {version.can.update && (
                                                    <Link
                                                        href={route('hierarchy-versions.edit', { hierarchyVersion: version.id })}
                                                        className="rounded-lg border border-[color:var(--app-border)] px-3 py-1.5 text-sm font-medium text-[color:var(--app-foreground)] hover:bg-[color:var(--app-surface-muted)]"
                                                    >
                                                        {t('common.edit')}
                                                    </Link>
                                                )}
                                                {version.can.manageTree && (
                                                    <Link
                                                        href={route('hierarchy-versions.tree.edit', { hierarchyVersion: version.id })}
                                                        className="rounded-lg border border-[color:var(--app-border)] px-3 py-1.5 text-sm font-medium text-[color:var(--app-foreground)] hover:bg-[color:var(--app-surface-muted)]"
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
                                                        className="rounded-lg bg-amber-500 px-3 py-1.5 text-sm font-medium text-[color:var(--app-foreground)] hover:bg-amber-600"
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
                                                        className={buttonClassName({ variant: 'outline', size: 'sm' })}
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
                    <div className="flex flex-wrap items-center justify-end gap-2 border-t border-[color:var(--app-border)] px-5 py-4">
                        {versions.links.map((link, index) => (
                            <Link
                                key={`${link.label}-${index}`}
                                href={link.url ?? '#'}
                                className={`rounded-lg px-3 py-1.5 text-sm ${link.active ? 'bg-[color:var(--color-primary)] text-white' : 'border border-[color:var(--app-border)] text-[color:var(--app-foreground)]'} ${link.url ? '' : 'pointer-events-none opacity-50'}`}
                            >{paginationLabel(link.label)}</Link>
                        ))}
                    </div>
                )}
            </section>
        </AuthenticatedLayout>
    );
}
