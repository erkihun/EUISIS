import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import NfcStatusBadge, { useNfcTypeLabel } from '@/Components/Nfc/NfcStatusBadge';
import { AppFilterBar, cx, filterInputCls, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import NfcSubNav, { useLocalizedName, type NfcCapabilities } from '../Partials/NfcSubNav';

type CredentialRow = {
    credential_id: string;
    credential_type: string;
    status: string;
    key_version: string | null;
    issued_at: string | null;
    last_used_at: string | null;
    card: { id: string; card_number: string; status: string | null } | null;
    employee: { employee_number: string; full_name: string } | null;
    organization: { name_en?: string | null; name_am?: string | null } | null;
};

type Props = {
    credentials: {
        data: CredentialRow[];
        meta: { current_page: number; last_page: number; per_page: number; total: number };
    };
    filters: Record<string, string>;
    organizations: { id: string; name_en: string; name_am: string | null }[];
    statuses: string[];
    can: NfcCapabilities;
};

export default function NfcCredentialsIndex({ credentials, filters, organizations, statuses, can }: Props) {
    const { t } = useLocale();
    const typeLabel = useNfcTypeLabel();
    const localizedName = useLocalizedName();

    const rows = credentials.data;
    const meta = credentials.meta;

    return (
        <AuthenticatedLayout>
            <Head title={t('nfc.credentials')} />

            <PageHeader title={t('nfc.credentials')} description={t('nfc.management')} />

            <NfcSubNav can={can} current="credentials" />

            <AppFilterBar routeName="nfc-management.credentials.index" filters={filters}>
                <input
                    name="search"
                    defaultValue={filters.search ?? ''}
                    placeholder={t('nfc.searchPlaceholder')}
                    className={`${filterInputCls} min-w-[16rem] flex-1`}
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
                    name="credential_type"
                    defaultValue={filters.credential_type ?? ''}
                    className={filterInputCls}
                    aria-label={t('nfc.credentialType')}
                >
                    <option value="">{t('nfc.allTypes')}</option>
                    {['secure_smart_card', 'ndef_reference', 'mobile_credential'].map((type) => (
                        <option key={type} value={type}>
                            {typeLabel(type)}
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
                            {t(`nfc.status${status.charAt(0).toUpperCase()}${status.slice(1)}`)}
                        </option>
                    ))}
                </select>
                <input
                    type="date"
                    name="issued_from"
                    defaultValue={filters.issued_from ?? ''}
                    className={filterInputCls}
                    aria-label={t('nfc.issuedDate')}
                />
                <input
                    type="date"
                    name="last_used_from"
                    defaultValue={filters.last_used_from ?? ''}
                    className={filterInputCls}
                    aria-label={t('nfc.lastUsed')}
                />
            </AppFilterBar>

            {rows.length === 0 ? (
                <p className="rounded-panel border border-dashed border-[color:var(--app-border-strong)] bg-[color:var(--app-surface)] py-12 text-center text-sm text-[color:var(--app-muted-foreground)]">
                    {t('nfc.noLogs')}
                </p>
            ) : (
                <>
                    {/* Desktop table */}
                    <div className="hidden overflow-x-auto rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] md:block">
                        <table className={cx(tableClassName, 'min-w-full')}>
                            <thead className={cx(tableHeadClassName, 'text-left')}>
                                <tr>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('nfc.credentialId')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('nfc.cardNumber')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('nfc.employee')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('nfc.organization')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('nfc.credentialType')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('nfc.status')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('nfc.issuedDate')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('nfc.lastUsed')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')} />
                                </tr>
                            </thead>
                            <tbody className={tableBodyClassName}>
                                {rows.map((row) => (
                                    <tr key={row.credential_id} className={tableRowClassName}>
                                        <td className={cx(tableCellClassName, 'font-mono text-xs text-[color:var(--app-muted-foreground)]')}>
                                            {row.credential_id.slice(0, 18)}…
                                        </td>
                                        <td className={tableCellClassName}>
                                            {row.card ? (
                                                <Link
                                                    href={route('id-cards.show', row.card.id)}
                                                    className="text-[color:var(--color-primary)] hover:underline dark:text-[color:var(--color-primary)]"
                                                >
                                                    {row.card.card_number}
                                                </Link>
                                            ) : (
                                                '—'
                                            )}
                                        </td>
                                        <td className={tableCellClassName}>
                                            <span className="block text-[color:var(--app-foreground)]">
                                                {row.employee?.full_name ?? '—'}
                                            </span>
                                            <span className="block text-xs text-[color:var(--app-muted-foreground)]">
                                                {row.employee?.employee_number}
                                            </span>
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}>
                                            {localizedName(row.organization) || '—'}
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}>
                                            {typeLabel(row.credential_type)}
                                        </td>
                                        <td className={tableCellClassName}>
                                            <NfcStatusBadge status={row.status} />
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}>
                                            {row.issued_at ? <LocalizedDateDisplay value={row.issued_at} /> : '—'}
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}>
                                            {row.last_used_at ? (
                                                <LocalizedDateDisplay value={row.last_used_at} />
                                            ) : (
                                                t('nfc.neverUsed')
                                            )}
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-right')}>
                                            <Link
                                                href={route('nfc-management.credentials.show', row.credential_id)}
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

                    {/* Mobile cards — the table would overflow the viewport */}
                    <div className="space-y-3 md:hidden">
                        {rows.map((row) => (
                            <Link
                                key={row.credential_id}
                                href={route('nfc-management.credentials.show', row.credential_id)}
                                className="block rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-4"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="truncate font-medium text-[color:var(--app-foreground)]">
                                            {row.employee?.full_name ?? row.card?.card_number ?? '—'}
                                        </p>
                                        <p className="truncate text-xs text-[color:var(--app-muted-foreground)]">
                                            {row.card?.card_number} · {typeLabel(row.credential_type)}
                                        </p>
                                    </div>
                                    <NfcStatusBadge status={row.status} />
                                </div>
                                <dl className="mt-3 grid grid-cols-2 gap-2 text-xs text-[color:var(--app-muted-foreground)]">
                                    <div>
                                        <dt className="text-[color:var(--app-muted-foreground)]">{t('nfc.issuedDate')}</dt>
                                        <dd>{row.issued_at ? <LocalizedDateDisplay value={row.issued_at} /> : '—'}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-[color:var(--app-muted-foreground)]">{t('nfc.lastUsed')}</dt>
                                        <dd>
                                            {row.last_used_at ? (
                                                <LocalizedDateDisplay value={row.last_used_at} />
                                            ) : (
                                                t('nfc.neverUsed')
                                            )}
                                        </dd>
                                    </div>
                                </dl>
                            </Link>
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
                                href={route('nfc-management.credentials.index', {
                                    ...filters,
                                    page: meta.current_page - 1,
                                })}
                                className="rounded-lg border border-[color:var(--app-border-strong)] px-3 py-1.5"
                            >
                                {t('common.previous')}
                            </Link>
                        )}
                        {meta.current_page < meta.last_page && (
                            <Link
                                href={route('nfc-management.credentials.index', {
                                    ...filters,
                                    page: meta.current_page + 1,
                                })}
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
