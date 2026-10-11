import { FormEvent } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { useLocale } from '@/hooks/useLocale';

import { actionLinkClassName, buttonClassName, cx, inputClassName, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName } from '@/Components/ui';
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

const inputCls = cx(inputClassName, 'w-full');

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
                <Link href={route('api-management.endpoints')} className={actionLinkClassName({ size: 'sm' })}>
                    &larr; {t('apiManagement.endpointCatalog')}
                </Link>
            </div>

            <section className="mb-6 rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5">
                <h3 className="mb-3 font-semibold text-[color:var(--app-foreground)]">{t('apiManagement.endpointSummary')}</h3>
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
                    <p className="mb-1 text-xs font-medium text-[color:var(--app-muted-foreground)]">{t('apiManagement.middleware')}</p>
                    <div className="flex flex-wrap gap-1.5">
                        {endpoint.middleware.map((entry) => (
                            <code
                                key={entry}
                                className="rounded bg-[color:var(--app-surface-muted)] px-1.5 py-0.5 font-mono text-[11px] text-[color:var(--app-foreground)]"
                            >
                                {entry}
                            </code>
                        ))}
                    </div>
                </div>

                {endpoint.description && (
                    <p className="mt-4 text-sm text-[color:var(--app-muted-foreground)]">{endpoint.description}</p>
                )}
            </section>

            <div className="mb-6 grid gap-6 lg:grid-cols-2">
                <section className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5">
                    <h3 className="mb-3 font-semibold text-[color:var(--app-foreground)]">{t('apiManagement.sampleRequest')}</h3>
                    <pre className="overflow-x-auto rounded-lg bg-[color:var(--app-surface-muted)] p-3 text-xs leading-relaxed text-[color:var(--app-foreground)]">
                        {sampleRequest}
                    </pre>
                    <p className="mt-2 text-xs text-[color:var(--app-muted-foreground)]">{t('apiManagement.sampleTokenNote')}</p>
                </section>

                <section className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5">
                    <h3 className="mb-3 font-semibold text-[color:var(--app-foreground)]">{t('apiManagement.commonErrors')}</h3>
                    <ul className="space-y-2">
                        {ERROR_CODES.map((error) => (
                            <li key={error.code} className="flex gap-3 text-sm">
                                <code className="h-fit rounded bg-red-100 px-1.5 py-0.5 font-mono text-[11px] font-semibold text-red-700 dark:bg-red-900/40 dark:text-red-300">
                                    {error.code}
                                </code>
                                <span className="text-[color:var(--app-muted-foreground)]">{t(`apiManagement.${error.key}`)}</span>
                            </li>
                        ))}
                    </ul>
                </section>
            </div>

            {can.update && (
                <section className="mb-6 rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5">
                    <h3 className="mb-1 font-semibold text-[color:var(--app-foreground)]">{t('apiManagement.updateEndpoint')}</h3>
                    <p className="mb-4 text-xs text-[color:var(--app-muted-foreground)]">{t('apiManagement.updateEndpointHint')}</p>

                    <form onSubmit={submit} className="space-y-3">
                        <div className="grid gap-3 sm:grid-cols-2">
                            <label className="block">
                                <span className="mb-1 block text-xs font-medium text-[color:var(--app-muted-foreground)]">
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
                                <span className="mb-1 block text-xs font-medium text-[color:var(--app-muted-foreground)]">
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
                            <span className="mb-1 block text-xs font-medium text-[color:var(--app-muted-foreground)]">
                                {t('common.description')}
                            </span>
                            <textarea
                                className={inputCls}
                                rows={3}
                                value={form.data.description}
                                onChange={(event) => form.setData('description', event.target.value)}
                            />
                        </label>

                        <label className="flex items-center gap-2 text-sm text-[color:var(--app-foreground)]">
                            <input
                                type="checkbox"
                                checked={form.data.is_public_documented}
                                onChange={(event) => form.setData('is_public_documented', event.target.checked)}
                                className="rounded border-[color:var(--app-border-strong)]"
                            />
                            {t('apiManagement.showInDocumentation')}
                        </label>

                        {Object.keys(form.errors).length > 0 && (
                            <p className="text-xs text-red-600 dark:text-red-400">{Object.values(form.errors).join(' ')}</p>
                        )}

                        <button
                            type="submit"
                            disabled={form.processing}
                            className={buttonClassName({ variant: 'primary' })}
                        >
                            {t('common.save')}
                        </button>
                    </form>
                </section>
            )}

            {can.viewLogs && (
                <section className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5">
                    <h3 className="mb-3 font-semibold text-[color:var(--app-foreground)]">{t('apiManagement.recentLogs')}</h3>

                    {recentLogs.length === 0 ? (
                        <p className="py-6 text-center text-sm text-[color:var(--app-muted-foreground)]">{t('apiManagement.noLogs')}</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className={cx(tableClassName, 'min-w-full text-left')}>
                                <thead className={tableHeadClassName}>
                                    <tr>
                                        <th className={cx(tableHeaderCellClassName, 'text-left')}>
                                            {t('apiManagement.application')}
                                        </th>
                                        <th className={cx(tableHeaderCellClassName, 'text-left')}>
                                            {t('apiManagement.statusCode')}
                                        </th>
                                        <th className={cx(tableHeaderCellClassName, 'text-left')}>
                                            {t('apiManagement.failureReason')}
                                        </th>
                                        <th className={cx(tableHeaderCellClassName, 'text-left')}>
                                            {t('apiManagement.requestedAt')}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className={tableBodyClassName}>
                                    {recentLogs.map((log) => (
                                        <tr key={log.id}>
                                            <td className={cx(tableCellClassName, 'text-[color:var(--app-foreground)]')}>
                                                {log.application?.name ?? '—'}
                                            </td>
                                            <td className={tableCellClassName}>
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
                                            <td className={cx(tableCellClassName, 'text-xs text-[color:var(--app-muted-foreground)]')}>
                                                {log.failure_reason ?? '—'}
                                            </td>
                                            <td className={cx(tableCellClassName, 'text-xs text-[color:var(--app-muted-foreground)]')}>
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
            <dt className="text-xs font-medium text-[color:var(--app-muted-foreground)]">{label}</dt>
            <dd className={`text-[color:var(--app-foreground)] ${mono ? 'font-mono text-xs' : 'text-sm'}`}>
                {value ?? '—'}
            </dd>
        </div>
    );
}
