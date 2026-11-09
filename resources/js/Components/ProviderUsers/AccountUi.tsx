import { router } from '@inertiajs/react';
import { AppDialog, Button, FormField, Input, StatusBadge, Textarea, type Tone } from '@euisis/ui';
import { useEffect, useState } from 'react';
import PasswordPolicyChecklist from '@/Components/PasswordPolicyChecklist';
import { useLocale } from '@/hooks/useLocale';

/** Shared pieces of the /provider-users pages. */

export type NamedItem = { id: string; code?: string | null; name_en?: string | null; name_am?: string | null };

export type AccountCan = {
    view: boolean;
    update: boolean;
    resetPassword: boolean;
    suspend: boolean;
    activate: boolean;
    delete: boolean;
    restore: boolean;
};

export type AccountSummary = {
    id: string;
    name: string;
    email: string | null;
    username: string | null;
    phone_number: string | null;
    provider_role: 'owner' | 'manager' | 'operator';
    status: 'active' | 'inactive' | 'suspended';
    portal_enabled: boolean;
    must_change_password: boolean;
    last_login_at: string | null;
    deleted_at: string | null;
    provider: (NamedItem & { status: string; deleted: boolean; type: { code: string; name_en: string | null; name_am: string | null } | null }) | null;
};

export function useLabel() {
    const { locale } = useLocale();
    return (item: { name_en?: string | null; name_am?: string | null; code?: string | null } | null | undefined, fallback = '—'): string => {
        if (!item) return fallback;
        const name = locale === 'am' ? item.name_am || item.name_en : item.name_en || item.name_am;
        return name || item.code || fallback;
    };
}

const STATUS_TONES: Record<string, Tone> = { active: 'success', inactive: 'neutral', suspended: 'danger', deleted: 'neutral' };

export function AccountStatus({ account }: { account: Pick<AccountSummary, 'status' | 'deleted_at' | 'portal_enabled'> }) {
    const { t } = useLocale();
    const status = account.deleted_at ? 'deleted' : account.status;
    return (
        <span className="inline-flex flex-wrap items-center gap-1">
            <StatusBadge tone={STATUS_TONES[status] ?? 'neutral'}>{t(`providerUsers.statuses.${status}`)}</StatusBadge>
            {!account.deleted_at && !account.portal_enabled && <StatusBadge tone="warning">{t('providerUsers.cannotSignIn')}</StatusBadge>}
        </span>
    );
}

/** Portal permission keys contain dots; their labels live under underscores. */
export function permissionLabelKey(key: string): string {
    return `providerUsers.permissions.${key.replace(/\./g, '_')}`;
}

export type AccountAction = 'resetPassword' | 'suspend' | 'activate' | 'delete' | 'restore';

const ACTION_ROUTES: Record<AccountAction, string> = {
    resetPassword: 'provider-users.reset-password',
    suspend: 'provider-users.suspend',
    activate: 'provider-users.activate',
    delete: 'provider-users.destroy',
    restore: 'provider-users.restore',
};

/**
 * Confirmation for one account action. Reset takes an optional password
 * (blank = a generated one-time password, shown once by the layout);
 * suspend takes an optional reason.
 */
export function AccountActionDialog({ action, account, onClose }: {
    action: AccountAction | null;
    account: Pick<AccountSummary, 'id' | 'name' | 'email' | 'username'> | null;
    onClose: () => void;
}) {
    const { t } = useLocale();
    const [value, setValue] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        setValue('');
        setError(undefined);
    }, [action, account?.id]);

    if (!action || !account) return null;

    const destructive = action === 'delete' || action === 'suspend';
    const label = t(`providerUsers.${action}`);
    const description = t(action === 'resetPassword' ? 'providerUsers.resetDescription' : `providerUsers.${action}Description`);

    const submit = () => {
        setBusy(true);
        const url = route(ACTION_ROUTES[action], account.id);
        const options = {
            preserveScroll: true,
            onError: (errors: Record<string, string>) => setError(errors.password ?? errors.reason ?? Object.values(errors)[0]),
            onSuccess: () => onClose(),
            onFinish: () => setBusy(false),
        };
        if (action === 'delete') {
            router.delete(url, options);
        } else {
            router.post(url, action === 'resetPassword' ? { password: value } : action === 'suspend' ? { reason: value } : {}, options);
        }
    };

    return (
        <AppDialog open onClose={onClose} title={`${label}: ${account.name}`} description={description}
            footer={<>
                <Button type="button" variant="ghost" onClick={onClose}>{t('providerUsers.cancel')}</Button>
                <Button type="button" variant={destructive ? 'destructive' : 'primary'} disabled={busy} onClick={submit}>{busy ? t('providerUsers.saving') : label}</Button>
            </>}>
            {action === 'resetPassword' && (
                <div className="space-y-3">
                    <FormField label={t('providerUsers.newPasswordOptional')} error={error}>
                        {({ id, describedBy, invalid }) => (
                            <Input id={id} type="password" autoComplete="new-password" value={value} aria-describedby={describedBy} aria-invalid={invalid}
                                onChange={(e) => setValue(e.target.value)} />
                        )}
                    </FormField>
                    {value !== '' && <PasswordPolicyChecklist password={value} personal={[account.name, account.email, account.username]} />}
                </div>
            )}
            {action === 'suspend' && (
                <FormField label={t('providerUsers.reasonOptional')} error={error}>
                    {({ id, describedBy, invalid }) => (
                        <Textarea id={id} rows={3} maxLength={500} value={value} aria-describedby={describedBy} aria-invalid={invalid}
                            onChange={(e) => setValue(e.target.value)} />
                    )}
                </FormField>
            )}
            {action !== 'resetPassword' && action !== 'suspend' && error && <p className="text-sm text-red-700 dark:text-red-400">{error}</p>}
        </AppDialog>
    );
}
