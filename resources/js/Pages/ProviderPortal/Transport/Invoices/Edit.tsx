import TransportProviderLayout from '@/Layouts/TransportProviderLayout';
import { InvoiceForm } from '@/Components/transport/TransportPortalForms';
import { useLocale } from '@/hooks/useLocale';
import type { TransportContract, TransportInvoice } from '@/types/transport';

export default function Edit({ invoice, contracts, defaultPeriod }: { invoice: TransportInvoice; contracts: TransportContract[]; defaultPeriod: { start: string; end: string } }) {
    const { t } = useLocale();

    return (
        <TransportProviderLayout title={`${t('transport.invoice')} ${invoice.invoice_number}`}>
            <InvoiceForm invoice={invoice} contracts={contracts} defaultPeriod={defaultPeriod} />
        </TransportProviderLayout>
    );
}
