import { FormEvent, useMemo, useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import EndpointAssignment, { AssignableEndpoint } from '@/Components/apiManagement/EndpointAssignment';
import { useLocale } from '@/hooks/useLocale';

type ScopeOption = { value: string; label: string };

type ApplicationRow = {
    id: string;
    name: string;
    code: string;
    owner_institution: string | null;
    status: string;
    allowed_scopes: string[];
    rate_limit_per_minute: number;
    tokens_count: number;
    last_used_at: string | null;
};

interface Props {
    applications: ApplicationRow[];
    scopes: ScopeOption[];
    assignableEndpoints: AssignableEndpoint[];
    endpoints_count: number;
    can: {
        create: boolean;
        update: boolean;
        delete: boolean;
        createTokens: boolean;
        revokeTokens: boolean;
        viewLogs: boolean;
        viewDocs: boolean;
        viewEndpoints: boolean;
    };
}

/** Navigation/summary tile for the API Management dashboard. */
function DashboardCard({
    title,
    value,
    hint,
    href,
}: {
    title: string;
    value: string;
    hint: string;
    href?: string;
}) {
    const body = (
        <>
            <p className="text-xs font-medium text-gray-500 dark:text-slate-400">{title}</p>
            <p className="mt-1 text-2xl font-semibold text-gray-900 dark:text-slate-100">{value}</p>
            <p className="mt-1 text-[11px] leading-snug text-gray-500 dark:text-slate-400">{hint}</p>
        </>
    );

    const className =
        'block rounded-2xl border border-gray-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900';

    return href ? (
        <Link href={href} className={`${className} transition hover:border-blue-400 hover:shadow-sm`}>
            {body}
        </Link>
    ) : (
        <div className={className}>{body}</div>
    );
}

export default function ApiManagementIndex({
    applications,
    scopes,
    assignableEndpoints,
    endpoints_count,
    can,
}: Props) {
    const { t } = useLocale();
    const [showForm, setShowForm] = useState(false);
    const flash = (usePage().props as { flash?: { generated_token?: string } }).flash;

    const form = useForm({
        name: '',
        code: '',
        owner_institution: '',
        contact_person: '',
        contact_email: '',
        callback_url: '',
        status: 'active',
        allowed_scopes: [] as string[],
        rate_limit_per_minute: 60,
        allowed_ips: [] as string[],
        endpoint_ids: [] as string[],
    });

    // Scopes the server will add automatically because a selected endpoint
    // asserts them. Surfaced so the saved scope list is never a surprise.
    const autoScopes = useMemo(() => {
        const required = new Set(
            assignableEndpoints
                .filter((endpoint) => form.data.endpoint_ids.includes(endpoint.id))
                .map((endpoint) => endpoint.required_scope)
                .filter(Boolean) as string[],
        );

        return [...required].filter((scope) => !form.data.allowed_scopes.includes(scope));
    }, [assignableEndpoints, form.data.endpoint_ids, form.data.allowed_scopes]);

    const inputCls =
        'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

    function toggleScope(scope: string) {
        form.setData(
            'allowed_scopes',
            form.data.allowed_scopes.includes(scope)
                ? form.data.allowed_scopes.filter((value) => value !== scope)
                : [...form.data.allowed_scopes, scope],
        );
    }

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(route('api-management.store'), { onSuccess: () => setShowForm(false) });
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={t('apiManagement.title')} description={t('apiManagement.description')} />}>
            <Head title={t('apiManagement.title')} />

            {/* Shown exactly once, immediately after generation. */}
            {flash?.generated_token && (
                <div className="mb-4 rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/40">
                    <p className="text-sm font-semibold text-amber-900 dark:text-amber-200">{t('apiManagement.copyTokenNow')}</p>
                    <code className="mt-2 block break-all rounded-lg bg-white px-3 py-2 font-mono text-xs text-gray-900 dark:bg-slate-950 dark:text-slate-100">
                        {flash.generated_token}
                    </code>
                </div>
            )}

            <div className="mb-4">
                <Link href={route('system-settings.index')} className="text-sm text-blue-600 hover:underline dark:text-blue-400">
                    &larr; {t('settings.title')}
                </Link>
            </div>

            {/*
              * Dashboard cards for the API Management areas. Rate limits, IP
              * allowlist and scopes are configured per application, so those
              * cards deep-link to where that configuration actually lives
              * rather than to a page that would duplicate it.
              */}
            <div className="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <DashboardCard
                    title={t('apiManagement.externalApplications')}
                    value={String(applications.length)}
                    hint={t('apiManagement.externalApplicationsHint')}
                />
                <DashboardCard
                    title={t('apiManagement.apiTokens')}
                    value={String(applications.reduce((total, application) => total + application.tokens_count, 0))}
                    hint={t('apiManagement.apiTokensHint')}
                />
                {can.viewEndpoints && (
                    <DashboardCard
                        title={t('apiManagement.apiEndpoints')}
                        value={String(endpoints_count)}
                        hint={t('apiManagement.apiEndpointsHint')}
                        href={route('api-management.endpoints')}
                    />
                )}
                {can.viewDocs && (
                    <DashboardCard
                        title={t('apiManagement.apiScopes')}
                        value={String(scopes.length)}
                        hint={t('apiManagement.apiScopesHint')}
                        href={route('api-management.docs')}
                    />
                )}
                <DashboardCard
                    title={t('apiManagement.rateLimits')}
                    value="—"
                    hint={t('apiManagement.rateLimitsHint')}
                />
                <DashboardCard
                    title={t('apiManagement.ipAllowlist')}
                    value="—"
                    hint={t('apiManagement.ipAllowlistCardHint')}
                />
                {can.viewLogs && (
                    <DashboardCard
                        title={t('apiManagement.apiLogs')}
                        value="—"
                        hint={t('apiManagement.apiLogsHint')}
                        href={route('api-management.logs')}
                    />
                )}
                {can.viewDocs && (
                    <DashboardCard
                        title={t('apiManagement.apiDocumentation')}
                        value="—"
                        hint={t('apiManagement.apiDocumentationHint')}
                        href={route('api-management.docs')}
                    />
                )}
            </div>

            <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
                <div className="flex flex-wrap gap-2">
                    {can.viewEndpoints && (
                        <Link href={route('api-management.endpoints')} className="rounded-lg border border-gray-300 px-3 py-1.5 text-sm dark:border-slate-700 dark:text-slate-200">
                            {t('apiManagement.apiEndpoints')}
                        </Link>
                    )}
                    {can.viewLogs && (
                        <Link href={route('api-management.logs')} className="rounded-lg border border-gray-300 px-3 py-1.5 text-sm dark:border-slate-700 dark:text-slate-200">
                            {t('apiManagement.apiLogs')}
                        </Link>
                    )}
                    {can.viewDocs && (
                        <Link href={route('api-management.docs')} className="rounded-lg border border-gray-300 px-3 py-1.5 text-sm dark:border-slate-700 dark:text-slate-200">
                            {t('apiManagement.apiDocumentation')}
                        </Link>
                    )}
                </div>
                {can.create && (
                    <button
                        type="button"
                        onClick={() => setShowForm((value) => !value)}
                        className="rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700"
                    >
                        {t('apiManagement.newApplication')}
                    </button>
                )}
            </div>

            {showForm && can.create && (
                <form onSubmit={submit} className="mb-6 space-y-4 rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        <input className={inputCls} placeholder={t('apiManagement.name')} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                        <input className={inputCls} placeholder={t('apiManagement.code')} value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} />
                        <input className={inputCls} placeholder={t('apiManagement.ownerInstitution')} value={form.data.owner_institution} onChange={(e) => form.setData('owner_institution', e.target.value)} />
                        <input className={inputCls} placeholder={t('apiManagement.contactPerson')} value={form.data.contact_person} onChange={(e) => form.setData('contact_person', e.target.value)} />
                        <input className={inputCls} type="email" placeholder={t('apiManagement.contactEmail')} value={form.data.contact_email} onChange={(e) => form.setData('contact_email', e.target.value)} />
                        <input
                            className={inputCls}
                            type="number"
                            min={1}
                            placeholder={t('apiManagement.rateLimit')}
                            value={form.data.rate_limit_per_minute}
                            onChange={(e) => form.setData('rate_limit_per_minute', Number(e.target.value))}
                        />
                    </div>

                    <fieldset>
                        <legend className="mb-2 text-xs font-semibold text-gray-600 dark:text-slate-400">{t('apiManagement.apiScopes')}</legend>
                        <div className="flex flex-wrap gap-3">
                            {scopes.map((scope) => (
                                <label key={scope.value} className="flex items-center gap-1.5 text-xs text-gray-700 dark:text-slate-300">
                                    <input
                                        type="checkbox"
                                        checked={form.data.allowed_scopes.includes(scope.value)}
                                        onChange={() => toggleScope(scope.value)}
                                    />
                                    {scope.label}
                                </label>
                            ))}
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend className="mb-1 text-xs font-semibold text-gray-600 dark:text-slate-400">
                            {t('apiManagement.endpointAssignment')}
                        </legend>
                        <p className="mb-2 text-[11px] text-gray-500 dark:text-slate-400">
                            {t('apiManagement.endpointAssignmentHint')}
                        </p>

                        <EndpointAssignment
                            endpoints={assignableEndpoints}
                            selected={form.data.endpoint_ids}
                            onChange={(ids) => form.setData('endpoint_ids', ids)}
                        />

                        {autoScopes.length > 0 && (
                            <p className="mt-2 text-[11px] text-amber-700 dark:text-amber-400">
                                {t('apiManagement.scopesAutoAdded')} {autoScopes.join(', ')}
                            </p>
                        )}
                    </fieldset>

                    {Object.keys(form.errors).length > 0 && (
                        <p className="text-xs text-red-600">{Object.values(form.errors).join(' ')}</p>
                    )}

                    <button type="submit" disabled={form.processing} className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">
                        {t('common.save')}
                    </button>
                </form>
            )}

            <section className="overflow-x-auto rounded-2xl border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <table className="min-w-full text-left text-sm">
                    <thead className="bg-gray-50 dark:bg-slate-950">
                        <tr>
                            <th className="px-4 py-2 font-medium text-gray-600 dark:text-slate-400">{t('apiManagement.name')}</th>
                            <th className="px-4 py-2 font-medium text-gray-600 dark:text-slate-400">{t('apiManagement.apiScopes')}</th>
                            <th className="px-4 py-2 font-medium text-gray-600 dark:text-slate-400">{t('apiManagement.rateLimit')}</th>
                            <th className="px-4 py-2 font-medium text-gray-600 dark:text-slate-400">{t('apiManagement.apiTokens')}</th>
                            <th className="px-4 py-2 font-medium text-gray-600 dark:text-slate-400">{t('common.status')}</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                        {applications.length === 0 ? (
                            <tr>
                                <td colSpan={5} className="px-4 py-8 text-center text-gray-500 dark:text-slate-400">
                                    {t('apiManagement.noApplications')}
                                </td>
                            </tr>
                        ) : applications.map((application) => (
                            <tr key={application.id} className="hover:bg-gray-50 dark:hover:bg-slate-800/40">
                                <td className="px-4 py-2">
                                    <Link href={route('api-management.show', application.id)} className="font-medium text-blue-600 hover:underline dark:text-blue-400">
                                        {application.name}
                                    </Link>
                                    <span className="ml-2 font-mono text-[11px] text-gray-400">{application.code}</span>
                                </td>
                                <td className="px-4 py-2 text-xs text-gray-600 dark:text-slate-400">{application.allowed_scopes.length}</td>
                                <td className="px-4 py-2 tabular-nums text-gray-700 dark:text-slate-300">{application.rate_limit_per_minute}/min</td>
                                <td className="px-4 py-2 tabular-nums text-gray-700 dark:text-slate-300">{application.tokens_count}</td>
                                <td className="px-4 py-2"><StatusBadge status={application.status} label={t(`common.${application.status}`)} /></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </section>
        </AuthenticatedLayout>
    );
}
