import StatusBadge from '@/Components/StatusBadge';
import { useLocale } from '@/hooks/useLocale';

/** Every state an NFC credential can hold. */
export type NfcCredentialStatus =
    | 'pending'
    | 'active'
    | 'suspended'
    | 'lost'
    | 'revoked'
    | 'replaced'
    | 'expired';

/**
 * Translation key per status. The shared `StatusBadge` already owns the colour
 * for each of these words, so this component adds only localisation — keeping
 * NFC badges identical to card and employee badges elsewhere in the system.
 */
const LABEL_KEYS: Record<string, string> = {
    pending: 'nfc.statusPending',
    active: 'nfc.statusActive',
    suspended: 'nfc.statusSuspended',
    lost: 'nfc.statusLost',
    revoked: 'nfc.statusRevoked',
    replaced: 'nfc.statusReplaced',
    expired: 'nfc.statusExpired',
};

export default function NfcStatusBadge({
    status,
    className = '',
}: {
    status: string;
    className?: string;
}) {
    const { t } = useLocale();
    const key = LABEL_KEYS[status];

    // Unknown states fall through to the raw value rather than rendering blank,
    // so a status added server-side stays legible until it is translated.
    return <StatusBadge status={status} label={key ? t(key) : status} className={className} />;
}

/** Result badge for verification logs: allowed / blocked. */
export function NfcResultBadge({ result }: { result: string }) {
    const { t } = useLocale();
    const isAllowed = result === 'allowed';

    return (
        <StatusBadge
            status={isAllowed ? 'active' : 'revoked'}
            label={isAllowed ? t('nfc.resultAllowed') : t('nfc.resultBlocked')}
        />
    );
}

/** Human label for a credential type, used in tables and detail panels. */
export function useNfcTypeLabel() {
    const { t } = useLocale();

    return (type: string): string =>
        ({
            secure_smart_card: t('nfc.typeSecureSmartCard'),
            ndef_reference: t('nfc.typeNdefReference'),
            mobile_credential: t('nfc.typeMobileCredential'),
        })[type] ?? type;
}

/** Human label for a terminal type. */
export function useTerminalTypeLabel() {
    const { t } = useLocale();

    return (type: string): string =>
        ({
            cafeteria: t('nfc.typeCafeteria'),
            transport: t('nfc.typeTransport'),
            verification: t('nfc.typeVerification'),
            access: t('nfc.typeAccess'),
            other: t('nfc.typeOther'),
        })[type] ?? type;
}
