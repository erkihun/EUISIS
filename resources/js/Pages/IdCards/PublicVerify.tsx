import { Head } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { CheckCircle, XCircle, AlertTriangle, ShieldCheck } from '@/Components/Icons';

type VerifyResult = {
    valid: boolean;
    status_code: 'active' | 'expired' | 'revoked' | 'replaced' | 'lost' | 'inactive' | 'invalid';
    organization: string | null;
    card_number?: string | null;
    expires_at?: string | null;
};

type Props = {
    result: VerifyResult;
};

export default function PublicVerify({ result }: Props) {
    const { t } = useLocale();

    const statusLabel: Record<VerifyResult['status_code'], string> = {
        active:   t('idCards.verifyStatusActive'),
        expired:  t('idCards.verifyStatusExpired'),
        revoked:  t('idCards.verifyStatusRevoked'),
        replaced: t('idCards.verifyStatusReplaced'),
        lost:     t('idCards.verifyStatusLost'),
        inactive: t('idCards.verifyStatusInactive'),
        invalid:  t('idCards.verifyStatusInvalid'),
    };

    const isExpired = result.status_code === 'expired';
    const isValid   = result.valid;

    const bannerClass = isValid
        ? 'bg-emerald-50 border-emerald-200 dark:bg-emerald-950/40 dark:border-emerald-800'
        : isExpired
            ? 'bg-amber-50 border-amber-200 dark:bg-amber-950/40 dark:border-amber-800'
            : 'bg-red-50 border-red-200 dark:bg-red-950/40 dark:border-red-800';

    const IconEl = isValid
        ? <CheckCircle className="h-16 w-16 text-emerald-500" />
        : isExpired
            ? <AlertTriangle className="h-16 w-16 text-amber-500" />
            : <XCircle className="h-16 w-16 text-red-500" />;

    return (
        <>
            <Head title={t('idCards.verifyPageTitle')} />

            <div className="flex min-h-screen items-center justify-center bg-(--app-surface-muted) px-4">
                <div className="w-full max-w-md">
                    {/* Header */}
                    <div className="mb-6 text-center">
                        <ShieldCheck className="mx-auto mb-2 h-8 w-8 text-(--app-muted-foreground)" />
                        <p className="text-sm text-(--app-muted-foreground)">
                            {t('idCards.verifyOfficialCheck')}
                        </p>
                    </div>

                    {/* Result card */}
                    <div className={`rounded-panel border p-8 text-center shadow-lg ${bannerClass}`}>
                        <div className="mb-4 flex justify-center">{IconEl}</div>

                        <h1 className="mb-1 text-2xl font-bold text-(--app-foreground)">
                            {statusLabel[result.status_code]}
                        </h1>

                        {result.organization && (
                            <p className="mt-3 text-base text-(--app-foreground)">
                                {result.organization}
                            </p>
                        )}

                        {result.card_number && (
                            <p className="mt-2 font-mono text-sm text-(--app-muted-foreground)">
                                {t('idCards.verifyCardNumber')} {result.card_number}
                            </p>
                        )}

                        {result.expires_at && (
                            <p className="mt-1 text-sm text-(--app-muted-foreground)">
                                {t('idCards.verifyExpires')} <LocalizedDateDisplay value={result.expires_at} />
                            </p>
                        )}
                    </div>

                    <p className="mt-6 text-center text-xs text-(--app-muted-foreground)">
                        {t('idCards.verifyFooter')}
                    </p>
                </div>
            </div>
        </>
    );
}
