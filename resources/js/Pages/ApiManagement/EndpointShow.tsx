import { FormEvent } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
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
    group: string;
    last_synced_at: string | null;
}

interface LogRow {
    id: string;
    application: { id: string; name: string; code: string } | null;
    method: string;
    status_code: number | null;
    success: boolean;
    failure_reason: string | null;
    requested_at: string | null;
}

interface Props {
    endpoint: Endpoint;
    sampleRequest: string;
    recentLogs: LogRow[];
    scopes: { value: string; label: string }[];
    statuses: string[];
    can: { update: boolean; viewLogs: boolean };
}

/** Errors any integration endpoint can return, with what actually causes them. */
const ERROR_CODES: { code: string; key: string }[] = [
    { code: '401', key: 'error401' },
    { code: '403', key: 'error403' },
    { code: '404', key: 'error404' },
    { code: '422', key: 'error422' },
    { code: '429', key: 'error429' },
];

const inputCls =
    'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100';

export default function ApiManagementEndpointShow({
    endpoint,
    sampleRequest,
    recentLogs,
    scopes,
    statuses,
    can,
}: Props) {
    const { t } = useLocale();

    const form = useForm({
        required_scope: endpoint.required_scope ?? '',
        description: endpoint.description ?? '',
        status: endpoint.status,
        is_public_documented: endpoint.is_public_documented,
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.patch(route('api-management.endpoints.update', endpoint.id), { preserveScroll: true });
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={`${endpoint.method} ${endpoint.uri}`} />}>
            <Head title={endpoint.uri} />

            <div className="mb-4">
                <Link href={route('api-management.endpoints')} className="text-sm text-blue-600 hover:underline dark:text-blue-400">
                    &larr; {t('apiManagement.endpointCatalog')}
                </Link>
            </div>

            <section className="mb-6 rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h3 className="mb-3 font-semibold text-gray-900 dark:text-slate-100">{t('apiManagement.endpointSummary')}</h3>
                <dl className="grid gap-3 text-sm sm:grid-cols-2">
                    <Field label={t('apiManagement.routeName')} value={endpoint.route_name} mono />
                    <Field label={t('apiManagement.controllerAction')} value={endpoint.controller_action} mono />
                    <Field
                        label={t('apiManagement.authRequired')}
                        value={endpoint.auth_required ? t('common.yes') : t('common.no')}
                    />
                    <Field label={t('apiManagement.requiredScope')} value={endpoint.required_scope ?? t('apiManagement.unmapped')} mono />
                    <Field label={t('apiManagement.rateLimit')} value={endpoint.rate_limit} mono />
                    <Field label={t('apiManagement.version')} value={endpoint.version} />
                    <Field label={t('common.status')} value={t(`apiManagement.status_${endpoint.status}`)} />
                    <Field
                        label={t('apiManagement.documented')}
                        value={endpoint.is_public_documented ? t('common.yes') : t('common.no')}
                    />
                </dl>

                <div className="mt-4">
                    <p className="mb-1 text-xs font-medium text-gray-500 dark:text-slate-400">{t('apiManagement.middleware')}</p>
                    <div className="flex flex-wrap gap-1.5">
                        {endpoint.middleware.map((entry) => (
                            <code
                                key={entry}
                                className="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-[11px] text-gray-800 dark:bg-slate-800 dark:text-slate-200"
                            >
                                {entry}
                            </code>
                        ))}
                    </div>
                </div>

                {endpoint.description && (
                    <p className="mt-4 text-sm text-gray-600 dark:text-slate-400">{endpoint.description}</p>
                )}
            </section>

            <div className="mb-6 grid gap-6 lg:grid-cols-2">
                <section className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h3 className="mb-3 font-semibold text-gray-900 dark:text-slate-100">{t('apiManagement.sampleRequest')}</h3>
                    <pre className="overflow-x-auto rounded-lg bg-gray-50 p-3 text-xs leading-relaxed text-gray-800 dark:bg-slate-950 dark:text-slate-200">
                        {sampleRequest}
                    </pre>
                    <p className="mt-2 text-xs text-gray-500 dark:text-slate-400">{t('apiManagement.sampleTokenNote')}</p>
                </section>

                <section className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h3 className="mb-3 font-semibold text-gray-900 dark:text-slate-100">{t('apiManagement.commonErrors')}</h3>
                    <ul className="space-y-2">
                        {ERROR_CODES.map((error) => (
                            <li key={error.code} className="flex gap-3 text-sm">
                                <code className="h-fit rounded bg-red-100 px-1.5 py-0.5 font-mono text-[11px] font-semibold text-red-700 dark:bg-red-900/40 dark:text-red-300">
                                    {error.code}
                                </code>
                                <span className="text-gray-600 dark:text-slate-400">{t(`apiManagement.${error.key}`)}</span>
                            </li>
                        ))}
                    </ul>
                </section>
            </div>

            {can.update && (
                <section className="mb-6 rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h3 className="mb-1 font-semibold text-gray-900 dark:text-slate-100">{t('apiManagement.updateEndpoint')}</h3>
                    <p className="mb-4 text-xs text-gray-500 dark:text-slate-400">{t('apiManagement.updateEndpointHint')}</p>

                    <form onSubmit={submit} className="space-y-3">
                        <div className="grid gap-3 sm:grid-cols-2">
                            <label className="block">
                                <span className="mb-1 block text-xs font-medium text-gray-600 dark:text-slate-400">
                                    {t('apiManagement.requiredScope')}
                                </span>
                                <select
                                    className={inputCls}
                                    value={form.data.required_scope}
                                    onChange={(event) => form.setData('required_scope', event.target.value)}
                                >
                                    <option value="">{t('apiManagement.unmapped')}</option>
                                    {scopes.map((scope) => (
                                        <option key={scope.value} value={scope.value}>
                                            {scope.value}
                                        </option>
                                    ))}
                                </select>
                            </label>

                            <label className="block">
                                <span className="mb-1 block text-xs font-medium text-gray-600 dark:text-slate-400">
                                    {t('common.status')}
                                </span>
                                <select
                                    className={inputCls}
                                    value={form.data.status}
                                    onChange={(event) => form.setData('status', event.target.value)}
                                >
                                    {statuses.map((status) => (
                                        <option key={status} value={status}>
                                            {t(`apiManagement.status_${status}`)}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        </div>

                        <label className="block">
                            <span className="mb-1 block text-xs font-medium text-gray-600 dark:text-slate-400">
                                {t('common.description')}
                            </span>
                            <textarea
                                className={inputCls}
                                rows={3}
                                value={form.data.description}
                                onChange={(event) => form.setData('description', event.target.value)}
                            />
                        </label>

                        <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-slate-300">
                            <input
                                type="checkbox"
                                checked={form.data.is_public_documented}
                                onChange={(event) => form.setData('is_public_documented', event.target.checked)}
                                className="rounded border-gray-300 dark:border-slate-700"
                            />
                            {t('apiManagement.showInDocumentation')}
                        </label>

                        {Object.keys(form.errors).length > 0 && (
                            <p className="text-xs text-red-600 dark:text-red-400">{Object.values(form.errors).join(' ')}</p>
                        )}

                        <button
                            type="submit"
                            disabled={form.processing}
                            className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60"
                        >
                            {t('common.save')}
                        </button>
                    </form>
                </section>
            )}

            {can.viewLogs && (
                <section className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h3 className="mb-3 font-semibold text-gray-900 dark:text-slate-100">{t('apiManagement.recentLogs')}</h3>

                    {recentLogs.length === 0 ? (
                        <p className="py-6 text-center text-sm text-gray-500 dark:text-slate-400">{t('apiManagement.noLogs')}</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-left text-sm">
                                <thead>
                                    <tr className="border-b border-gray-200 dark:border-slate-800">
                                        <th className="px-2 py-2 font-medium text-gray-600 dark:text-slate-400">
                                            {t('apiManagement.application')}
                                        </th>
                                        <th className="px-2 py-2 font-medium text-gray-600 dark:text-slate-400">
                                            {t('apiManagement.statusCode')}
                                        </th>
                                        <th className="px-2 py-2 font-medium text-gray-600 dark:text-slate-400">
                                            {t('apiManagement.failureReason')}
                                        </th>
                                        <th className="px-2 py-2 font-medium text-gray-600 dark:text-slate-400">
                                            {t('apiManagement.requestedAt')}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                                    {recentLogs.map((log) => (
                                        <tr key={log.id}>
                                            <td className="px-2 py-2 text-gray-700 dark:text-slate-300">
                                                {log.application?.name ?? '—'}
                                            </td>
                                            <td className="px-2 py-2">
                                                <span
                                                    className={`font-mono text-xs font-semibold ${
                                                        log.success
                                                            ? 'text-emerald-700 dark:text-emerald-400'
                                                            : 'text-red-600 dark:text-red-400'
                                                    }`}
                                                >
                                                    {log.status_code ?? '—'}
                                                </span>
                                            </td>
                                            <td className="px-2 py-2 text-xs text-gray-500 dark:text-slate-400">
                                                {log.failure_reason ?? '—'}
                                            </td>
                                            <td className="px-2 py-2 text-xs text-gray-500 dark:text-slate-400">
                                                {log.requested_at ?? '—'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            )}
        </AuthenticatedLayout>
    );
}

function Field({ label, value, mono = false }: { label: string; value: string | null; mono?: boolean }) {
    return (
        <div>
            <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">{label}</dt>
            <dd className={`text-gray-900 dark:text-slate-100 ${mono ? 'font-mono text-xs' : 'text-sm'}`}>
                {value ?? '—'}
            </dd>
        </div>
    );
}
