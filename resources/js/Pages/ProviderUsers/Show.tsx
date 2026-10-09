import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, Link } from '@inertiajs/react';
import { Alert, Button, Card, CardHeader, CardTitle, StatusBadge } from '@euisis/ui';
import { useState, type ReactNode } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { AccountActionDialog, AccountStatus, permissionLabelKey, useLabel, type AccountAction, type AccountCan, type AccountSummary } from '@/Components/ProviderUsers/AccountUi';

type Account = AccountSummary & {
    last_login_ip: string | null;
    suspended_at: string | null;
    suspension_reason: string | null;
    suspended_by: string | null;
    created_at: string | null;
    created_by: string | null;
    updated_at: string | null;
    updated_by: string | null;
    can_sign_in: boolean;
    provider_services: Array<{ code: string | null; name_en: string | null; name_am: string | null }>;
    service_permissions: string[];
};

type HistoryItem = { id: string; event: string; actor: string | null; reason: string | null; at: string | null };

function Detail({ term, children }: { term: string; children: ReactNode }) {
    return (
        <div className="grid gap-1 py-2 sm:grid-cols-3 sm:gap-4">
            <dt className="text-sm text-[color:var(--app-muted-foreground)]">{term}</dt>
            <dd className="text-sm text-[color:var(--app-foreground)] sm:col-span-2">{children}</dd>
        </div>
    );
}

export default function ProviderUserShow({ account, history, can }: { account: Account; history: HistoryItem[]; can: AccountCan }) {
    const { t } = useLocale();
    const label = useLabel();
    const [pending, setPending] = useState<AccountAction | null>(null);

    const eventLabel = (event: string) => {
        const key = `auditLogs.events.${event.replace(/\./g, '_')}`;
        const text = t(key);
        return text === key ? event : text;
    };

    const actions = (
        <div className="flex flex-wrap gap-2">
            {can.update && <Button as={Link} href={route('provider-users.edit', account.id)} size="sm" variant="primary">{t('providerUsers.edit')}</Button>}
            {can.resetPassword && <Button type="button" size="sm" variant="outline" onClick={() => setPending('resetPassword')}>{t('providerUsers.resetPassword')}</Button>}
            {can.activate && <Button type="button" size="sm" variant="outline" onClick={() => setPending('activate')}>{t('providerUsers.activate')}</Button>}
            {can.suspend && <Button type="button" size="sm" variant="outline" onClick={() => setPending('suspend')}>{t('providerUsers.suspend')}</Button>}
            {can.restore && <Button type="button" size="sm" variant="primary" onClick={() => setPending('restore')}>{t('providerUsers.restore')}</Button>}
            {can.delete && <Button type="button" size="sm" variant="destructive" onClick={() => setPending('delete')}>{t('providerUsers.delete')}</Button>}
        </div>
    );

    return (
        <AuthenticatedLayout header={<PageHeader title={account.name} description={label(account.provider)} backHref={route('provider-users.index')} actions={actions} />}>
            <Head title={account.name} />

            <div className="space-y-4">
                {account.deleted_at && <Alert tone="warning">{t('providerUsers.deletedNotice')}</Alert>}

                <div className="grid gap-4 lg:grid-cols-3">
                    <div className="space-y-4 lg:col-span-2">
                        <Card>
                            <CardHeader><CardTitle>{t('providerUsers.details')}</CardTitle></CardHeader>
                            <dl className="divide-y divide-[color:var(--app-border)] px-4 pb-2 sm:px-6">
                                <Detail term={t('providerUsers.name')}>{account.name}</Detail>
                                <Detail term={t('providerUsers.email')}>{account.email ?? '—'}</Detail>
                                <Detail term={t('providerUsers.username')}>{account.username ?? '—'}</Detail>
                                <Detail term={t('providerUsers.phone')}>{account.phone_number ?? '—'}</Detail>
                                <Detail term={t('providerUsers.provider')}>
                                    {account.provider ? (
                                        <span>
                                            {label(account.provider)}
                                            {account.provider.code && <span className="text-[color:var(--app-muted-foreground)]"> ({account.provider.code})</span>}
                                            {account.provider.status !== 'active' && <StatusBadge tone="warning" className="ml-2">{t('providerUsers.statuses.inactive')}</StatusBadge>}
                                        </span>
                                    ) : '—'}
                                </Detail>
                                <Detail term={t('providerUsers.providerServices')}>
                                    {account.provider_services.length === 0
                                        ? t('providerUsers.noServices')
                                        : <span className="flex flex-wrap gap-1">{account.provider_services.map((s) => <StatusBadge key={s.code ?? ''} tone="info">{label(s)}</StatusBadge>)}</span>}
                                </Detail>
                                <Detail term={t('providerUsers.status')}><AccountStatus account={account} /></Detail>
                                {account.status === 'suspended' && (
                                    <>
                                        <Detail term={t('providerUsers.suspendedAt')}><LocalizedDateDisplay value={account.suspended_at} withTime /></Detail>
                                        <Detail term={t('providerUsers.suspendedBy')}>{account.suspended_by ?? '—'}</Detail>
                                        <Detail term={t('providerUsers.suspensionReason')}>{account.suspension_reason ?? '—'}</Detail>
                                    </>
                                )}
                                <Detail term={t('providerUsers.createdAt')}>
                                    <LocalizedDateDisplay value={account.created_at} withTime />{account.created_by ? ` · ${account.created_by}` : ''}
                                </Detail>
                                <Detail term={t('providerUsers.updatedAt')}>
                                    <LocalizedDateDisplay value={account.updated_at} withTime />{account.updated_by ? ` · ${account.updated_by}` : ''}
                                </Detail>
                            </dl>
                        </Card>

                        <Card>
                            <CardHeader><CardTitle>{t('providerUsers.sectionAccess')}</CardTitle></CardHeader>
                            <dl className="divide-y divide-[color:var(--app-border)] px-4 pb-2 sm:px-6">
                                <Detail term={t('providerUsers.role')}>
                                    {t(`providerUsers.roles.${account.provider_role}`)}
                                    <span className="block text-xs text-[color:var(--app-muted-foreground)]">{t(`providerUsers.roleHelp.${account.provider_role}`)}</span>
                                </Detail>
                                <Detail term={t('providerUsers.grantedPermissions')}>
                                    {account.provider_role !== 'operator'
                                        ? t('providerUsers.allPermissions')
                                        : account.service_permissions.length === 0
                                            ? t('providerUsers.none')
                                            : <ul className="list-inside list-disc">{account.service_permissions.map((key) => <li key={key}>{t(permissionLabelKey(key))}</li>)}</ul>}
                                </Detail>
                            </dl>
                        </Card>

                        <Card>
                            <CardHeader><CardTitle>{t('providerUsers.history')}</CardTitle></CardHeader>
                            <div className="px-4 pb-4 sm:px-6">
                                {history.length === 0 ? (
                                    <p className="text-sm text-[color:var(--app-muted-foreground)]">{t('providerUsers.historyEmpty')}</p>
                                ) : (
                                    <ol className="space-y-3">
                                        {history.map((item) => (
                                            <li key={item.id} className="border-l-2 border-[color:var(--app-border)] pl-3">
                                                <p className="text-sm font-medium text-[color:var(--app-foreground)]">{eventLabel(item.event)}</p>
                                                <p className="text-xs text-[color:var(--app-muted-foreground)]">
                                                    <LocalizedDateDisplay value={item.at} withTime /> · {item.actor ?? t('providerUsers.system')}
                                                    {item.reason && item.reason !== item.event ? ` · ${item.reason}` : ''}
                                                </p>
                                            </li>
                                        ))}
                                    </ol>
                                )}
                            </div>
                        </Card>
                    </div>

                    <div className="space-y-4">
                        <Card>
                            <CardHeader><CardTitle>{t('providerUsers.signIn')}</CardTitle></CardHeader>
                            <div className="space-y-3 px-4 pb-4 text-sm sm:px-6">
                                <StatusBadge tone={account.can_sign_in ? 'success' : 'danger'}>
                                    {account.can_sign_in ? t('providerUsers.canSignIn') : t('providerUsers.cannotSignIn')}
                                </StatusBadge>
                                {account.must_change_password && !account.deleted_at && (
                                    <Alert tone="warning">{t('providerUsers.mustChangePassword')}</Alert>
                                )}
                                <div>
                                    <p className="text-[color:var(--app-muted-foreground)]">{t('providerUsers.lastLogin')}</p>
                                    <LocalizedDateDisplay value={account.last_login_at} withTime fallback={t('providerUsers.never')} />
                                    {account.last_login_ip && <p className="text-xs text-[color:var(--app-muted-foreground)]">{t('providerUsers.lastLoginIp')}: {account.last_login_ip}</p>}
                                </div>
                                <div>
                                    <p className="text-[color:var(--app-muted-foreground)]">{t('providerUsers.portalAddress')}</p>
                                    <code className="select-all break-all text-xs">{route('provider.portal.login')}</code>
                                </div>
                            </div>
                        </Card>
                    </div>
                </div>
            </div>

            <AccountActionDialog action={pending} account={pending ? account : null} onClose={() => setPending(null)} />
        </AuthenticatedLayout>
    );
}
