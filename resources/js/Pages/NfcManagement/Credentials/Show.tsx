import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import NfcStatusBadge, {
    NfcResultBadge,
    useNfcTypeLabel,
    useTerminalTypeLabel,
} from '@/Components/Nfc/NfcStatusBadge';
import { useLocale } from '@/hooks/useLocale';
import NfcSubNav, { useLocalizedName, type NfcCapabilities } from '../Partials/NfcSubNav';

import { actionLinkClassName, cx, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName } from '@/Components/ui';
type Props = {
    credential: {
        credential_id: string;
        credential_type: string;
        status: string;
        key_version: string | null;
        issued_at: string | null;
        activated_at: string | null;
        expires_at: string | null;
        revoked_at: string | null;
        last_used_at: string | null;
        created_by: string | null;
        revoked_by: string | null;
        replaced_by: string | null;
    };
    card: {
        id: string;
        card_number: string;
        status: string | null;
        issued_at: string | null;
        expires_at: string | null;
    } | null;
    employee: {
        employee_number: string;
        full_name: string;
        organization: { name_en?: string | null; name_am?: string | null } | null;
        organization_unit: { name_en?: string | null; name_am?: string | null } | null;
        position: { title_en?: string | null; title_am?: string | null } | null;
    } | null;
    lifecycle: { event: string; at: string; actor: string | null }[];
    activity: {
        id: string;
        occurred_at: string | null;
        event_type: string;
        result: string;
        reason_code: string | null;
        terminal: { terminal_code: string; name: string; terminal_type: string; service_type: string | null } | null;
        external_application: string | null;
    }[];
    can: NfcCapabilities;
};

function Field({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div>
            <dt className="text-xs text-(--app-muted-foreground)">{label}</dt>
            <dd className="mt-0.5 text-sm text-(--app-foreground)">{children}</dd>
        </div>
    );
}

export default function NfcCredentialShow({ credential, card, employee, lifecycle, activity, can }: Props) {
    const { t, locale } = useLocale();
    const typeLabel = useNfcTypeLabel();
    const terminalTypeLabel = useTerminalTypeLabel();
    const localizedName = useLocalizedName();

    const positionTitle = employee?.position
        ? ((locale === 'am' ? employee.position.title_am ?? employee.position.title_en : employee.position.title_en) ?? '—')
        : '—';

    const eventLabel = (event: string) => {
        const key = `nfc.event${event.charAt(0).toUpperCase()}${event.slice(1)}`;
        const translated = t(key);

        return translated === key ? event : translated;
    };

    return (
        <AuthenticatedLayout header={<PageHeader
                title={t('nfc.credential')}
                description={employee?.full_name ?? card?.card_number}
                backHref={route('nfc-management.credentials.index')}
            />}>
            <Head title={t('nfc.credential')} />


            <NfcSubNav can={can} current="credentials" />

            <div className="grid gap-4 lg:grid-cols-3">
                {/* Credential summary */}
                <section className="rounded-panel border border-(--app-border) bg-(--app-surface) p-5 lg:col-span-2">
                    <div className="mb-4 flex items-center justify-between gap-3">
                        <h2 className="text-sm font-semibold text-(--app-foreground)">
                            {t('nfc.credentialSummary')}
                        </h2>
                        <NfcStatusBadge status={credential.status} />
                    </div>

                    <p className="mb-4 break-all rounded-lg bg-(--app-surface-muted) px-3 py-2 font-mono text-xs text-(--app-foreground)">
                        {credential.credential_id}
                    </p>

                    <dl className="grid gap-4 sm:grid-cols-3">
                        <Field label={t('nfc.credentialType')}>{typeLabel(credential.credential_type)}</Field>
                        <Field label={t('nfc.keyVersion')}>{credential.key_version ?? '—'}</Field>
                        <Field label={t('nfc.issuedDate')}>
                            <LocalizedDateDisplay value={credential.issued_at} withTime />
                        </Field>
                        <Field label={t('nfc.activatedAt')}>
                            <LocalizedDateDisplay value={credential.activated_at} withTime />
                        </Field>
                        <Field label={t('nfc.expiresAt')}>
                            <LocalizedDateDisplay value={credential.expires_at} />
                        </Field>
                        <Field label={t('nfc.lastUsed')}>
                            <LocalizedDateDisplay value={credential.last_used_at} withTime fallback={t('nfc.neverUsed')} />
                        </Field>
                        <Field label={t('nfc.provisionedBy')}>{credential.created_by ?? '—'}</Field>
                        <Field label={t('nfc.revokedBy')}>{credential.revoked_by ?? '—'}</Field>
                        <Field label={t('nfc.replacedByCredential')}>
                            {credential.replaced_by ? (
                                <Link
                                    href={route('nfc-management.credentials.show', credential.replaced_by)}
                                    className={actionLinkClassName({ className: 'break-all font-mono' })}
                                >
                                    {credential.replaced_by.slice(0, 18)}…
                                </Link>
                            ) : (
                                '—'
                            )}
                        </Field>
                    </dl>
                </section>

                {/* Linked card + employee */}
                <div className="space-y-4">
                    <section className="rounded-panel border border-(--app-border) bg-(--app-surface) p-5">
                        <h2 className="mb-3 text-sm font-semibold text-(--app-foreground)">
                            {t('nfc.linkedIdCard')}
                        </h2>
                        {card ? (
                            <dl className="space-y-3">
                                <Field label={t('nfc.cardNumber')}>
                                    <Link
                                        href={route('id-cards.show', card.id)}
                                        className="text-primary hover:underline dark:text-primary"
                                    >
                                        {card.card_number}
                                    </Link>
                                </Field>
                                <Field label={t('nfc.cardStatus')}>
                                    {card.status ? <StatusBadge status={card.status} /> : '—'}
                                </Field>
                                <Field label={t('nfc.issuedDate')}>
                                    <LocalizedDateDisplay value={card.issued_at} />
                                </Field>
                                <Field label={t('nfc.expiresAt')}>
                                    <LocalizedDateDisplay value={card.expires_at} />
                                </Field>
                            </dl>
                        ) : (
                            <p className="text-sm text-(--app-muted-foreground)">—</p>
                        )}
                        <p className="mt-4 rounded-lg bg-blue-50 px-3 py-2 text-xs text-blue-800 dark:bg-blue-900/20 dark:text-blue-300">
                            {t('nfc.qrUnaffectedNote')}
                        </p>
                    </section>

                    <section className="rounded-panel border border-(--app-border) bg-(--app-surface) p-5">
                        <h2 className="mb-3 text-sm font-semibold text-(--app-foreground)">
                            {t('nfc.employee')}
                        </h2>
                        {employee ? (
                            <dl className="space-y-3">
                                <Field label={t('nfc.employee')}>{employee.full_name}</Field>
                                <Field label="#">{employee.employee_number}</Field>
                                <Field label={t('nfc.organization')}>{localizedName(employee.organization) || '—'}</Field>
                                <Field label={t('nfc.organizationUnit')}>
                                    {localizedName(employee.organization_unit) || '—'}
                                </Field>
                                <Field label={t('nfc.position')}>{positionTitle}</Field>
                            </dl>
                        ) : (
                            <p className="text-sm text-(--app-muted-foreground)">—</p>
                        )}
                    </section>
                </div>
            </div>

            {/* Lifecycle */}
            <section className="mt-4 rounded-panel border border-(--app-border) bg-(--app-surface) p-5">
                <h2 className="mb-4 text-sm font-semibold text-(--app-foreground)">
                    {t('nfc.lifecycleHistory')}
                </h2>
                {lifecycle.length === 0 ? (
                    <p className="text-sm text-(--app-muted-foreground)">{t('nfc.noLifecycle')}</p>
                ) : (
                    <ol className="space-y-3">
                        {lifecycle.map((entry, index) => (
                            <li key={`${entry.event}-${index}`} className="flex flex-wrap items-center gap-3 text-sm">
                                <span className="h-2 w-2 shrink-0 rounded-full bg-blue-500" />
                                <span className="font-medium text-(--app-foreground)">
                                    {eventLabel(entry.event)}
                                </span>
                                <LocalizedDateDisplay
                                    value={entry.at}
                                    withTime
                                    className="text-(--app-muted-foreground)"
                                />
                                {entry.actor && (
                                    <span className="text-xs text-(--app-muted-foreground)">{entry.actor}</span>
                                )}
                            </li>
                        ))}
                    </ol>
                )}
            </section>

            {/* Verification activity */}
            <section className="mt-4 rounded-panel border border-(--app-border) bg-(--app-surface) p-5">
                <h2 className="mb-1 text-sm font-semibold text-(--app-foreground)">
                    {t('nfc.recentActivity')}
                </h2>
                <p className="mb-4 text-xs text-(--app-muted-foreground)">{t('nfc.logsPrivacyNote')}</p>

                {activity.length === 0 ? (
                    <p className="text-sm text-(--app-muted-foreground)">{t('nfc.noActivity')}</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className={cx(tableClassName, 'min-w-full')}>
                            <thead className={cx(tableHeadClassName, 'text-left')}>
                                <tr>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('nfc.time')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('nfc.terminal')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('nfc.serviceType')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('nfc.result')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('nfc.reasonCode')}</th>
                                </tr>
                            </thead>
                            <tbody className={tableBodyClassName}>
                                {activity.map((entry) => (
                                    <tr key={entry.id}>
                                        <td className={cx(tableCellClassName, 'text-(--app-muted-foreground)')}>
                                            <LocalizedDateDisplay value={entry.occurred_at} withTime />
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-(--app-muted-foreground)')}>
                                            {entry.terminal
                                                ? `${entry.terminal.terminal_code} (${terminalTypeLabel(entry.terminal.terminal_type)})`
                                                : '—'}
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-(--app-muted-foreground)')}>
                                            {entry.terminal?.service_type ?? '—'}
                                        </td>
                                        <td className={tableCellClassName}>
                                            <NfcResultBadge result={entry.result} />
                                        </td>
                                        <td className={cx(tableCellClassName, 'font-mono text-xs text-(--app-muted-foreground)')}>
                                            {entry.reason_code ?? '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>
        </AuthenticatedLayout>
    );
}
