import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ChangeEvent, FormEvent, useEffect, useMemo, useRef, useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { toast } from '@/lib/toast';
import { localizedName } from '@/utils/localizedName';
import CodeRuleField from '@/Components/code-rules/CodeRuleField';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import {
    BasicIcon,
    ContactIcon,
    EmergencyIcon,
    EmploymentIcon,
    Field,
    FormActions,
    FormCard,
    PositionSelectedBadge,
    ReadOnlyValue,
    SystemIcon,
    helpCls,
    inputCls,
    labelCls,
} from '@/Components/employees/EmployeeFormLayout';

const EMPLOYMENT_TYPES = ['permanent', 'contract', 'temporary', 'probation', 'daily_labor', 'intern', 'other'] as const;

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

export default function EmployeesCreate({
    organizations,
    organizationUnits,
    hierarchyVersions,
    positions,
    selectedOrganizationId,
    selectedOrganizationUnitId,
    selectedPositionId,
    organizationLocked = false,
    placementContext,
}: {
    organizations: Option[];
    organizationUnits: Option[];
    hierarchyVersions: Option[];
    positions: Option[];
    selectedOrganizationId: string | null;
    selectedOrganizationUnitId: string | null;
    selectedPositionId: string | null;
    organizationLocked?: boolean;
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
        address: string;
        nationality: string;
        employment_type: string;
        emergency_contact_name: string;
        emergency_contact_phone: string;
        status: string;
        organization_id: string;
        organization_unit_id: string;
        hierarchy_version_id: string;
        position_id: string;
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
        address: '',
        nationality: '',
        employment_type: '',
        emergency_contact_name: '',
        emergency_contact_phone: '',
        status: 'active',
        organization_id: selectedOrganizationId ?? organizations[0]?.id ?? '',
        organization_unit_id: selectedOrganizationUnitId ?? '',
        hierarchy_version_id: hierarchyVersions[0]?.id ?? '',
        position_id: selectedPositionId ?? '',
        effective_from: new Date().toISOString().slice(0, 10),
        reason: '',
        photo: null,
    });

    const [photoPreview, setPhotoPreview] = useState<string | null>(null);
    const photoInputRef = useRef<HTMLInputElement>(null);

    const selectedOrg = organizations.find((organization) => organization.id === form.data.organization_id);
    const positionLocked = selectedPositionId !== null;

    const filteredOrganizationUnits = useMemo(
        () => organizationUnits.filter((unit) => unit.organization_id === form.data.organization_id),
        [organizationUnits, form.data.organization_id],
    );

    const filteredPositions = useMemo(
        () => positions.filter((position) => {
            if (position.organization_id !== form.data.organization_id) return false;
            if (form.data.organization_unit_id === '') return true;

            return position.organization_unit_id === form.data.organization_unit_id;
        }),
        [positions, form.data.organization_id, form.data.organization_unit_id],
    );

    function handleNationalIdChange(raw: string) {
        const digits = raw.replace(/\D/g, '').slice(0, 16);
        form.setData('national_id', digits);
    }

    function formatNationalId(digits: string): string {
        return digits.replace(/(.{4})/g, '$1 ').trim();
    }

    function handlePhotoChange(e: ChangeEvent<HTMLInputElement>) {
        const file = e.target.files?.[0] ?? null;
        form.setData('photo', file);
        /* Each preview holds a blob alive until it is revoked, so release the
         * previous one whenever the pick changes. */
        setPhotoPreview((previous) => {
            if (previous) URL.revokeObjectURL(previous);

            return file ? URL.createObjectURL(file) : null;
        });
    }

    function clearPhoto() {
        form.setData('photo', null);
        setPhotoPreview((previous) => {
            if (previous) URL.revokeObjectURL(previous);

            return null;
        });
        if (photoInputRef.current) photoInputRef.current.value = '';
    }

    useEffect(() => () => {
        if (photoPreview) URL.revokeObjectURL(photoPreview);
    }, [photoPreview]);

    /*
     * Guard the ~25 fields on this form against an accidental exit. Both exits
     * are covered: a browser close or reload, and an in-app navigation such as
     * the Cancel link or the sidebar. The submit itself must pass through, so
     * an in-flight visit is never challenged.
     */
    useEffect(() => {
        if (!form.isDirty || form.processing) {
            return;
        }

        const warnOnUnload = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };

        window.addEventListener('beforeunload', warnOnUnload);
        const stopListening = router.on('before', () => window.confirm(t('employees.unsavedChangesWarning')));

        return () => {
            window.removeEventListener('beforeunload', warnOnUnload);
            stopListening();
        };
    }, [form.isDirty, form.processing, t]);

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

    /*
     * Send the user to the field that failed.
     *
     * The form is six stacked cards tall and the save button is pinned to the
     * bottom of the viewport, so a rejected submit used to look like nothing
     * happened at all — the message was rendered correctly, just several
     * screens above the button that was pressed.
     */
    function focusFirstError(errors: Record<string, string>) {
        const [firstKey] = Object.keys(errors);

        if (firstKey === undefined) {
            return;
        }

        toast.error(errors[firstKey]);

        /* Most controls carry an id matching the field name; the few that are
         * rendered by a sub-component are found by name instead. */
        const field = document.getElementById(firstKey)
            ?? document.querySelector<HTMLElement>(`[name="${firstKey}"]`);

        field?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        field?.focus({ preventScroll: true });
    }

    function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        if (form.processing) return;
        form.post(route('employees.store'), {
            preserveScroll: true,
            onError: focusFirstError,
        });
    }

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

            <form onSubmit={submit} className="w-full">
                <div className="space-y-5">
                    <FormCard
                        wide
                        icon={<EmploymentIcon />}
                        title={t('employees.sectionPlacement')}
                        description={t('employees.sectionPlacementHelp')}
                        aside={placementContext ? <PositionSelectedBadge /> : undefined}
                    >
                        {placementContext ? (
                            <div className="md:col-span-2 xl:col-span-3">
                                <div className="rounded-card border border-gray-200 bg-gray-50 p-4 dark:border-slate-700 dark:bg-slate-950/40">
                                    <dl className="grid gap-4 sm:grid-cols-3">
                                        <ReadOnlyValue
                                            label={t('employees.selectedOrganization')}
                                            code={placementContext.organization?.code ?? null}
                                            value={placementContext.organization
                                                ? localizedName(placementContext.organization.name_en, placementContext.organization.name_am, locale)
                                                : '-'}
                                        />
                                        <ReadOnlyValue
                                            label={t('employees.selectedOrganizationUnit')}
                                            code={placementContext.organization_unit?.code ?? null}
                                            value={placementContext.organization_unit
                                                ? localizedName(placementContext.organization_unit.name_en, placementContext.organization_unit.name_am, locale)
                                                : '-'}
                                        />
                                        <ReadOnlyValue
                                            label={t('employees.selectedPosition')}
                                            code={placementContext.position.code}
                                            value={localizedName(placementContext.position.name_en, placementContext.position.name_am, locale)}
                                        />
                                    </dl>
                                    <div className="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-gray-200 pt-3 dark:border-slate-700">
                                        <p className="min-w-0 text-xs text-gray-500 dark:text-slate-400">
                                            {t('employees.placementFromPositionContext')}
                                            {' - '}
                                            {t('employees.positionContextLocked')}
                                        </p>
                                        <Link
                                            href={route('positions.index')}
                                            className="inline-flex items-center gap-1 rounded-lg border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 transition hover:bg-white dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                                        >
                                            {t('employees.changePosition')}
                                        </Link>
                                    </div>
                                </div>
                            </div>
                        ) : (
                            <>
                                <Field label={t('employees.organization')} error={form.errors.organization_id} required htmlFor="organization_id">
                                    <select
                                        id="organization_id"
                                        className={inputCls}
                                        value={form.data.organization_id}
                                        onChange={(e) => changeOrganization(e.target.value)}
                                        disabled={organizationLocked}
                                    >
                                        {organizationLocked ? (
                                            <option value={form.data.organization_id}>
                                                {selectedOrg
                                                    ? localizedName(selectedOrg.name_en, selectedOrg.name_am, locale)
                                                    : t('employees.selectedOrganization')}
                                            </option>
                                        ) : (
                                            <>
                                                <option value="">{t('employees.selectOrganization')}</option>
                                                {organizations.map((organization) => (
                                                    <option key={organization.id} value={organization.id}>
                                                        {localizedName(organization.name_en, organization.name_am, locale)}
                                                    </option>
                                                ))}
                                            </>
                                        )}
                                    </select>
                                </Field>

                                <Field
                                    label={t('employees.organizationUnit')}
                                    error={form.errors.organization_unit_id}
                                    htmlFor="organization_unit_id"
                                    help={
                                        !form.data.organization_id
                                            ? t('employees.selectOrganizationFirst')
                                            : filteredOrganizationUnits.length === 0
                                                ? t('employees.noUnitsForOrganization')
                                                : undefined
                                    }
                                >
                                    <select
                                        id="organization_unit_id"
                                        className={inputCls}
                                        value={form.data.organization_unit_id}
                                        onChange={(e) => changeOrganizationUnit(e.target.value)}
                                        disabled={!form.data.organization_id || filteredOrganizationUnits.length === 0}
                                    >
                                        <option value="">{t('employees.selectOrganizationUnit')}</option>
                                        {filteredOrganizationUnits.map((unit) => (
                                            <option key={unit.id} value={unit.id}>
                                                {unit.code ? `${unit.code} - ` : ''}
                                                {localizedName(unit.name_en, unit.name_am, locale)}
                                            </option>
                                        ))}
                                    </select>
                                </Field>

                                <Field
                                    label={t('employees.position')}
                                    error={form.errors.position_id}
                                    htmlFor="position_id"
                                    help={
                                        !form.data.organization_id
                                            ? t('employees.selectOrganizationFirst')
                                            : filteredPositions.length === 0
                                                ? t('employees.noVacantPositionsAvailable')
                                                : `${t('employees.onlyVacantPositionsShown')} - ${t('employees.organizationAutoFilledFromPosition')} - ${t('employees.selectVacantPositionFirst')}`
                                    }
                                >
                                    <select
                                        id="position_id"
                                        className={inputCls}
                                        value={form.data.position_id}
                                        onChange={(e) => changePosition(e.target.value)}
                                        disabled={!form.data.organization_id || filteredPositions.length === 0}
                                    >
                                        <option value="">{t('employees.selectPosition')}</option>
                                        {filteredPositions.map((position) => (
                                            <option key={position.id} value={position.id}>
                                                {position.job_position_code ? `${position.job_position_code} - ` : ''}
                                                {localizedName(position.title_en, position.title_am, locale)}
                                            </option>
                                        ))}
                                    </select>
                                </Field>

                                <Field label={t('organizations.hierarchyVersion')} error={form.errors.hierarchy_version_id} htmlFor="hierarchy_version_id">
                                    <select
                                        id="hierarchy_version_id"
                                        className={inputCls}
                                        value={form.data.hierarchy_version_id}
                                        onChange={(e) => form.setData('hierarchy_version_id', e.target.value)}
                                    >
                                        <option value="">{t('employees.noHierarchyVersion')}</option>
                                        {hierarchyVersions.map((version) => (
                                            <option key={version.id} value={version.id}>{version.version_name}</option>
                                        ))}
                                    </select>
                                </Field>
                            </>
                        )}

                        {positionLocked && (
                            <input type="hidden" name="position_id" value={form.data.position_id} readOnly />
                        )}
                    </FormCard>

                    <FormCard wide icon={<BasicIcon />} title={t('employees.sectionBasic')} description={t('employees.sectionBasicHelp')}>
                        <div className="min-w-0">
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

                        <Field label={t('employees.firstName')} error={form.errors.first_name} required htmlFor="first_name">
                            <input id="first_name" className={inputCls} value={form.data.first_name} onChange={(e) => form.setData('first_name', e.target.value)} />
                        </Field>

                        <Field label={t('employees.middleName')} error={form.errors.middle_name} htmlFor="middle_name">
                            <input id="middle_name" className={inputCls} value={form.data.middle_name} onChange={(e) => form.setData('middle_name', e.target.value)} />
                        </Field>

                        <Field label={t('employees.lastName')} error={form.errors.last_name} required htmlFor="last_name">
                            <input id="last_name" className={inputCls} value={form.data.last_name} onChange={(e) => form.setData('last_name', e.target.value)} />
                        </Field>

                        <Field label={t('employees.fullNameEn')} error={form.errors.name_en} htmlFor="name_en">
                            <input id="name_en" className={inputCls} placeholder={t('employees.fullNameEnPlaceholder')} value={form.data.name_en} onChange={(e) => form.setData('name_en', e.target.value)} />
                        </Field>

                        <Field label={t('employees.nationalId')} error={form.errors.national_id} htmlFor="national_id">
                            <input
                                id="national_id"
                                className={inputCls}
                                placeholder="XXXX XXXX XXXX XXXX"
                                inputMode="numeric"
                                value={formatNationalId(form.data.national_id)}
                                onChange={(e) => handleNationalIdChange(e.target.value)}
                                maxLength={19}
                            />
                        </Field>

                        <Field label={t('employees.gender')} error={form.errors.gender} htmlFor="gender">
                            <select id="gender" className={inputCls} value={form.data.gender} onChange={(e) => form.setData('gender', e.target.value)}>
                                <option value="">{t('employees.selectGender')}</option>
                                <option value="male">{t('employees.male')}</option>
                                <option value="female">{t('employees.female')}</option>
                            </select>
                        </Field>

                        <Field label={t('employees.dateOfBirth')} error={form.errors.date_of_birth}>
                            <LocalizedDatePicker className={inputCls} value={form.data.date_of_birth} onChange={(iso) => form.setData('date_of_birth', iso)} />
                        </Field>

                        <div className="md:col-span-2 xl:col-span-3">
                            <label className={labelCls} htmlFor="photo">{t('employees.photo')}</label>
                            <div className="flex flex-col gap-4 sm:flex-row sm:items-start">
                                {photoPreview ? (
                                    <div className="relative flex-shrink-0 self-start">
                                        <img src={photoPreview} alt="" className="h-20 w-16 rounded-lg border border-gray-200 object-cover dark:border-slate-700" />
                                        <button
                                            type="button"
                                            onClick={clearPhoto}
                                            aria-label={t('employees.removePhoto')}
                                            title={t('employees.removePhoto')}
                                            className="absolute -right-2 -top-2 flex h-5 w-5 items-center justify-center rounded-full bg-red-500 text-xs text-white hover:bg-red-600"
                                        >
                                            x
                                        </button>
                                    </div>
                                ) : (
                                    <div className="flex h-20 w-16 flex-shrink-0 items-center justify-center self-start rounded-lg border-2 border-dashed border-gray-300 text-gray-400 dark:border-slate-600 dark:text-slate-500">
                                        <BasicIcon />
                                    </div>
                                )}
                                <div className="min-w-0 flex-1">
                                    <input
                                        id="photo"
                                        ref={photoInputRef}
                                        type="file"
                                        accept="image/jpeg,image/png,image/webp"
                                        onChange={handlePhotoChange}
                                        className="w-full rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 file:mr-3 file:rounded file:border-0 file:bg-blue-50 file:px-2 file:py-1 file:text-xs file:font-medium file:text-blue-700 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-300"
                                    />
                                    <p className={helpCls}>{t('employees.photoHint')}</p>
                                    {form.errors.photo && <p role="alert" className="mt-1 text-xs text-red-600 dark:text-red-400">{form.errors.photo}</p>}
                                </div>
                            </div>
                        </div>
                    </FormCard>

                    <FormCard wide icon={<ContactIcon />} title={t('employees.sectionContact')} description={t('employees.sectionContactHelp')}>
                        <Field label={t('employees.phone')} error={form.errors.phone} htmlFor="phone">
                            <input id="phone" className={inputCls} placeholder="+251 9XX XXX XXX" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} />
                        </Field>

                        <Field label={t('employees.email')} error={form.errors.email} htmlFor="email">
                            <input id="email" className={inputCls} type="email" placeholder="employee@example.com" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                        </Field>

                        <Field label={t('employees.nationality')} error={form.errors.nationality} htmlFor="nationality">
                            <input id="nationality" className={inputCls} maxLength={100} placeholder={t('employees.nationalityPlaceholder')} value={form.data.nationality} onChange={(e) => form.setData('nationality', e.target.value)} />
                        </Field>

                        <Field label={t('employees.address')} error={form.errors.address} className="md:col-span-2 xl:col-span-3" htmlFor="address">
                            <textarea id="address" className={inputCls} rows={2} maxLength={500} placeholder={t('employees.addressPlaceholder')} value={form.data.address} onChange={(e) => form.setData('address', e.target.value)} />
                        </Field>
                    </FormCard>

                    <FormCard wide icon={<SystemIcon />} title={t('employees.sectionEmployment')} description={t('employees.sectionEmploymentHelp')}>
                        <Field label={t('employees.employmentType')} error={form.errors.employment_type} htmlFor="employment_type">
                            <select id="employment_type" className={inputCls} value={form.data.employment_type} onChange={(e) => form.setData('employment_type', e.target.value)}>
                                <option value="">{t('employees.employmentTypePlaceholder')}</option>
                                {EMPLOYMENT_TYPES.map((type) => (
                                    <option key={type} value={type}>{t(`employees.employmentType_${type}`)}</option>
                                ))}
                            </select>
                        </Field>

                        <Field label={t('employees.systemStatus')} error={form.errors.status} required htmlFor="status">
                            <select id="status" className={inputCls} value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                                <option value="active">{t('employees.active')}</option>
                                <option value="draft">{t('employees.draft')}</option>
                                <option value="suspended">{t('employees.suspended')}</option>
                                <option value="transferred">{t('employees.transferred')}</option>
                                <option value="retired">{t('employees.retired')}</option>
                                <option value="terminated">{t('employees.terminated')}</option>
                            </select>
                        </Field>

                        <Field label={t('employees.assignmentStartDate')} error={form.errors.effective_from} required>
                            <LocalizedDatePicker className={inputCls} value={form.data.effective_from} onChange={(iso) => form.setData('effective_from', iso)} />
                        </Field>
                    </FormCard>

                    <FormCard wide icon={<EmergencyIcon />} title={t('employees.sectionEmergency')} description={t('employees.sectionEmergencyHelp')}>
                        <Field label={t('employees.emergencyContactName')} error={form.errors.emergency_contact_name} htmlFor="emergency_contact_name">
                            <input id="emergency_contact_name" className={inputCls} maxLength={255} value={form.data.emergency_contact_name} onChange={(e) => form.setData('emergency_contact_name', e.target.value)} />
                        </Field>

                        <Field label={t('employees.emergencyContactPhone')} error={form.errors.emergency_contact_phone} htmlFor="emergency_contact_phone">
                            <input id="emergency_contact_phone" className={inputCls} maxLength={50} placeholder="+251 9XX XXX XXX" value={form.data.emergency_contact_phone} onChange={(e) => form.setData('emergency_contact_phone', e.target.value)} />
                        </Field>
                    </FormCard>

                    <FormCard icon={<SystemIcon />} title={t('employees.sectionSystem')} description={t('employees.sectionSystemHelp')} grid={false}>
                        <Field label={t('employees.assignmentReason')} error={form.errors.reason} htmlFor="reason">
                            <textarea id="reason" className={`${inputCls} min-h-[6rem]`} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                        </Field>
                    </FormCard>
                </div>

                <FormActions processing={form.processing} cancelHref={route('employees.index')} saveLabel={t('employees.saveEmployee')} />
            </form>
        </AuthenticatedLayout>
    );
}
