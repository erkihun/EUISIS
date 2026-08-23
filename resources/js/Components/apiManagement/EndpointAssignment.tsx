import { useMemo, useState } from 'react';
import { useLocale } from '@/hooks/useLocale';

export interface AssignableEndpoint {
    id: string;
    method: string;
    uri: string;
    route_name: string | null;
    required_scope: string | null;
    version: string | null;
    description: string | null;
    status: string;
    group: string;
}

interface Props {
    endpoints: AssignableEndpoint[];
    selected: string[];
    onChange: (ids: string[]) => void;
}

const METHOD_COLORS: Record<string, string> = {
    GET: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
    POST: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
    PATCH: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    PUT: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    DELETE: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
};

const filterCls =
    'rounded-lg border border-gray-300 px-2 py-1.5 text-xs dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100';

/**
 * Endpoint picker shared by the create and edit forms.
 *
 * Only endpoints the server considers assignable (active + documented) are
 * ever passed in; this component does not decide eligibility, it only presents
 * what it is given. The server re-validates every submitted id regardless.
 */
export default function EndpointAssignment({ endpoints, selected, onChange }: Props) {
    const { t } = useLocale();
    const [search, setSearch] = useState('');
    const [scopeFilter, setScopeFilter] = useState('');
    const [versionFilter, setVersionFilter] = useState('');

    const scopes = useMemo(
        () => [...new Set(endpoints.map((endpoint) => endpoint.required_scope).filter(Boolean))] as string[],
        [endpoints],
    );
    const versions = useMemo(
        () => [...new Set(endpoints.map((endpoint) => endpoint.version).filter(Boolean))] as string[],
        [endpoints],
    );

    const visible = useMemo(
        () =>
            endpoints.filter((endpoint) => {
                const haystack = `${endpoint.method} ${endpoint.uri} ${endpoint.route_name ?? ''}`.toLowerCase();

                return (
                    haystack.includes(search.toLowerCase()) &&
                    (scopeFilter === '' || endpoint.required_scope === scopeFilter) &&
                    (versionFilter === '' || endpoint.version === versionFilter)
                );
            }),
        [endpoints, search, scopeFilter, versionFilter],
    );

    // Grouped for display; the group header's "select all" acts on the
    // currently visible rows only, so it never silently selects filtered-out
    // endpoints the administrator cannot see.
    const grouped = useMemo(() => {
        const map = new Map<string, AssignableEndpoint[]>();

        visible.forEach((endpoint) => {
            map.set(endpoint.group, [...(map.get(endpoint.group) ?? []), endpoint]);
        });

        return [...map.entries()];
    }, [visible]);

    function toggle(id: string) {
        onChange(selected.includes(id) ? selected.filter((entry) => entry !== id) : [...selected, id]);
    }

    function selectGroup(rows: AssignableEndpoint[]) {
        onChange([...new Set([...selected, ...rows.map((row) => row.id)])]);
    }

    function clearGroup(rows: AssignableEndpoint[]) {
        const ids = new Set(rows.map((row) => row.id));
        onChange(selected.filter((entry) => !ids.has(entry)));
    }

    if (endpoints.length === 0) {
        return (
            <div className="rounded-lg border border-dashed border-gray-300 p-4 text-center text-xs text-gray-500 dark:border-slate-700 dark:text-slate-400">
                {t('apiManagement.noAssignableEndpoints')}
            </div>
        );
    }

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-center gap-2">
                <input
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder={t('apiManagement.searchEndpoints')}
                    className={`${filterCls} w-48`}
                />
                <select value={scopeFilter} onChange={(event) => setScopeFilter(event.target.value)} className={filterCls}>
                    <option value="">{t('apiManagement.allScopes')}</option>
                    {scopes.map((scope) => (
                        <option key={scope} value={scope}>
                            {scope}
                        </option>
                    ))}
                </select>
                <select value={versionFilter} onChange={(event) => setVersionFilter(event.target.value)} className={filterCls}>
                    <option value="">{t('apiManagement.allVersions')}</option>
                    {versions.map((version) => (
                        <option key={version} value={version}>
                            {version}
                        </option>
                    ))}
                </select>

                <span className="ml-auto text-xs text-gray-500 dark:text-slate-400">
                    {selected.length} / {endpoints.length}
                </span>
                <button
                    type="button"
                    onClick={() => onChange([])}
                    className="text-xs font-medium text-blue-600 hover:underline dark:text-blue-400"
                >
                    {t('apiManagement.clearSelection')}
                </button>
            </div>

            {grouped.length === 0 ? (
                <p className="py-4 text-center text-xs text-gray-500 dark:text-slate-400">
                    {t('apiManagement.noEndpointsMatch')}
                </p>
            ) : (
                <div className="max-h-96 space-y-4 overflow-y-auto rounded-lg border border-gray-200 p-3 dark:border-slate-800">
                    {grouped.map(([group, rows]) => (
                        <div key={group}>
                            <div className="mb-1 flex items-center gap-2">
                                <h4 className="text-xs font-semibold text-gray-700 dark:text-slate-300">
                                    {t(`apiManagement.group_${group}`)}
                                </h4>
                                <button
                                    type="button"
                                    onClick={() => selectGroup(rows)}
                                    className="text-[11px] font-medium text-blue-600 hover:underline dark:text-blue-400"
                                >
                                    {t('apiManagement.selectAllInGroup')}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => clearGroup(rows)}
                                    className="text-[11px] font-medium text-gray-500 hover:underline dark:text-slate-400"
                                >
                                    {t('apiManagement.clearSelection')}
                                </button>
                            </div>

                            <ul className="space-y-1">
                                {rows.map((endpoint) => (
                                    <li key={endpoint.id}>
                                        <label className="flex cursor-pointer items-start gap-2 rounded-lg px-2 py-1.5 hover:bg-gray-50 dark:hover:bg-slate-800/50">
                                            <input
                                                type="checkbox"
                                                checked={selected.includes(endpoint.id)}
                                                onChange={() => toggle(endpoint.id)}
                                                className="mt-0.5 rounded border-gray-300 dark:border-slate-700"
                                            />
                                            <span className="min-w-0 flex-1">
                                                <span className="flex flex-wrap items-center gap-1.5">
                                                    <span
                                                        className={`rounded px-1.5 py-0.5 font-mono text-[10px] font-semibold ${
                                                            METHOD_COLORS[endpoint.method] ??
                                                            'bg-gray-100 text-gray-700 dark:bg-slate-800 dark:text-slate-300'
                                                        }`}
                                                    >
                                                        {endpoint.method}
                                                    </span>
                                                    <code className="font-mono text-xs text-gray-800 dark:text-slate-200">
                                                        {endpoint.uri}
                                                    </code>
                                                    {endpoint.required_scope && (
                                                        <code className="rounded bg-gray-100 px-1 py-0.5 font-mono text-[10px] text-gray-600 dark:bg-slate-800 dark:text-slate-400">
                                                            {endpoint.required_scope}
                                                        </code>
                                                    )}
                                                    {endpoint.version && (
                                                        <span className="text-[10px] text-gray-400 dark:text-slate-500">
                                                            {endpoint.version}
                                                        </span>
                                                    )}
                                                </span>
                                                {endpoint.description && (
                                                    <span className="mt-0.5 block text-[11px] text-gray-500 dark:text-slate-400">
                                                        {endpoint.description}
                                                    </span>
                                                )}
                                            </span>
                                        </label>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </div>
            )}

            <p className="text-[11px] text-gray-500 dark:text-slate-400">{t('apiManagement.endpointScopeAutoHint')}</p>
        </div>
    );
}
