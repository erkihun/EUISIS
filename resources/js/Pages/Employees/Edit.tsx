import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { localizedName } from '@/utils/localizedName';
import { toDateInput } from '@/lib/dateUtils';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import {
    BasicIcon,
    ContactIcon,
    EmergencyIcon,
    EmploymentIcon,
    Field,
    FormActions,
    FormCard,
    ReadOnlyValue,
    helpCls,
    inputCls,
    labelCls,
} from '@/Components/employees/EmployeeFormLayout';

/** Mirrors App\Enums\EmploymentType — how an employee is engaged. */
const EMPLOYMENT_TYPES = ['permanent', 'contract', 'temporary', 'probation', 'daily_labor', 'intern', 'other'] as const;

type LocalizedOrganization = { name_en: string; name_am?: string | null };
type LocalizedPosition = { id?: string; title_en: string; title_am?: string | null };

type Employee = {
    id: string;
    employee_number: string;
    first_name: string;
    middle_name?: string | null;
    last_name: string;
    full_name: string;
    name_en?: string | null;
    metadata?: { name_en?: string | null; name_am?: string | null } | null;
    national_id?: string | null;
    phone?: string | null;
    email?: string | null;
    date_of_birth?: string | null;
    gender?: string | null;
    address?: string | null;
    nationality?: string | null;
    employment_type?: string | null;
    emergency_contact_name?: string | null;
    emergency_contact_phone?: string | null;
    status: string;
    photo_path?: string | null;
    photo_url?: string | null;
    current_assignment?: {
        organization?: LocalizedOrganization | null;
        position?: LocalizedPosition | null;
        effective_from?: string | null;
    } | null;
};

type Position = { id: string; title_en: string };

export default function EmployeesEdit({
    employee,
}: {
    employee: Employee;
    /** Supplied by the controller; placement is changed through Transfers, not here. */
    positions?: Position[];
}) {
    const { t, locale } = useLocale();

    const form = useForm<{
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
        photo: File | null;
        remove_photo: boolean;
    }>({
        first_name: employee.first_name ?? '',
        middle_name: employee.middle_name ?? '',
        last_name: employee.last_name ?? '',
        name_en: employee.name_en ?? employee.metadata?.name_en ?? '',
        national_id: employee.national_id ?? '',
        phone: employee.phone ?? '',
        email: employee.email ?? '',
        date_of_birth: toDateInput(employee.date_of_birth),
        gender: employee.gender ?? '',
        address: employee.address ?? '',
        nationality: employee.nationality ?? '',
        employment_type: employee.employment_type ?? '',
        emergency_contact_name: employee.emergency_contact_name ?? '',
        emergency_contact_phone: employee.emergency_contact_phone ?? '',
        status: employee.status ?? 'active',
        photo: null,
        remove_photo: false,
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
        form.setData((prev) => ({ ...prev, photo: file, remove_photo: false }));
        setPhotoPreview(file ? URL.createObjectURL(file) : null);
    }

    function clearNewPhoto() {
        form.setData((prev) => ({ ...prev, photo: null }));
        setPhotoPreview(null);
        if (photoInputRef.current) photoInputRef.current.value = '';
    }

    function toggleRemovePhoto() {
        form.setData((prev) => ({ ...prev, remove_photo: !prev.remove_photo, photo: null }));
        setPhotoPreview(null);
        if (photoInputRef.current) photoInputRef.current.value = '';
    }

    /*
     * A file input means the update must go out as a POST carrying a `_method`
     * override — Inertia cannot PATCH multipart. `form.processing` blocks a
     * second submit, and Inertia preserves `form.data` across a validation
     * error so typed input is never lost.
     */
    function submit(e: React.FormEvent) {
        e.preventDefault();
        if (form.processing) return;
        form.transform((data) => ({ ...data, _method: 'patch' }));
        form.post(route('employees.update', employee.id));
    }

    /*
     * The controller redirects to employees.show on success, so both save
     * buttons land on the detail page. The pair exists so Create and Edit
     * present the same action bar.
     */
    function saveAndView() {
        if (form.processing) return;
        form.transform((data) => ({ ...data, _method: 'patch' }));
        form.post(route('employees.update', employee.id));
    }

    const showCurrentPhoto = !!employee.photo_url && !form.data.remove_photo && !photoPreview;

    const assignedOrganization = employee.current_assignment?.organization;
    const assignedPosition = employee.current_assignment?.position;

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    backHref={route('employees.show', employee.id)}
                    title={t('employees.updateEmployee')}
                    description={`${employee.full_name} · ${employee.employee_number}`}
                />
            }
        >
            <Head title={`${t('employees.updateEmployee')} — ${employee.full_name}`} />

            <form onSubmit={submit} className="mx-auto w-full max-w-5xl">
                <div className="space-y-5">
                    {/* ── 1. Basic Information ──────────────────────────── */}
                    <FormCard
                        icon={<BasicIcon />}
                        title={t('employees.sectionBasic')}
                        description={t('employees.sectionBasicHelp')}
                    >
                        {/*
                          * The employee number is issued by the Code Rule engine
                          * at creation and is never editable afterwards, so it
                          * is shown rather than offered as a field.
                          */}
                        <Field label={t('employees.employeeNumber')}>
                            <input
                                className={inputCls}
                                value={employee.employee_number}
                                readOnly
                                disabled
                            />
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

                        <Field label={t('employees.firstName')} error={form.errors.first_name} required htmlFor="first_name">
                            <input
                                id="first_name"
                                className={inputCls}
                                placeholder={t('employees.firstName')}
                                value={form.data.first_name}
                                onChange={(e) => form.setData('first_name', e.target.value)}
                            />
                        </Field>

                        <Field label={t('employees.middleName')} error={form.errors.middle_name} htmlFor="middle_name">
                            <input
                                id="middle_name"
                                className={inputCls}
                                placeholder={t('employees.middleName')}
                                value={form.data.middle_name}
                                onChange={(e) => form.setData('middle_name', e.target.value)}
                            />
                        </Field>

                        <Field label={t('employees.lastName')} error={form.errors.last_name} required htmlFor="last_name">
                            <input
                                id="last_name"
                                className={inputCls}
                                placeholder={t('employees.lastName')}
                                value={form.data.last_name}
                                onChange={(e) => form.setData('last_name', e.target.value)}
                            />
                        </Field>

                        <Field label={t('employees.fullNameEn')} error={form.errors.name_en} htmlFor="name_en">
                            <input
                                id="name_en"
                                className={inputCls}
                                placeholder={t('employees.fullNameEnPlaceholder')}
                                value={form.data.name_en}
                                onChange={(e) => form.setData('name_en', e.target.value)}
                            />
                        </Field>

                        <Field label={t('employees.gender')} error={form.errors.gender} htmlFor="gender">
                            <select
                                id="gender"
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

                        {/* Photo spans the full card width so the preview has room. */}
                        <div className="md:col-span-2">
                            <label className={labelCls}>{t('employees.photo')}</label>
                            <div className="flex flex-col gap-4 sm:flex-row sm:items-start">
                                <div className="flex-shrink-0 self-start">
                                    {showCurrentPhoto && employee.photo_url ? (
                                        <div className="flex flex-col items-center gap-1">
                                            <img
                                                src={employee.photo_url}
                                                alt=""
                                                className="h-20 w-16 rounded-lg border border-gray-200 object-cover dark:border-slate-700"
                                            />
                                            <button
                                                type="button"
                                                onClick={toggleRemovePhoto}
                                                className="text-xs text-red-500 transition hover:text-red-700"
                                            >
                                                {t('common.remove')}
                                            </button>
                                        </div>
                                    ) : photoPreview ? (
                                        <div className="relative">
                                            <img
                                                src={photoPreview}
                                                alt=""
                                                className="h-20 w-16 rounded-lg border border-blue-200 object-cover dark:border-blue-700"
                                            />
                                            <button
                                                type="button"
                                                onClick={clearNewPhoto}
                                                aria-label={t('employees.removePhoto')}
                                                title={t('employees.removePhoto')}
                                                className="absolute -right-2 -top-2 flex h-5 w-5 items-center justify-center rounded-full bg-red-500 text-xs text-white hover:bg-red-600"
                                            >
                                                ×
                                            </button>
                                        </div>
                                    ) : (
                                        <div className="flex h-20 w-16 items-center justify-center rounded-lg border-2 border-dashed border-gray-300 text-gray-400 dark:border-slate-600 dark:text-slate-500">
                                            <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                            </svg>
                                        </div>
                                    )}
                                </div>

                                <div className="min-w-0 flex-1 space-y-2">
                                    {form.data.remove_photo && (
                                        <div className="flex flex-wrap items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 dark:border-red-800 dark:bg-red-950">
                                            <span className="text-xs text-red-700 dark:text-red-300">
                                                {t('employees.photoWillBeRemoved')}
                                            </span>
                                            <button
                                                type="button"
                                                onClick={toggleRemovePhoto}
                                                className="ml-auto text-xs text-red-500 transition hover:text-red-700"
                                            >
                                                {t('common.undo')}
                                            </button>
                                        </div>
                                    )}
                                    {!form.data.remove_photo && (
                                        <input
                                            ref={photoInputRef}
                                            type="file"
                                            accept="image/jpeg,image/png,image/webp"
                                            onChange={handlePhotoChange}
                                            className="w-full rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 file:mr-3 file:rounded file:border-0 file:bg-blue-50 file:px-2 file:py-1 file:text-xs file:font-medium file:text-blue-700 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-300"
                                        />
                                    )}
                                    <p className={helpCls}>{t('employees.photoHint')}</p>
                                    {form.errors.photo && (
                                        <p role="alert" className="text-xs text-red-600 dark:text-red-400">{form.errors.photo}</p>
                                    )}
                                </div>
                            </div>
                        </div>
                    </FormCard>

                    {/* ── 2. Contact Information ────────────────────────── */}
                    <FormCard
                        icon={<ContactIcon />}
                        title={t('employees.sectionContact')}
                        description={t('employees.sectionContactHelp')}
                    >
                        <Field label={t('employees.phone')} error={form.errors.phone} htmlFor="phone">
                            <input
                                id="phone"
                                className={inputCls}
                                placeholder="+251 9XX XXX XXX"
                                value={form.data.phone}
                                onChange={(e) => form.setData('phone', e.target.value)}
                            />
                        </Field>

                        <Field label={t('employees.email')} error={form.errors.email} htmlFor="email">
                            <input
                                id="email"
                                className={inputCls}
                                type="email"
                                placeholder="employee@example.com"
                                value={form.data.email}
                                onChange={(e) => form.setData('email', e.target.value)}
                            />
                        </Field>

                        <Field label={t('employees.nationality')} error={form.errors.nationality} htmlFor="nationality">
                            <input
                                id="nationality"
                                className={inputCls}
                                maxLength={100}
                                placeholder={t('employees.nationalityPlaceholder')}
                                value={form.data.nationality}
                                onChange={(e) => form.setData('nationality', e.target.value)}
                            />
                        </Field>

                        <Field
                            label={t('employees.employeeStatus')}
                            error={form.errors.employment_type}
                            htmlFor="employment_type"
                        >
                            <select
                                id="employment_type"
                                className={inputCls}
                                value={form.data.employment_type}
                                onChange={(e) => form.setData('employment_type', e.target.value)}
                            >
                                <option value="">{t('employees.employmentTypePlaceholder')}</option>
                                {EMPLOYMENT_TYPES.map((type) => (
                                    <option key={type} value={type}>
                                        {t(`employees.employmentType_${type}`)}
                                    </option>
                                ))}
                            </select>
                        </Field>

                        <Field
                            label={t('employees.address')}
                            error={form.errors.address}
                            className="md:col-span-2"
                            htmlFor="address"
                        >
                            <textarea
                                id="address"
                                className={inputCls}
                                rows={2}
                                maxLength={1000}
                                placeholder={t('employees.addressPlaceholder')}
                                value={form.data.address}
                                onChange={(e) => form.setData('address', e.target.value)}
                            />
                        </Field>
                    </FormCard>

                    {/* ── 3. Employment Information ─────────────────────── */}
                    <FormCard
                        icon={<EmploymentIcon />}
                        title={t('employees.sectionEmployment')}
                        description={t('employees.sectionEmploymentHelp')}
                    >
                        <Field label={t('employees.employmentStatus')} error={form.errors.status} required htmlFor="status">
                            <select
                                id="status"
                                className={inputCls}
                                value={form.data.status}
                                onChange={(e) => form.setData('status', e.target.value)}
                            >
                                <option value="active">{t('employees.active')}</option>
                                <option value="draft">{t('employees.draft')}</option>
                                <option value="suspended">{t('employees.suspended')}</option>
                                <option value="transferred">{t('employees.transferred')}</option>
                                <option value="retired">{t('employees.retired')}</option>
                                <option value="terminated">{t('employees.terminated')}</option>
                            </select>
                        </Field>

                        {/*
                          * Current placement is read-only here on purpose. The
                          * update endpoint accepts no organization, unit or
                          * position field — moving an employee runs through the
                          * Transfers workflow, which keeps the assignment
                          * history and the position-occupancy rules intact.
                          */}
                        <div className="md:col-span-2">
                            <div className="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-slate-700 dark:bg-slate-950/40">
                                <dl className="grid gap-4 sm:grid-cols-3">
                                    <ReadOnlyValue
                                        label={t('employees.currentOrganization')}
                                        value={assignedOrganization
                                            ? localizedName(assignedOrganization.name_en, assignedOrganization.name_am, locale)
                                            : '—'}
                                    />
                                    <ReadOnlyValue
                                        label={t('employees.columnPosition')}
                                        value={assignedPosition
                                            ? localizedName(assignedPosition.title_en, assignedPosition.title_am, locale)
                                            : t('employees.noPosition')}
                                    />
                                    <ReadOnlyValue
                                        label={t('employees.employeeNumber')}
                                        value={employee.employee_number}
                                    />
                                </dl>
                            </div>
                        </div>
                    </FormCard>

                    {/* ── 4. Emergency Contact ──────────────────────────── */}
                    <FormCard
                        icon={<EmergencyIcon />}
                        title={t('employees.sectionEmergency')}
                        description={t('employees.sectionEmergencyHelp')}
                    >
                        <Field
                            label={t('employees.emergencyContactName')}
                            error={form.errors.emergency_contact_name}
                            htmlFor="emergency_contact_name"
                        >
                            <input
                                id="emergency_contact_name"
                                className={inputCls}
                                maxLength={255}
                                placeholder={t('employees.emergencyContactName')}
                                value={form.data.emergency_contact_name}
                                onChange={(e) => form.setData('emergency_contact_name', e.target.value)}
                            />
                        </Field>

                        <Field
                            label={t('employees.emergencyContactPhone')}
                            error={form.errors.emergency_contact_phone}
                            htmlFor="emergency_contact_phone"
                        >
                            <input
                                id="emergency_contact_phone"
                                className={inputCls}
                                maxLength={50}
                                placeholder="+251 9XX XXX XXX"
                                value={form.data.emergency_contact_phone}
                                onChange={(e) => form.setData('emergency_contact_phone', e.target.value)}
                            />
                        </Field>
                    </FormCard>
                </div>

                <FormActions
                    processing={form.processing}
                    cancelHref={route('employees.show', employee.id)}
                    saveLabel={t('employees.saveEmployee')}
                    onSaveAndView={saveAndView}
                />
            </form>
        </AuthenticatedLayout>
    );
}
