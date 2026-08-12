import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { toDateInput } from '@/lib/dateUtils';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';

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
    status: string;
    photo_path?: string | null;
    photo_url?: string | null;
    current_assignment?: {
        organization?: { name_en: string } | null;
        position?: { id: string; title_en: string } | null;
    } | null;
};

type Position = { id: string; title_en: string };

const inputCls =
    'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder-slate-500';
const labelCls = 'mb-1 block text-xs font-medium text-gray-600 dark:text-slate-400';

function Field({ label, error, children }: { label: string; error?: string; children: React.ReactNode }) {
    return (
        <div>
            <label className={labelCls}>{label}</label>
            {children}
            {error && <p className="mt-1 text-xs text-red-600">{error}</p>}
        </div>
    );
}

export default function EmployeesEdit({
    employee,
    positions,
}: {
    employee: Employee;
    positions: Position[];
}) {
    const { t } = useLocale();

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

    function submit(e: React.FormEvent) {
        e.preventDefault();
        form.transform((data) => ({ ...data, _method: 'patch' }));
        form.post(route('employees.update', employee.id), { preserveState: true });
    }

    const showCurrentPhoto = !!employee.photo_url && !form.data.remove_photo && !photoPreview;

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={`${t('employees.editEmployee')} — ${employee.full_name}`}
                    description={employee.employee_number}
                    actions={
                        <Link
                            href={route('employees.show', employee.id)}
                            className="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                        >
                            {t('common.back')}
                        </Link>
                    }
                />
            }
        >
            <Head title={`${t('employees.editEmployee')} — ${employee.full_name}`} />

            <form
                className="rounded-2xl border border-gray-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900"
                onSubmit={submit}
            >
                <div className="grid gap-4 sm:grid-cols-2">
                    {/* National ID */}
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

                    {/* Status */}
                    <Field label={t('common.status')} error={form.errors.status}>
                        <select
                            className={inputCls}
                            value={form.data.status}
                            onChange={(e) => form.setData('status', e.target.value)}
                        >
                            <option value="active">{t('employees.active')}</option>
                            <option value="draft">{t('employees.draft')}</option>
                            <option value="suspended">{t('employees.suspended')}</option>
                            <option value="retired">{t('employees.retired')}</option>
                            <option value="terminated">{t('employees.terminated')}</option>
                        </select>
                    </Field>

                    {/* First name */}
                    <Field label={t('employees.firstName')} error={form.errors.first_name}>
                        <input
                            className={inputCls}
                            placeholder={t('employees.firstName')}
                            value={form.data.first_name}
                            onChange={(e) => form.setData('first_name', e.target.value)}
                        />
                    </Field>

                    {/* Middle name */}
                    <Field label={t('employees.middleName')}>
                        <input
                            className={inputCls}
                            placeholder={t('employees.middleName')}
                            value={form.data.middle_name}
                            onChange={(e) => form.setData('middle_name', e.target.value)}
                        />
                    </Field>

                    {/* Last name */}
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

                    {/* Phone */}
                    <Field label={t('employees.phone')}>
                        <input
                            className={inputCls}
                            placeholder="+251 9XX XXX XXX"
                            value={form.data.phone}
                            onChange={(e) => form.setData('phone', e.target.value)}
                        />
                    </Field>

                    {/* Email */}
                    <Field label={t('employees.email')} error={form.errors.email}>
                        <input
                            className={inputCls}
                            type="email"
                            placeholder="employee@example.com"
                            value={form.data.email}
                            onChange={(e) => form.setData('email', e.target.value)}
                        />
                    </Field>

                    {/* Date of birth */}
                    <Field label={t('employees.dateOfBirth')}>
                        <LocalizedDatePicker
                            className={inputCls}
                            value={form.data.date_of_birth}
                            onChange={(iso) => form.setData('date_of_birth', iso)}
                        />
                    </Field>

                    {/* Gender */}
                    <Field label={t('employees.gender')}>
                        <select
                            className={inputCls}
                            value={form.data.gender}
                            onChange={(e) => form.setData('gender', e.target.value)}
                        >
                            <option value="">{t('employees.gender')}</option>
                            <option value="male">{t('employees.male')}</option>
                            <option value="female">{t('employees.female')}</option>
                        </select>
                    </Field>

                    {/* Photo */}
                    <div className="sm:col-span-2">
                        <label className={labelCls}>{t('employees.photo')}</label>
                        <div className="flex items-start gap-4">
                            <div className="flex-shrink-0">
                                {showCurrentPhoto && employee.photo_url ? (
                                    <div className="flex flex-col items-center gap-1">
                                        <img
                                            src={employee.photo_url}
                                            alt="current photo"
                                            className="h-20 w-16 rounded-lg border border-gray-200 object-cover dark:border-slate-700"
                                        />
                                        <button
                                            type="button"
                                            onClick={toggleRemovePhoto}
                                            className="text-xs text-red-500 hover:text-red-700"
                                        >
                                            {t('common.remove')}
                                        </button>
                                    </div>
                                ) : photoPreview ? (
                                    <div className="relative">
                                        <img
                                            src={photoPreview}
                                            alt="new photo preview"
                                            className="h-20 w-16 rounded-lg border border-blue-200 object-cover dark:border-blue-700"
                                        />
                                        <button
                                            type="button"
                                            onClick={clearNewPhoto}
                                            className="absolute -right-2 -top-2 flex h-5 w-5 items-center justify-center rounded-full bg-red-500 text-white text-xs hover:bg-red-600"
                                        >
                                            ×
                                        </button>
                                    </div>
                                ) : (
                                    <div className="flex h-20 w-16 items-center justify-center rounded-lg border-2 border-dashed border-gray-300 text-gray-400 dark:border-slate-600 dark:text-slate-500">
                                        <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                        </svg>
                                    </div>
                                )}
                            </div>

                            <div className="flex-1 space-y-2">
                                {form.data.remove_photo && (
                                    <div className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 dark:border-red-800 dark:bg-red-950">
                                        <span className="text-xs text-red-700 dark:text-red-300">
                                            {t('employees.photoWillBeRemoved')}
                                        </span>
                                        <button
                                            type="button"
                                            onClick={toggleRemovePhoto}
                                            className="ml-auto text-xs text-red-500 hover:text-red-700"
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
                                <p className="text-xs text-gray-400 dark:text-slate-500">
                                    JPG, PNG or WEBP — max 4 MB
                                </p>
                                {form.errors.photo && (
                                    <p className="text-xs text-red-600">{form.errors.photo}</p>
                                )}
                            </div>
                        </div>
                    </div>
                </div>

                <div className="mt-6 flex gap-3 border-t border-gray-100 pt-5 dark:border-slate-800">
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-60"
                    >
                        {form.processing ? t('common.saving') : t('employees.saveEmployee')}
                    </button>
                    <Link
                        href={route('employees.show', employee.id)}
                        className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                    >
                        {t('common.cancel')}
                    </Link>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
