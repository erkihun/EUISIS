import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { useTerminalTypeLabel } from '@/Components/Nfc/NfcStatusBadge';
import { AppFilterBar, filterInputCls } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import NfcSubNav, { useLocalizedName, type NfcCapabilities } from '../Partials/NfcSubNav';

type TerminalRow = {
    id: string;
    terminal_code: string;
    name: string;
    terminal_type: string;
    service_type: string | null;
    status: string;
    last_seen_at: string | null;
    provider: { id: string; name: string } | null;
    organization: { id: string; name_en?: string | null; name_am?: string | null } | null;
    external_application: { id: string; name: string } | null;
};

type Props = {
    terminals: {
        data: TerminalRow[];
        meta: { current_page: number; last_page: number; per_page: number; total: number };
    };
    filters: Record<string, string>;
    types: string[];
    statuses: string[];
    organizations: { id: string; name_en: string; name_am: string | null }[];
    can: NfcCapabilities;
};

export default function NfcTerminalsIndex({ terminals, filters, types, statuses, organizations, can }: Props) {
    const { t } = useLocale();
    const terminalTypeLabel = useTerminalTypeLabel();
    const localizedName = useLocalizedName();

    const rows = terminals.data;
    const meta = terminals.meta;

    return (
        <AuthenticatedLayout>
            <Head title={t('nfc.terminals')} />

            <PageHeader
                title={t('nfc.terminals')}
                description={t('nfc.management')}
                actions={
                    can.createTerminals && (
                        <Link
                            href={route('nfc-management.terminals.create')}
                            className="rounded-lg bg-[color:var(--color-primary)] px-4 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)]"
                        >
                            {t('nfc.registerTerminal')}
                        </Link>
                    )
                }
            />

            <NfcSubNav can={can} current="terminals" />

            <AppFilterBar routeName="nfc-management.terminals.index" filters={filters}>
                <input
                    name="search"
                    defaultValue={filters.search ?? ''}
                    placeholder={t('nfc.searchTerminalsPlaceholder')}
                    className={`${filterInputCls} min-w-[15rem] flex-1`}
                    aria-label={t('nfc.searchTerminalsPlaceholder')}
                />
                <select
                    name="terminal_type"
                    defaultValue={filters.terminal_type ?? ''}
                    className={filterInputCls}
                    aria-label={t('nfc.terminalType')}
                >
                    <option value="">{t('nfc.allTypes')}</option>
                    {types.map((type) => (
                        <option key={type} value={type}>
                            {terminalTypeLabel(type)}
                        </option>
                    ))}
                </select>
                <select
                    name="status"
                    defaultValue={filters.status ?? ''}
                    className={filterInputCls}
                    aria-label={t('nfc.status')}
                >
                    <option value="">{t('nfc.allStatuses')}</option>
                    {statuses.map((status) => (
                        <option key={status} value={status}>
                            {status}
                        </option>
                    ))}
                </select>
                <select
                    name="organization_id"
                    defaultValue={filters.organization_id ?? ''}
                    className={filterInputCls}
                    aria-label={t('nfc.organization')}
                >
                    <option value="">{t('nfc.allOrganizations')}</option>
                    {organizations.map((organization) => (
                        <option key={organization.id} value={organization.id}>
                            {localizedName(organization)}
                        </option>
                    ))}
                </select>
            </AppFilterBar>

            {rows.length === 0 ? (
                <p className="rounded-panel border border-dashed border-gray-300 bg-white py-12 text-center text-sm text-gray-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400">
                    {t('nfc.noTerminals')}
                </p>
            ) : (
                <>
                    <div className="hidden overflow-x-auto rounded-panel border border-gray-200 bg-white md:block dark:border-slate-800 dark:bg-slate-900">
                        <table className="min-w-full divide-y divide-gray-200 text-sm dark:divide-slate-800">
                            <thead className="bg-gray-50 text-left text-xs text-gray-500 dark:bg-slate-950 dark:text-slate-400">
                                <tr>
                                    <th className="px-4 py-3">{t('nfc.terminalCode')}</th>
                                    <th className="px-4 py-3">{t('nfc.terminalName')}</th>
                                    <th className="px-4 py-3">{t('nfc.terminalType')}</th>
                                    <th className="px-4 py-3">{t('nfc.provider')}</th>
                                    <th className="px-4 py-3">{t('nfc.organization')}</th>
                                    <th className="px-4 py-3">{t('nfc.externalApplication')}</th>
                                    <th className="px-4 py-3">{t('nfc.status')}</th>
                                    <th className="px-4 py-3">{t('nfc.lastSeen')}</th>
                                    <th className="px-4 py-3" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                                {rows.map((row) => (
                                    <tr key={row.id} className="hover:bg-gray-50 dark:hover:bg-slate-800/50">
                                        <td className="px-4 py-3 font-mono text-xs text-gray-700 dark:text-slate-300">
                                            {row.terminal_code}
                                        </td>
                                        <td className="px-4 py-3 text-gray-900 dark:text-slate-100">{row.name}</td>
                                        <td className="px-4 py-3 text-gray-600 dark:text-slate-300">
                                            {terminalTypeLabel(row.terminal_type)}
                                        </td>
                                        <td className="px-4 py-3 text-gray-600 dark:text-slate-300">
                                            {row.provider?.name ?? '—'}
                                        </td>
                                        <td className="px-4 py-3 text-gray-600 dark:text-slate-300">
                                            {localizedName(row.organization) || '—'}
                                        </td>
                                        <td className="px-4 py-3 text-gray-600 dark:text-slate-300">
                                            {row.external_application?.name ?? '—'}
                                        </td>
                                        <td className="px-4 py-3">
                                            <StatusBadge status={row.status} />
                                        </td>
                                        <td className="px-4 py-3 text-gray-600 dark:text-slate-300">
                                            <LocalizedDateDisplay
                                                value={row.last_seen_at}
                                                withTime
                                                fallback={t('nfc.neverSeen')}
                                            />
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <Link
                                                href={route('nfc-management.terminals.show', row.id)}
                                                className="text-sm font-medium text-[color:var(--color-primary)] hover:underline dark:text-[color:var(--color-primary)]"
                                            >
                                                {t('nfc.view')}
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="space-y-3 md:hidden">
                        {rows.map((row) => (
                            <Link
                                key={row.id}
                                href={route('nfc-management.terminals.show', row.id)}
                                className="block rounded-panel border border-gray-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="truncate font-medium text-gray-900 dark:text-slate-100">
                                            {row.name}
                                        </p>
                                        <p className="truncate font-mono text-xs text-gray-500 dark:text-slate-400">
                                            {row.terminal_code}
                                        </p>
                                    </div>
                                    <StatusBadge status={row.status} />
                                </div>
                                <p className="mt-2 text-xs text-gray-600 dark:text-slate-300">
                                    {terminalTypeLabel(row.terminal_type)}
                                    {row.organization ? ` · ${localizedName(row.organization)}` : ''}
                                </p>
                            </Link>
                        ))}
                    </div>
                </>
            )}

            {meta.last_page > 1 && (
                <div className="mt-4 flex items-center justify-between text-sm">
                    <p className="text-gray-500 dark:text-slate-400">
                        {meta.current_page} / {meta.last_page} · {meta.total}
                    </p>
                    <div className="flex gap-2">
                        {meta.current_page > 1 && (
                            <Link
                                href={route('nfc-management.terminals.index', {
                                    ...filters,
                                    page: meta.current_page - 1,
                                })}
                                className="rounded-lg border border-gray-300 px-3 py-1.5 dark:border-slate-700"
                            >
                                {t('common.previous')}
                            </Link>
                        )}
                        {meta.current_page < meta.last_page && (
                            <Link
                                href={route('nfc-management.terminals.index', {
                                    ...filters,
                                    page: meta.current_page + 1,
                                })}
                                className="rounded-lg border border-gray-300 px-3 py-1.5 dark:border-slate-700"
                            >
                                {t('common.next')}
                            </Link>
                        )}
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
