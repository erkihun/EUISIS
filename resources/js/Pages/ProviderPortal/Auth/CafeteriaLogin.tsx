import { CardHeading, ICONS } from '@/Components/ProviderPortal/AuthFields';
import ProviderSignInForm, { type SessionNotice } from '@/Components/ProviderPortal/ProviderSignInForm';
import { useLocale } from '@/hooks/useLocale';
import CafeteriaAuthLayout from '@/Layouts/CafeteriaAuthLayout';

/** /provider/portal/cafeteria/login — the cafeteria provider's own sign-in. */
export default function CafeteriaLogin({ status, sessionNotice }: { status?: string | null; sessionNotice?: SessionNotice | null }) {
    const { t } = useLocale();

    return (
        <CafeteriaAuthLayout title={t('providerPortal.cafeteriaLogin')}>
            <CardHeading icon={ICONS.cup} title={t('providerPortal.cafeteriaLogin')} subtitle={t('providerPortal.cafeteriaLoginSubtitle')} />
            <ProviderSignInForm portal="cafeteria" status={status} sessionNotice={sessionNotice} />
        </CafeteriaAuthLayout>
    );
}
