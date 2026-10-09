import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import FormSection from '@/Components/FormSection';
import PasswordPolicyChecklist from '@/Components/PasswordPolicyChecklist';
import { Head, Link, useForm } from '@inertiajs/react';
import { Alert, Button, FormField, Input, Select, StatusBadge } from '@euisis/ui';
import { useMemo, useState, type FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { permissionLabelKey, useLabel } from '@/Components/ProviderUsers/AccountUi';

type Role = 'owner' | 'manager' | 'operator';

type ProviderOption = {
    id: string;
    code: string | null;
    name_en: string | null;
    name_am: string | null;
    status: string;
    type: string | null;
    services: string[];
    permissions: string[];
};

type Account = {
    id: string;
    provider_id: string;
    name: string;
    email: string | null;
    username: string | null;
    phone_number: string | null;
    provider_role: Role;
    status: string;
    portal_enabled: boolean;
    service_permissions: string[];
};

type FormData = {
    provider_id: string;
    name: string;
    email: string;
    username: string;
    phone_number: string;
    provider_role: Role;
    portal_enabled: boolean;
    service_permissions: string[];
    status: 'active' | 'inactive';
    password: string;
};

export default function ProviderUserForm({ account, providers, services, roles, defaults }: {
    account: Account | null;
    providers: ProviderOption[];
    services: Array<{ code: string; name_en: string | null; name_am: string | null }>;
    roles: Role[];
    defaults: { provider_id: string | null };
}) {
    const { t } = useLocale();
    const label = useLabel();
    const editing = account !== null;
    const [passwordMode, setPasswordMode] = useState<'generate' | 'manual'>('generate');

    const form = useForm<FormData>({
        provider_id: account?.provider_id ?? defaults.provider_id ?? '',
        name: account?.name ?? '',
        email: account?.email ?? '',
        username: account?.username ?? '',
        phone_number: account?.phone_number ?? '',
        provider_role: account?.provider_role ?? 'operator',
        portal_enabled: account?.portal_enabled ?? true,
        service_permissions: account?.service_permissions ?? [],
        status: 'active',
        password: '',
    });
    const { data, setData, errors, processing } = form;

    const provider = useMemo(() => providers.find((p) => p.id === data.provider_id) ?? null, [providers, data.provider_id]);
    const serviceName = (code: string) => label(services.find((s) => s.code === code) ?? { code });

    /** Offered keys grouped by the service they belong to (the second key segment). */
    const permissionGroups = useMemo(() => {
        const groups = new Map<string, string[]>();
        (provider?.permissions ?? []).forEach((key) => {
            const service = key.split('.')[1] ?? '';
            groups.set(service, [...(groups.get(service) ?? []), key]);
        });
        return [...groups.entries()];
    }, [provider]);

    const selectProvider = (id: string) => {
        const next = providers.find((p) => p.id === id);
        form.setData((current) => ({
            ...current,
            provider_id: id,
            // Keep only what the newly chosen provider offers.
            service_permissions: current.service_permissions.filter((key) => next?.permissions.includes(key)),
        }));
    };

    const togglePermission = (key: string, checked: boolean) => {
        setData('service_permissions', checked ? [...data.service_permissions, key] : data.service_permissions.filter((k) => k !== key));
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (editing) {
            form.transform(({ provider_id: _p, status: _s, password: _pw, ...rest }) => rest);
            form.patch(route('provider-users.update', account.id), { preserveScroll: true });
        } else {
            form.transform((values) => ({ ...values, password: passwordMode === 'manual' ? values.password : '' }));
            form.post(route('provider-users.store'), { preserveScroll: true });
        }
    };

    const title = editing ? t('providerUsers.editTitle') : t('providerUsers.createTitle');
    const backHref = editing ? route('provider-users.show', account.id) : route('provider-users.index');

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={editing ? account.name : t('providerUsers.subtitle')} backHref={backHref} />}>
            <Head title={title} />

            <form onSubmit={submit} className="space-y-6 rounded-[var(--radius-card)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-4 sm:p-6" noValidate>
                <FormSection title={t('providerUsers.sectionProvider')} description={t(editing ? 'providerUsers.providerFixed' : 'providerUsers.sectionProviderHelp')}>
                    <FormField label={t('providerUsers.provider')} required error={errors.provider_id}>
                        {({ id, describedBy, invalid }) => (
                            <Select id={id} value={data.provider_id} disabled={editing} aria-describedby={describedBy} aria-invalid={invalid} required
                                onChange={(e) => selectProvider(e.target.value)}>
                                <option value="">{t('providerUsers.selectProvider')}</option>
                                {providers.map((p) => (
                                    <option key={p.id} value={p.id}>{label(p)}{p.code ? ` (${p.code})` : ''}</option>
                                ))}
                            </Select>
                        )}
                    </FormField>
                    <div className="space-y-1.5">
                        <p className="text-sm font-medium text-[color:var(--app-foreground)]">{t('providerUsers.providerServices')}</p>
                        <div className="flex min-h-[var(--control-h-md)] flex-wrap items-center gap-1">
                            {provider === null
                                ? <span className="text-sm text-[color:var(--app-muted-foreground)]">—</span>
                                : provider.services.length === 0
                                    ? <span className="text-sm text-[color:var(--app-muted-foreground)]">{t('providerUsers.noServices')}</span>
                                    : provider.services.map((code) => <StatusBadge key={code} tone="info">{serviceName(code)}</StatusBadge>)}
                        </div>
                    </div>
                    {provider && provider.status !== 'active' && (
                        <Alert tone="warning" className="md:col-span-2">{t('providerUsers.providerInactive')}</Alert>
                    )}
                </FormSection>

                <FormSection title={t('providerUsers.sectionIdentity')} description={t('providerUsers.signInHelp')}>
                    <FormField label={t('providerUsers.name')} required error={errors.name}>
                        {({ id, describedBy, invalid }) => (
                            <Input id={id} value={data.name} autoComplete="off" maxLength={255} required aria-describedby={describedBy} aria-invalid={invalid}
                                onChange={(e) => setData('name', e.target.value)} />
                        )}
                    </FormField>
                    <FormField label={t('providerUsers.email')} description={t('providerUsers.emailHelp')} error={errors.email}>
                        {({ id, describedBy, invalid }) => (
                            <Input id={id} type="email" value={data.email} autoComplete="off" maxLength={255} aria-describedby={describedBy} aria-invalid={invalid}
                                onChange={(e) => setData('email', e.target.value)} />
                        )}
                    </FormField>
                    <FormField label={t('providerUsers.username')} description={t('providerUsers.usernameHelp')} error={errors.username}>
                        {({ id, describedBy, invalid }) => (
                            <Input id={id} value={data.username} autoComplete="off" maxLength={100} aria-describedby={describedBy} aria-invalid={invalid}
                                onChange={(e) => setData('username', e.target.value)} />
                        )}
                    </FormField>
                    <FormField label={t('providerUsers.phone')} description={t('providerUsers.phoneHelp')} error={errors.phone_number}>
                        {({ id, describedBy, invalid }) => (
                            <Input id={id} type="tel" inputMode="tel" value={data.phone_number} autoComplete="off" maxLength={30} aria-describedby={describedBy} aria-invalid={invalid}
                                onChange={(e) => setData('phone_number', e.target.value)} />
                        )}
                    </FormField>
                </FormSection>

                <FormSection title={t('providerUsers.sectionAccess')}>
                    <FormField label={t('providerUsers.role')} required description={t(`providerUsers.roleHelp.${data.provider_role}`)} error={errors.provider_role}>
                        {({ id, describedBy, invalid }) => (
                            <Select id={id} value={data.provider_role} aria-describedby={describedBy} aria-invalid={invalid}
                                onChange={(e) => setData('provider_role', e.target.value as Role)}>
                                {roles.map((role) => <option key={role} value={role}>{t(`providerUsers.roles.${role}`)}</option>)}
                            </Select>
                        )}
                    </FormField>
                    {!editing && (
                        <FormField label={t('providerUsers.initialStatus')} error={errors.status}>
                            {({ id, describedBy, invalid }) => (
                                <Select id={id} value={data.status} aria-describedby={describedBy} aria-invalid={invalid}
                                    onChange={(e) => setData('status', e.target.value as 'active' | 'inactive')}>
                                    <option value="active">{t('providerUsers.statuses.active')}</option>
                                    <option value="inactive">{t('providerUsers.statuses.inactive')}</option>
                                </Select>
                            )}
                        </FormField>
                    )}
                    <div className="md:col-span-2">
                        <label className="flex items-start gap-3 text-sm">
                            <input type="checkbox" className="mt-0.5 h-4 w-4 rounded border-[color:var(--app-border)]" checked={data.portal_enabled}
                                onChange={(e) => setData('portal_enabled', e.target.checked)} />
                            <span>
                                <span className="font-medium text-[color:var(--app-foreground)]">{t('providerUsers.portalEnabled')}</span>
                                <span className="block text-xs text-[color:var(--app-muted-foreground)]">{t('providerUsers.portalEnabledHelp')}</span>
                            </span>
                        </label>
                    </div>

                    <fieldset className="space-y-3 md:col-span-2">
                        <legend className="text-sm font-medium text-[color:var(--app-foreground)]">{t('providerUsers.permissionsTitle')}</legend>
                        <p className="text-xs text-[color:var(--app-muted-foreground)]">{t('providerUsers.permissionsHelp')}</p>
                        {provider === null ? (
                            <p className="text-sm text-[color:var(--app-muted-foreground)]">{t('providerUsers.selectProviderFirst')}</p>
                        ) : data.provider_role !== 'operator' ? (
                            <Alert tone="info">{t('providerUsers.permissionsImplied')}</Alert>
                        ) : permissionGroups.length === 0 ? (
                            <p className="text-sm text-[color:var(--app-muted-foreground)]">{t('providerUsers.noPermissionsOffered')}</p>
                        ) : (
                            permissionGroups.map(([service, keys]) => (
                                <div key={service} className="rounded-[var(--radius-control)] border border-[color:var(--app-border)] p-3">
                                    <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-[color:var(--app-muted-foreground)]">{serviceName(service)}</p>
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        {keys.map((key) => (
                                            <label key={key} className="flex items-center gap-2 text-sm">
                                                <input type="checkbox" className="h-4 w-4 rounded border-[color:var(--app-border)]"
                                                    checked={data.service_permissions.includes(key)} onChange={(e) => togglePermission(key, e.target.checked)} />
                                                {t(permissionLabelKey(key))}
                                            </label>
                                        ))}
                                    </div>
                                </div>
                            ))
                        )}
                        {errors.service_permissions && <p className="text-sm text-red-700 dark:text-red-400">{errors.service_permissions}</p>}
                    </fieldset>
                </FormSection>

                {!editing && (
                    <FormSection title={t('providerUsers.sectionPassword')} description={t('providerUsers.passwordHelp')} grid={false}>
                        <div className="space-y-3">
                            <label className="flex items-start gap-3 text-sm">
                                <input type="radio" name="password_mode" className="mt-0.5 h-4 w-4" checked={passwordMode === 'generate'} onChange={() => setPasswordMode('generate')} />
                                <span>
                                    <span className="font-medium text-[color:var(--app-foreground)]">{t('providerUsers.passwordGenerate')}</span>
                                    <span className="block text-xs text-[color:var(--app-muted-foreground)]">{t('providerUsers.passwordGenerateHelp')}</span>
                                </span>
                            </label>
                            <label className="flex items-center gap-3 text-sm">
                                <input type="radio" name="password_mode" className="h-4 w-4" checked={passwordMode === 'manual'} onChange={() => setPasswordMode('manual')} />
                                <span className="font-medium text-[color:var(--app-foreground)]">{t('providerUsers.passwordManual')}</span>
                            </label>
                            {passwordMode === 'manual' && (
                                <div className="max-w-md space-y-3 pl-7">
                                    <FormField label={t('providerUsers.password')} required error={errors.password}>
                                        {({ id, describedBy, invalid }) => (
                                            <Input id={id} type="password" autoComplete="new-password" value={data.password} aria-describedby={describedBy} aria-invalid={invalid}
                                                onChange={(e) => setData('password', e.target.value)} />
                                        )}
                                    </FormField>
                                    <PasswordPolicyChecklist password={data.password} personal={[data.name, data.email, data.username, data.phone_number]} />
                                </div>
                            )}
                        </div>
                    </FormSection>
                )}

                <div className="flex flex-col-reverse gap-3 border-t border-[color:var(--app-border)] pt-4 sm:flex-row sm:justify-end">
                    <Button as={Link} href={backHref} variant="outline">{t('providerUsers.cancel')}</Button>
                    <Button type="submit" variant="primary" disabled={processing}>{processing ? t('providerUsers.saving') : editing ? t('providerUsers.save') : t('providerUsers.create')}</Button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
