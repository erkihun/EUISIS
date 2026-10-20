import type { FormEvent } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import CodeRuleField from '@/Components/code-rules/CodeRuleField';
import { useLocale } from '@/hooks/useLocale';

import { actionLinkClassName, cx, selectClassName, Button, FieldError, FormLabel, Input } from '@/Components/ui';
interface OrganizationOption {
    id: string;
    name_en: string;
    name_am: string | null;
    code: string;
}

interface UnitTypeOption {
    id: string;
    code: string;
    name_en: string;
    name_am: string | null;
}

interface ParentUnitOption {
    id: string;
    name_en: string;
    name_am: string | null;
    code: string;
    depth: number;
}

interface SelectOption {
    value: string;
    label: string;
}

interface Props {
    institutions: OrganizationOption[];
    selectedInstitution: OrganizationOption | null;
    parentOfficeOptions: ParentUnitOption[];
    unitTypes: UnitTypeOption[];
    relationshipTypeOptions: SelectOption[];
    statusOptions: SelectOption[];
}

export default function InstitutionOfficeCreate({
    institutions,
    selectedInstitution,
    parentOfficeOptions,
    unitTypes,
    relationshipTypeOptions,
    statusOptions,
}: Props) {
    const { t } = useLocale();

    const defaultUnitTypeId = unitTypes.find((type) => type.code === 'office')?.id ?? unitTypes[0]?.id ?? '';

    const { data, setData, post, processing, errors } = useForm({
        organization_id: selectedInstitution?.id ?? '',
        organization_unit_type_id: defaultUnitTypeId,
        parent_unit_id: null as string | null,
        code: '',
        name_en: '',
        name_am: '',
        functional_reporting_organization_id: '',
        relationship_type: 'functional_reporting',
        status: 'active',
    });

    const inputCls = cx(selectClassName, 'w-full');

    const selectedOrganization = institutions.find((organization) => organization.id === data.organization_id) ?? selectedInstitution;
    const selectedParentUnit = parentOfficeOptions.find((unit) => unit.id === data.parent_unit_id) ?? null;
    const selectedFunctionalOrganization = institutions.find((organization) => organization.id === data.functional_reporting_organization_id) ?? null;
    const selectedUnitName = data.name_en || data.name_am || t('institutionOffices.notSelected');

    function submit(event: FormEvent) {
        event.preventDefault();
        post(route('institution-offices.store'));
    }

    function reloadParentUnits(organizationId: string) {
        setData('organization_id', organizationId);
        setData('parent_unit_id', null);

        router.get(
            route('institution-offices.create'),
            { organization_id: organizationId || undefined },
            { preserveScroll: true, preserveState: true, replace: true },
        );
    }

    return (
        <AuthenticatedLayout contentWidth="form"
            header={<PageHeader title={t('institutionOffices.create')} />}
        >
            <Head title={t('institutionOffices.create')} />

            <div>
                <form onSubmit={submit} className="space-y-6">
                    {Object.keys(errors).length > 0 && (
                        <div className="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900/60 dark:bg-red-950/30 dark:text-red-300">
                            {t('errors.validation_failed')}
                        </div>
                    )}

                    <section className="rounded-lg border border-(--app-border) bg-(--app-surface) p-6">
                        <div className="mb-4">
                            <h2 className="text-base font-semibold text-(--app-foreground)">
                                {t('institutionOffices.structuralPlacement')}
                            </h2>
                            <p className="mt-1 text-sm text-(--app-muted-foreground)">
                                {t('institutionOffices.createdAsOrganizationUnitHelp')}
                            </p>
                        </div>

                        <div className="grid gap-4 md:grid-cols-2">
                            <div>
                                <FormLabel>{`${t('institutionOffices.structuralOrganization')} *`}</FormLabel>
                                <select
                                    className={inputCls}
                                    value={data.organization_id}
                                    onChange={(event) => reloadParentUnits(event.target.value)}
                                    required
                                >
                                    <option value="">{t('institutionOffices.selectStructuralOrganization')}</option>
                                    {institutions.map((organization) => (
                                        <option key={organization.id} value={organization.id}>
                                            {organization.name_en} ({organization.code})
                                        </option>
                                    ))}
                                </select>
                                <p className="mt-1 text-xs text-(--app-muted-foreground)">
                                    {t('institutionOffices.structuralOrganizationHelp')}
                                </p>
                                <FieldError>{errors.organization_id}</FieldError>
                            </div>

                            <div>
                                <FormLabel>{`${t('institutionOffices.organizationUnitType')} *`}</FormLabel>
                                <select
                                    className={inputCls}
                                    value={data.organization_unit_type_id}
                                    onChange={(event) => setData('organization_unit_type_id', event.target.value)}
                                    required
                                >
                                    <option value="">{t('institutionOffices.selectOrganizationUnitType')}</option>
                                    {unitTypes.map((type) => (
                                        <option key={type.id} value={type.id}>
                                            {type.name_en} ({type.code})
                                        </option>
                                    ))}
                                </select>
                                <FieldError>{errors.organization_unit_type_id}</FieldError>
                            </div>

                            <div className="md:col-span-2">
                                <FormLabel>{t('institutionOffices.parentOrganizationUnit')}</FormLabel>
                                <select
                                    className={inputCls}
                                    value={data.parent_unit_id ?? ''}
                                    onChange={(event) => setData('parent_unit_id', event.target.value || null)}
                                    disabled={!data.organization_id}
                                >
                                    <option value="">{t('institutionOffices.selectParentOrganizationUnit')}</option>
                                    {parentOfficeOptions.map((unit) => (
                                        <option key={unit.id} value={unit.id}>
                                            {'-'.repeat(unit.depth)} {unit.name_en} ({unit.code})
                                        </option>
                                    ))}
                                </select>
                                <FieldError>{errors.parent_unit_id}</FieldError>
                            </div>
                        </div>
                    </section>

                    <section className="rounded-lg border border-(--app-border) bg-(--app-surface) p-6">
                        <h2 className="mb-4 text-base font-semibold text-(--app-foreground)">
                            {t('institutionOffices.officeUnitIdentity')}
                        </h2>

                        <div className="grid gap-4 md:grid-cols-2">
                            <div>
                                <FormLabel>{`${t('institutionOffices.unitNameEn')} *`}</FormLabel>
                                <Input
                                    className="w-full"
                                    value={data.name_en}
                                    onChange={(event) => setData('name_en', event.target.value)}
                                />
                                <FieldError>{errors.name_en}</FieldError>
                            </div>

                            <div>
                                <FormLabel>{t('institutionOffices.unitNameAm')}</FormLabel>
                                <Input
                                    className="w-full"
                                    value={data.name_am}
                                    onChange={(event) => setData('name_am', event.target.value)}
                                />
                                <FieldError>{errors.name_am}</FieldError>
                            </div>

                            <div>
                                <CodeRuleField
                                    entityType="organization_unit"
                                    context={{
                                        organization_id: data.organization_id || undefined,
                                        organization_unit_type_id: data.organization_unit_type_id || undefined,
                                    }}
                                    value={data.code}
                                    onChange={(value) => setData('code', value)}
                                    fieldName="code"
                                    label={`${t('institutionOffices.unitCode')} (${t('institutionOffices.codeAutoGenerated')})`}
                                    canManualOverride={false}
                                    error={errors.code}
                                />
                            </div>

                            <div>
                                <FormLabel>{`${t('institutionOffices.status')} *`}</FormLabel>
                                <select className={inputCls} value={data.status} onChange={(event) => setData('status', event.target.value)}>
                                    {statusOptions.map((option) => (
                                        <option key={option.value} value={option.value}>
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                                <FieldError>{errors.status}</FieldError>
                            </div>
                        </div>
                    </section>

                    <section className="rounded-lg border border-(--app-border) bg-(--app-surface) p-6">
                        <div className="mb-4">
                            <h2 className="text-base font-semibold text-(--app-foreground)">
                                {t('institutionOffices.reportingRelationship')}
                            </h2>
                            <p className="mt-1 text-sm text-(--app-muted-foreground)">
                                {t('institutionOffices.functionalReportingNoManagementAccess')}
                            </p>
                        </div>

                        <div className="grid gap-4 md:grid-cols-2">
                            <div>
                                <FormLabel>{t('institutionOffices.functionalReportingOrganization')}</FormLabel>
                                <select
                                    className={inputCls}
                                    value={data.functional_reporting_organization_id}
                                    onChange={(event) => setData('functional_reporting_organization_id', event.target.value)}
                                >
                                    <option value="">{t('institutionOffices.selectFunctionalReportingOrganization')}</option>
                                    {institutions.map((organization) => (
                                        <option key={organization.id} value={organization.id}>
                                            {organization.name_en} ({organization.code})
                                        </option>
                                    ))}
                                </select>
                                <p className="mt-1 text-xs text-(--app-muted-foreground)">
                                    {t('institutionOffices.functionalReportingHelp')}
                                </p>
                                <FieldError>{errors.functional_reporting_organization_id}</FieldError>
                            </div>

                            <div>
                                <FormLabel>{t('relationships.type')}</FormLabel>
                                <select
                                    className={inputCls}
                                    value={data.relationship_type}
                                    onChange={(event) => setData('relationship_type', event.target.value)}
                                >
                                    {relationshipTypeOptions.map((option) => (
                                        <option key={option.value} value={option.value}>
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                                <FieldError>{errors.relationship_type}</FieldError>
                            </div>
                        </div>
                    </section>

                    <section className="rounded-lg border border-blue-100 bg-blue-50 p-5 dark:border-blue-900/50 dark:bg-blue-950/20">
                        <h2 className="mb-3 text-base font-semibold text-blue-950 dark:text-blue-100">
                            {t('institutionOffices.relationshipPreview')}
                        </h2>
                        <div className="grid gap-3 md:grid-cols-3">
                            <PreviewRow label={t('institutionOffices.structuralOrganization')} value={formatOrganization(selectedOrganization, t('institutionOffices.notSelected'))} />
                            <PreviewRow label={t('institutionOffices.organizationUnit')} value={selectedUnitName} />
                            <PreviewRow label={t('institutionOffices.functionalReportingOrganization')} value={formatOrganization(selectedFunctionalOrganization, t('institutionOffices.notSelected'))} />
                            <PreviewRow label={t('institutionOffices.parentOrganizationUnit')} value={selectedParentUnit ? `${selectedParentUnit.name_en} (${selectedParentUnit.code})` : t('institutionOffices.notSelected')} />
                        </div>
                    </section>

                    <div className="flex items-center gap-3">
                        <Button type="submit" disabled={processing}>
                            {processing ? t('common.saving') : t('institutionOffices.create')}
                        </Button>
                        <Link href={route('institution-offices.index')} className={actionLinkClassName({ tone: 'neutral', size: 'sm' })}>
                            {t('common.cancel')}
                        </Link>
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}

function PreviewRow({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-md border border-blue-100 bg-(--app-surface) px-3 py-2 dark:border-blue-900/50">
            <div className="text-xs font-medium uppercase text-blue-700 dark:text-blue-300">{label}</div>
            <div className="mt-1 text-sm font-semibold text-(--app-foreground)">{value}</div>
        </div>
    );
}

function formatOrganization(organization: OrganizationOption | null, fallback: string): string {
    return organization ? `${organization.name_en} (${organization.code})` : fallback;
}
