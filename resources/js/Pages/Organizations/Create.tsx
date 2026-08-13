import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { useLocale } from '@/hooks/useLocale';
import { toast as showToast } from '@/lib/toast';
import { useRef, useState } from 'react';
import CodeRuleField from '@/Components/code-rules/CodeRuleField';
import FormSection from '@/Components/FormSection';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import ParentOrganizationSelect, { type ParentOrganizationOption } from '@/Components/organizations/ParentOrganizationSelect';

type OrgType = { id: string; name_en: string; name_am: string | null; code: string };
type HierarchyVersion = { id: string; version_name: string; status: string };
const inputCls =
    'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder-slate-500';

const labelCls = 'block text-xs font-medium text-gray-600 dark:text-slate-400';
const helpCls = 'mt-1 text-xs text-gray-400 dark:text-slate-500';

function Field({ label, error, children, help }: { label: string; error?: string; children: React.ReactNode; help?: string }) {
    return (
        <div>
            <label className={labelCls}>{label}</label>
            <div className="mt-1">{children}</div>
            {help && <p className={helpCls}>{help}</p>}
            {error && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{error}</p>}
        </div>
    );
}

export default function CreateOrganization({
    organizationTypes,
    hierarchyVersions,
    parentOrganizationOptions,
    selectedParentOrganization,
    requiresParentOrganization,
}: {
    organizationTypes: OrgType[];
    hierarchyVersions: HierarchyVersion[];
    parentOrganizationOptions: ParentOrganizationOption[];
    selectedParentOrganization: ParentOrganizationOption | null;
    requiresParentOrganization: boolean;
}) {
    const { t, locale } = useLocale();

    const form = useForm<{
        organization_type_id: string;
        code: string;
        name_en: string;
        name_am: string;
        legal_basis_ref: string;
        status: string;
        effective_from: string;
        effective_to: string;
        parent_organization_id: string;
        hierarchy_version_id: string;
        relationship_type: string;
        logo: File | null;
        branding_primary_color: string;
        branding_secondary_color: string;
    }>({
        organization_type_id: organizationTypes[0]?.id ?? '',
        code: '',
        name_en: '',
        name_am: '',
        legal_basis_ref: '',
        status: 'active',
        effective_from: '',
        effective_to: '',
        parent_organization_id: selectedParentOrganization?.id ?? '',
        hierarchy_version_id: selectedParentOrganization ? (hierarchyVersions[0]?.id ?? '') : '',
        relationship_type: 'reports_to',
        logo: null,
        branding_primary_color: '',
        branding_secondary_color: '',
    });

    const [logoPreview, setLogoPreview] = useState<string | null>(null);
    const [selectedParent, setSelectedParent] = useState<ParentOrganizationOption | null>(selectedParentOrganization);
    const fileInputRef = useRef<HTMLInputElement>(null);
    const errorMessages = Object.values(form.errors).filter(Boolean);

    function handleLogoChange(event: React.ChangeEvent<HTMLInputElement>) {
        const file = event.target.files?.[0] ?? null;
        form.setData('logo', file);
        setLogoPreview(file ? URL.createObjectURL(file) : null);
    }

    function clearLogo() {
        form.setData('logo', null);
        setLogoPreview(null);
        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
    }

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            name_am: data.name_am || null,
            legal_basis_ref: data.legal_basis_ref || null,
            effective_from: data.effective_from || null,
            effective_to: data.effective_to || null,
            parent_organization_id: data.parent_organization_id || null,
            hierarchy_version_id: data.hierarchy_version_id || null,
            relationship_type: data.relationship_type || null,
            branding_primary_color: data.branding_primary_color || null,
            branding_secondary_color: data.branding_secondary_color || null,
        }));
        form.post(route('organizations.store'), {
            forceFormData: form.data.logo !== null,
            onSuccess: () => {
                showToast.success(
                    form.data.parent_organization_id
                        ? t('organizations.childOrganizationCreatedSuccessfully')
                        : t('organizations.createdSuccessfully'),
                );
            },
            onError: (errors) => {
                const firstError = Object.values(errors).find(Boolean);

                if (firstError) {
                    showToast.error(String(firstError));
                }

                window.scrollTo({ top: 0, behavior: 'smooth' });
            },
        });
    }

    const primary = form.data.branding_primary_color;
    const secondary = form.data.branding_secondary_color;

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    backHref={route('organizations.index')}
                    title={t('organizations.createTitle')}
                    description={t('organizations.createDescription')}
                />
            }
        >
            <Head title={t('organizations.createOrganization')} />

            <div className="w-full">
                <form
                    onSubmit={submit}
                    className="w-full rounded-2xl border border-gray-200 bg-white p-4 sm:p-6 lg:p-8 dark:border-slate-800 dark:bg-slate-900"
                >
                    {errorMessages.length > 0 && (
                        <div className="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 dark:border-red-900/50 dark:bg-red-950/20">
                            <p className="text-sm font-medium text-red-700 dark:text-red-300">
                                {t('common.error')}
                            </p>
                            <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-red-700 dark:text-red-300">
                                {errorMessages.map((message, index) => (
                                    <li key={`${message}-${index}`}>{message}</li>
                                ))}
                            </ul>
                        </div>
                    )}

                    <div className="space-y-6">
                        {/* Basic Information */}
                        <FormSection title={t('organizations.sectionBasicInfo')} description={t('organizations.sectionBasicInfoHelp')} columns={3}>
                            <Field label={t('organizations.nameEn')} error={form.errors.name_en}>
                                <input
                                    className={inputCls}
                                    placeholder={t('organizations.fullNameEn')}
                                    value={form.data.name_en}
                                    onChange={(event) => form.setData('name_en', event.target.value)}
                                />
                            </Field>
                            <Field label={t('organizations.nameAm')} error={form.errors.name_am}>
                                <input
                                    className={inputCls}
                                    placeholder={t('organizations.fullNameAmPlaceholder')}
                                    value={form.data.name_am}
                                    onChange={(event) => form.setData('name_am', event.target.value)}
                                />
                            </Field>
                            <Field label={t('organizations.legalBasisRef')} error={form.errors.legal_basis_ref}>
                                <input
                                    className={inputCls}
                                    placeholder={t('organizations.legalBasisPlaceholder')}
                                    value={form.data.legal_basis_ref}
                                    onChange={(event) => form.setData('legal_basis_ref', event.target.value)}
                                />
                            </Field>
                        </FormSection>

                        {/* Classification */}
                        <FormSection title={t('organizations.sectionClassification')} description={t('organizations.sectionClassificationHelp')}>
                            <Field label={t('organizations.organizationType')} error={form.errors.organization_type_id} help={t('organizations.typeHelpText')}>
                                <select
                                    className={inputCls}
                                    value={form.data.organization_type_id}
                                    onChange={(event) => form.setData('organization_type_id', event.target.value)}
                                >
                                    {organizationTypes.map((organizationType) => (
                                        <option key={organizationType.id} value={organizationType.id}>
                                            {locale === 'am' ? (organizationType.name_am || organizationType.name_en) : organizationType.name_en} ({organizationType.code})
                                        </option>
                                    ))}
                                </select>
                            </Field>

                            <CodeRuleField
                                entityType="organization"
                                context={{
                                    organization_type_id: form.data.organization_type_id || undefined,
                                }}
                                value={form.data.code}
                                onChange={(v) => form.setData('code', v)}
                                fieldName="code"
                                label={t('organizations.code')}
                                canManualOverride={false}
                                error={form.errors.code}
                            />
                        </FormSection>

                        {/* Status & Validity */}
                        <FormSection title={t('organizations.sectionStatusValidity')} description={t('organizations.sectionStatusValidityHelp')} columns={3}>
                            <Field label={t('common.status')} error={form.errors.status}>
                                <select
                                    className={inputCls}
                                    value={form.data.status}
                                    onChange={(event) => form.setData('status', event.target.value)}
                                >
                                    <option value="active">{t('organizations.statusActive')}</option>
                                    <option value="draft">{t('organizations.statusDraft')}</option>
                                    <option value="inactive">{t('organizations.statusInactive')}</option>
                                </select>
                            </Field>
                            <Field label={t('common.effectiveFrom')} error={form.errors.effective_from}>
                                <LocalizedDatePicker
                                    className={inputCls}
                                    value={form.data.effective_from}
                                    onChange={(iso) => form.setData('effective_from', iso)}
                                />
                            </Field>
                            <Field label={t('common.effectiveTo')} error={form.errors.effective_to}>
                                <LocalizedDatePicker
                                    className={inputCls}
                                    value={form.data.effective_to}
                                    onChange={(iso) => form.setData('effective_to', iso)}
                                />
                            </Field>
                        </FormSection>

                        {/* Hierarchy Placement */}
                        <FormSection title={t('organizations.sectionHierarchy')} grid={false}>
                            <Field label={t('organizations.parentOrganization')} error={form.errors.parent_organization_id} help={t('organizations.parentHelpText')}>
                                <ParentOrganizationSelect
                                    value={form.data.parent_organization_id}
                                    initialOptions={parentOrganizationOptions}
                                    selectedOption={selectedParent}
                                    hierarchyVersionId={form.data.hierarchy_version_id || undefined}
                                    endpoint={route('organizations.parent-options')}
                                    allowNoParent={!requiresParentOrganization}
                                    locale={locale}
                                    onChange={(value, option) => {
                                        form.setData('parent_organization_id', value);
                                        setSelectedParent(option);
                                    }}
                                    labels={{
                                        placeholder: t('organizations.selectParentOrganization'),
                                        searchPlaceholder: t('organizations.searchParentOrganizations'),
                                        noParent: t('organizations.noParent'),
                                        noResults: t('organizations.noEligibleParentOrganizationsFound'),
                                        eligibleParents: t('organizations.eligibleParentOrganizations'),
                                        permissionHint: t('organizations.cannotCreateChildUnderThisOrganization'),
                                        level: t('organizations.level'),
                                    }}
                                />
                            </Field>

                            {form.data.parent_organization_id && (
                                <>
                                    {selectedParent && (
                                        <div className="rounded-xl border border-blue-100 bg-blue-50/70 px-4 py-3 dark:border-blue-900/60 dark:bg-blue-950/30">
                                            <div className="text-xs font-medium uppercase text-blue-700 dark:text-blue-300">
                                                {t('organizations.selectedParent')}
                                            </div>
                                            <div className="mt-1 text-sm font-medium text-gray-900 dark:text-slate-100">
                                                {selectedParent.code} - {locale === 'am'
                                                    ? (selectedParent.name_am || selectedParent.name_en)
                                                    : selectedParent.name_en}
                                            </div>
                                            {selectedParent.parent_path && (
                                                <div className="mt-1 text-xs text-gray-600 dark:text-slate-400">
                                                    {t('organizations.parentOrganizationPath')}: {selectedParent.parent_path}
                                                </div>
                                            )}
                                            {!selectedParent.can_create_child && (
                                                <div className="mt-2 text-xs text-orange-600 dark:text-orange-400">
                                                    {t('organizations.cannotCreateChildUnderThisOrganization')}
                                                </div>
                                            )}
                                        </div>
                                    )}

                                    <div className="grid gap-4 md:grid-cols-2">
                                        <Field label={t('organizations.hierarchyVersion')} error={form.errors.hierarchy_version_id}>
                                            <select
                                                className={inputCls}
                                                value={form.data.hierarchy_version_id}
                                                onChange={(event) => form.setData('hierarchy_version_id', event.target.value)}
                                            >
                                                <option value="">{t('organizations.selectVersion')}</option>
                                                {hierarchyVersions.map((version) => (
                                                    <option key={version.id} value={version.id}>
                                                        {version.version_name} ({t(`common.${version.status}`)})
                                                    </option>
                                                ))}
                                            </select>
                                            {hierarchyVersions.length === 0 && (
                                                <p className="mt-2 text-xs text-orange-600 dark:text-orange-400">
                                                    {t('organizations.draftHierarchyVersionRequired')}
                                                </p>
                                            )}
                                        </Field>

                                        <Field label={t('organizations.relationshipType')} error={form.errors.relationship_type}>
                                            <select
                                                className={inputCls}
                                                value={form.data.relationship_type}
                                                onChange={(event) => form.setData('relationship_type', event.target.value)}
                                            >
                                                <option value="reports_to">{t('organizations.reportsTo')}</option>
                                                <option value="geographically_under">{t('organizations.administrative')}</option>
                                                <option value="service_scope">{t('organizations.serviceScope')}</option>
                                                <option value="oversight">{t('organizations.technical')}</option>
                                            </select>
                                        </Field>
                                    </div>
                                </>
                            )}
                        </FormSection>

                        {/* Branding */}
                        <FormSection title={t('organizations.branding')} description={t('organizations.brandingHelpText')} grid={false}>
                            <div className="grid gap-5 sm:grid-cols-2">
                                <div>
                                    <label className={labelCls}>{t('organizations.logo')}</label>
                                    <div className="mt-1 space-y-2">
                                        {logoPreview && (
                                            <div className="flex items-center gap-3">
                                                <img
                                                    src={logoPreview}
                                                    alt={t('organizations.logoPreview')}
                                                    className="h-14 w-14 rounded-lg border border-gray-200 object-contain p-1 dark:border-slate-700"
                                                />
                                                <button
                                                    type="button"
                                                    onClick={clearLogo}
                                                    className="text-xs text-red-500 hover:text-red-700"
                                                >
                                                    {t('organizations.removeLogo')}
                                                </button>
                                            </div>
                                        )}
                                        <input
                                            ref={fileInputRef}
                                            type="file"
                                            accept="image/jpeg,image/png,image/webp"
                                            onChange={handleLogoChange}
                                            className="w-full rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 file:mr-3 file:rounded file:border-0 file:bg-blue-50 file:px-2 file:py-1 file:text-xs file:font-medium file:text-blue-700 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-300"
                                        />
                                        <p className="text-xs text-gray-400 dark:text-slate-500">
                                            {t('organizations.logoHint')}
                                        </p>
                                        {form.errors.logo && (
                                            <p className="text-xs text-red-600 dark:text-red-400">{form.errors.logo}</p>
                                        )}
                                    </div>
                                </div>

                                <div className="space-y-3">
                                    <Field label={t('organizations.brandingPrimaryColor')} error={form.errors.branding_primary_color}>
                                        <div className="flex items-center gap-2">
                                            <input
                                                type="color"
                                                value={primary || '#1D4ED8'}
                                                onChange={(event) => form.setData('branding_primary_color', event.target.value)}
                                                className="h-9 w-10 cursor-pointer rounded border border-gray-300 p-0.5 dark:border-slate-700"
                                            />
                                            <input
                                                className={inputCls}
                                                placeholder="#1D4ED8"
                                                value={primary}
                                                maxLength={7}
                                                onChange={(event) => form.setData('branding_primary_color', event.target.value)}
                                            />
                                        </div>
                                    </Field>

                                    <Field label={t('organizations.brandingSecondaryColor')} error={form.errors.branding_secondary_color}>
                                        <div className="flex items-center gap-2">
                                            <input
                                                type="color"
                                                value={secondary || '#F97316'}
                                                onChange={(event) => form.setData('branding_secondary_color', event.target.value)}
                                                className="h-9 w-10 cursor-pointer rounded border border-gray-300 p-0.5 dark:border-slate-700"
                                            />
                                            <input
                                                className={inputCls}
                                                placeholder="#F97316"
                                                value={secondary}
                                                maxLength={7}
                                                onChange={(event) => form.setData('branding_secondary_color', event.target.value)}
                                            />
                                        </div>
                                    </Field>

                                    {(primary || secondary) && (
                                        <div
                                            className="flex items-center gap-2 rounded-lg border border-l-4 border-gray-200 px-3 py-2 dark:border-slate-700"
                                            style={{ borderLeftColor: primary || '#1D4ED8' }}
                                        >
                                            <div className="flex gap-1.5">
                                                {primary && (
                                                    <span className="h-4 w-4 rounded-full border border-white shadow-sm" style={{ backgroundColor: primary }} />
                                                )}
                                                {secondary && (
                                                    <span className="h-4 w-4 rounded-full border border-white shadow-sm" style={{ backgroundColor: secondary }} />
                                                )}
                                            </div>
                                            <span className="text-xs text-gray-500 dark:text-slate-400">
                                                {t('organizations.colorPreview')}
                                            </span>
                                        </div>
                                    )}
                                </div>
                            </div>
                        </FormSection>
                    </div>

                    {/* Sticky action bar */}
                    <div className="sticky bottom-0 -mx-4 -mb-4 mt-8 flex items-center justify-end gap-3 border-t border-gray-100 bg-white/95 px-4 py-4 backdrop-blur sm:-mx-6 sm:-mb-6 sm:px-6 lg:-mx-8 lg:-mb-8 lg:px-8 dark:border-slate-800 dark:bg-slate-900/95">
                        <Link
                            href={route('organizations.index')}
                            className="rounded-lg px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-slate-300 dark:hover:bg-slate-800"
                        >
                            {t('common.cancel')}
                        </Link>
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:opacity-60 dark:focus:ring-offset-slate-900"
                        >
                            {form.processing ? t('common.saving') : t('organizations.createAction')}
                        </button>
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
