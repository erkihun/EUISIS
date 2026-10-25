import { router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import StatusBadge from '@/Components/StatusBadge';
import { useLocale } from '@/hooks/useLocale';
import { AppDialog, Button, Card, ConfirmDialog, EmptyState, FormField, Input, Select, Table, TBody, Td, Textarea, Th, THead, Tr } from '@euisis/ui';
import type { ApiClientRow, IntegrationCenterData, OrganizationOption } from './types';

type ClientForm = {
    name: string;
    description: string;
    scope_type: string;
    owner_organization_id: string;
    scopes: string[];
    allowed_ips: string;
    rate_limit_per_minute: string;
    expires_at: string;
    is_active: boolean;
};

const SCOPE_TYPES = ['citywide', 'subtree', 'self'] as const;

export function organizationLabel(option: OrganizationOption | undefined, locale: string): string {
    if (!option) return '—';
    return `${locale === 'am' && option.name_am ? option.name_am : option.name_en} (${option.code})`;
}

function clientStatus(client: ApiClientRow): string {
    if (client.revoked_at) return 'revoked';
    if (client.expires_at && new Date(client.expires_at) < new Date()) return 'expired';
    return client.is_active ? 'active' : 'inactive';
}

function emptyForm(client: ApiClientRow | null): ClientForm {
    return {
        name: client?.name ?? '',
        description: client?.description ?? '',
        scope_type: client?.scope_type ?? 'subtree',
        owner_organization_id: client?.owner_organization_id ?? '',
        scopes: client?.scopes ?? [],
        allowed_ips: (client?.allowed_ips ?? []).join('\n'),
        rate_limit_per_minute: String(client?.rate_limit_per_minute ?? 60),
        expires_at: client?.expires_at ? client.expires_at.slice(0, 10) : '',
        is_active: client?.is_active ?? true,
    };
}

/** API clients: register (the secret is shown once), edit, rotate the secret, revoke. */
export default function ApiClientsPanel({ clients, options, storeUrl }: { clients: ApiClientRow[]; options: IntegrationCenterData['options']; storeUrl: string }) {
    const { t, locale } = useLocale();
    const [editing, setEditing] = useState<ApiClientRow | 'new' | null>(null);
    const [rotating, setRotating] = useState<ApiClientRow | null>(null);
    const [revoking, setRevoking] = useState<ApiClientRow | null>(null);
    const form = useForm<ClientForm>(emptyForm(null));
    const revokeForm = useForm({ reason: '' });
    const organizations = new Map(options.organizations.map((option) => [option.id, option]));
    const label = (key: string) => t(`core.integration.center.clients.${key}`);

    const open = (client: ApiClientRow | 'new') => {
        form.clearErrors();
        form.setData(emptyForm(client === 'new' ? null : client));
        setEditing(client);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const done = { preserveScroll: true, onSuccess: () => setEditing(null) };
        if (editing === 'new') {
            form.post(storeUrl, done);
        } else if (editing) {
            const initialExpiry = editing.expires_at?.slice(0, 10) ?? '';
            // An unchanged expiry is not sent again, so a client past its date can still be edited.
            form.transform((data) => {
                const { expires_at: expiresAt, ...rest } = data;
                return expiresAt === initialExpiry ? rest : data;
            });
            form.put(editing.urls.update, done);
        }
    };

    return (
        <section className="space-y-3" aria-labelledby="integration-clients-title">
            <div className="flex flex-wrap items-end justify-between gap-2">
                <h2 id="integration-clients-title" className="text-base font-semibold text-(--app-foreground)">{t('core.integration.center.tabs.clients')}</h2>
                <Button size="sm" variant="primary" onClick={() => open('new')}>{label('register')}</Button>
            </div>

            <Card className="overflow-hidden p-0">
                {clients.length === 0 ? (
                    <EmptyState title={label('empty')} description={label('emptyHint')} />
                ) : (
                    <Table>
                        <THead>
                            <Tr>
                                <Th>{label('name')}</Th>
                                <Th>{label('status')}</Th>
                                <Th>{label('reads')}</Th>
                                <Th>{label('scopes')}</Th>
                                <Th align="right">{label('calls')}</Th>
                                <Th>{label('expiresAt')}</Th>
                                <Th>{label('lastUsed')}</Th>
                                <Th align="right">{label('actions')}</Th>
                            </Tr>
                        </THead>
                        <TBody>
                            {clients.map((client) => {
                                const status = clientStatus(client);
                                return (
                                    <Tr key={client.uuid}>
                                        <Td>
                                            <div className="font-medium text-(--app-foreground)">{client.name}</div>
                                            <div className="font-mono text-xs text-(--app-muted-foreground)">{client.uuid}</div>
                                        </Td>
                                        <Td><StatusBadge status={status} label={label(`statuses.${status}`)} /></Td>
                                        <Td muted>
                                            {client.scope_type === 'citywide' ? label('scopeTypes.citywide')
                                                : `${organizationLabel(organizations.get(client.owner_organization_id ?? ''), locale)} · ${label(`scopeTypes.${client.scope_type ?? 'subtree'}`)}`}
                                        </Td>
                                        <Td><div className="flex max-w-xs flex-wrap gap-1">{client.scopes.map((scope) => <code key={scope} className="rounded-sm bg-(--app-surface-muted) px-1.5 py-0.5 text-xs">{scope}</code>)}</div></Td>
                                        <Td numeric>{client.calls_24h}{client.errors_24h > 0 && <span className="ml-1 text-xs text-red-600 dark:text-red-400">({client.errors_24h})</span>}</Td>
                                        <Td muted><LocalizedDateDisplay value={client.expires_at} /></Td>
                                        <Td muted><LocalizedDateDisplay value={client.last_used_at} withTime fallback={label('never')} /></Td>
                                        <Td align="right">
                                            {!client.revoked_at && (
                                                <div className="flex justify-end gap-1">
                                                    <Button size="xs" variant="outline" onClick={() => open(client)}>{label('edit')}</Button>
                                                    <Button size="xs" variant="outline" onClick={() => setRotating(client)}>{label('rotate')}</Button>
                                                    <Button size="xs" variant="destructiveOutline" onClick={() => { revokeForm.reset(); revokeForm.clearErrors(); setRevoking(client); }}>{label('revoke')}</Button>
                                                </div>
                                            )}
                                        </Td>
                                    </Tr>
                                );
                            })}
                        </TBody>
                    </Table>
                )}
            </Card>

            <AppDialog
                open={editing !== null}
                onClose={() => setEditing(null)}
                maxWidth="2xl"
                title={editing === 'new' ? label('register') : label('edit')}
                description={editing === 'new' ? label('registerHint') : undefined}
                footer={(
                    <>
                        <Button variant="outline" onClick={() => setEditing(null)} disabled={form.processing}>{label('cancel')}</Button>
                        <Button variant="primary" type="submit" form="integration-client-form" loading={form.processing}>{label('save')}</Button>
                    </>
                )}
            >
                <form id="integration-client-form" onSubmit={submit} className="grid gap-4 md:grid-cols-2">
                    <FormField label={label('name')} required error={form.errors.name}>
                        {({ id }) => <Input id={id} value={form.data.name} maxLength={160} onChange={(event) => form.setData('name', event.target.value)} />}
                    </FormField>
                    <FormField label={label('rateLimit')} error={form.errors.rate_limit_per_minute}>
                        {({ id }) => <Input id={id} type="number" min={10} max={6000} value={form.data.rate_limit_per_minute} onChange={(event) => form.setData('rate_limit_per_minute', event.target.value)} />}
                    </FormField>
                    <FormField className="md:col-span-2" label={label('description')} error={form.errors.description}>
                        {({ id }) => <Textarea id={id} rows={2} maxLength={2000} value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} />}
                    </FormField>
                    <FormField label={label('scopeType')} required error={form.errors.scope_type}>
                        {({ id }) => (
                            <Select id={id} value={form.data.scope_type} onChange={(event) => form.setData('scope_type', event.target.value)}>
                                {SCOPE_TYPES.map((type) => <option key={type} value={type}>{label(`scopeTypes.${type}`)}</option>)}
                            </Select>
                        )}
                    </FormField>
                    {form.data.scope_type !== 'citywide' && (
                        <FormField label={label('organization')} required error={form.errors.owner_organization_id}>
                            {({ id }) => (
                                <Select id={id} value={form.data.owner_organization_id} onChange={(event) => form.setData('owner_organization_id', event.target.value)}>
                                    <option value="">{label('chooseOrganization')}</option>
                                    {options.organizations.map((option) => <option key={option.id} value={option.id}>{organizationLabel(option, locale)}</option>)}
                                </Select>
                            )}
                        </FormField>
                    )}
                    {form.data.scope_type === 'citywide' && form.errors.owner_organization_id && (
                        <p className="text-sm text-red-600 dark:text-red-400" role="alert">{form.errors.owner_organization_id}</p>
                    )}
                    <fieldset className="md:col-span-2">
                        <legend className="text-sm font-medium text-(--app-foreground)">{label('scopes')}</legend>
                        <div className="mt-2 grid gap-2 sm:grid-cols-2">
                            {options.scopes.map((scope) => (
                                <label key={scope} className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        className="h-4 w-4 rounded-sm border-(--app-border-strong) text-primary focus:ring-primary"
                                        checked={form.data.scopes.includes(scope)}
                                        onChange={(event) => form.setData('scopes', event.target.checked ? [...form.data.scopes, scope] : form.data.scopes.filter((item) => item !== scope))}
                                    />
                                    <code className="text-xs">{scope}</code>
                                </label>
                            ))}
                        </div>
                        {form.errors.scopes && <p className="mt-1 text-xs text-red-600 dark:text-red-400" role="alert">{form.errors.scopes}</p>}
                    </fieldset>
                    <FormField label={label('allowedIps')} description={label('allowedIpsHint')} error={(form.errors as Record<string, string | undefined>)['allowed_ips.0'] ?? form.errors.allowed_ips}>
                        {({ id, describedBy }) => <Textarea id={id} aria-describedby={describedBy} rows={3} value={form.data.allowed_ips} onChange={(event) => form.setData('allowed_ips', event.target.value)} />}
                    </FormField>
                    <div className="space-y-4">
                        <FormField label={label('expiresAt')} description={editing === 'new' ? label('expiresHint') : undefined} error={form.errors.expires_at}>
                            {({ id }) => <Input id={id} type="date" value={form.data.expires_at} onChange={(event) => form.setData('expires_at', event.target.value)} />}
                        </FormField>
                        {editing !== 'new' && (
                            <label className="flex items-center gap-2 text-sm">
                                <input type="checkbox" className="h-4 w-4 rounded-sm border-(--app-border-strong) text-primary focus:ring-primary" checked={form.data.is_active} onChange={(event) => form.setData('is_active', event.target.checked)} />
                                {label('active')}
                            </label>
                        )}
                    </div>
                </form>
            </AppDialog>

            <ConfirmDialog
                open={rotating !== null}
                onClose={() => setRotating(null)}
                title={label('rotateTitle')}
                description={label('rotateDescription')}
                confirmLabel={label('rotate')}
                cancelLabel={label('cancel')}
                onConfirm={() => rotating && router.post(rotating.urls.rotate, {}, { preserveScroll: true, onFinish: () => setRotating(null) })}
            />

            <AppDialog
                open={revoking !== null}
                onClose={() => setRevoking(null)}
                title={label('revokeTitle')}
                description={label('revokeDescription')}
                footer={(
                    <>
                        <Button variant="outline" onClick={() => setRevoking(null)} disabled={revokeForm.processing}>{label('cancel')}</Button>
                        <Button
                            variant="destructive"
                            loading={revokeForm.processing}
                            onClick={() => revoking && revokeForm.post(revoking.urls.revoke, { preserveScroll: true, onSuccess: () => setRevoking(null) })}
                        >
                            {label('revoke')}
                        </Button>
                    </>
                )}
            >
                <FormField label={label('reason')} required error={revokeForm.errors.reason}>
                    {({ id }) => <Textarea id={id} rows={3} maxLength={1000} value={revokeForm.data.reason} onChange={(event) => revokeForm.setData('reason', event.target.value)} />}
                </FormField>
            </AppDialog>
        </section>
    );
}
