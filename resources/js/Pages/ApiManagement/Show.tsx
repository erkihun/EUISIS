import { FormEvent, useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import EndpointAssignment, { AssignableEndpoint } from '@/Components/apiManagement/EndpointAssignment';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';

type TokenRow = {
    id: number | string;
    name: string;
    abilities: string[] | null;
    last_used_at: string | null;
    created_at: string | null;
};

interface Props {
    application: {
        id: string;
        name: string;
        code: string;
        owner_institution: string | null;
        contact_person: string | null;
        contact_email: string | null;
        callback_url: string | null;
        status: string;
        allowed_scopes: string[];
        rate_limit_per_minute: number;
        allowed_ips: string[];
        last_used_at: string | null;
    };
    tokens: TokenRow[];
    scopes: { value: string; label: string }[];
    assignableEndpoints: AssignableEndpoint[];
    assignedEndpoints: AssignedEndpoint[];
    can: { update: boolean; delete?: boolean; createTokens: boolean; revokeTokens: boolean };
}

interface AssignedEndpoint {
    id: string;
    method: string;
    uri: string;
    required_scope: string | null;
    version: string | null;
    status: string;
    is_enabled: boolean;
    last_used_at: string | null;
}

export default function ApiManagementShow({
    application,
    tokens,
    scopes,
    assignableEndpoints,
    assignedEndpoints,
    can,
}: Props) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const [editing, setEditing] = useState(false);
    const [copied, setCopied] = useState(false);
    const flash = (usePage().props as { flash?: { generated_token?: string } }).flash;

    const form = useForm({
        name: application.name,
        code: application.code,
        owner_institution: application.owner_institution ?? '',
        contact_person: application.contact_person ?? '',
        contact_email: application.contact_email ?? '',
        callback_url: application.callback_url ?? '',
        status: application.status,
        allowed_scopes: application.allowed_scopes,
        rate_limit_per_minute: application.rate_limit_per_minute,
        allowed_ips: application.allowed_ips,
        // Preloaded so opening the edit form does not clear existing
        // assignments — sync() would otherwise detach every one of them.
        endpoint_ids: assignedEndpoints.map((endpoint) => endpoint.id),
    });

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
        form.patch(route('api-management.update', application.id), { onSuccess: () => setEditing(false) });
    }

    /** Deleting also revokes every issued token, so confirm explicitly. */
    async function handleDelete() {
        const { confirmed } = await confirm({
            title: t('apiManagement.deleteApplication'),
            description: t('apiManagement.deleteWarning'),
            confirmLabel: t('common.delete'),
            cancelLabel: t('common.cancel'),
            variant: 'danger',
        });

        if (confirmed) {
            router.delete(route('api-management.destroy', application.id));
        }
    }

    async function handleRevoke(tokenId: number | string) {
        const { confirmed } = await confirm({
            title: t('apiManagement.revokeToken'),
            description: t('apiManagement.revokeWarning'),
            confirmLabel: t('apiManagement.revokeToken'),
            cancelLabel: t('common.cancel'),
            variant: 'danger',
        });

        if (confirmed) {
            router.delete(route('api-management.tokens.destroy', [application.id, tokenId]));
        }
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={application.name} description={application.code} />}>
            <Head title={application.name} />

            {/* The plaintext token exists only in this one response. */}
            {flash?.generated_token && (
                <div className="mb-4 rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/40">
                    <p className="text-sm font-semibold text-amber-900 dark:text-amber-200">{t('apiManagement.copyTokenNow')}</p>
                    <div className="mt-2 flex flex-wrap items-center gap-2">
                        <code className="min-w-0 flex-1 break-all rounded-lg bg-white px-3 py-2 font-mono text-xs text-gray-900 dark:bg-slate-950 dark:text-slate-100">
                            {flash.generated_token}
                        </code>
                        <button
                            type="button"
                            onClick={() => {
                                void navigator.clipboard?.writeText(flash.generated_token ?? '');
                                setCopied(true);
                                window.setTimeout(() => setCopied(false), 2000);
                            }}
                            className="shrink-0 rounded-lg bg-amber-600 px-3 py-2 text-xs font-semibold text-white hover:bg-amber-700"
                        >
                            {copied ? t('common.copied') : t('common.copy')}
                        </button>
                    </div>
                    <p className="mt-2 text-xs text-amber-800 dark:text-amber-300">
                        {t('apiManagement.tokenEnvHint')}
                    </p>
                </div>
            )}

            <div className="mb-4">
                <Link href={route('api-management.index')} className="text-sm text-blue-600 hover:underline dark:text-blue-400">
                    &larr; {t('apiManagement.title')}
                </Link>
            </div>

            <section className="mb-6 rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <div className="mb-4 flex flex-wrap items-center justify-end gap-2">
                    {can.update && (
                        <button
                            type="button"
                            onClick={() => setEditing((value) => !value)}
                            className="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                        >
                            {editing ? t('common.cancel') : t('common.edit')}
                        </button>
                    )}
                    {can.delete && (
                        <button
                            type="button"
                            onClick={handleDelete}
                            className="rounded-lg border border-red-300 px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-50 dark:border-red-800 dark:text-red-400 dark:hover:bg-red-950/40"
                        >
                            {t('common.delete')}
                        </button>
                    )}
                </div>

                {editing && can.update ? (
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            <input className={inputCls} placeholder={t('apiManagement.name')} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                            <input className={inputCls} placeholder={t('apiManagement.code')} value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} />
                            <input className={inputCls} placeholder={t('apiManagement.ownerInstitution')} value={form.data.owner_institution} onChange={(e) => form.setData('owner_institution', e.target.value)} />
                            <input className={inputCls} placeholder={t('apiManagement.contactPerson')} value={form.data.contact_person} onChange={(e) => form.setData('contact_person', e.target.value)} />
                            <input className={inputCls} type="email" placeholder={t('apiManagement.contactEmail')} value={form.data.contact_email} onChange={(e) => form.setData('contact_email', e.target.value)} />
                            <input className={inputCls} type="number" min={1} value={form.data.rate_limit_per_minute} onChange={(e) => form.setData('rate_limit_per_minute', Number(e.target.value))} />
                            <select className={inputCls} value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                                <option value="active">{t('common.active')}</option>
                                <option value="suspended">{t('common.suspended')}</option>
                                <option value="revoked">{t('common.revoked')}</option>
                            </select>
                            <input
                                className={`${inputCls} sm:col-span-2`}
                                placeholder={t('apiManagement.ipAllowlistHint')}
                                value={form.data.allowed_ips.join(', ')}
                                onChange={(e) => form.setData(
                                    'allowed_ips',
                                    e.target.value.split(',').map((ip) => ip.trim()).filter(Boolean),
                                )}
                            />
                        </div>

                        <fieldset>
                            <legend className="mb-2 text-xs font-semibold text-gray-600 dark:text-slate-400">{t('apiManagement.apiScopes')}</legend>
                            <div className="flex flex-wrap gap-3">
                                {scopes.map((scope) => (
                                    <label key={scope.value} className="flex items-center gap-1.5 text-xs text-gray-700 dark:text-slate-300">
                                        <input type="checkbox" checked={form.data.allowed_scopes.includes(scope.value)} onChange={() => toggleScope(scope.value)} />
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
                        </fieldset>

                        {Object.keys(form.errors).length > 0 && (
                            <p className="text-xs text-red-600">{Object.values(form.errors).join(' ')}</p>
                        )}

                        <p className="text-xs text-gray-500 dark:text-slate-400">{t('apiManagement.scopeChangeHint')}</p>

                        <button type="submit" disabled={form.processing} className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">
                            {t('common.save')}
                        </button>
                    </form>
                ) : (
                <>
                <dl className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <div>
                        <dt className="text-xs text-gray-500 dark:text-slate-400">{t('common.status')}</dt>
                        <dd className="mt-1"><StatusBadge status={application.status} label={t(`common.${application.status}`)} /></dd>
                    </div>
                    <div>
                        <dt className="text-xs text-gray-500 dark:text-slate-400">{t('apiManagement.rateLimit')}</dt>
                        <dd className="mt-1 text-sm tabular-nums text-gray-900 dark:text-slate-100">{application.rate_limit_per_minute}/min</dd>
                    </div>
                    <div>
                        <dt className="text-xs text-gray-500 dark:text-slate-400">{t('apiManagement.ipAllowlist')}</dt>
                        <dd className="mt-1 text-sm text-gray-900 dark:text-slate-100">
                            {application.allowed_ips.length > 0 ? application.allowed_ips.join(', ') : t('apiManagement.anyIp')}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-xs text-gray-500 dark:text-slate-400">{t('apiManagement.ownerInstitution')}</dt>
                        <dd className="mt-1 text-sm text-gray-900 dark:text-slate-100">{application.owner_institution ?? '—'}</dd>
                    </div>
                </dl>

                <div className="mt-4">
                    <p className="text-xs text-gray-500 dark:text-slate-400">{t('apiManagement.apiScopes')}</p>
                    <div className="mt-1 flex flex-wrap gap-1.5">
                        {application.allowed_scopes.length === 0 ? (
                            <span className="text-sm text-gray-400">—</span>
                        ) : application.allowed_scopes.map((scope) => (
                            <span key={scope} className="rounded-full bg-blue-100 px-2 py-0.5 font-mono text-[10px] text-blue-800 dark:bg-blue-900/40 dark:text-blue-200">
                                {scope}
                            </span>
                        ))}
                    </div>
                </div>
                </>
                )}
            </section>

            <section className="mb-6 rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h3 className="mb-1 font-semibold text-gray-900 dark:text-slate-100">
                    {t('apiManagement.assignedEndpoints')}
                </h3>

                {assignedEndpoints.length === 0 ? (
                    <p className="py-6 text-center text-sm text-amber-700 dark:text-amber-400">
                        {t('apiManagement.noAssignedEndpoints')}
                    </p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-left text-sm">
                            <thead>
                                <tr className="border-b border-gray-200 dark:border-slate-800">
                                    <th className="px-2 py-2 font-medium text-gray-600 dark:text-slate-400">{t('apiManagement.method')}</th>
                                    <th className="px-2 py-2 font-medium text-gray-600 dark:text-slate-400">{t('apiManagement.endpoint')}</th>
                                    <th className="px-2 py-2 font-medium text-gray-600 dark:text-slate-400">{t('apiManagement.requiredScope')}</th>
                                    <th className="px-2 py-2 font-medium text-gray-600 dark:text-slate-400">{t('common.status')}</th>
                                    <th className="px-2 py-2 font-medium text-gray-600 dark:text-slate-400">{t('apiManagement.lastUsed')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                                {assignedEndpoints.map((endpoint) => (
                                    <tr key={endpoint.id}>
                                        <td className="px-2 py-2">
                                            <code className="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-[11px] font-semibold text-gray-800 dark:bg-slate-800 dark:text-slate-200">
                                                {endpoint.method}
                                            </code>
                                        </td>
                                        <td className="px-2 py-2 font-mono text-xs text-gray-800 dark:text-slate-200">
                                            {endpoint.uri}
                                        </td>
                                        <td className="px-2 py-2 font-mono text-[11px] text-gray-600 dark:text-slate-400">
                                            {endpoint.required_scope ?? '—'}
                                        </td>
                                        <td className="px-2 py-2 text-xs text-gray-600 dark:text-slate-400">
                                            {t(`apiManagement.status_${endpoint.status}`)}
                                        </td>
                                        <td className="px-2 py-2 text-xs text-gray-500 dark:text-slate-400">
                                            {endpoint.last_used_at ?? '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>

            <section className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h3 className="font-semibold text-gray-900 dark:text-slate-100">{t('apiManagement.apiTokens')}</h3>
                    {can.createTokens && (
                        <button
                            type="button"
                            onClick={() => router.post(route('api-management.tokens.store', application.id), { name: application.code })}
                            className="rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700"
                        >
                            {t('apiManagement.generateToken')}
                        </button>
                    )}
                </div>

                <div className="overflow-x-auto rounded-xl border border-gray-100 dark:border-slate-800">
                    <table className="min-w-full text-left text-sm">
                        <thead className="bg-gray-50 dark:bg-slate-950">
                            <tr>
                                <th className="px-4 py-2 font-medium text-gray-600 dark:text-slate-400">{t('apiManagement.name')}</th>
                                <th className="px-4 py-2 font-medium text-gray-600 dark:text-slate-400">{t('apiManagement.apiScopes')}</th>
                                <th className="px-4 py-2 font-medium text-gray-600 dark:text-slate-400">{t('apiManagement.lastUsed')}</th>
                                <th className="px-4 py-2" />
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                            {tokens.length === 0 ? (
                                <tr><td colSpan={4} className="px-4 py-6 text-center text-gray-500 dark:text-slate-400">{t('apiManagement.noTokens')}</td></tr>
                            ) : tokens.map((token) => (
                                <tr key={token.id}>
                                    <td className="px-4 py-2 text-gray-800 dark:text-slate-200">{token.name}</td>
                                    <td className="px-4 py-2 font-mono text-[10px] text-gray-500 dark:text-slate-400">{(token.abilities ?? []).join(', ')}</td>
                                    <td className="px-4 py-2 text-xs text-gray-500 dark:text-slate-400">{token.last_used_at ?? '—'}</td>
                                    <td className="px-4 py-2 text-right">
                                        {can.revokeTokens && (
                                            <button
                                                type="button"
                                                onClick={() => handleRevoke(token.id)}
                                                className="text-xs font-medium text-red-600 hover:underline dark:text-red-400"
                                            >
                                                {t('apiManagement.revokeToken')}
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </section>
        </AuthenticatedLayout>
    );
}
