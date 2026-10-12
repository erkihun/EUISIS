import { useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import StatusBadge from '@/Components/StatusBadge';
import { Building2, SearchIcon } from '@/Components/Icons';
import type { OrganizationSummary } from '@/types/organizationUnit';

import { buttonClassName, cx, inputClassName, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
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
                <SearchIcon className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[color:var(--app-muted-foreground)]" />
                <input
                    type="text"
                    className={cx(inputClassName, 'w-full pl-9 pr-3')}
                    placeholder={t('organizationUnits.searchOrganizations')}
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                />
            </div>

            {filtered.length === 0 ? (
                <div className="flex flex-col items-center justify-center rounded-panel border border-dashed border-[color:var(--app-border)] bg-[color:var(--app-surface)] px-6 py-12 text-center">
                    <Building2 className="h-10 w-10 text-[color:var(--app-muted-foreground)]" />
                    <p className="mt-3 text-sm text-[color:var(--app-muted-foreground)]">
                        {t('organizationUnits.noOrganizationUnitsFound')}
                    </p>
                </div>
            ) : (
                <div className="overflow-hidden rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)]">
                    <table className={tableClassName}>
                        <thead className={tableHeadClassName}>
                            <tr>
                                <th className={cx(tableHeaderCellClassName, 'text-left')}>
                                    {t('common.code')}
                                </th>
                                <th className={cx(tableHeaderCellClassName, 'text-left')}>
                                    {t('common.name')}
                                </th>
                                <th className={cx(tableHeaderCellClassName, 'text-left hidden')}>
                                    {t('organizations.organizationType') || 'Type'}
                                </th>
                                <th className={cx(tableHeaderCellClassName, 'text-left')}>
                                    {t('common.status')}
                                </th>
                                <th className={cx(tableHeaderCellClassName, 'text-right')}>
                                    {t('organizationUnits.unitCount')}
                                </th>
                                <th className={cx(tableHeaderCellClassName, 'text-left')} />
                            </tr>
                        </thead>
                        <tbody className={tableBodyClassName}>
                            {filtered.map((org) => (
                                <tr
                                    key={org.id}
                                    className={cx(tableRowClassName, 'cursor-pointer')}
                                    onClick={() => onSelect(org)}
                                >
                                    <td className={cx(tableCellClassName, 'font-mono text-xs text-[color:var(--app-muted-foreground)]')}>
                                        {org.code}
                                    </td>
                                    <td className={tableCellClassName}>
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
                                                    <p className="text-xs text-[color:var(--app-muted-foreground)]">{org.name_am}</p>
                                                )}
                                            </div>
                                        </div>
                                    </td>
                                    <td className={cx(tableCellClassName, 'hidden text-[color:var(--app-muted-foreground)]')}>
                                        {org.type?.name_en ?? '—'}
                                    </td>
                                    <td className={tableCellClassName}>
                                        <StatusBadge status={org.status} />
                                    </td>
                                    <td className={cx(tableCellClassName, 'text-right font-medium text-[color:var(--app-muted-foreground)]')}>
                                        {org.organization_units_count ?? 0}
                                    </td>
                                    <td className={tableCellClassName}>
                                        <button
                                            type="button"
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                onSelect(org);
                                            }}
                                            className={buttonClassName({ variant: 'primary', size: 'sm' })}
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
