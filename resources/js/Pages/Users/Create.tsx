import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import FormSection from '@/Components/FormSection';
import UserAvatar from '@/Components/UserAvatar';
import { useLocale } from '@/hooks/useLocale';

type Role = { id: number; name: string; scope: 'organization' | 'global' };

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

type OrgOption = { id: string; name_en: string; name_am?: string | null };

export default function CreateUser({
    roles,
    statusOptions,
    organizations = [],
    requiresOrganizationScope,
}: {
    roles: Role[];
    statusOptions: string[];
    organizations?: OrgOption[];
    requiresOrganizationScope: boolean;
}) {
    const { t } = useLocale();
    const [photoPreview, setPhotoPreview] = useState<string | null>(null);
    const [nationalIdDisplay, setNationalIdDisplay] = useState('');

    const statusLabels: Record<string, string> = {
        active: t('users.active'),
        inactive: t('users.inactive'),
    };

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
        role_ids: number[];
        profile_photo: File | null;
        national_id: string;
        phone_number: string;
        gender: string;
        organization_scope_ids: string[];
        scope_type: string;
    }>({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        status: 'active',
        role_ids: [],
        profile_photo: null,
        national_id: '',
        phone_number: '',
        gender: '',
        organization_scope_ids: organizations.length === 1 && requiresOrganizationScope ? [organizations[0].id] : [],
        scope_type: 'self',
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

    function toggleRole(id: number) {
        const current = form.data.role_ids;
        const nextRoleIds = current.includes(id) ? current.filter((roleId) => roleId !== id) : [...current, id];
        form.setData('role_ids', nextRoleIds);

        const nextRequiresScope = roles.some(
            (role) => nextRoleIds.includes(role.id) && role.scope === 'organization',
        );
        if (nextRequiresScope && form.data.organization_scope_ids.length === 0 && organizations.length === 1) {
            form.setData('organization_scope_ids', [organizations[0].id]);
        }
    }

    function submit(e: React.FormEvent) {
        e.preventDefault();
        form.post(route('users.store'), { forceFormData: true });
    }

    const selectedRoleRequiresScope = roles.some(
        (role) => form.data.role_ids.includes(role.id) && role.scope === 'organization',
    );
    const organizationScopeRequired = requiresOrganizationScope || selectedRoleRequiresScope;

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    backHref={route('users.index')}
                    title={t('users.createTitle')}
                    description={t('users.createDescription')}
                />
            }
        >
            <Head title={t('users.createTitle')} />

            <div className="w-full">
                <form
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
                                <UserAvatar src={photoPreview} name={form.data.name || 'U'} size={56} />
                                <div className="flex flex-col gap-1.5">
                                    <label className="cursor-pointer rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800">
                                        {t('users.uploadPhoto')}
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
                                    placeholder={t('users.fullNamePlaceholder')}
                                    value={form.data.name}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                />
                            </Field>
                        </FormSection>

                        {/* ── Account Access ────────────────────────────── */}
                        <FormSection
                            title={t('users.sectionAccount')}
                            description={t('users.sectionAccountHelp')}
                            columns={3}
                        >
                            <Field label={t('users.email')} error={form.errors.email}>
                                <input
                                    type="email"
                                    className={inputCls}
                                    placeholder={t('users.emailPlaceholder')}
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
                                    {statusOptions.map((s) => (
                                        <option key={s} value={s}>
                                            {statusLabels[s] ?? (s.charAt(0).toUpperCase() + s.slice(1))}
                                        </option>
                                    ))}
                                </select>
                            </Field>

                            <Field label={t('users.password')} error={form.errors.password}>
                                <input
                                    type="password"
                                    className={inputCls}
                                    placeholder={t('users.passwordHint')}
                                    value={form.data.password}
                                    onChange={(e) => form.setData('password', e.target.value)}
                                />
                            </Field>

                            <Field label={t('users.confirmPassword')} error={form.errors.password_confirmation}>
                                <input
                                    type="password"
                                    className={inputCls}
                                    placeholder={t('users.repeatPassword')}
                                    value={form.data.password_confirmation}
                                    onChange={(e) => form.setData('password_confirmation', e.target.value)}
                                />
                            </Field>
                        </FormSection>

                        {/* ── Personal Details ──────────────────────────── */}
                        <FormSection
                            title={t('users.sectionPersonal')}
                            description={t('users.sectionPersonalHelp')}
                            columns={3}
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

                        <FormSection
                            title={t('users.userOrganizationScopes.title')}
                            description={organizationScopeRequired
                                ? t('users.organizationScopeRequired')
                                : t('users.userOrganizationScopes.selectOrganizations')}
                            columns={2}
                        >
                            <Field label={t('users.userOrganizationScopes.organization')} error={form.errors.organization_scope_ids}>
                                <select
                                    className={inputCls}
                                    value={form.data.organization_scope_ids[0] ?? ''}
                                    onChange={(e) => form.setData('organization_scope_ids', e.target.value ? [e.target.value] : [])}
                                >
                                    <option value="">{t('users.userOrganizationScopes.noOrganizations')}</option>
                                    {organizations.map((organization) => (
                                        <option key={organization.id} value={organization.id}>
                                            {organization.name_en}
                                        </option>
                                    ))}
                                </select>
                            </Field>

                            <Field label={t('users.userOrganizationScopes.scopeType')} error={form.errors.scope_type}>
                                <select
                                    className={inputCls}
                                    value={form.data.scope_type}
                                    onChange={(e) => form.setData('scope_type', e.target.value)}
                                >
                                    <option value="self">{t('users.userOrganizationScopes.scopeTypes.self')}</option>
                                    <option value="subtree">{t('users.userOrganizationScopes.scopeTypes.subtree')}</option>
                                </select>
                            </Field>
                        </FormSection>

                        {/* ── Roles ─────────────────────────────────────── */}
                        <FormSection
                            title={t('users.selectRole')}
                            description={organizationScopeRequired
                                ? t('users.roleAppliesWithinScope')
                                : t('users.sectionRolesHelp')}
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
                                            className="flex cursor-pointer items-center justify-between gap-2 rounded-lg border border-gray-200 px-3 py-2 transition-colors hover:bg-gray-50 dark:border-slate-700 dark:hover:bg-slate-800"
                                        >
                                            <input
                                                type="checkbox"
                                                className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-slate-600"
                                                checked={form.data.role_ids.includes(role.id)}
                                                onChange={() => toggleRole(role.id)}
                                            />
                                            <span className="min-w-0 flex-1 text-sm text-gray-700 dark:text-slate-300">
                                                {role.name}
                                            </span>
                                            <span className="rounded-full bg-blue-50 px-2 py-0.5 text-[10px] font-medium text-blue-700 dark:bg-blue-950/50 dark:text-blue-300">
                                                {role.scope === 'organization' ? t('users.scopedRole') : t('users.globalRole')}
                                            </span>
                                        </label>
                                    ))}
                                </div>
                            )}
                            {form.errors.role_ids && (
                                <p className="text-xs text-red-600 dark:text-red-400">{form.errors.role_ids}</p>
                            )}
                        </FormSection>
                    </div>

                    {/* Sticky action bar */}
                    <div className="sticky bottom-0 -mx-6 -mb-6 mt-8 flex items-center justify-end gap-3 border-t border-gray-100 bg-white/95 px-6 py-4 backdrop-blur dark:border-slate-800 dark:bg-slate-900/95">
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
                            {form.processing ? t('common.saving') : t('users.createAction')}
                        </button>
                    </div>
                </form>

            </div>
        </AuthenticatedLayout>
    );
}
