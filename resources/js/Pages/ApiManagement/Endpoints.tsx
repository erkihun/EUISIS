import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { useLocale } from '@/hooks/useLocale';

interface Endpoint {
    id: string;
    method: string;
    uri: string;
    route_name: string | null;
    controller_action: string | null;
    middleware: string[];
    auth_required: boolean;
    required_scope: string | null;
    rate_limit: string | null;
    status: string;
    description: string | null;
    version: string | null;
    is_public_documented: boolean;
    last_synced_at: string | null;
}

interface Props {
    endpoints: Endpoint[];
    unsynced_count: number;
    scopes: { value: string; label: string }[];
    statuses: string[];
    can: { sync: boolean; update: boolean; viewLogs: boolean };
}

const METHOD_COLORS: Record<string, string> = {
    GET: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
    POST: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
    PATCH: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    PUT: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    DELETE: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
};

const STATUS_COLORS: Record<string, string> = {
    active: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
    deprecated: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    disabled: 'bg-slate-200 text-slate-600 dark:bg-slate-800 dark:text-slate-400',
};

/**
 * The endpoint catalog. Rows come from the database, populated by syncing the
 * application route table — never from a hand-written list.
 */
export default function ApiManagementEndpoints({ endpoints, unsynced_count, statuses, can }: Props) {
    const { t } = useLocale();
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [syncing, setSyncing] = useState(false);

    const visible = useMemo(
        () =>
            endpoints.filter((endpoint) => {
                const haystack = `${endpoint.method} ${endpoint.uri} ${endpoint.route_name ?? ''} ${
                    endpoint.required_scope ?? ''
                }`.toLowerCase();

                return (
                    haystack.includes(search.toLowerCase()) &&
                    (statusFilter === '' || endpoint.status === statusFilter)
                );
            }),
        [endpoints, search, statusFilter],
    );

    function sync() {
        setSyncing(true);
        router.post(
            route('api-management.endpoints.sync'),
            {},
            { preserveScroll: true, onFinish: () => setSyncing(false) },
        );
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={t('apiManagement.endpointCatalog')} />}>
            <Head title={t('apiManagement.endpointCatalog')} />

            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <Link href={route('api-management.index')} className="text-sm text-blue-600 hover:underline dark:text-blue-400">
                    &larr; {t('apiManagement.title')}
                </Link>

                {can.sync && (
                    <button
                        type="button"
                        onClick={sync}
                        disabled={syncing}
                        className="rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60"
                    >
                        {syncing ? t('common.loading') : t('apiManagement.syncEndpoints')}
                    </button>
                )}
            </div>

            {unsynced_count > 0 && (
                <div className="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300">
                    {t('apiManagement.unsyncedEndpoints').replace(':count', String(unsynced_count))}
                </div>
            )}

            <div className="mb-4 flex flex-wrap gap-3">
                <input
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder={t('apiManagement.searchEndpoints')}
                    className="w-64 rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
                />
                <select
                    value={statusFilter}
                    onChange={(event) => setStatusFilter(event.target.value)}
                    className="rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
                >
                    <option value="">{t('apiManagement.allStatuses')}</option>
                    {statuses.map((status) => (
                        <option key={status} value={status}>
                            {t(`apiManagement.status_${status}`)}
                        </option>
                    ))}
                </select>
            </div>

            <section className="overflow-x-auto rounded-2xl border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <table className="min-w-full text-left text-sm">
                    <thead className="bg-gray-50 dark:bg-slate-800/60">
                        <tr>
                            <th className="px-3 py-2 font-medium text-gray-600 dark:text-slate-400">{t('apiManagement.method')}</th>
                            <th className="px-3 py-2 font-medium text-gray-600 dark:text-slate-400">{t('apiManagement.endpoint')}</th>
                            <th className="px-3 py-2 font-medium text-gray-600 dark:text-slate-400">{t('apiManagement.routeName')}</th>
                            <th className="px-3 py-2 font-medium text-gray-600 dark:text-slate-400">{t('apiManagement.authRequired')}</th>
                            <th className="px-3 py-2 font-medium text-gray-600 dark:text-slate-400">{t('apiManagement.requiredScope')}</th>
                            <th className="px-3 py-2 font-medium text-gray-600 dark:text-slate-400">{t('apiManagement.rateLimit')}</th>
                            <th className="px-3 py-2 font-medium text-gray-600 dark:text-slate-400">{t('apiManagement.version')}</th>
                            <th className="px-3 py-2 font-medium text-gray-600 dark:text-slate-400">{t('common.status')}</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                        {visible.length === 0 ? (
                            <tr>
                                <td colSpan={8} className="px-3 py-10 text-center text-sm text-gray-500 dark:text-slate-400">
                                    {t('apiManagement.noEndpoints')}
                                </td>
                            </tr>
                        ) : (
                            visible.map((endpoint) => (
                                <tr key={endpoint.id} className="hover:bg-gray-50 dark:hover:bg-slate-800/40">
                                    <td className="px-3 py-2">
                                        <span
                                            className={`rounded px-1.5 py-0.5 font-mono text-[11px] font-semibold ${
                                                METHOD_COLORS[endpoint.method] ??
                                                'bg-gray-100 text-gray-700 dark:bg-slate-800 dark:text-slate-300'
                                            }`}
                                        >
                                            {endpoint.method}
                                        </span>
                                    </td>
                                    <td className="px-3 py-2">
                                        <Link
                                            href={route('api-management.endpoints.show', endpoint.id)}
                                            className="font-mono text-xs text-blue-600 hover:underline dark:text-blue-400"
                                        >
                                            {endpoint.uri}
                                        </Link>
                                    </td>
                                    <td className="px-3 py-2 font-mono text-[11px] text-gray-500 dark:text-slate-400">
                                        {endpoint.route_name ?? '—'}
                                    </td>
                                    <td className="px-3 py-2 text-xs">
                                        {endpoint.auth_required ? (
                                            <span className="text-emerald-700 dark:text-emerald-400">{t('common.yes')}</span>
                                        ) : (
                                            <span className="text-red-600 dark:text-red-400">{t('common.no')}</span>
                                        )}
                                    </td>
                                    <td className="px-3 py-2">
                                        {endpoint.required_scope ? (
                                            <code className="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-[11px] text-gray-800 dark:bg-slate-800 dark:text-slate-200">
                                                {endpoint.required_scope}
                                            </code>
                                        ) : (
                                            <span className="rounded bg-amber-100 px-1.5 py-0.5 text-[11px] font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">
                                                {t('apiManagement.unmapped')}
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-3 py-2 font-mono text-[11px] text-gray-500 dark:text-slate-400">
                                        {endpoint.rate_limit ?? '—'}
                                    </td>
                                    <td className="px-3 py-2 text-xs text-gray-500 dark:text-slate-400">{endpoint.version ?? '—'}</td>
                                    <td className="px-3 py-2">
                                        <span
                                            className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${
                                                STATUS_COLORS[endpoint.status] ?? STATUS_COLORS.disabled
                                            }`}
                                        >
                                            {t(`apiManagement.status_${endpoint.status}`)}
                                        </span>
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </section>
        </AuthenticatedLayout>
    );
}
