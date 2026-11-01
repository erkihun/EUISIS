import { usePage } from '@inertiajs/react';
import { Card } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';

type SignatureProps = { signature?: { url: string | null; read_only: boolean } };

/**
 * The person's signature as it appears on letters and decisions (court-ms
 * only). Read-only here: an administrator uploads or replaces it from the
 * users pages, so the account form never offers a signature field.
 */
export default function SignatureCard() {
    const { t } = useLocale();
    const { signature } = usePage().props as SignatureProps;

    if (!signature) return null;

    return (
        <Card>
            <h2 className="text-sm font-semibold text-(--app-foreground)">{t('cm.auth.signature')}</h2>
            <p className="mt-1 text-sm text-(--app-muted-foreground)">{t('cm.auth.signature_read_only')}</p>
            <div className="mt-4 flex min-h-24 items-center justify-center rounded-lg border border-dashed border-(--app-border) bg-(--app-surface-muted) p-4">
                {signature.url
                    ? <img src={signature.url} alt={t('cm.auth.signature')} className="max-h-24 object-contain" />
                    : <span className="text-sm text-(--app-muted-foreground)">{t('cm.auth.signature_none')}</span>}
            </div>
        </Card>
    );
}
