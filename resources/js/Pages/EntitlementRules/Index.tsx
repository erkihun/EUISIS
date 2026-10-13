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
type EntitlementRuleRow = {
    id: string;
    name: string;
    service_type: { id: string; name_en: string; name_am?: string | null } | null;
    rule_definition: { quota_limit?: number; period_days?: number; notes?: string } | null;
    is_active: boolean;
    entitlements_count: number | null;
    can: { view: boolean; update: boolean; archive: boolean; restore: boolean };
};

type ServiceTypeOption = { id: string; name_en: string; name_am?: string | null };

export default function EntitlementRulesIndex({
    rules,
    filters,
    serviceTypes,
    can,
}: {
    rules: EntitlementRuleRow[];
    filters: Record<string, string>;
    serviceTypes: ServiceTypeOption[];
    can: { create: boolean };
}) {
    const { t, locale } = useLocale();
    const { confirm } = useConfirm();
    const form = useForm({
        search: filters.search ?? '',
        service_type_id: filters.service_type_id ?? '',
        is_active: filters.is_active ?? '',
    });

    const hasActiveFilters = Object.values(filters).some(Boolean);

    function submit() {
        router.get(route('entitlement-rules.index'), form.data, { preserveState: true, preserveScroll: true });
    }

    return (
        <AuthenticatedLayout
            header={(
                <PageHeader
                    title={t('entitlementRules.title')}
                    actions={can.create ? (
                        <Link
                            href={route('entitlement-rules.create')}
                            className={buttonClassName({ variant: 'primary', size: 'sm' })}
                        >
                            <Plus className="h-3.5 w-3.5" />
                            {t('entitlementRules.createTitle')}
                        </Link>
                    ) : undefined}
                />
            )}
        >
            <Head title={t('entitlementRules.title')} />

            <div className="space-y-6">
                <FilterPanel onSubmit={submit} onReset={() => router.get(route('entitlement-rules.index'))} hasActiveFilters={hasActiveFilters}>
                    <FilterField label={t('common.search')} wide>
                        <Input type="search"
                            value={form.data.search}
                            placeholder={t('entitlementRules.searchPlaceholder')}
                            onChange={(event) => form.setData('search', event.target.value)}
                        />
                    </FilterField>
                    <FilterField label={t('entitlementRules.serviceType')}>
                        <Select
                            value={form.data.service_type_id}
                            onChange={(event) => form.setData('service_type_id', event.target.value)}
                        >
                            <option value="">{t('common.all')}</option>
                            {serviceTypes.map((serviceType) => (
                                <option key={serviceType.id} value={serviceType.id}>
                                    {locale === 'am' ? (serviceType.name_am ?? serviceType.name_en) : serviceType.name_en}
                                </option>
                            ))}
                        </Select>
                    </FilterField>
                    <FilterField label={t('common.status')}>
                        <Select
                            value={form.data.is_active}
                            onChange={(event) => form.setData('is_active', event.target.value)}
                        >
                            <option value="">{t('common.allStatuses')}</option>
                            <option value="1">{t('common.active')}</option>
                            <option value="0">{t('common.inactive')}</option>
                        </Select>
                    </FilterField>
                </FilterPanel>

                <section className={tableContainerClassName}>
                    {rules.length === 0 ? (
                        <EmptyState title={t('entitlementRules.noRules')} description={hasActiveFilters ? t('common.noMatchingRecords') : undefined} />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className={cx(tableClassName, 'min-w-full text-left')}>
                                <thead className={tableHeadClassName}>
                                    <tr>
                                        {[
                                            t('entitlementRules.ruleName'),
                                            t('entitlementRules.serviceType'),
                                            t('entitlementRules.quotaLimit'),
                                            t('entitlementRules.periodDays'),
                                            t('entitlementRules.entitlementsCount'),
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
                                    {rules.map((rule) => (
                                        <tr key={rule.id} className={tableRowClassName}>
                                            <td className={tableCellClassName}>
                                                {rule.can.view ? (
                                                    <Link href={route('entitlement-rules.show', rule.id)} className="font-medium text-[color:var(--color-primary)] hover:text-[color:var(--color-primary-hover)] dark:text-[color:var(--color-primary)]">
                                                        {rule.name}
                                                    </Link>
                                                ) : (
                                                    rule.name
                                                )}
                                            </td>
                                            <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}>
                                                {rule.service_type ? (locale === 'am' ? (rule.service_type.name_am ?? rule.service_type.name_en) : rule.service_type.name_en) : '—'}
                                            </td>
                                            <td className={cx(tableCellClassName, 'tabular-nums')}>{rule.rule_definition?.quota_limit ?? '—'}</td>
                                            <td className={cx(tableCellClassName, 'tabular-nums')}>{rule.rule_definition?.period_days ?? '—'}</td>
                                            <td className={cx(tableCellClassName, 'tabular-nums')}>{rule.entitlements_count ?? 0}</td>
                                            <td className={tableCellClassName}>
                                                <StatusBadge status={rule.is_active ? 'active' : 'inactive'} />
                                            </td>
                                            <td className={tableCellClassName}>
                                                <div className="flex justify-end">
                                                    <AppActionMenu
                                                        items={[
                                                            { label: t('common.edit'), href: route('entitlement-rules.edit', rule.id), show: rule.can.update },
                                                            { label: t('common.delete'), onClick: async () => {
                                                                const { confirmed } = await confirm({
                                                                    title: t('confirmations.confirmDeleteTitle'),
                                                                    description: t('confirmations.thisRecordWillMoveToRecycleBin'),
                                                                    confirmLabel: t('confirmations.delete'),
                                                                    cancelLabel: t('confirmations.cancel'),
                                                                    variant: 'danger',
                                                                });
                                                                if (confirmed) router.delete(route('entitlement-rules.archive', rule.id));
                                                            }, variant: 'danger', show: rule.can.archive && rule.is_active },
                                                            { label: t('common.restore'), onClick: async () => {
                                                                const { confirmed } = await confirm({
                                                                    title: t('confirmations.confirmRestoreTitle'),
                                                                    description: t('confirmations.thisActionCannotBeUndone'),
                                                                    confirmLabel: t('confirmations.restore'),
                                                                    cancelLabel: t('confirmations.cancel'),
                                                                    variant: 'default',
                                                                });
                                                                if (confirmed) router.post(route('entitlement-rules.restore', rule.id));
                                                            }, show: rule.can.restore && !rule.is_active },
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
