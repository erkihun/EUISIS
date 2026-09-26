import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';
import CafeteriaForm, { type CafeteriaFormData, type NetworkOption, type OrgOption, type ParentOption, type PayeeOption } from './CafeteriaForm';

export default function ProvidersCreate({ organizations, payees, networks, parents, defaults }: {
    organizations: OrgOption[];
    payees: PayeeOption[];
    networks: NetworkOption[];
    parents: ParentOption[];
    defaults: { provider_id?: string | null; cafeteria_service_network_id?: string | null; location_type?: CafeteriaFormData['location_type'] };
}) {
    const { t } = useLocale();
    const startsNetwork = !defaults.cafeteria_service_network_id && (defaults.location_type ?? 'main') === 'main';
    const form = useForm<CafeteriaFormData>({
        provider_mode: payees.length === 0 ? 'new' : 'existing',
        provider_id: defaults.provider_id ?? '',
        provider_code: '',
        provider_name_en: '',
        provider_name_am: '',
        location_type: defaults.location_type ?? 'main',
        network_mode: payees.length === 0 || startsNetwork ? 'new' : 'existing',
        cafeteria_service_network_id: defaults.cafeteria_service_network_id ?? '',
        network_code: '',
        network_name_en: '',
        parent_cafeteria_id: '',
        code: '',
        name_en: '',
        name_am: '',
        organization_id: '',
        operational_status: 'open',
        opening_time: '',
        closing_time: '',
        capacity: '',
        contact_person: '',
        phone_number: '',
        email: '',
        location: '',
        is_active: true,
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        form.post(route('cafeteria.providers.store'));
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={t('cafeteriaPolicy.addCafeteria')} description={t('cafeteriaPolicy.cafeteriasDescription')} backHref={route('cafeteria.providers.index')} />}>
            <Head title={t('cafeteriaPolicy.addCafeteria')} />
            <CafeteriaForm form={form} mode="create" organizations={organizations} payees={payees} networks={networks} parents={parents}
                onSubmit={submit} cancelHref={route('cafeteria.providers.index')} />
        </AuthenticatedLayout>
    );
}
