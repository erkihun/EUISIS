import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head } from '@inertiajs/react';
import { EligibilityForm } from '@/Components/transport/TransportVerifiedForms';
import { useLocale } from '@/hooks/useLocale';
import type { ProviderOption, TransportEligibility } from '@/types/transport';

export default function Edit(props: { grant: TransportEligibility; today: string; providers: ProviderOption[]; contracts: { id: string; contract_reference: string; version_no: number }[] }) {
    const { t } = useLocale();
    return (
        <AuthenticatedLayout contentWidth="form" header={<PageHeader title={t('transport.editEligibility')} backHref={route('transport.eligibility.index')} />}>
            <Head title={t('transport.editEligibility')} />
            <EligibilityForm {...props} />
        </AuthenticatedLayout>
    );
}
