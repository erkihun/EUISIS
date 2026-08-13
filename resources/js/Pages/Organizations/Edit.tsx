import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { useLocale } from '@/hooks/useLocale';
import { useRef, useState } from 'react';
import { toDateInput } from '@/lib/dateUtils';
import CodeRuleField from '@/Components/code-rules/CodeRuleField';
import FormSection from '@/Components/FormSection';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';

type OrgType = { id: string; name_en: string; name_am: string | null; code: string };

type Organization = {
    id: string;
    code: string;
    name_en: string;
    name_am: string | null;
    legal_basis_ref: string | null;
    status: string;
    effective_from: string | null;
    effective_to: string | null;
    organization_type_id: string;
    type?: OrgType | null;
    logo_url: string | null;
    has_logo: boolean;
    branding_primary_color: string | null;
    branding_secondary_color: string | null;
};

const inputCls =
    'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder-slate-500';

const labelCls = 'block text-xs font-medium text-gray-600 dark:text-slate-400';
const helpCls = 'mt-1 text-xs text-gray-400 dark:text-slate-500';

function Field({
    label,
    error,
    children,
    help,
}: {
    label: string;
    error?: string;
    children: React.ReactNode;
    help?: string;
}) {
    return (
        <div>
            <label className={labelCls}>{label}</label>
            <div className="mt-1">{children}</div>
            {help && <p className={helpCls}>{help}</p>}
            {error && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{error}</p>}
        </div>
    );
}

export default function EditOrganization({
    organization,
    organizationTypes,
}: {
    organization: Organization;
    organizationTypes: OrgType[];
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
        logo: File | null;
        remove_logo: boolean;
        branding_primary_color: string;
        branding_secondary_color: string;
    }>({
        organization_type_id: organization.organization_type_id ?? '',
        code: organization.code ?? '',
        name_en: organization.name_en ?? '',
        name_am: organization.name_am ?? '',
        legal_basis_ref: organization.legal_basis_ref ?? '',
        status: organization.status ?? '',
        effective_from: toDateInput(organization.effective_from),
        effective_to: toDateInput(organization.effective_to),
        logo: null,
        remove_logo: false,
        branding_primary_color: organization.branding_primary_color ?? '',
        branding_secondary_color: organization.branding_secondary_color ?? '',
    });

    const [logoPreview, setLogoPreview] = useState<string | null>(null);
    const fileInputRef = useRef<HTMLInputElement>(null);
    const errorMessages = Object.values(form.errors).filter(Boolean);

    function handleLogoChange(e: React.ChangeEvent<HTMLInputElement>) {
        const file = e.target.files?.[0] ?? null;
        form.setData((prev) => ({ ...prev, logo: file, remove_logo: false }));
        setLogoPreview(file ? URL.createObjectURL(file) : null);
    }

    function clearNewLogo() {
        form.setData((prev) => ({ ...prev, logo: null }));
        setLogoPreview(null);
        if (fileInputRef.current) fileInputRef.current.value = '';
    }

    function toggleRemoveLogo() {
        form.setData((prev) => ({ ...prev, remove_logo: !prev.remove_logo, logo: null }));
        setLogoPreview(null);
        if (fileInputRef.current) fileInputRef.current.value = '';
    }

    function submit(e: React.FormEvent) {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            _method: 'patch',
            name_am: data.name_am || null,
            legal_basis_ref: data.legal_basis_ref || null,
            effective_from: data.effective_from || null,
            effective_to: data.effective_to || null,
            branding_primary_color: data.branding_primary_color || null,
            branding_secondary_color: data.branding_secondary_color || null,
        }));
        form.post(route('organizations.update', organization.id), { preserveState: true });
    }

    const primary = form.data.branding_primary_color;
    const secondary = form.data.branding_secondary_color;
    const showCurrentLogo = organization.has_logo && !form.data.remove_logo && !logoPreview;

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    backHref={route('organizations.show', organization.id)}
                    title={`${t('organizations.editPrefix')} ${locale === 'am' ? (organization.name_am || organization.name_en) : organization.name_en}`}
                    description={organization.code}
                />
            }
        >
            <Head title={`${t('organizations.editPrefix')} ${locale === 'am' ? (organization.name_am || organization.name_en) : organization.name_en}`} />

            <div className="w-full">
                <form
                    onSubmit={submit}
                    className="w-full rounded-2xl border border-gray-200 bg-white p-4 sm:p-6 lg:p-8 dark:border-slate-800 dark:bg-slate-900"
                >
                    {errorMessages.length > 0 && (
                        <div className="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 dark:border-red-900/50 dark:bg-red-950/20">
                            <p className="text-sm font-medium text-red-700 dark:text-red-300">{t('common.error')}</p>
                            <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-red-700 dark:text-red-300">
                                {errorMessages.map((message, index) => <li key={`${message}-${index}`}>{message}</li>)}
                            </ul>
                        </div>
                    )}
                    <div className="space-y-6">
                        {/* Basic Information */}
                        <FormSection title={t('organizations.sectionBasicInfo')} description={t('organizations.sectionBasicInfoHelp')} columns={3}>
                            <Field label={t('organizations.nameEn')} error={form.errors.name_en}>
                                <input
                                    className={inputCls}
                                    value={form.data.name_en}
                                    onChange={(e) => form.setData('name_en', e.target.value)}
                                />
                            </Field>
                            <Field label={t('organizations.nameAm')} error={form.errors.name_am}>
                                <input
                                    className={inputCls}
                                    placeholder={t('organizations.fullNameAmPlaceholder')}
                                    value={form.data.name_am}
                                    onChange={(e) => form.setData('name_am', e.target.value)}
                                />
                            </Field>
                            <Field label={t('organizations.legalBasisRef')} error={form.errors.legal_basis_ref}>
                                <input
                                    className={inputCls}
                                    placeholder={t('organizations.legalBasisPlaceholder')}
                                    value={form.data.legal_basis_ref}
                                    onChange={(e) => form.setData('legal_basis_ref', e.target.value)}
                                />
                            </Field>
                        </FormSection>

                        {/* Classification */}
                        <FormSection title={t('organizations.sectionClassification')} description={t('organizations.sectionClassificationHelp')}>
                            <Field label={t('organizations.organizationType')} error={form.errors.organization_type_id} help={t('organizations.typeHelpText')}>
                                <select
                                    className={inputCls}
                                    value={form.data.organization_type_id}
                                    onChange={(e) => form.setData('organization_type_id', e.target.value)}
                                >
                                    {organizationTypes.map((ot) => (
                                        <option key={ot.id} value={ot.id}>
                                            {locale === 'am' ? (ot.name_am || ot.name_en) : ot.name_en} ({ot.code})
                                        </option>
                                    ))}
                                </select>
                            </Field>

                            <CodeRuleField
                                entityType="organization"
                                value={form.data.code}
                                onChange={(v) => form.setData('code', v)}
                                fieldName="code"
                                label={t('organizations.code')}
                                canManualOverride={false}
                                existingCode={organization.code}
                                preserveExistingCodeOnEdit
                                error={form.errors.code}
                            />
                        </FormSection>

                        {/* Status & Validity */}
                        <FormSection title={t('organizations.sectionStatusValidity')} description={t('organizations.sectionStatusValidityHelp')} columns={3}>
                            <Field label={t('common.status')} error={form.errors.status}>
                                <select
                                    className={inputCls}
                                    value={form.data.status}
                                    onChange={(e) => form.setData('status', e.target.value)}
                                >
                                    <option value="active">{t('organizations.statusActive')}</option>
                                    <option value="draft">{t('organizations.statusDraft')}</option>
                                    <option value="inactive">{t('organizations.statusInactive')}</option>
                                    <option value="merged">{t('organizations.statusMerged')}</option>
                                    <option value="dissolved">{t('organizations.statusDissolved')}</option>
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

                        {/* Branding */}
                        <FormSection title={t('organizations.branding')} description={t('organizations.brandingHelpText')} grid={false}>
                            <div className="grid gap-5 sm:grid-cols-2">
                                {/* Logo */}
                                <div>
                                    <label className={labelCls}>{t('organizations.logo')}</label>
                                    <div className="mt-1 space-y-2">
                                        {showCurrentLogo && organization.logo_url && (
                                            <div className="flex items-center gap-3">
                                                <img
                                                    src={organization.logo_url}
                                                    alt={t('organizations.currentLogo')}
                                                    className="h-14 w-14 rounded-lg border border-gray-200 object-contain p-1 dark:border-slate-700"
                                                />
                                                <div className="flex flex-col gap-1">
                                                    <span className="text-xs text-gray-500 dark:text-slate-400">
                                                        {t('organizations.currentLogo')}
                                                    </span>
                                                    <button
                                                        type="button"
                                                        onClick={toggleRemoveLogo}
                                                        className="text-left text-xs text-red-500 hover:text-red-700"
                                                    >
                                                        {t('organizations.removeLogo')}
                                                    </button>
                                                </div>
                                            </div>
                                        )}

                                        {form.data.remove_logo && (
                                            <div className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 dark:border-red-800 dark:bg-red-950">
                                                <span className="text-xs text-red-700 dark:text-red-300">
                                                    {t('organizations.logoWillBeRemoved')}
                                                </span>
                                                <button
                                                    type="button"
                                                    onClick={toggleRemoveLogo}
                                                    className="ml-auto text-xs text-red-500 hover:text-red-700"
                                                >
                                                    {t('common.undo')}
                                                </button>
                                            </div>
                                        )}

                                        {logoPreview && (
                                            <div className="flex items-center gap-3">
                                                <img
                                                    src={logoPreview}
                                                    alt={t('organizations.logoPreview')}
                                                    className="h-14 w-14 rounded-lg border border-blue-200 object-contain p-1 dark:border-blue-700"
                                                />
                                                <div className="flex flex-col gap-1">
                                                    <span className="text-xs text-blue-600 dark:text-blue-400">
                                                        {t('organizations.newLogoSelected')}
                                                    </span>
                                                    <button
                                                        type="button"
                                                        onClick={clearNewLogo}
                                                        className="text-left text-xs text-red-500 hover:text-red-700"
                                                    >
                                                        {t('common.cancel')}
                                                    </button>
                                                </div>
                                            </div>
                                        )}

                                        {!form.data.remove_logo && (
                                            <input
                                                ref={fileInputRef}
                                                type="file"
                                                accept="image/jpeg,image/png,image/webp"
                                                onChange={handleLogoChange}
                                                className="w-full rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 file:mr-3 file:rounded file:border-0 file:bg-blue-50 file:px-2 file:py-1 file:text-xs file:font-medium file:text-blue-700 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-300"
                                            />
                                        )}

                                        <p className="text-xs text-gray-400 dark:text-slate-500">
                                            {t('organizations.logoHint')}
                                        </p>
                                        {form.errors.logo && (
                                            <p className="text-xs text-red-600 dark:text-red-400">{form.errors.logo}</p>
                                        )}
                                    </div>
                                </div>

                                {/* Colors */}
                                <div className="space-y-3">
                                    <Field label={t('organizations.brandingPrimaryColor')} error={form.errors.branding_primary_color}>
                                        <div className="flex items-center gap-2">
                                            <input
                                                type="color"
                                                value={primary || '#1D4ED8'}
                                                onChange={(e) => form.setData('branding_primary_color', e.target.value)}
                                                className="h-9 w-10 cursor-pointer rounded border border-gray-300 p-0.5 dark:border-slate-700"
                                            />
                                            <input
                                                className={inputCls}
                                                placeholder="#1D4ED8"
                                                value={primary}
                                                maxLength={7}
                                                onChange={(e) => form.setData('branding_primary_color', e.target.value)}
                                            />
                                        </div>
                                    </Field>

                                    <Field label={t('organizations.brandingSecondaryColor')} error={form.errors.branding_secondary_color}>
                                        <div className="flex items-center gap-2">
                                            <input
                                                type="color"
                                                value={secondary || '#F97316'}
                                                onChange={(e) => form.setData('branding_secondary_color', e.target.value)}
                                                className="h-9 w-10 cursor-pointer rounded border border-gray-300 p-0.5 dark:border-slate-700"
                                            />
                                            <input
                                                className={inputCls}
                                                placeholder="#F97316"
                                                value={secondary}
                                                maxLength={7}
                                                onChange={(e) => form.setData('branding_secondary_color', e.target.value)}
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
                            href={route('organizations.show', organization.id)}
                            className="rounded-lg px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-slate-300 dark:hover:bg-slate-800"
                        >
                            {t('common.cancel')}
                        </Link>
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:opacity-60 dark:focus:ring-offset-slate-900"
                        >
                            {form.processing ? t('common.saving') : t('organizations.saveChanges')}
                        </button>
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
