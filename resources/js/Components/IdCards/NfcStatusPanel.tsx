import { Link, router } from '@inertiajs/react';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import NfcStatusBadge, { useNfcTypeLabel } from '@/Components/Nfc/NfcStatusBadge';
import { NfcIcon } from '@/Components/Icons';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';
import { toast } from '@/lib/toast';

import { buttonClassName } from '@/Components/ui';
export type NfcStatus = {
    can: Record<string, boolean>;
    credentials: {
        id: string;
        credential_id: string;
        status: string;
        credential_type: string;
        issued_at: string;
        activated_at: string | null;
        expires_at: string | null;
        last_used_at: string | null;
        key_version: string | null;
    }[];
};

/** Lifecycle verbs offered for each state. */
const ACTIONS_BY_STATUS: Record<string, string[]> = {
    pending: ['activate', 'lost', 'revoke', 'replace'],
    active: ['suspend', 'lost', 'revoke', 'replace'],
    suspended: ['activate', 'lost', 'revoke', 'replace'],
    lost: ['revoke', 'replace'],
    revoked: ['replace'],
    replaced: [],
    expired: ['replace'],
};

/** Which permission each verb consumes — mirrors the server-side map. */
const PERMISSION_BY_ACTION: Record<string, string> = {
    activate: 'activate',
    suspend: 'suspend',
    lost: 'revoke',
    revoke: 'revoke',
    replace: 'replace',
};

/** Actions that end a credential's ability to authorize services. */
const DESTRUCTIVE = new Set(['lost', 'revoke', 'replace']);

export default function NfcStatusPanel({ cardId, nfc }: { cardId: string; nfc: NfcStatus }) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const typeLabel = useNfcTypeLabel();

    if (!nfc.can.view) return null;

    const credentials = nfc.credentials ?? [];
    const live = credentials.filter((c) => ['pending', 'active', 'suspended'].includes(c.status));
    const isEnabled = credentials.some((c) => c.status === 'active');
    const canProvision = nfc.can.provision && live.length === 0;

    async function runAction(credentialId: string, action: string) {
        const verb = action.charAt(0).toUpperCase() + action.slice(1);

        const { confirmed } = await confirm({
            title: t(`nfc.confirm${verb}Title`),
            description: t(`nfc.confirm${verb}Body`),
            confirmLabel: t(`nfc.${action === 'lost' ? 'markLost' : action}`),
            variant: DESTRUCTIVE.has(action) ? 'danger' : 'default',
        });

        if (!confirmed) return;

        router.post(
            route('nfc.transition', { card: cardId, credential: credentialId, action }),
            {},
            {
                preserveScroll: true,
                onSuccess: () => toast.success(t('nfc.actionApplied')),
                onError: (errors) => toast.error(Object.values(errors)[0] ?? t('common.error')),
            },
        );
    }

    return (
        <section className="mb-6 rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5">
            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-2">
                    <NfcIcon className="h-5 w-5 text-[color:var(--app-muted-foreground)]" />
                    <h2 className="text-sm font-semibold text-[color:var(--app-foreground)]">
                        {t('nfc.credential')}
                    </h2>
                    <span className="text-xs text-[color:var(--app-muted-foreground)]">
                        {t('nfc.nfcEnabled')}: {isEnabled ? t('nfc.yes') : t('nfc.no')}
                    </span>
                </div>

                {canProvision && (
                    <Link
                        href={route('nfc.provision.create', cardId)}
                        className={buttonClassName({ variant: 'primary' })}
                    >
                        {t('nfc.provision')}
                    </Link>
                )}
            </div>

            {credentials.length === 0 ? (
                <p className="rounded-card border border-dashed border-[color:var(--app-border-strong)] py-8 text-center text-sm text-[color:var(--app-muted-foreground)]">
                    {t('nfc.notProvisioned')}
                </p>
            ) : (
                <div className="space-y-3">
                    {credentials.map((credential) => {
                        const actions = (ACTIONS_BY_STATUS[credential.status] ?? []).filter(
                            (action) => nfc.can[PERMISSION_BY_ACTION[action]],
                        );

                        return (
                            <div
                                key={credential.credential_id}
                                className="rounded-card border border-[color:var(--app-border)] p-4"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="break-all font-mono text-xs text-[color:var(--app-muted-foreground)]">
                                            {credential.credential_id}
                                        </p>
                                        <p className="mt-1 text-xs text-[color:var(--app-muted-foreground)]">
                                            {typeLabel(credential.credential_type)}
                                        </p>
                                    </div>
                                    <NfcStatusBadge status={credential.status} />
                                </div>

                                <dl className="mt-3 grid grid-cols-2 gap-3 text-xs sm:grid-cols-4">
                                    <div>
                                        <dt className="text-[color:var(--app-muted-foreground)]">{t('nfc.issuedDate')}</dt>
                                        <dd className="text-[color:var(--app-foreground)]">
                                            <LocalizedDateDisplay value={credential.issued_at} />
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-[color:var(--app-muted-foreground)]">{t('nfc.lastUsed')}</dt>
                                        <dd className="text-[color:var(--app-foreground)]">
                                            <LocalizedDateDisplay
                                                value={credential.last_used_at}
                                                withTime
                                                fallback={t('nfc.neverUsed')}
                                            />
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-[color:var(--app-muted-foreground)]">{t('nfc.expiresAt')}</dt>
                                        <dd className="text-[color:var(--app-foreground)]">
                                            <LocalizedDateDisplay value={credential.expires_at} />
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-[color:var(--app-muted-foreground)]">{t('nfc.keyVersion')}</dt>
                                        <dd className="text-[color:var(--app-foreground)]">
                                            {credential.key_version ?? '—'}
                                        </dd>
                                    </div>
                                </dl>

                                {credential.status === 'pending' && (
                                    <p className="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
                                        {t('nfc.waitingForHardware')}
                                    </p>
                                )}

                                {actions.length > 0 && (
                                    <div className="mt-3 flex flex-wrap gap-2">
                                        {actions.map((action) => (
                                            <button
                                                key={action}
                                                type="button"
                                                onClick={() => runAction(credential.credential_id, action)}
                                                className={`rounded-lg border px-3 py-1.5 text-sm font-medium transition ${
                                                    DESTRUCTIVE.has(action)
                                                        ? 'border-red-300 text-red-700 hover:bg-red-50 dark:border-red-800 dark:text-red-400 dark:hover:bg-red-900/20'
                                                        : 'border-[color:var(--app-border-strong)] text-[color:var(--app-foreground)] hover:bg-[color:var(--app-surface-muted)]'
                                                }`}
                                            >
                                                {t(`nfc.${action === 'lost' ? 'markLost' : action}`)}
                                            </button>
                                        ))}
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>
            )}

            <p className="mt-4 rounded-lg bg-blue-50 px-3 py-2 text-xs text-blue-800 dark:bg-blue-900/20 dark:text-blue-300">
                {t('nfc.qrUnaffectedNote')}
            </p>
        </section>
    );
}
