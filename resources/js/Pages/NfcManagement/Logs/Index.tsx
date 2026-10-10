import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { NfcResultBadge, useTerminalTypeLabel } from '@/Components/Nfc/NfcStatusBadge';
import { AppFilterBar, cx, filterInputCls, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import NfcSubNav, { useLocalizedName, type NfcCapabilities } from '../Partials/NfcSubNav';

type LogRow = {
    id: string;
    occurred_at: string | null;
    event_type: string;
    result: string;
    reason_code: string | null;
    credential_id: string | null;
    employee: { employee_number: string; full_name: string } | null;
    terminal: { terminal_code: string; name: string; terminal_type: string; service_type: string | null } | null;
    external_application: string | null;
};

type Props = {
    logs: {
        data: LogRow[];
        meta: { current_page: number; last_page: number; per_page: number; total: number };
    };
    filters: Record<string, string>;
    organizations: { id: string; name_en: string; name_am: string | null }[];
    terminals: { id: string; terminal_code: string; name: string }[];
    can: NfcCapabilities;
};

export default function NfcLogsIndex({ logs, filters, organizations, terminals, can }: Props) {
    const { t } = useLocale();
    const terminalTypeLabel = useTerminalTypeLabel();
    const localizedName = useLocalizedName();

    const rows = logs.data;
    const meta = logs.meta;

    return (
        <AuthenticatedLayout>
            <Head title={t('nfc.verificationLogs')} />

            <PageHeader title={t('nfc.verificationLogs')} description={t('nfc.logsPrivacyNote')} />

            <NfcSubNav can={can} current="logs" />

            <AppFilterBar routeName="nfc-management.logs.index" filters={filters}>
                <input
                    name="search"
                    defaultValue={filters.search ?? ''}
                    placeholder={t('nfc.searchPlaceholder')}
                    className={`${filterInputCls} min-w-[15rem] flex-1`}
                    aria-label={t('nfc.searchPlaceholder')}
                />
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
                <select
                    name="terminal_id"
                    defaultValue={filters.terminal_id ?? ''}
                    className={filterInputCls}
                    aria-label={t('nfc.terminal')}
                >
                    <option value="">{t('nfc.allTerminals')}</option>
                    {terminals.map((terminal) => (
                        <option key={terminal.id} value={terminal.id}>
                            {terminal.terminal_code}
                        </option>
                    ))}
                </select>
                <select
                    name="result"
                    defaultValue={filters.result ?? ''}
                    className={filterInputCls}
                    aria-label={t('nfc.result')}
                >
                    <option value="">{t('nfc.allResults')}</option>
                    <option value="allowed">{t('nfc.resultAllowed')}</option>
                    <option value="blocked">{t('nfc.resultBlocked')}</option>
                </select>
                <input
                    name="reason_code"
                    defaultValue={filters.reason_code ?? ''}
                    placeholder={t('nfc.reasonCode')}
                    className={filterInputCls}
                    aria-label={t('nfc.reasonCode')}
                />
                <input
                    type="date"
                    name="from"
                    defaultValue={filters.from ?? ''}
                    className={filterInputCls}
                    aria-label={t('nfc.dateFrom')}
                />
                <input
                    type="date"
                    name="to"
                    defaultValue={filters.to ?? ''}
                    className={filterInputCls}
                    aria-label={t('nfc.dateTo')}
                />
            </AppFilterBar>

            {rows.length === 0 ? (
                <p className="rounded-panel border border-dashed border-[color:var(--app-border-strong)] bg-[color:var(--app-surface)] py-12 text-center text-sm text-[color:var(--app-muted-foreground)]">
                    {t('nfc.noLogs')}
                </p>
            ) : (
                <>
                    <div className="hidden overflow-x-auto rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] md:block">
                        <table className={cx(tableClassName, 'min-w-full')}>
                            <thead className={cx(tableHeadClassName, 'text-left')}>
                                <tr>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('nfc.time')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('nfc.credentialId')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('nfc.employee')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('nfc.terminal')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('nfc.terminalType')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('nfc.serviceType')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('nfc.result')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('nfc.reasonCode')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('nfc.externalApplication')}</th>
                                </tr>
                            </thead>
                            <tbody className={tableBodyClassName}>
                                {rows.map((row) => (
                                    <tr key={row.id} className={tableRowClassName}>
                                        <td className={cx(tableCellClassName, 'whitespace-nowrap text-[color:var(--app-muted-foreground)]')}>
                                            <LocalizedDateDisplay value={row.occurred_at} withTime />
                                        </td>
                                        <td className={cx(tableCellClassName, 'font-mono text-xs')}>
                                            {row.credential_id ? (
                                                <Link
                                                    href={route('nfc-management.credentials.show', row.credential_id)}
                                                    className="text-[color:var(--color-primary)] hover:underline dark:text-[color:var(--color-primary)]"
                                                >
                                                    {row.credential_id.slice(0, 14)}…
                                                </Link>
                                            ) : (
                                                '—'
                                            )}
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}>
                                            {row.employee?.full_name ?? '—'}
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}>
                                            {row.terminal?.terminal_code ?? '—'}
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}>
                                            {row.terminal ? terminalTypeLabel(row.terminal.terminal_type) : '—'}
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}>
                                            {row.terminal?.service_type ?? '—'}
                                        </td>
                                        <td className={tableCellClassName}>
                                            <NfcResultBadge result={row.result} />
                                        </td>
                                        <td className={cx(tableCellClassName, 'font-mono text-xs text-[color:var(--app-muted-foreground)]')}>
                                            {row.reason_code ?? '—'}
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}>
                                            {row.external_application ?? '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="space-y-3 md:hidden">
                        {rows.map((row) => (
                            <div
                                key={row.id}
                                className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-4"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="truncate font-medium text-[color:var(--app-foreground)]">
                                            {row.employee?.full_name ?? row.credential_id?.slice(0, 14) ?? '—'}
                                        </p>
                                        <p className="text-xs text-[color:var(--app-muted-foreground)]">
                                            <LocalizedDateDisplay value={row.occurred_at} withTime />
                                        </p>
                                    </div>
                                    <NfcResultBadge result={row.result} />
                                </div>
                                <p className="mt-2 text-xs text-[color:var(--app-muted-foreground)]">
                                    {row.terminal?.terminal_code ?? '—'}
                                    {row.reason_code ? ` · ${row.reason_code}` : ''}
                                </p>
                            </div>
                        ))}
                    </div>
                </>
            )}

            {meta.last_page > 1 && (
                <div className="mt-4 flex items-center justify-between text-sm">
                    <p className="text-[color:var(--app-muted-foreground)]">
                        {meta.current_page} / {meta.last_page} · {meta.total}
                    </p>
                    <div className="flex gap-2">
                        {meta.current_page > 1 && (
                            <Link
                                href={route('nfc-management.logs.index', { ...filters, page: meta.current_page - 1 })}
                                className="rounded-lg border border-[color:var(--app-border-strong)] px-3 py-1.5"
                            >
                                {t('common.previous')}
                            </Link>
                        )}
                        {meta.current_page < meta.last_page && (
                            <Link
                                href={route('nfc-management.logs.index', { ...filters, page: meta.current_page + 1 })}
                                className="rounded-lg border border-[color:var(--app-border-strong)] px-3 py-1.5"
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
