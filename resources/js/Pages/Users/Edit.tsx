import { useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import FormSection from '@/Components/FormSection';
import UserAvatar from '@/Components/UserAvatar';
import OrganizationScopesCard from '@/Components/Users/OrganizationScopesCard';
import { useLocale } from '@/hooks/useLocale';
import type { PageProps } from '@/types';

type Role = { id: number; name: string };

type OrganizationScope = {
    id: string;
    organization: { id: string; name_en: string; name_am?: string } | null;
    scope_type: 'self' | 'subtree' | 'citywide' | 'service_provider';
    effective_from: string | null;
    effective_to: string | null;
    is_active: boolean;
};

type OrgOption = {
    id: string;
    code?: string | null;
    name_en: string;
    name_am?: string | null;
    status?: string;
    type?: { name_en: string; name_am?: string | null } | null;
};

const inputCls =
    'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder-slate-500';
const labelCls = 'block text-xs font-medium text-gray-600 dark:text-slate-400';

function Field({
    label,
    error,
    children,
    className,
}: {
    label: string;
    error?: string;
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <div className={className}>
            <label className={labelCls}>{label}</label>
            <div className="mt-1">{children}</div>
            {error && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{error}</p>}
        </div>
    );
}

function formatNationalId(raw: string): string {
    const digits = raw.replace(/\D/g, '').slice(0, 16);
    return digits.replace(/(.{4})/g, '$1 ').trimEnd();
}

export default function EditUser({
    user,
    roles,
    userRoles,
    organizations,
    can,
}: {
    user: {
        id: number;
        name: string;
        email: string;
        status: string;
        national_id?: string | null;
        phone_number?: string | null;
        gender?: string | null;
        profile_photo_url?: string | null;
        organization_scopes?: OrganizationScope[];
    };
    roles: Role[];
    userRoles: string[];
    organizations?: OrgOption[];
    can?: { assignOrganizationScopes?: boolean };
}) {
    const { t } = useLocale();
    const { auth } = usePage<PageProps>().props;
    const isSelf = auth.user?.id === user.id;

    const [photoPreview, setPhotoPreview] = useState<string | null>(null);
    const [nationalIdDisplay, setNationalIdDisplay] = useState(
        formatNationalId(user.national_id ?? ''),
    );

    const genderOptions = [
        { value: '', label: t('users.selectGender') },
        { value: 'male', label: t('users.genderMale') },
        { value: 'female', label: t('users.genderFemale') },
        { value: 'other', label: t('users.genderOther') },
        { value: 'not_specified', label: t('users.genderNotSpecified') },
    ];

    const form = useForm<{
        name: string;
        email: string;
        password: string;
        password_confirmation: string;
        status: string;
        roles: string[];
        profile_photo: File | null;
        national_id: string;
        phone_number: string;
        gender: string;
    }>({
        name: user.name,
        email: user.email,
        password: '',
        password_confirmation: '',
        status: user.status,
        roles: userRoles,
        profile_photo: null,
        national_id: user.national_id ?? '',
        phone_number: user.phone_number ?? '',
        gender: user.gender ?? '',
    });

    function handlePhotoChange(e: React.ChangeEvent<HTMLInputElement>) {
        const file = e.target.files?.[0];
        if (!file) return;
        form.setData('profile_photo', file);
        const reader = new FileReader();
        reader.onload = (ev) => setPhotoPreview(ev.target?.result as string);
        reader.readAsDataURL(file);
    }

    function removePhoto() {
        form.setData('profile_photo', null);
        setPhotoPreview(null);
    }

    function toggleRole(name: string) {
        const current = form.data.roles;
        form.setData(
            'roles',
            current.includes(name) ? current.filter((r) => r !== name) : [...current, name],
        );
    }

    function submit(e: React.FormEvent) {
        e.preventDefault();
        // Use router.post with _method=PATCH so file uploads work (multipart requires POST)
        const fd = new FormData();
        fd.append('_method', 'PATCH');
        fd.append('name', form.data.name);
        fd.append('email', form.data.email);
        fd.append('password', form.data.password);
        fd.append('password_confirmation', form.data.password_confirmation);
        fd.append('status', form.data.status);
        form.data.roles.forEach((r) => fd.append('roles[]', r));
        fd.append('national_id', form.data.national_id);
        fd.append('phone_number', form.data.phone_number);
        fd.append('gender', form.data.gender);
        if (form.data.profile_photo) {
            fd.append('profile_photo', form.data.profile_photo);
        }
        router.post(route('users.update', user.id), fd, { preserveScroll: true });
    }

    const currentPhoto = photoPreview ?? user.profile_photo_url;

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    backHref={route('users.index')}
                    title={t('users.editTitle')}
                    description={`${t('users.editPrefix')} ${user.name}`}
                />
            }
        >
            <Head title={t('users.editTitle')} />

            {/* Two columns: profile form on the left, organization scopes on the right. */}
            <div className="grid w-full items-start gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                <form
                    id="user-edit-form"
                    onSubmit={submit}
                    className="rounded-2xl border border-gray-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900"
                >
                    <div className="space-y-6">
                        {/* ── Profile ───────────────────────────────────── */}
                        <FormSection
                            title={t('users.sectionProfile')}
                            description={t('users.sectionProfileHelp')}
                            grid={false}
                        >
                            <div className="flex items-center gap-4">
                                <UserAvatar src={currentPhoto} name={form.data.name || user.name} size={56} />
                                <div className="flex flex-col gap-1.5">
                                    <label className="cursor-pointer rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800">
                                        {user.profile_photo_url ? t('users.changePhoto') : t('users.uploadPhoto')}
                                        <input
                                            type="file"
                                            accept="image/jpg,image/jpeg,image/png,image/webp"
                                            className="sr-only"
                                            onChange={handlePhotoChange}
                                        />
                                    </label>
                                    {photoPreview && (
                                        <button
                                            type="button"
                                            onClick={removePhoto}
                                            className="text-left text-xs text-red-500 hover:text-red-700 dark:text-red-400"
                                        >
                                            {t('common.remove')}
                                        </button>
                                    )}
                                </div>
                            </div>
                            {form.errors.profile_photo && (
                                <p className="text-xs text-red-600 dark:text-red-400">{form.errors.profile_photo}</p>
                            )}

                            <Field label={t('users.name')} error={form.errors.name}>
                                <input
                                    className={inputCls}
                                    value={form.data.name}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                />
                            </Field>
                        </FormSection>

                        {/* ── Account Access ────────────────────────────── */}
                        <FormSection
                            title={t('users.sectionAccount')}
                            description={t('users.sectionAccountHelp')}
                        >
                            <Field label={t('users.email')} error={form.errors.email}>
                                <input
                                    type="email"
                                    className={inputCls}
                                    value={form.data.email}
                                    onChange={(e) => form.setData('email', e.target.value)}
                                />
                            </Field>

                            <Field label={t('users.status')} error={form.errors.status}>
                                <select
                                    className={inputCls}
                                    value={form.data.status}
                                    onChange={(e) => form.setData('status', e.target.value)}
                                >
                                    <option value="active">{t('users.active')}</option>
                                    <option value="inactive">{t('users.inactive')}</option>
                                </select>
                            </Field>

                            {/* Password is optional on edit — leaving it blank keeps the current one. */}
                            <div className="rounded-lg border border-gray-100 p-3 md:col-span-2 dark:border-slate-800">
                                <p className="mb-2 text-[10px] font-semibold uppercase tracking-wider text-gray-400 dark:text-slate-500">
                                    {t('users.changePasswordNote')}
                                </p>
                                <div className="grid gap-4 md:grid-cols-2">
                                    <Field label={t('users.newPassword')} error={form.errors.password}>
                                        <input
                                            type="password"
                                            className={inputCls}
                                            placeholder={t('users.newPasswordPlaceholder')}
                                            value={form.data.password}
                                            onChange={(e) => form.setData('password', e.target.value)}
                                        />
                                    </Field>
                                    <Field
                                        label={t('users.confirmNewPassword')}
                                        error={form.errors.password_confirmation}
                                    >
                                        <input
                                            type="password"
                                            className={inputCls}
                                            placeholder={t('users.repeatNewPasswordPlaceholder')}
                                            value={form.data.password_confirmation}
                                            onChange={(e) => form.setData('password_confirmation', e.target.value)}
                                        />
                                    </Field>
                                </div>
                            </div>
                        </FormSection>

                        {/* ── Personal Details ──────────────────────────── */}
                        <FormSection
                            title={t('users.sectionPersonal')}
                            description={t('users.sectionPersonalHelp')}
                        >
                            <Field label={t('users.nationalId')} error={form.errors.national_id}>
                                <input
                                    className={inputCls}
                                    placeholder={t('users.nationalIdPlaceholder')}
                                    value={nationalIdDisplay}
                                    maxLength={19}
                                    onChange={(e) => {
                                        const formatted = formatNationalId(e.target.value);
                                        setNationalIdDisplay(formatted);
                                        form.setData('national_id', formatted.replace(/\s/g, ''));
                                    }}
                                />
                            </Field>

                            <Field label={t('users.phoneNumber')} error={form.errors.phone_number}>
                                <div className="flex">
                                    <span className="inline-flex items-center rounded-l-lg border border-r-0 border-gray-300 bg-gray-50 px-3 text-sm text-gray-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-400">
                                        +251
                                    </span>
                                    <input
                                        className="w-full rounded-r-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder-slate-500"
                                        placeholder={t('users.phonePlaceholder')}
                                        maxLength={9}
                                        value={form.data.phone_number.replace(/^\+251/, '')}
                                        onChange={(e) => {
                                            const digits = e.target.value.replace(/\D/g, '').slice(0, 9);
                                            form.setData('phone_number', digits ? '+251' + digits : '');
                                        }}
                                    />
                                </div>
                            </Field>

                            <Field label={t('users.gender')} error={form.errors.gender}>
                                <select
                                    className={inputCls}
                                    value={form.data.gender}
                                    onChange={(e) => form.setData('gender', e.target.value)}
                                >
                                    {genderOptions.map((opt) => (
                                        <option key={opt.value} value={opt.value}>
                                            {opt.label}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                        </FormSection>

                        {/* ── Roles ─────────────────────────────────────── */}
                        <FormSection
                            title={t('users.sectionRoles')}
                            description={t('users.sectionRolesHelp')}
                            grid={false}
                        >
                            {roles.length === 0 ? (
                                <p className="rounded-lg border border-dashed border-gray-200 px-3 py-4 text-center text-sm text-gray-500 dark:border-slate-700 dark:text-slate-400">
                                    {t('users.noRoles')}
                                </p>
                            ) : (
                                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                    {roles.map((role) => (
                                        <label
                                            key={role.id}
                                            className="flex cursor-pointer items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 transition-colors hover:bg-gray-50 dark:border-slate-700 dark:hover:bg-slate-800"
                                        >
                                            <input
                                                type="checkbox"
                                                className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-slate-600"
                                                checked={form.data.roles.includes(role.name)}
                                                onChange={() => toggleRole(role.name)}
                                            />
                                            <span className="text-sm text-gray-700 dark:text-slate-300">
                                                {role.name}
                                            </span>
                                        </label>
                                    ))}
                                </div>
                            )}
                            {form.errors.roles && (
                                <p className="text-xs text-red-600 dark:text-red-400">{form.errors.roles}</p>
                            )}
                        </FormSection>
                    </div>

                    {/* Sticky action bar */}
                    <div className="sticky bottom-0 -mx-6 -mb-6 mt-8 flex items-center justify-end gap-3 border-t border-gray-100 bg-white/95 px-6 py-4 backdrop-blur dark:border-slate-800 dark:bg-slate-900/95">
                        {/* Self-edit guard: hide deactivate for own account */}
                        {isSelf && (
                            <p className="mr-auto text-xs text-amber-600 dark:text-amber-400">
                                {t('users.cannotDeleteSelf')}
                            </p>
                        )}
                        <Link
                            href={route('users.index')}
                            className="rounded-lg px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-slate-300 dark:hover:bg-slate-800"
                        >
                            {t('common.cancel')}
                        </Link>
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:opacity-60 dark:focus:ring-offset-slate-900"
                        >
                            {form.processing ? t('common.saving') : t('users.saveChanges')}
                        </button>
                    </div>
                </form>

                {organizations && (
                    <OrganizationScopesCard
                        userId={String(user.id)}
                        scopes={user.organization_scopes ?? []}
                        organizations={organizations}
                        canManage={can?.assignOrganizationScopes ?? false}
                    />
                )}
            </div>
        </AuthenticatedLayout>
    );
}
