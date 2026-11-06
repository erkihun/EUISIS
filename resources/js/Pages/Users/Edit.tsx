import { useState, type ChangeEvent, type FormEvent, type ReactNode } from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import FormSection from '@/Components/FormSection';
import UserAvatar from '@/Components/UserAvatar';
import OrganizationScopesCard from '@/Components/Users/OrganizationScopesCard';
import PasswordPolicyChecklist from '@/Components/PasswordPolicyChecklist';
import RolePicker, { type PickableRole } from '@/Components/Users/RolePicker';
import { useLocale } from '@/hooks/useLocale';
import { toast } from '@/lib/toast';
import type { PageProps } from '@/types';

import { actionLinkClassName, buttonClassName, cx, inputClassName } from '@/Components/ui';

type OrganizationScope = {
    id: string;
    organization: { id: string; name_en: string; name_am?: string | null; status?: string } | null;
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

const inputCls = cx(inputClassName, 'w-full');

/** A labelled field; the label is tied to the control by `id`. */
function Field({ id, label, hint, error, children, className }: {
    id: string;
    label: string;
    hint?: string;
    error?: string;
    children: ReactNode;
    className?: string;
}) {
    return (
        <div className={className}>
            <label htmlFor={id} className="block text-xs font-medium text-(--app-muted-foreground)">{label}</label>
            <div className="mt-1">{children}</div>
            {hint && !error && <p id={`${id}-hint`} className="mt-1 text-xs text-(--app-muted-foreground)">{hint}</p>}
            {error && <p id={`${id}-error`} className="mt-1 text-xs text-red-600 dark:text-red-400">{error}</p>}
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
    organizationScopeUnavailable,
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
    roles: PickableRole[];
    userRoles: string[];
    organizations?: OrgOption[] | null;
    can?: { assignOrganizationScopes?: boolean };
    organizationScopeUnavailable: boolean;
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
        generate_temporary_password: boolean;
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
        generate_temporary_password: false,
        status: user.status,
        roles: userRoles,
        profile_photo: null,
        national_id: user.national_id ?? '',
        phone_number: user.phone_number ?? '',
        gender: user.gender ?? '',
    });

    function handlePhotoChange(event: ChangeEvent<HTMLInputElement>) {
        const file = event.target.files?.[0];
        if (!file) return;
        form.setData('profile_photo', file);
        const reader = new FileReader();
        reader.onload = (loaded) => setPhotoPreview(loaded.target?.result as string);
        reader.readAsDataURL(file);
    }

    function removePhoto() {
        form.setData('profile_photo', null);
        setPhotoPreview(null);
    }

    /*
     * The picker works with role ids and the form with role names (that is
     * what the server takes). A role the user holds that this administrator
     * may not assign is not in the picker; it stays on the user untouched.
     */
    const selectedRoleIds = roles.filter((role) => form.data.roles.includes(role.name)).map((role) => role.id);
    const pickableNames = roles.map((role) => role.name);

    function toggleRole(id: number) {
        const role = roles.find((candidate) => candidate.id === id);
        if (!role) return;
        const current = form.data.roles;
        form.setData('roles', current.includes(role.name) ? current.filter((name) => name !== role.name) : [...current, role.name]);
    }

    function clearRoles() {
        form.setData('roles', form.data.roles.filter((name) => !pickableNames.includes(name)));
    }

    /*
     * Send the person to the field that failed: the page is four sections
     * tall and the save button is pinned to the bottom, so a refused save
     * could otherwise pass unnoticed.
     */
    function focusFirstError(errors: Record<string, string>) {
        const [firstKey] = Object.keys(errors);
        if (firstKey === undefined) return;

        toast.error(errors[firstKey]);

        // Controls are id'd user-<field>; the roles section carries user-roles.
        const field = document.getElementById(firstKey === 'national_id_hash' ? 'user-national_id' : `user-${firstKey}`);
        field?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        field?.focus({ preventScroll: true });
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        if (form.processing) return;

        // Sent as a POST with _method=PATCH: a photo needs a multipart body,
        // and multipart only travels by POST. roles_submitted tells the server
        // that an absent roles[] means "no roles", which multipart cannot say.
        form.transform((data) => {
            const payload: Record<string, unknown> = {
                _method: 'PATCH',
                name: data.name,
                email: data.email,
                status: data.status,
                roles: data.roles,
                roles_submitted: '1',
                national_id: data.national_id,
                phone_number: data.phone_number,
                gender: data.gender,
            };
            if (!isSelf) {
                if (data.generate_temporary_password) {
                    payload.generate_temporary_password = '1';
                } else {
                    payload.password = data.password;
                    payload.password_confirmation = data.password_confirmation;
                }
            }
            if (data.profile_photo) {
                payload.profile_photo = data.profile_photo;
            }

            return payload;
        });
        form.post(route('users.update', user.id), {
            forceFormData: true,
            preserveScroll: true,
            onError: focusFirstError,
        });
    }

    const currentPhoto = photoPreview ?? user.profile_photo_url;

    return (
        <AuthenticatedLayout contentWidth="form"
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
                    noValidate
                    className="rounded-panel border border-(--app-border) bg-(--app-surface) p-6"
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
                                    <label className={cx(buttonClassName({ variant: 'outline', size: 'xs' }), 'cursor-pointer focus-within:ring-2 focus-within:ring-primary')}>
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
                                            className={actionLinkClassName({ tone: 'danger' })}
                                        >
                                            {t('common.remove')}
                                        </button>
                                    )}
                                </div>
                            </div>
                            {form.errors.profile_photo && (
                                <p className="text-xs text-red-600 dark:text-red-400">{form.errors.profile_photo}</p>
                            )}

                            <Field id="user-name" label={t('users.name')} error={form.errors.name}>
                                <input
                                    id="user-name"
                                    className={inputCls}
                                    required
                                    aria-invalid={!!form.errors.name}
                                    value={form.data.name}
                                    onChange={(event) => form.setData('name', event.target.value)}
                                />
                            </Field>
                        </FormSection>

                        {/* ── Account Access ────────────────────────────── */}
                        <FormSection
                            title={t('users.sectionAccount')}
                            description={t('users.sectionAccountHelp')}
                        >
                            <Field id="user-email" label={t('users.email')} error={form.errors.email}>
                                <input
                                    id="user-email"
                                    type="email"
                                    className={inputCls}
                                    required
                                    aria-invalid={!!form.errors.email}
                                    value={form.data.email}
                                    onChange={(event) => form.setData('email', event.target.value)}
                                />
                            </Field>

                            {/* Your own account stays active: the server refuses the change, so the field says so instead. */}
                            <Field id="user-status" label={t('users.status')} error={form.errors.status} hint={isSelf ? t('users.cannotDeactivateSelf') : undefined}>
                                <select
                                    id="user-status"
                                    className={inputCls}
                                    disabled={isSelf}
                                    aria-invalid={!!form.errors.status}
                                    value={form.data.status}
                                    onChange={(event) => form.setData('status', event.target.value)}
                                >
                                    <option value="active">{t('users.active')}</option>
                                    <option value="inactive">{t('users.inactive')}</option>
                                </select>
                            </Field>

                            {isSelf ? (
                                // Your own password changes only where the current one is confirmed.
                                <p className="rounded-lg border border-dashed border-(--app-border) bg-(--app-surface-muted) px-3 py-2 text-xs text-(--app-muted-foreground) md:col-span-2">
                                    {t('users.ownPasswordInProfile')}{' '}
                                    <Link href={route('profile.edit')} className={actionLinkClassName()}>{t('users.openMyProfile')}</Link>
                                </p>
                            ) : (
                                // Password is optional on edit — leaving it blank keeps the current one.
                                <div className="rounded-lg border border-(--app-border) p-3 md:col-span-2">
                                    <p className="mb-2 text-[10px] font-semibold text-(--app-muted-foreground)">
                                        {t('users.changePasswordNote')}
                                    </p>
                                    <div className="grid gap-4 md:grid-cols-2">
                                        <Field id="user-password" label={t('users.newPassword')} error={form.errors.password}>
                                            <input
                                                id="user-password"
                                                type="password"
                                                className={inputCls}
                                                autoComplete="new-password"
                                                aria-invalid={!!form.errors.password}
                                                disabled={form.data.generate_temporary_password}
                                                placeholder={t('users.newPasswordPlaceholder')}
                                                value={form.data.password}
                                                onChange={(event) => form.setData('password', event.target.value)}
                                            />
                                        </Field>
                                        <Field
                                            id="user-password_confirmation"
                                            label={t('users.confirmNewPassword')}
                                            error={form.errors.password_confirmation}
                                        >
                                            <input
                                                id="user-password_confirmation"
                                                type="password"
                                                className={inputCls}
                                                autoComplete="new-password"
                                                aria-invalid={!!form.errors.password_confirmation}
                                                disabled={form.data.generate_temporary_password}
                                                placeholder={t('users.repeatNewPasswordPlaceholder')}
                                                value={form.data.password_confirmation}
                                                onChange={(event) => form.setData('password_confirmation', event.target.value)}
                                            />
                                        </Field>
                                    </div>
                                    <label className="mt-3 flex items-center gap-2 text-xs text-(--app-foreground)">
                                        <input
                                            type="checkbox"
                                            className="h-4 w-4 rounded-sm border-(--app-border-strong) text-primary focus:ring-primary"
                                            checked={form.data.generate_temporary_password}
                                            onChange={(event) => form.setData((data) => ({ ...data, generate_temporary_password: event.target.checked, password: '', password_confirmation: '' }))}
                                        />
                                        {t('auth.passwordPolicy.generateTemporary')}
                                    </label>
                                    {!form.data.generate_temporary_password && form.data.password !== '' && (
                                        <PasswordPolicyChecklist
                                            className="mt-3"
                                            password={form.data.password}
                                            confirmation={form.data.password_confirmation}
                                            personal={[form.data.name, form.data.email, form.data.phone_number]}
                                        />
                                    )}
                                </div>
                            )}
                        </FormSection>

                        {/* ── Personal Details ──────────────────────────── */}
                        <FormSection
                            title={t('users.sectionPersonal')}
                            description={t('users.sectionPersonalHelp')}
                        >
                            {/* A duplicate is reported against the hash the uniqueness is checked on. */}
                            <Field id="user-national_id" label={t('users.nationalId')} error={form.errors.national_id ?? (form.errors as Record<string, string | undefined>).national_id_hash}>
                                <input
                                    id="user-national_id"
                                    className={cx(inputCls, 'font-mono tracking-wide')}
                                    inputMode="numeric"
                                    placeholder={t('users.nationalIdPlaceholder')}
                                    value={nationalIdDisplay}
                                    maxLength={19}
                                    aria-invalid={!!form.errors.national_id}
                                    onChange={(event) => {
                                        const formatted = formatNationalId(event.target.value);
                                        setNationalIdDisplay(formatted);
                                        form.setData('national_id', formatted.replace(/\s/g, ''));
                                    }}
                                />
                            </Field>

                            <Field id="user-phone_number" label={t('users.phoneNumber')} error={form.errors.phone_number}>
                                <div className="flex">
                                    <span className="inline-flex items-center rounded-l-lg border border-r-0 border-(--app-border-strong) bg-(--app-surface-muted) px-3 text-sm text-(--app-muted-foreground)">
                                        +251
                                    </span>
                                    <input
                                        id="user-phone_number"
                                        type="tel"
                                        inputMode="numeric"
                                        className={cx(inputClassName, 'w-full rounded-l-none')}
                                        placeholder={t('users.phonePlaceholder')}
                                        maxLength={9}
                                        aria-invalid={!!form.errors.phone_number}
                                        value={form.data.phone_number.replace(/^\+251/, '')}
                                        onChange={(event) => {
                                            const digits = event.target.value.replace(/\D/g, '').slice(0, 9);
                                            form.setData('phone_number', digits ? '+251' + digits : '');
                                        }}
                                    />
                                </div>
                            </Field>

                            <Field id="user-gender" label={t('users.gender')} error={form.errors.gender}>
                                <select
                                    id="user-gender"
                                    className={inputCls}
                                    aria-invalid={!!form.errors.gender}
                                    value={form.data.gender}
                                    onChange={(event) => form.setData('gender', event.target.value)}
                                >
                                    {genderOptions.map((option) => (
                                        <option key={option.value} value={option.value}>
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                        </FormSection>

                        {/* ── Roles ─────────────────────────────────────── */}
                        <FormSection
                            id="user-roles"
                            title={t('users.sectionRoles')}
                            description={t('users.sectionRolesHelp')}
                            grid={false}
                        >
                            <RolePicker
                                roles={roles}
                                selected={selectedRoleIds}
                                onToggle={toggleRole}
                                onClear={clearRoles}
                                error={form.errors.roles}
                            />
                        </FormSection>
                    </div>

                    {/* Sticky action bar */}
                    <div className="sticky bottom-0 -mx-6 -mb-6 mt-8 flex items-center justify-end gap-3 border-t border-(--app-border) bg-[color-mix(in_srgb,var(--app-surface)_95%,transparent)] px-6 py-4 backdrop-blur-sm">
                        <Link href={route('users.index')} className={buttonClassName({ variant: 'ghost' })}>
                            {t('common.cancel')}
                        </Link>
                        <button
                            type="submit"
                            disabled={form.processing}
                            className={buttonClassName({ variant: 'primary' })}
                        >
                            {form.processing ? t('common.saving') : t('users.saveChanges')}
                        </button>
                    </div>
                </form>

                {organizationScopeUnavailable ? (
                    <aside className="rounded-panel border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/20 dark:text-amber-200">
                        {t('users.userOrganizationScopes.unavailableForUnrestrictedRole')}
                    </aside>
                ) : organizations && (
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
