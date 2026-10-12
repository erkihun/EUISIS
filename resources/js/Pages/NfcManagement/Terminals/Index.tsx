import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { useTerminalTypeLabel } from '@/Components/Nfc/NfcStatusBadge';
import { AppFilterBar, FilterField, ListPagination, actionLinkClassName, buttonClassName, cx, filterInputCls, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
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
        <AuthenticatedLayout header={<PageHeader
                title={t('nfc.terminals')}
                description={t('nfc.management')}
                actions={
                    can.createTerminals && (
                        <Link
                            href={route('nfc-management.terminals.create')}
                            className={buttonClassName({ variant: 'primary' })}
                        >
                            {t('nfc.registerTerminal')}
                        </Link>
                    )
                }
            />}>
            <Head title={t('nfc.terminals')} />


            <NfcSubNav can={can} current="terminals" />

            <AppFilterBar routeName="nfc-management.terminals.index" filters={filters}>
                <FilterField label={t('common.search')} wide>
                    <input
                        type="search"
                        name="search"
                        defaultValue={filters.search ?? ''}
                        placeholder={t('nfc.searchTerminalsPlaceholder')}
                        className={filterInputCls}
                    />
                </FilterField>
                <FilterField label={t('nfc.terminalType')}>
                    <select
                        name="terminal_type"
                        defaultValue={filters.terminal_type ?? ''}
                        className={filterInputCls}
                    >
                        <option value="">{t('nfc.allTypes')}</option>
                        {types.map((type) => (
                            <option key={type} value={type}>
                                {terminalTypeLabel(type)}
                            </option>
                        ))}
                    </select>
                </FilterField>
                <FilterField label={t('nfc.status')}>
                    <select
                        name="status"
                        defaultValue={filters.status ?? ''}
                        className={filterInputCls}
                    >
                        <option value="">{t('nfc.allStatuses')}</option>
                        {statuses.map((status) => (
                            <option key={status} value={status}>
                                {status}
                            </option>
                        ))}
                    </select>
                </FilterField>
                <FilterField label={t('nfc.organization')}>
                    <select
                        name="organization_id"
                        defaultValue={filters.organization_id ?? ''}
                        className={filterInputCls}
                    >
                        <option value="">{t('nfc.allOrganizations')}</option>
                        {organizations.map((organization) => (
                            <option key={organization.id} value={organization.id}>
                                {localizedName(organization)}
                            </option>
                        ))}
                    </select>
                </FilterField>
            </AppFilterBar>

            {rows.length === 0 ? (
                <p className="rounded-panel border border-dashed border-[color:var(--app-border-strong)] bg-[color:var(--app-surface)] py-12 text-center text-sm text-[color:var(--app-muted-foreground)]">
                    {t('nfc.noTerminals')}
                </p>
            ) : (
                <>
                    <div className="hidden overflow-x-auto rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] md:block">
                        <table className={cx(tableClassName, 'min-w-full')}>
                            <thead className={cx(tableHeadClassName, 'text-left')}>
                                <tr>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('nfc.terminalCode')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('nfc.terminalName')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('nfc.terminalType')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('nfc.provider')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('nfc.organization')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('nfc.externalApplication')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('nfc.status')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('nfc.lastSeen')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')} />
                                </tr>
                            </thead>
                            <tbody className={tableBodyClassName}>
                                {rows.map((row) => (
                                    <tr key={row.id} className={tableRowClassName}>
                                        <td className={cx(tableCellClassName, 'font-mono text-xs text-[color:var(--app-foreground)]')}>
                                            {row.terminal_code}
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-[color:var(--app-foreground)]')}>{row.name}</td>
                                        <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}>
                                            {terminalTypeLabel(row.terminal_type)}
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}>
                                            {row.provider?.name ?? '—'}
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}>
                                            {localizedName(row.organization) || '—'}
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}>
                                            {row.external_application?.name ?? '—'}
                                        </td>
                                        <td className={tableCellClassName}>
                                            <StatusBadge status={row.status} />
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}>
                                            <LocalizedDateDisplay
                                                value={row.last_seen_at}
                                                withTime
                                                fallback={t('nfc.neverSeen')}
                                            />
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-right')}>
                                            <Link
                                                href={route('nfc-management.terminals.show', row.id)}
                                                className={actionLinkClassName({ size: 'sm' })}
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
                                className="block rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-4"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="truncate font-medium text-[color:var(--app-foreground)]">
                                            {row.name}
                                        </p>
                                        <p className="truncate font-mono text-xs text-[color:var(--app-muted-foreground)]">
                                            {row.terminal_code}
                                        </p>
                                    </div>
                                    <StatusBadge status={row.status} />
                                </div>
                                <p className="mt-2 text-xs text-[color:var(--app-muted-foreground)]">
                                    {terminalTypeLabel(row.terminal_type)}
                                    {row.organization ? ` · ${localizedName(row.organization)}` : ''}
                                </p>
                            </Link>
                        ))}
                    </div>
                </>
            )}

            <ListPagination meta={meta} bare className="mt-4" />
        </AuthenticatedLayout>
    );
}
