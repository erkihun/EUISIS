import { router } from '@inertiajs/react';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import StatusBadge from '@/Components/StatusBadge';
import { useLocale } from '@/hooks/useLocale';
import { Button, Card, cx, EmptyState, FilterField, filterPanelClassName, Pagination, Select, StatusBadge as Badge, Table, TBody, Td, Th, THead, Tr } from '@euisis/ui';
import type { ApiCallRow, DeliveryRow, EventRow, IntegrationCenterData, Paged } from './types';

/** Reloads the open tab with new filters (or page), keeping the rest. */
function useReload(center: IntegrationCenterData) {
    return (changes: Record<string, string | boolean | number | null>) => {
        const query: Record<string, string | boolean | number> = { ...center.filters, tab: center.tab };
        delete query.page;
        Object.entries(changes).forEach(([key, value]) => {
            if (value === null || value === '' || value === false) delete query[key];
            else query[key] = value;
        });
        router.get(center.urls.page, query, { preserveScroll: true, preserveState: true });
    };
}

function TablePage<T>({ page, empty, onPage, children }: { page: Paged<T>; empty: string; onPage: (page: number) => void; children: React.ReactNode }) {
    return (
        <Card className="overflow-hidden p-0">
            {page.data.length === 0 ? <EmptyState title={empty} /> : (
                <>
                    {children}
                    <div className="border-t border-(--app-border) px-4 py-3"><Pagination meta={page.meta} onPageChange={onPage} /></div>
                </>
            )}
        </Card>
    );
}

/** Webhook deliveries with their attempts; a delivery not yet delivered can be sent again. */
export function DeliveriesPanel({ page, center }: { page: Paged<DeliveryRow>; center: IntegrationCenterData }) {
    const { t } = useLocale();
    const reload = useReload(center);
    const label = (key: string) => t(`core.integration.center.deliveries.${key}`);

    return (
        <section className="space-y-3">
            <div className={cx(filterPanelClassName, 'flex flex-wrap items-end gap-3')}>
                <FilterField label={label('status')}>
                    <Select value={String(center.filters.status ?? '')} onChange={(event) => reload({ status: event.target.value })}>
                        <option value="">{label('allStatuses')}</option>
                        {['pending', 'delivered', 'failed'].map((status) => <option key={status} value={status}>{label(`statuses.${status}`)}</option>)}
                    </Select>
                </FilterField>
                <FilterField label={label('webhook')}>
                    <Select value={String(center.filters.webhook ?? '')} onChange={(event) => reload({ webhook: event.target.value })}>
                        <option value="">{label('allWebhooks')}</option>
                        {center.options.webhooks.map((webhook) => <option key={webhook.id} value={webhook.id}>{webhook.name}</option>)}
                    </Select>
                </FilterField>
            </div>
            <TablePage page={page} empty={label('empty')} onPage={(number) => reload({ page: number })}>
                <Table>
                    <THead>
                        <Tr>
                            <Th>{label('created')}</Th>
                            <Th>{label('webhook')}</Th>
                            <Th>{label('event')}</Th>
                            <Th>{label('status')}</Th>
                            <Th align="right">{label('attempts')}</Th>
                            <Th>{label('response')}</Th>
                            <Th>{label('nextAttempt')}</Th>
                            <Th align="right"><span className="sr-only">{label('retry')}</span></Th>
                        </Tr>
                    </THead>
                    <TBody>
                        {page.data.map((delivery) => (
                            <Tr key={delivery.id}>
                                <Td muted><LocalizedDateDisplay value={delivery.created_at} withTime /></Td>
                                <Td>{delivery.endpoint_name}</Td>
                                <Td><code className="text-xs">{delivery.event_type}</code><div className="font-mono text-xs text-(--app-muted-foreground)">{delivery.entity_id}</div></Td>
                                <Td><StatusBadge status={delivery.status} label={label(`statuses.${delivery.status}`)} /></Td>
                                <Td numeric>{delivery.attempts}</Td>
                                <Td muted>
                                    {delivery.response_code ?? '—'}
                                    {delivery.error && <div className="max-w-xs truncate text-xs text-red-600 dark:text-red-400" title={delivery.error}>{delivery.error}</div>}
                                </Td>
                                <Td muted><LocalizedDateDisplay value={delivery.status === 'delivered' ? delivery.delivered_at : delivery.next_attempt_at} withTime /></Td>
                                <Td align="right">
                                    {delivery.retry_url && (
                                        <Button size="xs" variant="outline" onClick={() => router.post(delivery.retry_url as string, {}, { preserveScroll: true })}>{label('retry')}</Button>
                                    )}
                                </Td>
                            </Tr>
                        ))}
                    </TBody>
                </Table>
            </TablePage>
        </section>
    );
}

/** Every published change event, newest first; the payload opens on request. */
export function EventLogPanel({ page, center }: { page: Paged<EventRow>; center: IntegrationCenterData }) {
    const { t } = useLocale();
    const reload = useReload(center);
    const label = (key: string) => t(`core.integration.center.events.${key}`);

    return (
        <section className="space-y-3">
            <div className={cx(filterPanelClassName, 'flex flex-wrap items-end gap-3')}>
                <FilterField label={label('type')}>
                    <Select value={String(center.filters.type ?? '')} onChange={(event) => reload({ type: event.target.value })}>
                        <option value="">{label('allTypes')}</option>
                        {center.options.event_types.map((type) => <option key={type} value={type}>{type}</option>)}
                    </Select>
                </FilterField>
            </div>
            <TablePage page={page} empty={label('empty')} onPage={(number) => reload({ page: number })}>
                <Table>
                    <THead>
                        <Tr>
                            <Th>{label('occurredAt')}</Th>
                            <Th>{label('type')}</Th>
                            <Th>{label('entity')}</Th>
                            <Th align="right">{label('version')}</Th>
                            <Th>{label('data')}</Th>
                        </Tr>
                    </THead>
                    <TBody>
                        {page.data.map((event) => (
                            <Tr key={event.id}>
                                <Td muted><LocalizedDateDisplay value={event.occurred_at} withTime /></Td>
                                <Td><code className="text-xs">{event.event_type}</code></Td>
                                <Td>{event.entity_type}<div className="font-mono text-xs text-(--app-muted-foreground)">{event.entity_id}</div></Td>
                                <Td numeric>{event.payload_version}</Td>
                                <Td>
                                    <details>
                                        <summary className="cursor-pointer text-xs text-primary">{label('showData')}</summary>
                                        <pre className="mt-2 max-h-64 max-w-md overflow-auto rounded-control bg-(--app-surface-muted) p-2 text-xs">{JSON.stringify(event.data, null, 2)}</pre>
                                    </details>
                                </Td>
                            </Tr>
                        ))}
                    </TBody>
                </Table>
            </TablePage>
        </section>
    );
}

/** The API call log: who called what, with the answer and the refusal code. */
export function ApiCallsPanel({ page, center }: { page: Paged<ApiCallRow>; center: IntegrationCenterData }) {
    const { t } = useLocale();
    const reload = useReload(center);
    const label = (key: string) => t(`core.integration.center.logs.${key}`);

    return (
        <section className="space-y-3">
            <div className={cx(filterPanelClassName, 'flex flex-wrap items-end gap-3')}>
                <FilterField label={label('client')}>
                    <Select value={String(center.filters.client ?? '')} onChange={(event) => reload({ client: event.target.value })}>
                        <option value="">{label('allClients')}</option>
                        {center.options.clients.map((client) => <option key={client.uuid} value={client.uuid}>{client.name}</option>)}
                    </Select>
                </FilterField>
                <FilterField label={label('errorsOnly')}>
                    <label className="flex h-(--control-h-md) items-center gap-2 text-sm">
                        <input type="checkbox" className="h-4 w-4 rounded-sm border-(--app-border-strong) text-primary focus:ring-primary"
                            checked={Boolean(center.filters.errors)} onChange={(event) => reload({ errors: event.target.checked ? 1 : null })} />
                        {label('errorsOnlyHint')}
                    </label>
                </FilterField>
            </div>
            <TablePage page={page} empty={label('empty')} onPage={(number) => reload({ page: number })}>
                <Table>
                    <THead>
                        <Tr>
                            <Th>{label('time')}</Th>
                            <Th>{label('client')}</Th>
                            <Th>{label('request')}</Th>
                            <Th>{label('status')}</Th>
                            <Th align="right">{label('duration')}</Th>
                            <Th>{label('ip')}</Th>
                            <Th>{label('requestId')}</Th>
                        </Tr>
                    </THead>
                    <TBody>
                        {page.data.map((log) => (
                            <Tr key={log.id}>
                                <Td muted><LocalizedDateDisplay value={log.created_at} withTime /></Td>
                                <Td>{log.client_name ?? '—'}</Td>
                                <Td><code className="text-xs">{log.method} {log.route}</code></Td>
                                <Td>
                                    <Badge tone={log.status >= 500 ? 'danger' : log.status >= 400 ? 'warning' : 'success'}>{log.status}</Badge>
                                    {log.denial_code && <div className="font-mono text-xs text-(--app-muted-foreground)">{log.denial_code}</div>}
                                </Td>
                                <Td numeric>{log.duration_ms === null ? '—' : `${log.duration_ms} ms`}</Td>
                                <Td muted>{log.ip ?? '—'}</Td>
                                <Td muted><span className="font-mono text-xs">{log.request_id ?? '—'}</span></Td>
                            </Tr>
                        ))}
                    </TBody>
                </Table>
            </TablePage>
        </section>
    );
}
