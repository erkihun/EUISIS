import TransportProviderLayout from '@/Layouts/TransportProviderLayout';
import { InvoiceForm } from '@/Components/transport/TransportPortalForms';
import { useLocale } from '@/hooks/useLocale';
import type { TransportContract } from '@/types/transport';

export default function Create({ contracts, defaultPeriod }: { contracts: TransportContract[]; defaultPeriod: { start: string; end: string } }) {
    const { t } = useLocale();

    return (
        <TransportProviderLayout title={t('transport.newInvoice')}>
            <InvoiceForm contracts={contracts} defaultPeriod={defaultPeriod} />
        </TransportProviderLayout>
    );
}
