import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Plus } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';

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
    const inputClassName =
        'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[color:var(--color-primary)] dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
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
                            className="inline-flex items-center gap-1.5 rounded-lg bg-[color:var(--color-primary)] px-3 py-1.5 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)]"
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
                <section className="rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <form className="grid gap-3 md:grid-cols-[1fr_220px_auto]" onSubmit={submit}>
                        <input
                            className={inputClassName}
                            value={form.data.search}
                            placeholder={t('serviceTypes.searchPlaceholder')}
                            onChange={(event) => form.setData('search', event.target.value)}
                        />
                        <select
                            className={inputClassName}
                            value={form.data.is_active}
                            onChange={(event) => form.setData('is_active', event.target.value)}
                        >
                            <option value="">{t('common.status')}</option>
                            <option value="1">{t('serviceTypes.activeOnly')}</option>
                            <option value="0">{t('serviceTypes.inactiveOnly')}</option>
                        </select>
                        <button className="rounded-lg bg-[color:var(--color-primary)] px-4 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)]" type="submit">
                            {t('common.filter')}
                        </button>
                    </form>
                </section>

                <section className="rounded-panel border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    {serviceTypes.length === 0 ? (
                        <div className="p-6">
                            <EmptyState title={t('serviceTypes.noServiceTypes')} />
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-left text-sm">
                                <thead className="bg-gray-50 dark:bg-slate-950">
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
                                            <th key={heading} className="px-4 py-3 text-xs font-semibold text-gray-500 dark:text-slate-400">
                                                {heading}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {serviceTypes.map((serviceType) => (
                                        <tr key={serviceType.id} className="border-t border-gray-100 text-gray-700 dark:border-slate-800 dark:text-slate-200">
                                            <td className="px-4 py-3 font-mono text-xs">{serviceType.code}</td>
                                            <td className="px-4 py-3">
                                                {serviceType.can.view ? (
                                                    <Link href={route('service-types.show', serviceType.id)} className="font-medium text-[color:var(--color-primary)] hover:text-[color:var(--color-primary-hover)] dark:text-[color:var(--color-primary)]">
                                                        {serviceType.name_en}
                                                    </Link>
                                                ) : (
                                                    serviceType.name_en
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-gray-500 dark:text-slate-400">{serviceType.name_am ?? '—'}</td>
                                            <td className="px-4 py-3 tabular-nums">{serviceType.providers_count ?? 0}</td>
                                            <td className="px-4 py-3 tabular-nums">{serviceType.rules_count ?? 0}</td>
                                            <td className="px-4 py-3">
                                                <StatusBadge status={serviceType.is_active ? 'active' : 'inactive'} />
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex justify-end gap-3">
                                                    {serviceType.can.update && (
                                                        <Link href={route('service-types.edit', serviceType.id)} className="text-xs font-medium text-[color:var(--color-primary)] hover:text-[color:var(--color-primary-hover)]">
                                                            {t('common.edit')}
                                                        </Link>
                                                    )}
                                                    {serviceType.can.archive && serviceType.is_active && (
                                                        <button
                                                            type="button"
                                                            onClick={async () => {
                                                                const { confirmed } = await confirm({
                                                                    title: t('confirmations.confirmDeleteTitle'),
                                                                    description: t('confirmations.thisRecordWillMoveToRecycleBin'),
                                                                    confirmLabel: t('confirmations.delete'),
                                                                    cancelLabel: t('confirmations.cancel'),
                                                                    variant: 'danger',
                                                                });
                                                                if (confirmed) router.delete(route('service-types.archive', serviceType.id));
                                                            }}
                                                            className="text-xs font-medium text-red-600 hover:text-red-800"
                                                        >
                                                            {t('common.delete')}
                                                        </button>
                                                    )}
                                                    {serviceType.can.restore && !serviceType.is_active && (
                                                        <button
                                                            type="button"
                                                            onClick={async () => {
                                                                const { confirmed } = await confirm({
                                                                    title: t('confirmations.confirmRestoreTitle'),
                                                                    description: t('confirmations.thisActionCannotBeUndone'),
                                                                    confirmLabel: t('confirmations.restore'),
                                                                    cancelLabel: t('confirmations.cancel'),
                                                                    variant: 'default',
                                                                });
                                                                if (confirmed) router.post(route('service-types.restore', serviceType.id));
                                                            }}
                                                            className="text-xs font-medium text-green-600 hover:text-green-800"
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
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
