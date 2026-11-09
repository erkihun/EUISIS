import { useState, type ChangeEvent, type FormEvent, type ReactNode } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import FormSection from '@/Components/FormSection';
import UserAvatar from '@/Components/UserAvatar';
import PasswordPolicyChecklist from '@/Components/PasswordPolicyChecklist';
import RolePicker, { type PickableRole } from '@/Components/Users/RolePicker';
import OrganizationPicker, { organizationName, type PickableOrganization } from '@/Components/Users/OrganizationPicker';
import { useLocale } from '@/hooks/useLocale';
import { actionLinkClassName, badgeClassName, buttonClassName, cx, inputClassName } from '@/Components/ui';

const inputCls = cx(inputClassName, 'w-full');

/** A labelled field; the label is tied to the control by `id`. */
function Field({ id, label, hint, error, aside, children, className }: {
    id: string;
    label: string;
    hint?: string;
    error?: string;
    aside?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    return (
        <div className={className}>
            <div className="flex items-baseline justify-between gap-2">
                <label htmlFor={id} className="block text-xs font-medium text-(--app-muted-foreground)">{label}</label>
                {aside}
            </div>
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

/**
 * New user: who they are, how they sign in, their roles and the organizations
 * those roles apply in. A summary beside the form (below it on small screens)
 * shows what will be created, and the roles picked can be removed from there.
 */
export default function CreateUser({
    roles,
    statusOptions,
    organizations = [],
    requiresOrganizationScope,
}: {
    roles: PickableRole[];
    statusOptions: string[];
    organizations?: PickableOrganization[];
    requiresOrganizationScope: boolean;
}) {
    const { t, locale } = useLocale();
    const c = (key: string) => t(`users.createForm.${key}`);
    const [photoPreview, setPhotoPreview] = useState<string | null>(null);
    const [nationalIdDisplay, setNationalIdDisplay] = useState('');
    const [showPassword, setShowPassword] = useState(false);

    const statusLabels: Record<string, string> = { active: t('users.active'), inactive: t('users.inactive') };
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

    function toggleRole(id: number) {
        const current = form.data.role_ids;
        const nextRoleIds = current.includes(id) ? current.filter((roleId) => roleId !== id) : [...current, id];
        form.setData('role_ids', nextRoleIds);

        // With one organization to choose from, a scoped role picks it.
        const nextRequiresScope = roles.some((role) => nextRoleIds.includes(role.id) && role.scope === 'organization');
        if (nextRequiresScope && form.data.organization_scope_ids.length === 0 && organizations.length === 1) {
            form.setData('organization_scope_ids', [organizations[0].id]);
        }
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(route('users.store'), { forceFormData: true });
    }

    const selectedRoles = roles.filter((role) => form.data.role_ids.includes(role.id));
    const organizationScopeRequired = requiresOrganizationScope || selectedRoles.some((role) => role.scope === 'organization');
    const selectedOrganizations = organizations.filter((organization) => form.data.organization_scope_ids.includes(organization.id));
    const scopeMissing = organizationScopeRequired && selectedOrganizations.length === 0;
    const passwordType = showPassword ? 'text' : 'password';

    const actions = (
        <>
            <Link href={route('users.index')} className={buttonClassName({ variant: 'ghost' })}>{t('common.cancel')}</Link>
            <button type="submit" disabled={form.processing} className={buttonClassName({ variant: 'primary' })}>
                {form.processing ? t('common.saving') : t('users.createAction')}
            </button>
        </>
    );

    return (
        <AuthenticatedLayout contentWidth="form"
            header={<PageHeader backHref={route('users.index')} title={t('users.createTitle')} description={t('users.createDescription')} />}
        >
            <Head title={t('users.createTitle')} />

            <form onSubmit={submit} noValidate className="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_20rem]">
                <div className="space-y-6 rounded-panel border border-(--app-border) bg-(--app-surface) p-6">
                    {/* 1. Who the user is */}
                    <FormSection step={1} title={t('users.sectionProfile')} description={t('users.sectionPersonalHelp')} grid={false}>
                        <div className="flex flex-col gap-5 sm:flex-row sm:items-start">
                            <div className="flex shrink-0 items-center gap-3 sm:flex-col sm:items-center">
                                <UserAvatar src={photoPreview} name={form.data.name || 'U'} size={72} />
                                <div className="flex flex-col items-start gap-1 sm:items-center">
                                    <label className={cx(buttonClassName({ variant: 'outline', size: 'xs' }), 'cursor-pointer focus-within:ring-2 focus-within:ring-primary')}>
                                        {t('users.uploadPhoto')}
                                        <input type="file" accept="image/jpg,image/jpeg,image/png,image/webp" className="sr-only" onChange={handlePhotoChange} />
                                    </label>
                                    {photoPreview
                                        ? <button type="button" onClick={removePhoto} className={actionLinkClassName({ tone: 'danger' })}>{t('common.remove')}</button>
                                        : <span className="text-[11px] text-(--app-muted-foreground)">{c('photoHelp')}</span>}
                                </div>
                            </div>
                            <div className="grid min-w-0 flex-1 gap-4 md:grid-cols-2">
                                <Field id="user-name" label={t('users.name')} error={form.errors.name} className="md:col-span-2">
                                    <input id="user-name" className={inputCls} autoComplete="off" required aria-invalid={!!form.errors.name} placeholder={t('users.fullNamePlaceholder')}
                                        value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} />
                                </Field>
                                <Field id="user-national-id" label={t('users.nationalId')} error={form.errors.national_id}>
                                    <input id="user-national-id" className={cx(inputCls, 'font-mono tracking-wide')} inputMode="numeric" placeholder={t('users.nationalIdPlaceholder')}
                                        value={nationalIdDisplay} maxLength={19} aria-invalid={!!form.errors.national_id}
                                        onChange={(event) => {
                                            const formatted = formatNationalId(event.target.value);
                                            setNationalIdDisplay(formatted);
                                            form.setData('national_id', formatted.replace(/\s/g, ''));
                                        }} />
                                </Field>
                                <Field id="user-gender" label={t('users.gender')} error={form.errors.gender}>
                                    <select id="user-gender" className={inputCls} value={form.data.gender} onChange={(event) => form.setData('gender', event.target.value)}>
                                        {genderOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                                    </select>
                                </Field>
                                <Field id="user-phone" label={t('users.phoneNumber')} error={form.errors.phone_number} className="md:col-span-2">
                                    <div className="flex">
                                        <span className="inline-flex items-center rounded-l-lg border border-r-0 border-(--app-border-strong) bg-(--app-surface-muted) px-3 text-sm text-(--app-muted-foreground)">+251</span>
                                        <input id="user-phone" type="tel" inputMode="numeric" className={cx(inputClassName, 'w-full rounded-l-none')} placeholder={t('users.phonePlaceholder')} maxLength={9}
                                            value={form.data.phone_number.replace(/^\+251/, '')} aria-invalid={!!form.errors.phone_number}
                                            onChange={(event) => {
                                                const digits = event.target.value.replace(/\D/g, '').slice(0, 9);
                                                form.setData('phone_number', digits ? '+251' + digits : '');
                                            }} />
                                    </div>
                                </Field>
                            </div>
                        </div>
                        {form.errors.profile_photo && <p className="text-xs text-red-600 dark:text-red-400">{form.errors.profile_photo}</p>}
                    </FormSection>

                    {/* 2. How they sign in */}
                    <FormSection step={2} title={t('users.sectionAccount')} description={t('users.sectionAccountHelp')}>
                        <Field id="user-email" label={t('users.email')} error={form.errors.email}>
                            <input id="user-email" type="email" className={inputCls} autoComplete="off" required aria-invalid={!!form.errors.email} placeholder={t('users.emailPlaceholder')}
                                value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} />
                        </Field>
                        <Field id="user-status" label={t('users.status')} error={form.errors.status}>
                            <select id="user-status" className={inputCls} value={form.data.status} onChange={(event) => form.setData('status', event.target.value)}>
                                {statusOptions.map((status) => <option key={status} value={status}>{statusLabels[status] ?? status}</option>)}
                            </select>
                        </Field>
                        <Field id="user-password" label={t('users.password')} error={form.errors.password}
                            aside={<button type="button" onClick={() => setShowPassword((value) => !value)} className="text-xs font-medium text-primary hover:underline">{showPassword ? c('hidePassword') : c('showPassword')}</button>}>
                            <input id="user-password" type={passwordType} className={inputCls} autoComplete="new-password" aria-describedby="user-password-help" aria-invalid={!!form.errors.password}
                                value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} />
                        </Field>
                        <Field id="user-password-confirmation" label={t('users.confirmPassword')} error={form.errors.password_confirmation}>
                            <input id="user-password-confirmation" type={passwordType} className={inputCls} autoComplete="new-password" placeholder={t('users.repeatPassword')} aria-invalid={!!form.errors.password_confirmation}
                                value={form.data.password_confirmation} onChange={(event) => form.setData('password_confirmation', event.target.value)} />
                        </Field>
                        <div className="md:col-span-2">
                            {form.data.password !== '' ? (
                                <PasswordPolicyChecklist password={form.data.password} confirmation={form.data.password_confirmation}
                                    personal={[form.data.name, form.data.email, form.data.phone_number]} />
                            ) : (
                                <p id="user-password-help" className="rounded-control border border-dashed border-(--app-border) bg-(--app-surface-muted) px-3 py-2 text-xs text-(--app-muted-foreground)">{c('passwordHelp')}</p>
                            )}
                        </div>
                    </FormSection>

                    {/* 3. What they may do */}
                    <FormSection step={3} id="user-roles" title={t('users.sectionRoles')} description={organizationScopeRequired ? t('users.roleAppliesWithinScope') : t('users.sectionRolesHelp')} grid={false}>
                        <RolePicker roles={roles} selected={form.data.role_ids} onToggle={toggleRole} onClear={() => form.setData('role_ids', [])} error={form.errors.role_ids} />
                    </FormSection>

                    {/* 4. Where the roles apply */}
                    <FormSection step={4} id="user-scope" title={t('users.userOrganizationScopes.title')}
                        description={t('users.userOrganizationScopes.selectOrganizations')}
                        aside={<span className={badgeClassName(organizationScopeRequired ? 'warning' : 'neutral')}>{organizationScopeRequired ? c('required') : c('optional')}</span>}
                        grid={false}>
                        <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_16rem]">
                            <OrganizationPicker organizations={organizations} selected={form.data.organization_scope_ids}
                                onChange={(ids) => form.setData('organization_scope_ids', ids)} error={form.errors.organization_scope_ids} invalid={!!form.errors.organization_scope_ids} />
                            <fieldset>
                                <legend className="block text-xs font-medium text-(--app-muted-foreground)">{t('users.userOrganizationScopes.scopeType')}</legend>
                                <div className="mt-1 space-y-2">
                                    {(['self', 'subtree'] as const).map((type) => (
                                        <label key={type} className={cx(
                                            'flex cursor-pointer items-start gap-2.5 rounded-control border px-3 py-2.5',
                                            form.data.scope_type === type ? 'border-primary bg-primary/5' : 'border-(--app-border) hover:bg-(--app-surface-muted)',
                                        )}>
                                            <input type="radio" name="scope_type" value={type} className="mt-0.5 h-4 w-4 text-primary focus:ring-primary"
                                                checked={form.data.scope_type === type} onChange={() => form.setData('scope_type', type)} />
                                            <span>
                                                <span className="block text-sm font-medium text-(--app-foreground)">{t(`users.userOrganizationScopes.scopeTypes.${type}`)}</span>
                                                <span className="mt-0.5 block text-xs text-(--app-muted-foreground)">{type === 'self' ? c('scopeSelf') : c('scopeSubtree')}</span>
                                            </span>
                                        </label>
                                    ))}
                                </div>
                                {form.errors.scope_type && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{form.errors.scope_type}</p>}
                            </fieldset>
                        </div>
                    </FormSection>

                    {/* The actions, for screens where the summary sits below the form. */}
                    <div className="sticky bottom-0 -mx-6 -mb-6 flex items-center justify-end gap-3 border-t border-(--app-border) bg-[color-mix(in_srgb,var(--app-surface)_95%,transparent)] px-6 py-4 backdrop-blur-sm xl:hidden">
                        {actions}
                    </div>
                </div>

                {/* What will be created */}
                <aside className="space-y-4 rounded-panel border border-(--app-border) bg-(--app-surface) p-5 xl:sticky xl:top-24" aria-label={c('summaryTitle')}>
                    <p className="text-[11px] font-semibold uppercase tracking-wide text-(--app-muted-foreground)">{c('summaryTitle')}</p>
                    <div className="flex items-center gap-3">
                        <UserAvatar src={photoPreview} name={form.data.name || 'U'} size={44} />
                        <div className="min-w-0">
                            <p className={cx('truncate text-sm font-semibold', form.data.name ? 'text-(--app-foreground)' : 'text-(--app-muted-foreground)')}>{form.data.name || c('summaryUnnamed')}</p>
                            <p className="truncate text-xs text-(--app-muted-foreground)">{form.data.email || t('users.emailPlaceholder')}</p>
                        </div>
                        <span className={cx(badgeClassName(form.data.status === 'active' ? 'success' : 'neutral'), 'ml-auto shrink-0')}>{statusLabels[form.data.status] ?? form.data.status}</span>
                    </div>

                    <div className="border-t border-(--app-border) pt-4">
                        <p className="text-xs font-medium text-(--app-muted-foreground)">{c('summaryRoles')} <span className="tabular-nums">({selectedRoles.length})</span></p>
                        {selectedRoles.length === 0 ? (
                            <a href="#user-roles" className="mt-1 block text-xs text-(--app-muted-foreground) hover:text-primary">{c('summaryNoRoles')}</a>
                        ) : (
                            <ul className="mt-2 flex flex-wrap gap-1.5">
                                {selectedRoles.map((role) => (
                                    <li key={role.id} className="inline-flex items-center gap-1 rounded-full border border-(--app-border) bg-(--app-surface-muted) py-0.5 pl-2.5 pr-1 text-xs text-(--app-foreground)">
                                        {role.name}
                                        <button type="button" onClick={() => toggleRole(role.id)} aria-label={c('remove').replace(':name', role.name)}
                                            className="grid h-4 w-4 place-items-center rounded-full text-(--app-muted-foreground) hover:bg-(--app-border) hover:text-(--app-foreground)">×</button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    <div className="border-t border-(--app-border) pt-4">
                        <p className="text-xs font-medium text-(--app-muted-foreground)">
                            {c('summaryScope')}
                            {selectedOrganizations.length > 0 && <> · {t(`users.userOrganizationScopes.scopeTypes.${form.data.scope_type}`)}</>}
                        </p>
                        {selectedOrganizations.length === 0 ? (
                            <a href="#user-scope" className={cx('mt-1 block text-xs hover:underline', scopeMissing ? 'font-medium text-amber-700 dark:text-amber-400' : 'text-(--app-muted-foreground)')}>
                                {scopeMissing ? c('summaryScopeNeeded') : c('summaryNoScope')}
                            </a>
                        ) : (
                            <ul className="mt-1 space-y-0.5 text-xs text-(--app-foreground)">
                                {selectedOrganizations.slice(0, 5).map((organization) => <li key={organization.id} className="truncate">{organizationName(organization, locale)}</li>)}
                                {selectedOrganizations.length > 5 && <li className="text-(--app-muted-foreground)">+{selectedOrganizations.length - 5}</li>}
                            </ul>
                        )}
                    </div>

                    <div className="hidden flex-col-reverse gap-2 border-t border-(--app-border) pt-4 xl:flex [&>*]:w-full [&>*]:justify-center">
                        {actions}
                    </div>
                </aside>
            </form>
        </AuthenticatedLayout>
    );
}
