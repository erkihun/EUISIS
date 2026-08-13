import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import FormSection from '@/Components/FormSection';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent, useMemo, useRef, useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { localizedName } from '@/utils/localizedName';
import CodeRuleField from '@/Components/code-rules/CodeRuleField';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';

type Option = {
    id: string;
    name_en?: string;
    name_am?: string | null;
    title_en?: string;
    title_am?: string | null;
    job_position_code?: string;
    code?: string | null;
    organization_id?: string | null;
    organization_unit_id?: string | null;
    version_name?: string;
    status?: string;
};

const inputCls =
    'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:cursor-not-allowed disabled:bg-gray-50 disabled:text-gray-400 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder-slate-500 dark:disabled:bg-slate-900';
const labelCls = 'mb-1 block text-xs font-medium text-gray-600 dark:text-slate-400';
const helpCls = 'mt-1 text-xs text-gray-400 dark:text-slate-500';

type PlacementRecord = {
    id: string;
    code: string | null;
    name_en: string | null;
    name_am: string | null;
};

type PlacementContext = {
    organization: PlacementRecord | null;
    organization_unit: PlacementRecord | null;
    position: PlacementRecord;
};

/** A resolved placement value shown instead of an editable control. */
function ReadOnlyValue({ label, value, code }: { label: string; value: string; code?: string | null }) {
    return (
        <div className="min-w-0">
            <dt className={labelCls}>{label}</dt>
            <dd className="truncate text-sm font-semibold text-gray-900 dark:text-slate-100">{value}</dd>
            {code && <dd className="mt-0.5 font-mono text-[11px] text-gray-400 dark:text-slate-500">{code}</dd>}
        </div>
    );
}

function Field({
    label,
    error,
    help,
    children,
    className,
}: {
    label: string;
    error?: string;
    help?: string;
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <div className={className}>
            <label className={labelCls}>{label}</label>
            {children}
            {help && <p className={helpCls}>{help}</p>}
            {error && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{error}</p>}
        </div>
    );
}

export default function EmployeesCreate({
    organizations,
    organizationUnits,
    hierarchyVersions,
    positions,
    selectedOrganizationId,
    selectedOrganizationUnitId,
    selectedPositionId,
    placementContext,
}: {
    organizations: Option[];
    organizationUnits: Option[];
    hierarchyVersions: Option[];
    positions: Option[];
    selectedOrganizationId: string | null;
    selectedOrganizationUnitId: string | null;
    selectedPositionId: string | null;
    placementContext: PlacementContext | null;
}) {
    const { t, locale } = useLocale();

    const form = useForm<{
        employee_number: string;
        first_name: string;
        middle_name: string;
        last_name: string;
        name_en: string;
        national_id: string;
        phone: string;
        email: string;
        date_of_birth: string;
        gender: string;
        status: string;
        organization_id: string;
        organization_unit_id: string;
        hierarchy_version_id: string;
        position_id: string;
        position_title: string;
        effective_from: string;
        reason: string;
        photo: File | null;
    }>({
        employee_number: '',
        first_name: '',
        middle_name: '',
        last_name: '',
        name_en: '',
        national_id: '',
        phone: '',
        email: '',
        date_of_birth: '',
        gender: '',
        status: 'active',
        organization_id: selectedOrganizationId ?? organizations[0]?.id ?? '',
        organization_unit_id: selectedOrganizationUnitId ?? '',
        hierarchy_version_id: hierarchyVersions[0]?.id ?? '',
        position_id: selectedPositionId ?? '',
        position_title: '',
        effective_from: new Date().toISOString().slice(0, 10),
        reason: '',
        photo: null,
    });

    const [photoPreview, setPhotoPreview] = useState<string | null>(null);
    const photoInputRef = useRef<HTMLInputElement>(null);

    function handleNationalIdChange(raw: string) {
        const digits = raw.replace(/\D/g, '').slice(0, 16);
        form.setData('national_id', digits);
    }

    function formatNationalId(digits: string): string {
        return digits.replace(/(.{4})/g, '$1 ').trim();
    }

    function handlePhotoChange(e: React.ChangeEvent<HTMLInputElement>) {
        const file = e.target.files?.[0] ?? null;
        form.setData('photo', file);
        setPhotoPreview(file ? URL.createObjectURL(file) : null);
    }

    function clearPhoto() {
        form.setData('photo', null);
        setPhotoPreview(null);
        if (photoInputRef.current) photoInputRef.current.value = '';
    }

    const selectedOrg = organizations.find((organization) => organization.id === form.data.organization_id);

    // ── Dependent options: organization → units → positions ───────────────
    // All options are already scoped, active-only and (for positions) vacant on
    // the server, so filtering is instant and needs no extra request.
    const filteredOrganizationUnits = useMemo(
        () => organizationUnits.filter((unit) => unit.organization_id === form.data.organization_id),
        [organizationUnits, form.data.organization_id],
    );

    const filteredPositions = useMemo(
        () => positions.filter((position) => {
            if (position.organization_id !== form.data.organization_id) return false;
            return form.data.organization_unit_id === '' || position.organization_unit_id === form.data.organization_unit_id;
        }),
        [positions, form.data.organization_id, form.data.organization_unit_id],
    );

    function changeOrganization(organizationId: string) {
        form.setData({
            ...form.data,
            organization_id: organizationId,
            organization_unit_id: '',
            position_id: '',
        });
    }

    function changeOrganizationUnit(organizationUnitId: string) {
        form.setData({
            ...form.data,
            organization_unit_id: organizationUnitId,
            position_id: '',
        });
    }

    /**
     * The position is the authoritative choice: its organization and unit win
     * over whatever is currently selected, so the three fields can never drift
     * out of sync (the backend rejects a mismatch either way).
     */
    function changePosition(positionId: string) {
        const position = positions.find((candidate) => candidate.id === positionId);

        if (position === undefined) {
            form.setData({ ...form.data, position_id: '' });
            return;
        }

        form.setData({
            ...form.data,
            position_id: positionId,
            organization_id: position.organization_id ?? form.data.organization_id,
            organization_unit_id: position.organization_unit_id ?? '',
        });
    }

    function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        form.post(route('employees.store'));
    }

    const orgLocked = selectedOrganizationId !== null;
    const positionLocked = selectedPositionId !== null;

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    backHref={route('employees.index')}
                    title={t('employees.createEmployee')}
                    description={t('employees.createDescription')}
                />
            }
        >
            <Head title={t('employees.createEmployee')} />

            <div className="w-full">
                <form
                    className="rounded-2xl border border-gray-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900"
                    onSubmit={submit}
                >
                    <div className="space-y-6">
                        {/* ── Employee Identity ─────────────────────────── */}
                        <FormSection
                            title={t('employees.sectionIdentity')}
                            description={t('employees.sectionIdentityHelp')}
                            columns={3}
                        >
                            <div>
                                <CodeRuleField
                                    entityType="employee"
                                    context={{
                                        organization_id: form.data.organization_id || undefined,
                                        organization_unit_id: form.data.organization_unit_id || undefined,
                                    }}
                                    value={form.data.employee_number}
                                    onChange={(v) => form.setData('employee_number', v)}
                                    fieldName="employee_number"
                                    label={t('employees.employeeNumber')}
                                    canManualOverride={false}
                                    error={form.errors.employee_number}
                                />
                            </div>

                            <Field label={t('employees.nationalId')} error={form.errors.national_id}>
                                <input
                                    className={inputCls}
                                    placeholder="XXXX XXXX XXXX XXXX"
                                    inputMode="numeric"
                                    value={formatNationalId(form.data.national_id)}
                                    onChange={(e) => handleNationalIdChange(e.target.value)}
                                    maxLength={19}
                                />
                            </Field>

                            <Field label={t('employees.firstName')} error={form.errors.first_name}>
                                <input
                                    className={inputCls}
                                    placeholder={t('employees.firstName')}
                                    value={form.data.first_name}
                                    onChange={(e) => form.setData('first_name', e.target.value)}
                                />
                            </Field>

                            <Field label={t('employees.middleName')} error={form.errors.middle_name}>
                                <input
                                    className={inputCls}
                                    placeholder={t('employees.middleName')}
                                    value={form.data.middle_name}
                                    onChange={(e) => form.setData('middle_name', e.target.value)}
                                />
                            </Field>

                            <Field label={t('employees.lastName')} error={form.errors.last_name}>
                                <input
                                    className={inputCls}
                                    placeholder={t('employees.lastName')}
                                    value={form.data.last_name}
                                    onChange={(e) => form.setData('last_name', e.target.value)}
                                />
                            </Field>

                            <Field label={t('employees.fullNameEn')} error={form.errors.name_en}>
                                <input
                                    className={inputCls}
                                    placeholder={t('employees.fullNameEnPlaceholder')}
                                    value={form.data.name_en}
                                    onChange={(e) => form.setData('name_en', e.target.value)}
                                />
                            </Field>

                            <Field label={t('employees.gender')} error={form.errors.gender}>
                                <select
                                    className={inputCls}
                                    value={form.data.gender}
                                    onChange={(e) => form.setData('gender', e.target.value)}
                                >
                                    <option value="">{t('employees.selectGender')}</option>
                                    <option value="male">{t('employees.male')}</option>
                                    <option value="female">{t('employees.female')}</option>
                                </select>
                            </Field>

                            <Field label={t('employees.dateOfBirth')} error={form.errors.date_of_birth}>
                                <LocalizedDatePicker
                                    className={inputCls}
                                    value={form.data.date_of_birth}
                                    onChange={(iso) => form.setData('date_of_birth', iso)}
                                />
                            </Field>

                            <Field label={t('employees.phone')} error={form.errors.phone}>
                                <input
                                    className={inputCls}
                                    placeholder="+251 9XX XXX XXX"
                                    value={form.data.phone}
                                    onChange={(e) => form.setData('phone', e.target.value)}
                                />
                            </Field>

                            <Field label={t('employees.email')} error={form.errors.email} className="md:col-span-2 xl:col-span-3">
                                <input
                                    className={inputCls}
                                    type="email"
                                    placeholder="employee@example.com"
                                    value={form.data.email}
                                    onChange={(e) => form.setData('email', e.target.value)}
                                />
                            </Field>
                        </FormSection>

                        {/* ── Employment Placement ──────────────────────── */}
                        <FormSection
                            title={t('employees.sectionPlacement')}
                            description={t('employees.sectionPlacementHelp')}
                            columns={3}
                        >
                            {placementContext ? (
                                <div className="md:col-span-2 xl:col-span-3">
                                    <div className="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-slate-700 dark:bg-slate-900/60">
                                        <dl className="grid gap-4 sm:grid-cols-3">
                                            <ReadOnlyValue
                                                label={t('employees.selectedOrganization')}
                                                code={placementContext.organization?.code ?? null}
                                                value={placementContext.organization
                                                    ? localizedName(placementContext.organization.name_en, placementContext.organization.name_am, locale)
                                                    : '—'}
                                            />
                                            <ReadOnlyValue
                                                label={t('employees.selectedOrganizationUnit')}
                                                code={placementContext.organization_unit?.code ?? null}
                                                value={placementContext.organization_unit
                                                    ? localizedName(placementContext.organization_unit.name_en, placementContext.organization_unit.name_am, locale)
                                                    : '—'}
                                            />
                                            <ReadOnlyValue
                                                label={t('employees.selectedPosition')}
                                                code={placementContext.position.code}
                                                value={localizedName(placementContext.position.name_en, placementContext.position.name_am, locale)}
                                            />
                                        </dl>
                                        <div className="mt-4 flex flex-wrap items-center justify-between gap-2">
                                            <p className="text-xs text-gray-500 dark:text-slate-400">
                                                {t('employees.placementFromPositionContext')}
                                            </p>
                                            <Link
                                                href={route('positions.index')}
                                                className="text-xs font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400"
                                            >
                                                {t('employees.changePosition')}
                                            </Link>
                                        </div>
                                    </div>
                                </div>
                            ) : (
                                <>
                                    <div className="md:col-span-2 xl:col-span-3">
                                        <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-dashed border-gray-300 bg-gray-50 px-4 py-3 dark:border-slate-700 dark:bg-slate-900/60">
                                            <p className="text-xs text-gray-500 dark:text-slate-400">
                                                {t('employees.selectVacantPositionFirst')}
                                            </p>
                                            <Link
                                                href={route('positions.index')}
                                                className="text-xs font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400"
                                            >
                                                {t('employees.selectPosition')}
                                            </Link>
                                        </div>
                                    </div>

                                <Field label={t('employees.organization')} error={form.errors.organization_id}>
                                    <select
                                        className={inputCls}
                                        value={form.data.organization_id}
                                        onChange={(e) => changeOrganization(e.target.value)}
                                        disabled={orgLocked}
                                    >
                                        {orgLocked ? (
                                            <option value={form.data.organization_id}>
                                                {selectedOrg
                                                    ? localizedName(selectedOrg.name_en, selectedOrg.name_am, locale)
                                                    : t('employees.selectedOrganization')}
                                            </option>
                                        ) : (
                                            <>
                                                <option value="">{t('employees.selectOrganization')}</option>
                                                {organizations.map((o) => (
                                                    <option key={o.id} value={o.id}>
                                                        {localizedName(o.name_en, o.name_am, locale)}
                                                    </option>
                                                ))}
                                            </>
                                        )}
                                    </select>
                                </Field>
                                </>
                            )}

                            {!positionLocked && (
                                <Field
                                    label={t('positions.organizationUnit')}
                                    error={form.errors.organization_unit_id}
                                    help={
                                        !form.data.organization_id
                                            ? t('employees.selectOrganizationFirst')
                                            : filteredOrganizationUnits.length === 0
                                                ? t('employees.noUnitsForOrganization')
                                                : undefined
                                    }
                                >
                                    <select
                                        className={inputCls}
                                        value={form.data.organization_unit_id}
                                        onChange={(e) => changeOrganizationUnit(e.target.value)}
                                        disabled={!form.data.organization_id || filteredOrganizationUnits.length === 0}
                                    >
                                        <option value="">{t('employees.selectOrganizationUnit')}</option>
                                        {filteredOrganizationUnits.map((unit) => (
                                            <option key={unit.id} value={unit.id}>
                                                {unit.code ? `${unit.code} — ` : ''}
                                                {localizedName(unit.name_en, unit.name_am, locale)}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                            )}

                            {!positionLocked && (
                                <Field
                                    label={t('positions.title')}
                                    error={form.errors.position_id}
                                    help={
                                        !form.data.organization_id
                                            ? t('employees.selectOrganizationFirst')
                                            : filteredPositions.length === 0
                                                ? t('employees.noPositionsForOrganization')
                                                : `${t('employees.onlyVacantPositionsShown')} · ${t('employees.organizationAutoFilledFromPosition')}`
                                    }
                                >
                                    <select
                                        className={inputCls}
                                        value={form.data.position_id}
                                        onChange={(e) => changePosition(e.target.value)}
                                        disabled={!form.data.organization_id || filteredPositions.length === 0}
                                    >
                                        <option value="">{t('employees.selectPosition')}</option>
                                        {filteredPositions.map((pos) => (
                                            <option key={pos.id} value={pos.id}>
                                                {pos.job_position_code ? `${pos.job_position_code} — ` : ''}
                                                {localizedName(pos.title_en, pos.title_am, locale)}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                            )}

                            {!positionLocked && (
                                <Field label={t('employees.orCreatePosition')} error={form.errors.position_title}>
                                    <input
                                        className={inputCls}
                                        placeholder={t('employees.orCreatePosition')}
                                        value={form.data.position_title}
                                        onChange={(e) => form.setData('position_title', e.target.value)}
                                    />
                                </Field>
                            )}

                            {!positionLocked && (
                                <Field label={t('organizations.hierarchyVersion')} error={form.errors.hierarchy_version_id}>
                                    <select
                                        className={inputCls}
                                        value={form.data.hierarchy_version_id}
                                        onChange={(e) => form.setData('hierarchy_version_id', e.target.value)}
                                    >
                                        <option value="">{t('employees.noHierarchyVersion')}</option>
                                        {hierarchyVersions.map((v) => (
                                            <option key={v.id} value={v.id}>{v.version_name}</option>
                                        ))}
                                    </select>
                                </Field>
                            )}

                            <Field label={t('common.status')} error={form.errors.status}>
                                <select
                                    className={inputCls}
                                    value={form.data.status}
                                    onChange={(e) => form.setData('status', e.target.value)}
                                >
                                    <option value="active">{t('employees.active')}</option>
                                    <option value="draft">{t('employees.draft')}</option>
                                    <option value="suspended">{t('employees.suspended')}</option>
                                </select>
                            </Field>

                            <Field label={t('common.effectiveFrom')} error={form.errors.effective_from}>
                                <LocalizedDatePicker
                                    className={inputCls}
                                    value={form.data.effective_from}
                                    onChange={(iso) => form.setData('effective_from', iso)}
                                />
                            </Field>

                            <Field
                                label={t('employees.assignmentReason')}
                                error={form.errors.reason}
                                className="md:col-span-2 xl:col-span-3"
                            >
                                <textarea
                                    className={`${inputCls} min-h-[6rem]`}
                                    placeholder={t('employees.assignmentReason')}
                                    value={form.data.reason}
                                    onChange={(e) => form.setData('reason', e.target.value)}
                                />
                            </Field>
                        </FormSection>

                        {/* ── Attachments ───────────────────────────────── */}
                        <FormSection
                            title={t('employees.sectionAttachments')}
                            description={t('employees.sectionAttachmentsHelp')}
                            grid={false}
                        >
                            <div>
                                <label className={labelCls}>{t('employees.photo')}</label>
                                <div className="flex items-start gap-4">
                                    {photoPreview ? (
                                        <div className="relative flex-shrink-0">
                                            <img
                                                src={photoPreview}
                                                alt=""
                                                className="h-20 w-16 rounded-lg border border-gray-200 object-cover dark:border-slate-700"
                                            />
                                            <button
                                                type="button"
                                                onClick={clearPhoto}
                                                aria-label={t('employees.removePhoto')}
                                                title={t('employees.removePhoto')}
                                                className="absolute -right-2 -top-2 flex h-5 w-5 items-center justify-center rounded-full bg-red-500 text-xs text-white hover:bg-red-600"
                                            >
                                                ×
                                            </button>
                                        </div>
                                    ) : (
                                        <div className="flex h-20 w-16 flex-shrink-0 items-center justify-center rounded-lg border-2 border-dashed border-gray-300 text-gray-400 dark:border-slate-600 dark:text-slate-500">
                                            <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                            </svg>
                                        </div>
                                    )}
                                    <div className="flex-1">
                                        <input
                                            ref={photoInputRef}
                                            type="file"
                                            accept="image/jpeg,image/png,image/webp"
                                            onChange={handlePhotoChange}
                                            className="w-full rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 file:mr-3 file:rounded file:border-0 file:bg-blue-50 file:px-2 file:py-1 file:text-xs file:font-medium file:text-blue-700 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-300"
                                        />
                                        <p className={helpCls}>{t('employees.photoHint')}</p>
                                        {form.errors.photo && (
                                            <p className="mt-1 text-xs text-red-600 dark:text-red-400">{form.errors.photo}</p>
                                        )}
                                    </div>
                                </div>
                            </div>
                        </FormSection>
                    </div>

                    {/* Sticky action bar */}
                    <div className="sticky bottom-0 -mx-6 -mb-6 mt-8 flex items-center justify-end gap-3 border-t border-gray-100 bg-white/95 px-6 py-4 backdrop-blur dark:border-slate-800 dark:bg-slate-900/95">
                        <Link
                            href={route('employees.index')}
                            className="rounded-lg px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-slate-300 dark:hover:bg-slate-800"
                        >
                            {t('common.cancel')}
                        </Link>
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:opacity-60 dark:focus:ring-offset-slate-900"
                        >
                            {form.processing ? t('common.saving') : t('employees.saveEmployee')}
                        </button>
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
