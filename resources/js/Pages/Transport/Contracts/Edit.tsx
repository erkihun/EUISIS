import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head } from '@inertiajs/react';
import { ContractForm } from '@/Components/transport/TransportVerifiedForms';
import { useLocale } from '@/hooks/useLocale';
import type { ProviderOption, TransportContract } from '@/types/transport';

type Props = { contract: TransportContract; today: string; providers: ProviderOption[]; models: { value: string; terms: string[] }[]; periods: string[]; termKeys: string[] };

export default function Edit(props: Props) {
    const { t } = useLocale();
    return (
        <AuthenticatedLayout contentWidth="form" header={<PageHeader title={`${t('transport.editContract')} — ${props.contract.contract_reference} v${props.contract.version_no}`} backHref={route('transport.contracts.show', props.contract.id)} />}>
            <Head title={t('transport.editContract')} />
            <ContractForm {...props} />
        </AuthenticatedLayout>
    );
}
