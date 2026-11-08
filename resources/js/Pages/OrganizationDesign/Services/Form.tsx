import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import ServiceForm, { emptyService, type ClassificationOption, type FunctionOption, type IsicOption, type Option, type ServiceFormValues } from '@/Components/organizationDesign/ServiceForm';
import { nameOf, useDesignText } from '@/Components/organizationDesign/ui';
import { Head, router } from '@inertiajs/react';

type ServiceRecord = {
    id: string; code: string; version_no: number; status: string; organization_id: string;
    name_en: string; name_am: string | null; description_en: string | null; description_am: string | null;
    service_type_id: string | null; service_classification_id: string | null; cpc_classification_id: string | null;
    beneficiary_type: string | null; delivery_channel: string | null; legal_basis: string | null; owner_organization_unit_id: string | null;
    effective_from: string | null; effective_to: string | null; function_ids: string[]; isic_activity_ids: string[];
};

type Props = {
    /** create: a new draft; edit: a draft; version: a new version of a published service. */
    mode: 'create' | 'edit' | 'version';
    service: ServiceRecord | null;
    organizationId: string | null;
    organizations: Option[] | null;
    organizationFunctions: FunctionOption[];
    organizationUnits: Option[];
    classifications: ClassificationOption[];
    cpcClassifications: ClassificationOption[];
    isicOptions: IsicOption[];
    serviceTypes: Option[];
    beneficiaryTypes: string[];
    deliveryChannels: string[];
};

/**
 * The service page for writing: one page, not a dialog, for a new draft, a
 * draft edit and a new version. On a new service the institution is chosen
 * first and the page reloads that institution's units and functions.
 */
export default function ServicesForm({ mode, service, organizationId, organizations, organizationFunctions, organizationUnits, classifications, cpcClassifications, isicOptions, serviceTypes, beneficiaryTypes, deliveryChannels }: Props) {
    const { locale, tt } = useDesignText();

    const fromService = (s: ServiceRecord): ServiceFormValues => ({
        organization_id: s.organization_id, code: s.code, name_en: s.name_en, name_am: s.name_am ?? '',
        description_en: s.description_en ?? '', description_am: s.description_am ?? '',
        service_type_id: s.service_type_id ?? '', service_classification_id: s.service_classification_id ?? '', cpc_classification_id: s.cpc_classification_id ?? '',
        beneficiary_type: s.beneficiary_type ?? '', delivery_channel: s.delivery_channel ?? '', legal_basis: s.legal_basis ?? '',
        owner_organization_unit_id: s.owner_organization_unit_id ?? '', effective_from: s.effective_from ?? '', effective_to: s.effective_to ?? '',
        function_ids: s.function_ids, isic_activity_ids: s.isic_activity_ids,
    });

    const initial: ServiceFormValues = mode === 'create' || service === null
        ? emptyService(organizationId ?? '')
        : mode === 'version' ? { ...fromService(service), effective_from: '', effective_to: '' } : fromService(service);

    const indexHref = route('organization-design.services.index');
    const showHref = service ? route('organization-design.services.show', service.id) : indexHref;
    const target = mode === 'create'
        ? { url: route('organization-design.services.store'), method: 'post' as const, cancelHref: indexHref }
        : mode === 'edit'
            ? { url: route('organization-design.services.update', service!.id), method: 'put' as const, cancelHref: showHref }
            : { url: route('organization-design.services.versions.store', service!.id), method: 'post' as const, cancelHref: showHref };

    const title = mode === 'create' ? tt('services.create') : mode === 'edit' ? tt('services.edit') : tt('services.newVersion');
    const description = mode === 'create'
        ? tt('services.createHelp')
        : service ? `${service.code} · ${nameOf(service, locale)} · ${tt('services.version')} ${service.version_no}${mode === 'version' ? ` — ${tt('services.newVersionHelp')}` : ''}` : undefined;

    const organizationName = organizations?.find((o) => o.id === organizationId);

    return (
        <AuthenticatedLayout contentWidth="form" header={<PageHeader title={title} description={description} backHref={target.cancelHref} />}>
            <Head title={organizationName ? `${title} · ${nameOf(organizationName, locale)}` : title} />
            <ServiceForm
                initial={initial}
                url={target.url}
                method={target.method}
                cancelHref={target.cancelHref}
                lockCode={mode === 'version'}
                lookups={{ organizations, functions: organizationFunctions, units: organizationUnits, classifications, cpcClassifications, isicOptions, serviceTypes, beneficiaryTypes, deliveryChannels }}
                onOrganizationChange={mode === 'create' ? (id) => router.get(route('organization-design.services.create'), id ? { organization_id: id } : {}, {
                    preserveState: true, preserveScroll: true, replace: true, only: ['organizationId', 'organizationFunctions', 'organizationUnits'],
                }) : undefined}
            />
        </AuthenticatedLayout>
    );
}
