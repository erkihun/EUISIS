import { CardHeading, ICONS } from '@/Components/ProviderPortal/AuthFields';
import ProviderSignInForm, { type SessionNotice } from '@/Components/ProviderPortal/ProviderSignInForm';
import { useLocale } from '@/hooks/useLocale';
import TransportAuthLayout from '@/Layouts/TransportAuthLayout';

/** /provider/portal/transport/login — the transport provider's own sign-in. */
export default function TransportLogin({ status, sessionNotice }: { status?: string | null; sessionNotice?: SessionNotice | null }) {
    const { t } = useLocale();

    return (
        <TransportAuthLayout title={t('providerPortal.transportLogin')}>
            <CardHeading icon={ICONS.bus} title={t('providerPortal.transportLogin')} subtitle={t('providerPortal.transportLoginSubtitle')} />
            <ProviderSignInForm portal="transport" status={status} sessionNotice={sessionNotice} />
        </TransportAuthLayout>
    );
}
