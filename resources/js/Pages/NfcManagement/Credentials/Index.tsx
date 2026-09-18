import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import NfcStatusBadge, { useNfcTypeLabel } from '@/Components/Nfc/NfcStatusBadge';
import { AppFilterBar, filterInputCls } from '@/Components/ui';
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
                <p className="rounded-panel border border-dashed border-gray-300 bg-white py-12 text-center text-sm text-gray-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400">
                    {t('nfc.noLogs')}
                </p>
            ) : (
                <>
                    {/* Desktop table */}
                    <div className="hidden overflow-x-auto rounded-panel border border-gray-200 bg-white md:block dark:border-slate-800 dark:bg-slate-900">
                        <table className="min-w-full divide-y divide-gray-200 text-sm dark:divide-slate-800">
                            <thead className="bg-gray-50 text-left text-xs text-gray-500 dark:bg-slate-950 dark:text-slate-400">
                                <tr>
                                    <th className="px-4 py-3">{t('nfc.credentialId')}</th>
                                    <th className="px-4 py-3">{t('nfc.cardNumber')}</th>
                                    <th className="px-4 py-3">{t('nfc.employee')}</th>
                                    <th className="px-4 py-3">{t('nfc.organization')}</th>
                                    <th className="px-4 py-3">{t('nfc.credentialType')}</th>
                                    <th className="px-4 py-3">{t('nfc.status')}</th>
                                    <th className="px-4 py-3">{t('nfc.issuedDate')}</th>
                                    <th className="px-4 py-3">{t('nfc.lastUsed')}</th>
                                    <th className="px-4 py-3" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                                {rows.map((row) => (
                                    <tr key={row.credential_id} className="hover:bg-gray-50 dark:hover:bg-slate-800/50">
                                        <td className="px-4 py-3 font-mono text-xs text-gray-600 dark:text-slate-300">
                                            {row.credential_id.slice(0, 18)}…
                                        </td>
                                        <td className="px-4 py-3">
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
                                        <td className="px-4 py-3">
                                            <span className="block text-gray-900 dark:text-slate-100">
                                                {row.employee?.full_name ?? '—'}
                                            </span>
                                            <span className="block text-xs text-gray-500 dark:text-slate-400">
                                                {row.employee?.employee_number}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-gray-600 dark:text-slate-300">
                                            {localizedName(row.organization) || '—'}
                                        </td>
                                        <td className="px-4 py-3 text-gray-600 dark:text-slate-300">
                                            {typeLabel(row.credential_type)}
                                        </td>
                                        <td className="px-4 py-3">
                                            <NfcStatusBadge status={row.status} />
                                        </td>
                                        <td className="px-4 py-3 text-gray-600 dark:text-slate-300">
                                            {row.issued_at ? <LocalizedDateDisplay value={row.issued_at} /> : '—'}
                                        </td>
                                        <td className="px-4 py-3 text-gray-600 dark:text-slate-300">
                                            {row.last_used_at ? (
                                                <LocalizedDateDisplay value={row.last_used_at} />
                                            ) : (
                                                t('nfc.neverUsed')
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-right">
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
                                className="block rounded-panel border border-gray-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="truncate font-medium text-gray-900 dark:text-slate-100">
                                            {row.employee?.full_name ?? row.card?.card_number ?? '—'}
                                        </p>
                                        <p className="truncate text-xs text-gray-500 dark:text-slate-400">
                                            {row.card?.card_number} · {typeLabel(row.credential_type)}
                                        </p>
                                    </div>
                                    <NfcStatusBadge status={row.status} />
                                </div>
                                <dl className="mt-3 grid grid-cols-2 gap-2 text-xs text-gray-600 dark:text-slate-300">
                                    <div>
                                        <dt className="text-gray-400 dark:text-slate-500">{t('nfc.issuedDate')}</dt>
                                        <dd>{row.issued_at ? <LocalizedDateDisplay value={row.issued_at} /> : '—'}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-gray-400 dark:text-slate-500">{t('nfc.lastUsed')}</dt>
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
                    <p className="text-gray-500 dark:text-slate-400">
                        {meta.current_page} / {meta.last_page} · {meta.total}
                    </p>
                    <div className="flex gap-2">
                        {meta.current_page > 1 && (
                            <Link
                                href={route('nfc-management.credentials.index', {
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
                                href={route('nfc-management.credentials.index', {
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
