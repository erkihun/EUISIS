import { router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import StatusBadge from '@/Components/StatusBadge';
import { useLocale } from '@/hooks/useLocale';
import { AppDialog, Button, Card, ConfirmDialog, EmptyState, FormField, Input, Select, Table, TBody, Td, Th, THead, Tr } from '@euisis/ui';
import { organizationLabel } from './ApiClientsPanel';
import type { IntegrationCenterData, WebhookRow } from './types';

type WebhookForm = { name: string; url: string; event_types: string[]; scope_organization_id: string; is_active: boolean };

const ALL_EVENTS = '*';

/** Webhook endpoints: register (the signing secret is shown once), edit, turn on or off, rotate the secret. */
export default function WebhooksPanel({ webhooks, options, storeUrl }: { webhooks: WebhookRow[]; options: IntegrationCenterData['options']; storeUrl: string }) {
    const { t, locale } = useLocale();
    const [editing, setEditing] = useState<WebhookRow | 'new' | null>(null);
    const [rotating, setRotating] = useState<WebhookRow | null>(null);
    const form = useForm<WebhookForm>({ name: '', url: '', event_types: [], scope_organization_id: '', is_active: true });
    const organizations = new Map(options.organizations.map((option) => [option.id, option]));
    const label = (key: string) => t(`core.integration.center.webhooks.${key}`);
    const allEvents = form.data.event_types.includes(ALL_EVENTS);

    const open = (webhook: WebhookRow | 'new') => {
        form.clearErrors();
        form.setData(webhook === 'new'
            ? { name: '', url: '', event_types: [], scope_organization_id: '', is_active: true }
            : { name: webhook.name, url: webhook.url, event_types: webhook.event_types, scope_organization_id: webhook.scope_organization_id ?? '', is_active: webhook.is_active });
        setEditing(webhook);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const done = { preserveScroll: true, onSuccess: () => setEditing(null) };
        if (editing === 'new') form.post(storeUrl, done);
        else if (editing) form.put(editing.urls.update, done);
    };

    const toggle = (webhook: WebhookRow) => router.put(webhook.urls.update, { is_active: !webhook.is_active }, { preserveScroll: true });

    return (
        <section className="space-y-3" aria-labelledby="integration-webhooks-title">
            <div className="flex flex-wrap items-end justify-between gap-2">
                <h2 id="integration-webhooks-title" className="text-base font-semibold text-(--app-foreground)">{t('core.integration.center.tabs.webhooks')}</h2>
                <Button size="sm" variant="primary" onClick={() => open('new')}>{label('register')}</Button>
            </div>

            <Card className="overflow-hidden p-0">
                {webhooks.length === 0 ? (
                    <EmptyState title={label('empty')} description={label('emptyHint')} />
                ) : (
                    <Table>
                        <THead>
                            <Tr>
                                <Th>{label('name')}</Th>
                                <Th>{label('status')}</Th>
                                <Th>{label('events')}</Th>
                                <Th>{label('organization')}</Th>
                                <Th>{label('deliveries')}</Th>
                                <Th>{label('lastSuccess')}</Th>
                                <Th>{label('lastFailure')}</Th>
                                <Th align="right">{label('actions')}</Th>
                            </Tr>
                        </THead>
                        <TBody>
                            {webhooks.map((webhook) => (
                                <Tr key={webhook.id}>
                                    <Td>
                                        <div className="font-medium text-(--app-foreground)">{webhook.name}</div>
                                        <div className="max-w-xs truncate font-mono text-xs text-(--app-muted-foreground)" title={webhook.url}>{webhook.url}</div>
                                    </Td>
                                    <Td>
                                        {webhook.suspended_at
                                            ? <span title={webhook.suspended_reason ?? undefined}><StatusBadge status="danger" label={label('suspended')} /></span>
                                            : <StatusBadge status={webhook.is_active ? 'active' : 'inactive'} label={label(webhook.is_active ? 'on' : 'off')} />}
                                        {(webhook.consecutive_failures ?? 0) > 0 && <div className="mt-1 text-xs text-(--app-muted-foreground)">{label('failuresInARow')}: {webhook.consecutive_failures}</div>}
                                        {webhook.previous_secret_expires_at && <div className="mt-1 text-xs text-(--app-muted-foreground)">{label('oldSecretUntil')} <LocalizedDateDisplay value={webhook.previous_secret_expires_at} withTime /></div>}
                                    </Td>
                                    <Td>
                                        <div className="flex max-w-xs flex-wrap gap-1">
                                            {webhook.event_types.map((type) => <code key={type} className="rounded-sm bg-(--app-surface-muted) px-1.5 py-0.5 text-xs">{type === ALL_EVENTS ? label('allEvents') : type}</code>)}
                                        </div>
                                    </Td>
                                    <Td muted>{webhook.scope_organization_id ? organizationLabel(organizations.get(webhook.scope_organization_id), locale) : label('allOrganizations')}</Td>
                                    <Td muted>
                                        {Object.entries(webhook.deliveries).length === 0 ? '—' : Object.entries(webhook.deliveries)
                                            .map(([status, count]) => `${t(`core.integration.center.deliveries.statuses.${status}`)} ${count}`).join(' · ')}
                                    </Td>
                                    <Td muted><LocalizedDateDisplay value={webhook.last_success_at} withTime /></Td>
                                    <Td muted><LocalizedDateDisplay value={webhook.last_failure_at} withTime /></Td>
                                    <Td align="right">
                                        <div className="flex justify-end gap-1">
                                            <Button size="xs" variant="outline" onClick={() => open(webhook)}>{label('edit')}</Button>
                                            {webhook.urls.resume
                                                ? <Button size="xs" variant="primary" onClick={() => router.post(webhook.urls.resume as string, {}, { preserveScroll: true })}>{label('resume')}</Button>
                                                : <Button size="xs" variant="outline" onClick={() => toggle(webhook)}>{label(webhook.is_active ? 'disable' : 'enable')}</Button>}
                                            <Button size="xs" variant="outline" onClick={() => setRotating(webhook)}>{label('rotate')}</Button>
                                        </div>
                                    </Td>
                                </Tr>
                            ))}
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
                        <Button variant="outline" onClick={() => setEditing(null)} disabled={form.processing}>{t('core.integration.center.clients.cancel')}</Button>
                        <Button variant="primary" type="submit" form="integration-webhook-form" loading={form.processing}>{t('core.integration.center.clients.save')}</Button>
                    </>
                )}
            >
                <form id="integration-webhook-form" onSubmit={submit} className="grid gap-4 md:grid-cols-2">
                    <FormField label={label('name')} required error={form.errors.name}>
                        {({ id }) => <Input id={id} value={form.data.name} maxLength={160} onChange={(event) => form.setData('name', event.target.value)} />}
                    </FormField>
                    <FormField label={label('url')} required error={form.errors.url}>
                        {({ id }) => <Input id={id} type="url" inputMode="url" value={form.data.url} maxLength={500} placeholder="https://" onChange={(event) => form.setData('url', event.target.value)} />}
                    </FormField>
                    <FormField className="md:col-span-2" label={label('organization')} description={label('organizationHint')} error={form.errors.scope_organization_id}>
                        {({ id, describedBy }) => (
                            <Select id={id} aria-describedby={describedBy} value={form.data.scope_organization_id} onChange={(event) => form.setData('scope_organization_id', event.target.value)}>
                                <option value="">{label('allOrganizations')}</option>
                                {options.organizations.map((option) => <option key={option.id} value={option.id}>{organizationLabel(option, locale)}</option>)}
                            </Select>
                        )}
                    </FormField>
                    <fieldset className="md:col-span-2">
                        <legend className="text-sm font-medium text-(--app-foreground)">{label('events')}</legend>
                        <label className="mt-2 flex items-center gap-2 text-sm font-medium">
                            <input type="checkbox" className="h-4 w-4 rounded-sm border-(--app-border-strong) text-primary focus:ring-primary" checked={allEvents}
                                onChange={(event) => form.setData('event_types', event.target.checked ? [ALL_EVENTS] : [])} />
                            {label('allEvents')}
                        </label>
                        <div className="mt-2 grid gap-2 sm:grid-cols-2">
                            {options.event_types.map((type) => (
                                <label key={type} className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        disabled={allEvents}
                                        className="h-4 w-4 rounded-sm border-(--app-border-strong) text-primary focus:ring-primary"
                                        checked={allEvents || form.data.event_types.includes(type)}
                                        onChange={(event) => form.setData('event_types', event.target.checked ? [...form.data.event_types, type] : form.data.event_types.filter((item) => item !== type))}
                                    />
                                    <code className="text-xs">{type}</code>
                                </label>
                            ))}
                        </div>
                        {form.errors.event_types && <p className="mt-1 text-xs text-red-600 dark:text-red-400" role="alert">{form.errors.event_types}</p>}
                    </fieldset>
                    {editing !== 'new' && (
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" className="h-4 w-4 rounded-sm border-(--app-border-strong) text-primary focus:ring-primary" checked={form.data.is_active} onChange={(event) => form.setData('is_active', event.target.checked)} />
                            {label('on')}
                        </label>
                    )}
                </form>
            </AppDialog>

            <ConfirmDialog
                open={rotating !== null}
                onClose={() => setRotating(null)}
                title={label('rotateTitle')}
                description={label('rotateDescription')}
                confirmLabel={label('rotate')}
                cancelLabel={t('core.integration.center.clients.cancel')}
                onConfirm={() => rotating && router.post(rotating.urls.rotate, {}, { preserveScroll: true, onFinish: () => setRotating(null) })}
            />
        </section>
    );
}
