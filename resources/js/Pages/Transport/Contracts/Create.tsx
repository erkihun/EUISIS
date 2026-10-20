import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head } from '@inertiajs/react';
import { ContractForm } from '@/Components/transport/TransportVerifiedForms';
import { useLocale } from '@/hooks/useLocale';
import type { ProviderOption } from '@/types/transport';

type Props = { today: string; providers: ProviderOption[]; models: { value: string; terms: string[] }[]; periods: string[]; termKeys: string[] };

export default function Create(props: Props) {
    const { t } = useLocale();
    return (
        <AuthenticatedLayout contentWidth="form" header={<PageHeader title={t('transport.newContract')} backHref={route('transport.contracts.index')} />}>
            <Head title={t('transport.newContract')} />
            <ContractForm {...props} />
        </AuthenticatedLayout>
    );
}
