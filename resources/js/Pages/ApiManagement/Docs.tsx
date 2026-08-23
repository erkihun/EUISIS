import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { useLocale } from '@/hooks/useLocale';

interface Endpoint {
    id: string;
    method: string;
    uri: string;
    required_scope: string | null;
    auth_required: boolean;
    description: string | null;
    version: string | null;
}

interface Props {
    markdown: string;
    scopes: { value: string; label: string }[];
    groups: Record<string, Endpoint[]>;
}

/**
 * Functional groups, in the order they are presented. Kept explicit so the
 * documentation reads in a sensible order rather than alphabetically.
 */
const GROUP_ORDER = [
    'id_card_verification',
    'employee_verification',
    'service_eligibility',
    'service_transactions',
    'reports',
    'system_health',
    'other',
];

const METHOD_COLORS: Record<string, string> = {
    GET: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
    POST: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
    PATCH: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    PUT: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    DELETE: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
};

/**
 * Renders the integration guide shipped in docs/. Displayed as preformatted
 * text rather than parsed markdown so no markdown dependency is introduced
 * and no untrusted HTML is ever injected.
 *
 * The endpoint sections above it are read from the synced endpoint catalog, not
 * from the guide, so a newly added endpoint appears here without anyone editing
 * documentation. Only active, documented endpoints are published.
 */
export default function ApiManagementDocs({ markdown, scopes, groups }: Props) {
    const { t } = useLocale();

    const populated = GROUP_ORDER.filter((group) => (groups[group] ?? []).length > 0);

    return (
        <AuthenticatedLayout header={<PageHeader title={t('apiManagement.apiDocumentation')} />}>
            <Head title={t('apiManagement.apiDocumentation')} />

            <div className="mb-4">
                <Link href={route('api-management.index')} className="text-sm text-blue-600 hover:underline dark:text-blue-400">
                    &larr; {t('apiManagement.title')}
                </Link>
            </div>

            <section className="mb-6 rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h3 className="mb-3 font-semibold text-gray-900 dark:text-slate-100">{t('apiManagement.apiScopes')}</h3>
                <ul className="space-y-1">
                    {scopes.map((scope) => (
                        <li key={scope.value} className="flex flex-wrap items-center gap-2 text-sm">
                            <code className="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-[11px] text-gray-800 dark:bg-slate-800 dark:text-slate-200">
                                {scope.value}
                            </code>
                            <span className="text-gray-600 dark:text-slate-400">{scope.label}</span>
                        </li>
                    ))}
                </ul>
            </section>

            <section className="mb-6 rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h3 className="mb-1 font-semibold text-gray-900 dark:text-slate-100">
                    {t('apiManagement.availableEndpoints')}
                </h3>
                <p className="mb-4 text-xs text-gray-500 dark:text-slate-400">
                    {t('apiManagement.availableEndpointsHint')}
                </p>

                {populated.length === 0 ? (
                    <p className="py-8 text-center text-sm text-gray-500 dark:text-slate-400">
                        {t('apiManagement.noDocumentedEndpoints')}
                    </p>
                ) : (
                    <div className="space-y-6">
                        {populated.map((group) => (
                            <div key={group}>
                                <h4 className="mb-2 text-sm font-semibold text-gray-800 dark:text-slate-200">
                                    {t(`apiManagement.group_${group}`)}
                                </h4>

                                <div className="overflow-x-auto">
                                    <table className="min-w-full text-left text-sm">
                                        <thead>
                                            <tr className="border-b border-gray-200 dark:border-slate-800">
                                                <th className="px-2 py-2 font-medium text-gray-600 dark:text-slate-400">
                                                    {t('apiManagement.method')}
                                                </th>
                                                <th className="px-2 py-2 font-medium text-gray-600 dark:text-slate-400">
                                                    {t('apiManagement.endpoint')}
                                                </th>
                                                <th className="px-2 py-2 font-medium text-gray-600 dark:text-slate-400">
                                                    {t('apiManagement.requiredScope')}
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                                            {(groups[group] ?? []).map((endpoint) => (
                                                <tr key={endpoint.id}>
                                                    <td className="px-2 py-2 align-top">
                                                        <span
                                                            className={`rounded px-1.5 py-0.5 font-mono text-[11px] font-semibold ${
                                                                METHOD_COLORS[endpoint.method] ??
                                                                'bg-gray-100 text-gray-700 dark:bg-slate-800 dark:text-slate-300'
                                                            }`}
                                                        >
                                                            {endpoint.method}
                                                        </span>
                                                    </td>
                                                    <td className="px-2 py-2">
                                                        <code className="font-mono text-xs text-gray-800 dark:text-slate-200">
                                                            {endpoint.uri}
                                                        </code>
                                                        {endpoint.description && (
                                                            <p className="mt-0.5 text-xs text-gray-500 dark:text-slate-400">
                                                                {endpoint.description}
                                                            </p>
                                                        )}
                                                    </td>
                                                    <td className="px-2 py-2 align-top">
                                                        {endpoint.required_scope ? (
                                                            <code className="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-[11px] text-gray-800 dark:bg-slate-800 dark:text-slate-200">
                                                                {endpoint.required_scope}
                                                            </code>
                                                        ) : (
                                                            <span className="text-xs text-gray-400 dark:text-slate-500">
                                                                {t('apiManagement.unmapped')}
                                                            </span>
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </section>

            <section className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                {markdown ? (
                    <pre className="max-h-[70vh] overflow-auto whitespace-pre-wrap break-words text-xs leading-relaxed text-gray-800 dark:text-slate-200">
                        {markdown}
                    </pre>
                ) : (
                    <p className="py-8 text-center text-sm text-gray-500 dark:text-slate-400">{t('apiManagement.noDocs')}</p>
                )}
            </section>
        </AuthenticatedLayout>
    );
}
