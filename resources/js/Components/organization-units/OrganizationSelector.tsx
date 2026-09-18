import { useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import StatusBadge from '@/Components/StatusBadge';
import { Building2, SearchIcon } from '@/Components/Icons';
import type { OrganizationSummary } from '@/types/organizationUnit';

interface Props {
    organizations: OrganizationSummary[];
    onSelect: (org: OrganizationSummary) => void;
}

export default function OrganizationSelector({ organizations, onSelect }: Props) {
    const { t } = useLocale();
    const [search, setSearch] = useState('');

    const filtered = search.trim()
        ? organizations.filter(
              (o) =>
                  o.name_en.toLowerCase().includes(search.toLowerCase()) ||
                  (o.name_am ?? '').includes(search) ||
                  o.code.toLowerCase().includes(search.toLowerCase()),
          )
        : organizations;

    return (
        <div className="space-y-4">
            <div className="relative">
                <SearchIcon className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400 dark:text-slate-500" />
                <input
                    type="text"
                    className="w-full rounded-lg border border-gray-300 bg-white py-2 pl-9 pr-3 text-sm text-gray-900 placeholder-gray-400 focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[color:var(--color-primary)] dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder-slate-500"
                    placeholder={t('organizationUnits.searchOrganizations')}
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                />
            </div>

            {filtered.length === 0 ? (
                <div className="flex flex-col items-center justify-center rounded-panel border border-dashed border-gray-200 bg-white px-6 py-12 text-center dark:border-slate-700 dark:bg-slate-900">
                    <Building2 className="h-10 w-10 text-gray-300 dark:text-slate-600" />
                    <p className="mt-3 text-sm text-gray-500 dark:text-slate-400">
                        {t('organizationUnits.noOrganizationUnitsFound')}
                    </p>
                </div>
            ) : (
                <div className="overflow-hidden rounded-panel border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <table className="w-full text-sm">
                        <thead className="border-b border-gray-100 bg-gray-50 dark:border-slate-800 dark:bg-slate-800/50">
                            <tr>
                                <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-slate-400">
                                    {t('common.code')}
                                </th>
                                <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-slate-400">
                                    {t('common.name')}
                                </th>
                                <th className="hidden px-4 py-3 text-left text-xs font-semibold text-gray-500 md:table-cell dark:text-slate-400">
                                    {t('organizations.organizationType') || 'Type'}
                                </th>
                                <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-slate-400">
                                    {t('common.status')}
                                </th>
                                <th className="px-4 py-3 text-right text-xs font-semibold text-gray-500 dark:text-slate-400">
                                    {t('organizationUnits.unitCount')}
                                </th>
                                <th className="px-4 py-3" />
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                            {filtered.map((org) => (
                                <tr
                                    key={org.id}
                                    className="cursor-pointer text-gray-700 transition-colors hover:bg-blue-50 dark:text-slate-200 dark:hover:bg-blue-900/10"
                                    onClick={() => onSelect(org)}
                                >
                                    <td className="px-4 py-3 font-mono text-xs text-gray-500 dark:text-slate-400">
                                        {org.code}
                                    </td>
                                    <td className="px-4 py-3">
                                        <div className="flex items-center gap-2">
                                            {org.has_logo && org.logo_url ? (
                                                <img
                                                    src={org.logo_url}
                                                    alt=""
                                                    className="h-7 w-7 rounded-full object-cover"
                                                />
                                            ) : (
                                                <span className="flex h-7 w-7 items-center justify-center rounded-full bg-blue-100 text-xs font-semibold text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">
                                                    {org.name_en.charAt(0).toUpperCase()}
                                                </span>
                                            )}
                                            <div>
                                                <p className="font-medium">{org.name_en}</p>
                                                {org.name_am && (
                                                    <p className="text-xs text-gray-400 dark:text-slate-500">{org.name_am}</p>
                                                )}
                                            </div>
                                        </div>
                                    </td>
                                    <td className="hidden px-4 py-3 text-sm text-gray-500 md:table-cell dark:text-slate-400">
                                        {org.type?.name_en ?? '—'}
                                    </td>
                                    <td className="px-4 py-3">
                                        <StatusBadge status={org.status} />
                                    </td>
                                    <td className="px-4 py-3 text-right text-sm font-medium text-gray-600 dark:text-slate-300">
                                        {org.organization_units_count ?? 0}
                                    </td>
                                    <td className="py-3 pl-2 pr-4">
                                        <button
                                            type="button"
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                onSelect(org);
                                            }}
                                            className="rounded-lg bg-[color:var(--color-primary)] px-3 py-1.5 text-xs font-medium text-white transition-colors hover:bg-[color:var(--color-primary-hover)]"
                                        >
                                            {t('organizationUnits.viewUnits')}
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}
