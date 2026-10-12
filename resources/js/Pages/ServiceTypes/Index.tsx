import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Plus } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';

import { FilterField, FilterPanel, Input, Select, buttonClassName, cx, tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
import AppActionMenu from '@/Components/ui/AppActionMenu';
type ServiceTypeRow = {
    id: string;
    code: string;
    name_en: string;
    name_am: string | null;
    description: string | null;
    is_active: boolean;
    providers_count: number | null;
    rules_count: number | null;
    can: { view: boolean; update: boolean; archive: boolean; restore: boolean };
};

export default function ServiceTypesIndex({
    serviceTypes,
    filters,
    can,
}: {
    serviceTypes: ServiceTypeRow[];
    filters: Record<string, string>;
    can: { create: boolean };
}) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const form = useForm({
        search: filters.search ?? '',
        is_active: filters.is_active ?? '',
    });

    const hasActiveFilters = Object.values(filters).some(Boolean);

    function submit() {
        router.get(route('service-types.index'), form.data, { preserveState: true, preserveScroll: true });
    }

    return (
        <AuthenticatedLayout
            header={(
                <PageHeader
                    title={t('serviceTypes.title')}
                    actions={can.create ? (
                        <Link
                            href={route('service-types.create')}
                            className={buttonClassName({ variant: 'primary', size: 'sm' })}
                        >
                            <Plus className="h-3.5 w-3.5" />
                            {t('serviceTypes.createTitle')}
                        </Link>
                    ) : undefined}
                />
            )}
        >
            <Head title={t('serviceTypes.title')} />

            <div className="space-y-6">
                <FilterPanel onSubmit={submit} onReset={() => router.get(route('service-types.index'))} hasActiveFilters={hasActiveFilters}>
                    <FilterField label={t('common.search')} wide>
                        <Input type="search"
                            value={form.data.search}
                            placeholder={t('serviceTypes.searchPlaceholder')}
                            onChange={(event) => form.setData('search', event.target.value)}
                        />
                    </FilterField>
                    <FilterField label={t('common.status')}>
                        <Select
                            value={form.data.is_active}
                            onChange={(event) => form.setData('is_active', event.target.value)}
                        >
                            <option value="">{t('common.allStatuses')}</option>
                            <option value="1">{t('serviceTypes.activeOnly')}</option>
                            <option value="0">{t('serviceTypes.inactiveOnly')}</option>
                        </Select>
                    </FilterField>
                </FilterPanel>

                <section className={tableContainerClassName}>
                    {serviceTypes.length === 0 ? (
                        <EmptyState title={t('serviceTypes.noServiceTypes')} description={hasActiveFilters ? t('common.noMatchingRecords') : undefined} />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className={cx(tableClassName, 'min-w-full text-left')}>
                                <thead className={tableHeadClassName}>
                                    <tr>
                                        {[
                                            t('serviceTypes.code'),
                                            t('serviceTypes.englishName'),
                                            t('serviceTypes.amharicName'),
                                            t('serviceTypes.providersCount'),
                                            t('serviceTypes.rulesCount'),
                                            t('common.status'),
                                            '',
                                        ].map((heading) => (
                                            <th key={heading} className={cx(tableHeaderCellClassName, 'text-left')}>
                                                {heading}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className={tableBodyClassName}>
                                    {serviceTypes.map((serviceType) => (
                                        <tr key={serviceType.id} className={tableRowClassName}>
                                            <td className={cx(tableCellClassName, 'font-mono text-xs')}>{serviceType.code}</td>
                                            <td className={tableCellClassName}>
                                                {serviceType.can.view ? (
                                                    <Link href={route('service-types.show', serviceType.id)} className="font-medium text-[color:var(--color-primary)] hover:text-[color:var(--color-primary-hover)] dark:text-[color:var(--color-primary)]">
                                                        {serviceType.name_en}
                                                    </Link>
                                                ) : (
                                                    serviceType.name_en
                                                )}
                                            </td>
                                            <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}>{serviceType.name_am ?? '—'}</td>
                                            <td className={cx(tableCellClassName, 'tabular-nums')}>{serviceType.providers_count ?? 0}</td>
                                            <td className={cx(tableCellClassName, 'tabular-nums')}>{serviceType.rules_count ?? 0}</td>
                                            <td className={tableCellClassName}>
                                                <StatusBadge status={serviceType.is_active ? 'active' : 'inactive'} />
                                            </td>
                                            <td className={tableCellClassName}>
                                                <div className="flex justify-end">
                                                    <AppActionMenu
                                                        items={[
                                                            { label: t('common.edit'), href: route('service-types.edit', serviceType.id), show: serviceType.can.update },
                                                            { label: t('common.delete'), onClick: async () => {
                                                                const { confirmed } = await confirm({
                                                                    title: t('confirmations.confirmDeleteTitle'),
                                                                    description: t('confirmations.thisRecordWillMoveToRecycleBin'),
                                                                    confirmLabel: t('confirmations.delete'),
                                                                    cancelLabel: t('confirmations.cancel'),
                                                                    variant: 'danger',
                                                                });
                                                                if (confirmed) router.delete(route('service-types.archive', serviceType.id));
                                                            }, variant: 'danger', show: serviceType.can.archive && serviceType.is_active },
                                                            { label: t('common.restore'), onClick: async () => {
                                                                const { confirmed } = await confirm({
                                                                    title: t('confirmations.confirmRestoreTitle'),
                                                                    description: t('confirmations.thisActionCannotBeUndone'),
                                                                    confirmLabel: t('confirmations.restore'),
                                                                    cancelLabel: t('confirmations.cancel'),
                                                                    variant: 'default',
                                                                });
                                                                if (confirmed) router.post(route('service-types.restore', serviceType.id));
                                                            }, show: serviceType.can.restore && !serviceType.is_active },
                                                        ]}
                                                    />
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
        </AuthenticatedLayout>
    );
}
