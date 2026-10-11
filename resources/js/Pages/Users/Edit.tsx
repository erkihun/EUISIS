import { useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import FormSection from '@/Components/FormSection';
import UserAvatar from '@/Components/UserAvatar';
import OrganizationScopesCard from '@/Components/Users/OrganizationScopesCard';
import PasswordPolicyChecklist from '@/Components/PasswordPolicyChecklist';
import { useLocale } from '@/hooks/useLocale';
import type { PageProps } from '@/types';

import { badgeClassName, buttonClassName, cx, inputClassName } from '@/Components/ui';
type Role = { id: number; name: string; scope: 'organization' | 'global' };

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

const inputCls = cx(inputClassName, 'w-full');
const labelCls = 'block text-xs font-medium text-[color:var(--app-muted-foreground)]';

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
    roles: Role[];
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
        if (form.data.generate_temporary_password) {
            fd.append('generate_temporary_password', '1');
        } else {
            fd.append('password', form.data.password);
            fd.append('password_confirmation', form.data.password_confirmation);
        }
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
                    className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-6"
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
                                    <label className="cursor-pointer rounded-lg border border-[color:var(--app-border-strong)] bg-[color:var(--app-surface)] px-3 py-1.5 text-xs font-medium text-[color:var(--app-foreground)] hover:bg-[color:var(--app-surface-muted)]">
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
                            <div className="rounded-lg border border-[color:var(--app-border)] p-3 md:col-span-2">
                                <p className="mb-2 text-[10px] font-semibold text-[color:var(--app-muted-foreground)]">
                                    {t('users.changePasswordNote')}
                                </p>
                                <div className="grid gap-4 md:grid-cols-2">
                                    <Field label={t('users.newPassword')} error={form.errors.password}>
                                        <input
                                            type="password"
                                            className={inputCls}
                                            autoComplete="new-password"
                                            disabled={form.data.generate_temporary_password}
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
                                            autoComplete="new-password"
                                            disabled={form.data.generate_temporary_password}
                                            placeholder={t('users.repeatNewPasswordPlaceholder')}
                                            value={form.data.password_confirmation}
                                            onChange={(e) => form.setData('password_confirmation', e.target.value)}
                                        />
                                    </Field>
                                </div>
                                <label className="mt-3 flex items-center gap-2 text-xs text-[color:var(--app-foreground)]">
                                    <input
                                        type="checkbox"
                                        checked={form.data.generate_temporary_password}
                                        onChange={(e) => form.setData((data) => ({ ...data, generate_temporary_password: e.target.checked, password: '', password_confirmation: '' }))}
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
                                    <span className="inline-flex items-center rounded-l-lg border border-r-0 border-[color:var(--app-border-strong)] bg-[color:var(--app-surface-muted)] px-3 text-sm text-[color:var(--app-muted-foreground)]">
                                        +251
                                    </span>
                                    <input
                                        className={cx(inputClassName, 'w-full')}
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
                                <p className="rounded-lg border border-dashed border-[color:var(--app-border)] px-3 py-4 text-center text-sm text-[color:var(--app-muted-foreground)]">
                                    {t('users.noRoles')}
                                </p>
                            ) : (
                                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                    {roles.map((role) => (
                                        <label
                                            key={role.id}
                                            className="flex cursor-pointer items-center gap-2 rounded-lg border border-[color:var(--app-border)] px-3 py-2 transition-colors hover:bg-[color:var(--app-surface-muted)]"
                                        >
                                            <input
                                                type="checkbox"
                                                className="h-4 w-4 rounded border-[color:var(--app-border-strong)] text-[color:var(--color-primary)] focus:ring-[color:var(--color-primary)]"
                                                checked={form.data.roles.includes(role.name)}
                                                onChange={() => toggleRole(role.name)}
                                            />
                                              <span className="min-w-0 flex-1 text-sm text-[color:var(--app-foreground)]">
                                                 {role.name}
                                              </span>
                                              <span className={badgeClassName('info')}>
                                                  {role.scope === 'organization' ? t('users.scopedRole') : t('users.globalRole')}
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
                    <div className="sticky bottom-0 -mx-6 -mb-6 mt-8 flex items-center justify-end gap-3 border-t border-[color:var(--app-border)] bg-white/95 px-6 py-4 backdrop-blur dark:bg-slate-900/95">
                        {/* Self-edit guard: hide deactivate for own account */}
                        {isSelf && (
                            <p className="mr-auto text-xs text-amber-600 dark:text-amber-400">
                                {t('users.cannotDeleteSelf')}
                            </p>
                        )}
                        <Link
                            href={route('users.index')}
                            className="rounded-lg px-4 py-2 text-sm font-medium text-[color:var(--app-foreground)] hover:bg-[color:var(--app-surface-muted)]"
                        >
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
