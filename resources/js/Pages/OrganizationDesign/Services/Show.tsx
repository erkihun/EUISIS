import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import ServiceForm, { type ClassificationOption, type FunctionOption, type IsicOption, type Option, type ServiceFormValues } from '@/Components/organizationDesign/ServiceForm';
import { StatusPill, nameOf, titleOf, useDesignText } from '@/Components/organizationDesign/ui';
import { Button, Card, CardHeader, CardTitle, actionLinkClassName } from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import type { ServiceSummary } from './Index';

type Props = {
    service: ServiceSummary & {
        description_en: string | null; description_am: string | null; legal_basis: string | null; service_type_id: string | null;
        service_type: Option | null; cpc_classification_id: string | null; cpc_classification: (Option & { revision: string }) | null;
        owner_organization_unit_id: string | null; approved_by: string | null; approved_at: string | null; created_by: string | null;
        function_ids: string[]; isic_activity_ids: string[];
    };
    functions: { id: string; code: string; title_en: string; title_am: string | null; mandate_code: string | null }[];
    isicActivities: { id: string; isic_code: string; name_en: string; name_am: string | null; revision: string }[];
    versions: { id: string; version_no: number; status: string; effective_from: string | null; effective_to: string | null }[];
    history: { from: string | null; to: string; actor: string | null; reason: string | null; at: string }[];
    organizationFunctions: FunctionOption[];
    organizationUnits: Option[];
    classifications: ClassificationOption[];
    cpcClassifications: ClassificationOption[];
    isicOptions: IsicOption[];
    serviceTypes: Option[];
    beneficiaryTypes: string[];
    deliveryChannels: string[];
    can: { update: boolean; publish: boolean; version: boolean };
};

export default function ServiceShow({ service, functions, isicActivities, versions, history, organizationFunctions, organizationUnits, classifications, cpcClassifications, isicOptions, serviceTypes, beneficiaryTypes, deliveryChannels, can }: Props) {
    const { locale, tt } = useDesignText();
    const { confirm } = useConfirm();
    const [editing, setEditing] = useState(false);
    const [versioning, setVersioning] = useState(false);

    const values: ServiceFormValues = {
        organization_id: service.organization_id, code: service.code, name_en: service.name_en, name_am: service.name_am ?? '',
        description_en: service.description_en ?? '', description_am: service.description_am ?? '',
        service_type_id: service.service_type_id ?? '', service_classification_id: service.service_classification_id ?? '', cpc_classification_id: service.cpc_classification_id ?? '',
        beneficiary_type: service.beneficiary_type ?? '', delivery_channel: service.delivery_channel ?? '', legal_basis: service.legal_basis ?? '',
        owner_organization_unit_id: service.owner_organization_unit_id ?? '', effective_from: service.effective_from ?? '', effective_to: service.effective_to ?? '',
        function_ids: service.function_ids, isic_activity_ids: service.isic_activity_ids,
    };
    const lookups = { functions: organizationFunctions, units: organizationUnits, classifications, cpcClassifications, isicOptions, serviceTypes, beneficiaryTypes, deliveryChannels };

    async function publish() {
        const { confirmed } = await confirm({ title: tt('services.publish'), description: tt('services.publishConfirm'), variant: 'warning' });
        if (confirmed) router.post(route('organization-design.services.publish', service.id), {}, { preserveScroll: true });
    }

    async function cancel() {
        const { confirmed } = await confirm({ title: tt('services.cancel'), description: tt('services.cancelConfirm'), variant: 'danger' });
        if (confirmed) router.post(route('organization-design.services.cancel', service.id));
    }

    const detail = (label: string, value: React.ReactNode) => (
        <div className="min-w-0">
            <dt className="text-xs font-medium text-(--app-muted-foreground)">{label}</dt>
            <dd className="mt-0.5 wrap-break-word text-sm text-(--app-foreground)">{value === null || value === undefined || value === '' ? '—' : value}</dd>
        </div>
    );

    return (
        <AuthenticatedLayout header={
            <PageHeader
                backHref={route('organization-design.services.index')}
                title={`${service.code} · ${nameOf(service, locale)}`}
                description={`${nameOf(service.organization, locale)} · ${tt('services.version')} ${service.version_no}`}
                actions={(
                    <div className="flex flex-wrap gap-2">
                        {can.update && <Button variant="outline" onClick={() => setEditing(true)}>{tt('services.edit')}</Button>}
                        {can.update && <Button variant="destructiveOutline" onClick={cancel}>{tt('services.cancel')}</Button>}
                        {can.version && <Button variant="outline" onClick={() => setVersioning(true)}>{tt('services.newVersion')}</Button>}
                        {can.publish && <Button variant="primary" onClick={publish}>{tt('services.publish')}</Button>}
                    </div>
                )}
            />
        }>
            <Head title={`${service.code} · ${nameOf(service, locale)}`} />

            <div className="grid gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <Card>
                        <CardHeader className="flex items-center justify-between gap-3">
                            <CardTitle>{tt('services.details')}</CardTitle>
                            <StatusPill status={service.status} />
                        </CardHeader>
                        <dl className="grid gap-4 p-5 sm:grid-cols-2">
                            {detail(tt('fields.serviceClassification'), service.classification ? `${service.classification.code} · ${nameOf(service.classification, locale)}` : null)}
                            {detail(tt('fields.cpcClassification'), service.cpc_classification ? `${service.cpc_classification.code} · ${nameOf(service.cpc_classification, locale)} (${service.cpc_classification.revision})` : null)}
                            {detail(tt('fields.beneficiaryType'), service.beneficiary_type ? tt(`services.beneficiary.${service.beneficiary_type}`) : null)}
                            {detail(tt('fields.deliveryChannel'), service.delivery_channel ? tt(`services.channel.${service.delivery_channel}`) : null)}
                            {detail(tt('fields.ownerUnit'), nameOf(service.owner_unit, locale))}
                            {detail(tt('fields.serviceType'), nameOf(service.service_type, locale))}
                            {detail(tt('fields.legalBasis'), service.legal_basis)}
                            {detail(tt('fields.effectiveFrom'), <LocalizedDateDisplay value={service.effective_from} />)}
                            {detail(tt('fields.effectiveTo'), service.effective_to ? <LocalizedDateDisplay value={service.effective_to} /> : null)}
                            {detail(tt('services.draftedBy'), service.created_by)}
                            {detail(tt('services.publishedBy'), service.approved_by)}
                            {detail(tt('fields.descriptionEn'), service.description_en)}
                            {detail(tt('fields.descriptionAm'), service.description_am)}
                        </dl>
                        {service.status === 'draft' && !can.publish && can.update && (
                            <p className="border-t border-(--app-border) px-5 py-3 text-xs text-(--app-muted-foreground)">{tt('services.publishByOther')}</p>
                        )}
                    </Card>

                    <Card>
                        <CardHeader><CardTitle>{tt('services.justifiedBy')}</CardTitle><p className="mt-0.5 text-xs text-(--app-muted-foreground)">{tt('services.justifiedByHelp')}</p></CardHeader>
                        {functions.length === 0 ? <p className="px-5 py-6 text-center text-sm text-(--app-muted-foreground)">{tt('services.noFunctions')}</p> : (
                            <ul className="divide-y divide-(--app-border)">
                                {functions.map((f) => (
                                    <li key={f.id} className="flex flex-wrap items-start gap-3 px-5 py-3">
                                        <span className="font-mono text-xs text-(--app-muted-foreground)">{f.code}</span>
                                        <span className="min-w-0 flex-1 text-sm text-(--app-foreground)">{titleOf(f, locale)}</span>
                                        {f.mandate_code && <span className="text-xs text-(--app-muted-foreground)">{tt('functions.underMandate')} {f.mandate_code}</span>}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>

                    <Card>
                        <CardHeader><CardTitle>{tt('services.isic')}</CardTitle><p className="mt-0.5 text-xs text-(--app-muted-foreground)">{tt('services.isicHelp')}</p></CardHeader>
                        {isicActivities.length === 0 ? <p className="px-5 py-6 text-center text-sm text-(--app-muted-foreground)">{tt('services.noIsic')}</p> : (
                            <ul className="divide-y divide-(--app-border)">
                                {isicActivities.map((a) => (
                                    <li key={a.id} className="flex flex-wrap items-start gap-3 px-5 py-3">
                                        <span className="font-mono text-xs text-(--app-muted-foreground)">{a.isic_code}</span>
                                        <span className="min-w-0 flex-1 text-sm text-(--app-foreground)">{nameOf(a, locale)}</span>
                                        <span className="text-xs text-(--app-muted-foreground)">{a.revision}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                </div>

                <div className="space-y-4">
                    <Card>
                        <CardHeader><CardTitle>{tt('services.versions')}</CardTitle></CardHeader>
                        <ul className="divide-y divide-(--app-border)">
                            {versions.map((v) => (
                                <li key={v.id} className="flex items-center justify-between gap-3 px-5 py-3 text-sm">
                                    <div>
                                        {v.id === service.id ? <span className="font-semibold">v{v.version_no}</span> : <Link href={route('organization-design.services.show', v.id)} className={actionLinkClassName({ size: 'sm' })}>v{v.version_no}</Link>}
                                        <p className="text-xs text-(--app-muted-foreground)"><LocalizedDateDisplay value={v.effective_from} />{v.effective_to && <> – <LocalizedDateDisplay value={v.effective_to} /></>}</p>
                                    </div>
                                    <StatusPill status={v.status} />
                                </li>
                            ))}
                        </ul>
                    </Card>
                    <Card>
                        <CardHeader><CardTitle>{tt('services.history')}</CardTitle></CardHeader>
                        <ul className="divide-y divide-(--app-border)">
                            {history.map((h, i) => (
                                <li key={i} className="px-5 py-3 text-sm">
                                    <p className="font-medium text-(--app-foreground)">{h.from ? `${tt(`status.${h.from}`)} → ` : ''}{tt(`status.${h.to}`)}</p>
                                    <p className="text-xs text-(--app-muted-foreground)">{h.actor ?? '—'} · <LocalizedDateDisplay value={h.at} withTime /></p>
                                    {h.reason && <p className="mt-0.5 text-xs text-(--app-muted-foreground)">{h.reason}</p>}
                                </li>
                            ))}
                        </ul>
                    </Card>
                </div>
            </div>

            {can.update && <ServiceForm show={editing} onClose={() => setEditing(false)} title={tt('services.edit')} initial={values} url={route('organization-design.services.update', service.id)} method="put" lookups={lookups} />}
            {can.version && <ServiceForm show={versioning} onClose={() => setVersioning(false)} title={tt('services.newVersion')} help={tt('services.newVersionHelp')} initial={{ ...values, effective_from: '', effective_to: '' }} url={route('organization-design.services.versions.store', service.id)} method="post" lookups={lookups} lockCode />}
        </AuthenticatedLayout>
    );
}
