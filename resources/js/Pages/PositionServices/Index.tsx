import PageHeader from '@/Components/PageHeader';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import type { JSX } from 'react';

/**
 * Feedback services per position.
 *
 * Distinct from /service-types, which manages the shared platform catalog.
 * These rows say which services a POSITION provides, and they drive the public
 * feedback dropdown an employee's QR opens.
 */

type NamePair = { name_en: string; name_am: string | null } | null;

type Record = {
    id: string;
    service_no: string | null;
    is_active: boolean;
    is_performance_evaluation_enabled: boolean;
    sort_order: number;
    position: {
        id: string;
        code: string | null;
        title_en: string;
        title_am: string | null;
    } | null;
    organization: (NamePair & { id: string }) | null;
    name_en: string;
    name_am: string | null;
};

type Props = {
    records: { data: Record[]; links: { url: string | null; label: string; active: boolean }[] };
    filters: { organization_id?: string; position_id?: string; search?: string };
    organizations: { id: string; name_en: string; name_am: string | null }[];
    can: { create: boolean };
};

const inputCls =
    'rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-900 focus:border-blue-500 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

export default function PositionServicesIndex({ records, filters, organizations, can }: Props): JSX.Element {
    const { locale, t } = useLocale();
    const am = locale === 'am';

    function applyFilter(key: string, value: string) {
        router.get(
            route('position-services.index'),
            { ...filters, [key]: value || undefined },
            { preserveState: true, preserveScroll: true },
        );
    }

    function destroy(id: string) {
        if (!window.confirm(t('confirmations.deleteWarning'))) {
            return;
        }

        router.delete(route('position-services.destroy', id), { preserveScroll: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title={t('serviceFeedback.positionServices')} />

            <div className="space-y-5">
                <PageHeader
                    title={t('serviceFeedback.positionServices')}
                    description={t('serviceFeedback.positionServicesHint')}
                    actions={
                        can.create ? (
                            <Link
                                href={route('position-services.create')}
                                className="rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700"
                            >
                                {t('serviceFeedback.addPositionService')}
                            </Link>
                        ) : undefined
                    }
                />

                <div className="flex flex-wrap gap-2 rounded-xl border border-gray-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-900">
                    <select
                        className={inputCls}
                        value={filters.organization_id ?? ''}
                        onChange={(e) => applyFilter('organization_id', e.target.value)}
                        aria-label={t('serviceFeedback.filterOrganization')}
                    >
                        <option value="">{t('serviceFeedback.filterOrganization')}</option>
                        {organizations.map((org) => (
                            <option key={org.id} value={org.id}>
                                {am ? (org.name_am ?? org.name_en) : org.name_en}
                            </option>
                        ))}
                    </select>

                    <input
                        type="search"
                        className={inputCls}
                        defaultValue={filters.search ?? ''}
                        placeholder={t('serviceFeedback.searchServices')}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                applyFilter('search', (e.target as HTMLInputElement).value);
                            }
                        }}
                    />
                </div>

                <div className="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <table className="min-w-full divide-y divide-gray-200 text-sm dark:divide-slate-800">
                        <thead className="bg-gray-50 dark:bg-slate-950">
                            <tr>
                                <Th>{t('serviceFeedback.serviceIdNo')}</Th>
                                <Th>{t('serviceFeedback.serviceName')}</Th>
                                <Th>{t('serviceFeedback.position')}</Th>
                                <Th>{t('serviceFeedback.filterOrganization')}</Th>
                                <Th>{t('serviceFeedback.filterStatus')}</Th>
                                <Th>{t('serviceFeedback.usePerformanceEvaluation')}</Th>
                                <Th />
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                            {records.data.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="px-4 py-8 text-center text-gray-500 dark:text-slate-400">
                                        {t('serviceFeedback.noPositionServices')}
                                    </td>
                                </tr>
                            )}

                            {records.data.map((row) => (
                                <tr key={row.id} className="hover:bg-gray-50 dark:hover:bg-slate-800/50">
                                    <td className="whitespace-nowrap px-4 py-2.5 font-mono text-gray-900 dark:text-slate-100">
                                        {row.service_no ?? '—'}
                                    </td>
                                    <td className="px-4 py-2.5 text-gray-700 dark:text-slate-300">
                                        {am ? (row.name_am ?? row.name_en) : row.name_en}
                                    </td>
                                    <td className="px-4 py-2.5 text-gray-700 dark:text-slate-300">
                                        {row.position
                                            ? am
                                                ? (row.position.title_am ?? row.position.title_en)
                                                : row.position.title_en
                                            : '—'}
                                    </td>
                                    <td className="px-4 py-2.5 text-gray-600 dark:text-slate-400">
                                        {row.organization
                                            ? am
                                                ? (row.organization.name_am ?? row.organization.name_en)
                                                : row.organization.name_en
                                            : '—'}
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-2.5">
                                        <span
                                            className={`rounded-full px-2 py-0.5 text-xs font-medium ${
                                                row.is_active
                                                    ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300'
                                                    : 'bg-gray-100 text-gray-600 dark:bg-slate-800 dark:text-slate-400'
                                            }`}
                                        >
                                            {row.is_active ? t('common.active') : t('common.inactive')}
                                        </span>
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-2.5 text-gray-600 dark:text-slate-400">
                                        {row.is_performance_evaluation_enabled ? t('common.yes') : t('common.no')}
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-2.5 text-right">
                                        <Link
                                            href={route('position-services.edit', row.id)}
                                            className="text-xs font-medium text-blue-600 hover:underline dark:text-blue-400"
                                        >
                                            {t('common.edit')}
                                        </Link>
                                        <button
                                            type="button"
                                            onClick={() => destroy(row.id)}
                                            className="ml-3 text-xs font-medium text-red-600 hover:underline dark:text-red-400"
                                        >
                                            {t('common.delete')}
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {records.links.length > 3 && (
                    <div className="flex flex-wrap gap-1">
                        {records.links.map((link, index) => (
                            <Link
                                key={index}
                                href={link.url ?? '#'}
                                preserveState
                                preserveScroll
                                className={`rounded-md px-3 py-1.5 text-sm ${
                                    link.active
                                        ? 'bg-blue-600 text-white'
                                        : link.url
                                          ? 'border border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800'
                                          : 'cursor-not-allowed border border-gray-200 text-gray-400 dark:border-slate-800 dark:text-slate-600'
                                }`}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

function Th({ children }: { children?: React.ReactNode }): JSX.Element {
    return (
        <th className="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
            {children}
        </th>
    );
}
