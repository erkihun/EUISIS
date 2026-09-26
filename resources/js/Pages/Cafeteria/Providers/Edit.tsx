import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';
import type { NamePair } from '@/Components/Cafeteria/PolicyUi';
import CafeteriaForm, { type CafeteriaFormData, type NetworkOption, type OrgOption, type ParentOption, type PayeeOption } from './CafeteriaForm';

type Cafeteria = {
    id: string; code: string; name_en: string; name_am: string | null;
    organization_id: string | null;
    provider_id: string | null; provider: NamePair;
    cafeteria_service_network_id: string | null; parent_cafeteria_id: string | null;
    location_type: CafeteriaFormData['location_type']; operational_status: CafeteriaFormData['operational_status'];
    opening_time: string | null; closing_time: string | null; capacity: number | null;
    contact_person: string | null; phone_number: string | null; email: string | null; location: string | null;
    is_active: boolean;
};

export default function ProvidersEdit({ provider, organizations, payees, networks, parents }: {
    provider: Cafeteria;
    organizations: OrgOption[];
    payees: PayeeOption[];
    networks: NetworkOption[];
    parents: ParentOption[];
}) {
    const { t } = useLocale();
    const form = useForm<CafeteriaFormData>({
        provider_mode: 'existing',
        provider_id: provider.provider_id ?? '',
        provider_code: '',
        provider_name_en: '',
        provider_name_am: '',
        location_type: provider.location_type ?? 'main',
        network_mode: 'existing',
        cafeteria_service_network_id: provider.cafeteria_service_network_id ?? '',
        network_code: '',
        network_name_en: '',
        parent_cafeteria_id: provider.parent_cafeteria_id ?? '',
        code: provider.code,
        name_en: provider.name_en,
        name_am: provider.name_am ?? '',
        organization_id: provider.organization_id ?? '',
        operational_status: provider.operational_status ?? 'open',
        opening_time: provider.opening_time ?? '',
        closing_time: provider.closing_time ?? '',
        capacity: provider.capacity ? String(provider.capacity) : '',
        contact_person: provider.contact_person ?? '',
        phone_number: provider.phone_number ?? '',
        email: provider.email ?? '',
        location: provider.location ?? '',
        is_active: provider.is_active,
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            // The provider is fixed once set; send it only to adopt a legacy location.
            provider_id: provider.provider_id ? undefined : data.provider_id || undefined,
        }));
        form.patch(route('cafeteria.providers.update', provider.id));
    }

    // Parents exclude the location itself (a location is never its own parent).
    const parentOptions = parents.filter((p) => p.id !== provider.id);

    return (
        <AuthenticatedLayout header={<PageHeader title={t('cafeteriaPolicy.editCafeteria')} backHref={route('cafeteria.providers.show', provider.id)} />}>
            <Head title={t('cafeteriaPolicy.editCafeteria')} />
            <CafeteriaForm form={form} mode="edit" organizations={organizations} payees={payees} networks={networks} parents={parentOptions}
                lockedProvider={provider.provider_id ? provider.provider : undefined}
                onSubmit={submit} cancelHref={route('cafeteria.providers.show', provider.id)} />
        </AuthenticatedLayout>
    );
}
