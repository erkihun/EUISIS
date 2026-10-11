import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import NfcStatusBadge, { useNfcTypeLabel } from '@/Components/Nfc/NfcStatusBadge';
import { AppFilterBar, FilterField, ListPagination, actionLinkClassName, cx, filterInputCls, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
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
    const [dates, setDates] = useState({ issued_from: filters.issued_from ?? '', last_used_from: filters.last_used_from ?? '' });
    const typeLabel = useNfcTypeLabel();
    const localizedName = useLocalizedName();

    const rows = credentials.data;
    const meta = credentials.meta;

    return (
        <AuthenticatedLayout>
            <Head title={t('nfc.credentials')} />

            <PageHeader title={t('nfc.credentials')} description={t('nfc.management')} />

            <NfcSubNav can={can} current="credentials" />

            <AppFilterBar routeName="nfc-management.credentials.index" filters={filters} onBeforeSubmit={(params) => ({ ...params, ...dates })}>
                <FilterField label={t('common.search')} wide>
                    <input
                        type="search"
                        name="search"
                        defaultValue={filters.search ?? ''}
                        placeholder={t('nfc.searchPlaceholder')}
                        className={filterInputCls}
                    />
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
                <FilterField label={t('nfc.credentialType')}>
                    <select
                        name="credential_type"
                        defaultValue={filters.credential_type ?? ''}
                        className={filterInputCls}
                    >
                        <option value="">{t('nfc.allTypes')}</option>
                        {['secure_smart_card', 'ndef_reference', 'mobile_credential'].map((type) => (
                            <option key={type} value={type}>
                                {typeLabel(type)}
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
                                {t(`nfc.status${status.charAt(0).toUpperCase()}${status.slice(1)}`)}
                            </option>
                        ))}
                    </select>
                </FilterField>
                <FilterField label={t('nfc.issuedDate')} htmlFor="nfc-issued-from">
                    <LocalizedDatePicker id="nfc-issued-from" className={cx(filterInputCls, 'w-full')} value={dates.issued_from} onChange={(value) => setDates({ ...dates, issued_from: value })} />
                </FilterField>
                <FilterField label={t('nfc.lastUsed')} htmlFor="nfc-last-used-from">
                    <LocalizedDatePicker id="nfc-last-used-from" className={cx(filterInputCls, 'w-full')} value={dates.last_used_from} onChange={(value) => setDates({ ...dates, last_used_from: value })} />
                </FilterField>
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

            <ListPagination meta={meta} bare className="mt-4" />
        </AuthenticatedLayout>
    );
}
