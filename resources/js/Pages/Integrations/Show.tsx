import { FormEvent } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import TokenReveal from '@/Components/apiManagement/TokenReveal';
import AppActionMenu from '@/Components/ui/AppActionMenu';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';
import StatusBadge from '@/Components/StatusBadge';
import { Button, Card, FormField, Input, ListPagination, Select, Table, TBody, THead, Td, Textarea, Th, Tr, cx } from '@/Components/ui';

interface DeliveryRow {
    id: string;
    event_id: string;
    event_type: string | null;
    generation: number;
    status: string;
    attempts: number;
    last_http_status: number | null;
    last_error_category: string | null;
    last_latency_ms: number | null;
    next_attempt_at: string | null;
    last_attempt_at: string | null;
    created_at: string | null;
}

interface Props {
    subscription: {
        id: string;
        name: string;
        application: string | null;
        application_code: string | null;
        callback_url: string;
        event_types: string[];
        status: string;
        health: string;
        consecutive_failures: number;
        last_success_at: string | null;
        last_failure_at: string | null;
        last_failure_category: string | null;
        secret_rotated_at: string | null;
        previous_secret_expires_at: string | null;
        start_after_position: number;
    };
    deliveries: { data: DeliveryRow[]; current_page: number; last_page: number; total: number } | null;
    statusFilter: string;
    replays: { id: string; by: string | null; reason: string; event_count: number; created_at: string | null }[];
    can: { manage: boolean; deliveries: boolean; replay: boolean; payload: boolean };
}

const HEALTH_TONE: Record<string, string> = { healthy: 'active', degraded: 'pending', failing: 'failed', unknown: 'inactive' };
const DELIVERY_TONE: Record<string, string> = { succeeded: 'active', dead: 'failed', retrying: 'pending', pending: 'pending', in_flight: 'pending', skipped: 'inactive' };
const STATUSES = ['', 'succeeded', 'retrying', 'dead', 'pending', 'skipped'];

export default function IntegrationsShow({ subscription, deliveries, statusFilter, replays, can }: Props) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const flash = (usePage().props as { flash?: { generated_secret?: string } }).flash;
    const replay = useForm({ mode: 'failed', from: '', to: '', event_ids: '', reason: '' });

    async function rotate() {
        const { confirmed } = await confirm({ title: t('integrations.rotateConfirmTitle'), description: t('integrations.rotateConfirmBody'), confirmLabel: t('integrations.rotateSecret'), cancelLabel: t('common.cancel'), variant: 'warning' });
        if (confirmed) router.post(route('integrations.subscriptions.rotate', subscription.id), {}, { preserveScroll: true });
    }

    async function toggleStatus() {
        if (subscription.status === 'active') {
            const { confirmed } = await confirm({ title: t('integrations.disableConfirmTitle'), description: t('integrations.disableConfirmBody'), confirmLabel: t('integrations.disable'), cancelLabel: t('common.cancel'), variant: 'danger' });
            if (!confirmed) return;
        }
        router.post(route('integrations.subscriptions.status', subscription.id), { status: subscription.status === 'active' ? 'disabled' : 'active' }, { preserveScroll: true });
    }

    function submitReplay(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        replay.post(route('integrations.subscriptions.replay', subscription.id), { preserveScroll: true, onSuccess: () => replay.reset() });
    }

    const actions = can.manage ? (
        <div className="flex flex-wrap gap-2">
            <Button variant="outline" size="sm" onClick={() => router.visit(route('integrations.subscriptions.edit', subscription.id))}>{t('common.edit')}</Button>
            <Button variant="outline" size="sm" onClick={() => router.post(route('integrations.subscriptions.test', subscription.id), {}, { preserveScroll: true })}>{t('integrations.sendTest')}</Button>
            <Button variant="outline" size="sm" onClick={rotate}>{t('integrations.rotateSecret')}</Button>
            <Button variant={subscription.status === 'active' ? 'destructiveOutline' : 'primary'} size="sm" onClick={toggleStatus}>
                {subscription.status === 'active' ? t('integrations.disable') : t('integrations.enable')}
            </Button>
        </div>
    ) : undefined;

    return (
        <AuthenticatedLayout header={<PageHeader title={subscription.name} description={subscription.application ?? undefined} backHref={route('integrations.index')} actions={actions} />}>
            <Head title={subscription.name} />

            <div className="space-y-6">
                {flash?.generated_secret && (
                    <TokenReveal key={flash.generated_secret} token={flash.generated_secret} title={t('integrations.secretRevealTitle')} hint={t('integrations.secretRevealHint')} />
                )}

                <Card>
                    <dl className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <div>
                            <dt className="text-xs text-(--app-muted-foreground)">{t('common.status')}</dt>
                            <dd className="mt-1"><StatusBadge status={subscription.status === 'active' ? 'active' : 'inactive'} label={t(`integrations.status_${subscription.status}`)} /></dd>
                        </div>
                        <div>
                            <dt className="text-xs text-(--app-muted-foreground)">{t('integrations.health')}</dt>
                            <dd className="mt-1"><StatusBadge status={HEALTH_TONE[subscription.health] ?? 'inactive'} label={t(`integrations.health_${subscription.health}`)} /></dd>
                        </div>
                        <div>
                            <dt className="text-xs text-(--app-muted-foreground)">{t('integrations.lastSuccess')}</dt>
                            <dd className="mt-1 text-sm text-(--app-foreground)">{subscription.last_success_at ?? t('integrations.never')}</dd>
                        </div>
                        <div>
                            <dt className="text-xs text-(--app-muted-foreground)">{t('integrations.lastFailure')}</dt>
                            <dd className="mt-1 text-sm text-(--app-foreground)">
                                {subscription.last_failure_at ?? t('integrations.never')}
                                {subscription.last_failure_category && <span className="ml-1 font-mono text-xs text-(--app-muted-foreground)">({subscription.last_failure_category})</span>}
                            </dd>
                        </div>
                        <div className="sm:col-span-2">
                            <dt className="text-xs text-(--app-muted-foreground)">{t('integrations.callbackUrl')}</dt>
                            <dd className="mt-1 break-all font-mono text-xs text-(--app-foreground)">{subscription.callback_url}</dd>
                        </div>
                        <div>
                            <dt className="text-xs text-(--app-muted-foreground)">{t('integrations.secretRotatedAt')}</dt>
                            <dd className="mt-1 text-sm text-(--app-foreground)">{subscription.secret_rotated_at ?? '—'}</dd>
                            {subscription.previous_secret_expires_at && (
                                <dd className="mt-1 text-xs text-amber-700 dark:text-amber-400">{t('integrations.previousSecretUntil')}: {subscription.previous_secret_expires_at}</dd>
                            )}
                        </div>
                        <div>
                            <dt className="text-xs text-(--app-muted-foreground)">{t('integrations.startAfter')}</dt>
                            <dd className="mt-1 text-sm tabular-nums text-(--app-foreground)">{subscription.start_after_position}</dd>
                        </div>
                    </dl>
                    <div className="mt-4">
                        <p className="text-xs text-(--app-muted-foreground)">{t('integrations.eventTypes')}</p>
                        <div className="mt-1 flex flex-wrap gap-1.5">
                            {subscription.event_types.map((type) => <code key={type} className="rounded-sm bg-(--app-surface-muted) px-1.5 py-0.5 font-mono text-xs">{type}</code>)}
                        </div>
                    </div>
                </Card>

                {deliveries && (
                    <Card className="overflow-hidden p-0">
                        <div className="flex flex-col gap-3 border-b border-(--app-border) px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
                            <h2 className="text-base font-semibold text-(--app-foreground)">{t('integrations.deliveries')}</h2>
                            <nav aria-label={t('common.status')} className="inline-flex w-fit flex-wrap rounded-control bg-(--app-surface-muted) p-0.5">
                                {STATUSES.map((status) => (
                                    <Link key={status || 'all'} preserveScroll href={route('integrations.subscriptions.show', subscription.id) + (status ? `?status=${status}` : '')}
                                        aria-current={statusFilter === status ? 'page' : undefined}
                                        className={cx('rounded-[calc(var(--radius-control)-2px)] px-2.5 py-1 text-xs font-medium',
                                            statusFilter === status ? 'bg-(--app-surface) text-(--app-foreground) shadow-xs' : 'text-(--app-muted-foreground) hover:text-(--app-foreground)')}>
                                        {status ? t(`integrations.delivery_${status}`) : t('integrations.allStatuses')}
                                    </Link>
                                ))}
                            </nav>
                        </div>
                        {deliveries.data.length === 0 ? (
                            <p className="px-5 py-6 text-sm text-(--app-muted-foreground)">{t('integrations.noDeliveries')}</p>
                        ) : (
                            <Table>
                                <THead>
                                    <Tr>
                                        <Th>{t('integrations.event')}</Th>
                                        <Th align="right">{t('integrations.generation')}</Th>
                                        <Th>{t('common.status')}</Th>
                                        <Th align="right">{t('integrations.attempts')}</Th>
                                        <Th>{t('integrations.httpStatus')}</Th>
                                        <Th>{t('integrations.errorCategory')}</Th>
                                        <Th align="right">{t('integrations.latencyMs')}</Th>
                                        <Th>{t('integrations.lastAttempt')}</Th>
                                        <Th>{t('integrations.nextAttempt')}</Th>
                                        <Th><span className="sr-only">{t('common.actions')}</span></Th>
                                    </Tr>
                                </THead>
                                <TBody>
                                    {deliveries.data.map((row) => (
                                        <Tr key={row.id}>
                                            <Td><span className="font-mono text-xs">{row.event_type}</span><div className="font-mono text-[11px] text-(--app-muted-foreground)">{row.event_id}</div></Td>
                                            <Td numeric>{row.generation}</Td>
                                            <Td><StatusBadge status={DELIVERY_TONE[row.status] ?? 'inactive'} label={t(`integrations.delivery_${row.status}`)} /></Td>
                                            <Td numeric>{row.attempts}</Td>
                                            <Td numeric>{row.last_http_status ?? '—'}</Td>
                                            <Td className="font-mono text-xs">{row.last_error_category ?? '—'}</Td>
                                            <Td numeric>{row.last_latency_ms === null ? '—' : `${row.last_latency_ms} ms`}</Td>
                                            <Td muted>{row.last_attempt_at ?? '—'}</Td>
                                            <Td muted>{row.next_attempt_at ?? '—'}</Td>
                                            <Td align="right">
                                                <AppActionMenu items={[{ label: t('integrations.payloadAction'), href: route('integrations.events.show', row.event_id), show: can.payload }]} />
                                            </Td>
                                        </Tr>
                                    ))}
                                </TBody>
                            </Table>
                        )}
                        <div className="px-5 pb-4"><ListPagination meta={deliveries} /></div>
                    </Card>
                )}

                {can.replay && (
                    <Card>
                        <h2 className="text-base font-semibold text-(--app-foreground)">{t('integrations.replay')}</h2>
                        <p className="mt-0.5 text-sm text-(--app-muted-foreground)">{t('integrations.replayHint')}</p>
                        <form onSubmit={submitReplay} className="mt-4 grid gap-4 sm:grid-cols-2">
                            <FormField label={t('integrations.replayMode')} error={replay.errors.mode}>
                                {({ id, describedBy, invalid }) => (
                                    <Select id={id} aria-describedby={describedBy} aria-invalid={invalid} value={replay.data.mode} onChange={(event) => replay.setData('mode', event.target.value)}>
                                        {['failed', 'range', 'events'].map((mode) => <option key={mode} value={mode}>{t(`integrations.replayMode_${mode}`)}</option>)}
                                    </Select>
                                )}
                            </FormField>
                            {replay.data.mode === 'range' && (
                                <div className="grid grid-cols-2 gap-3">
                                    <FormField label={t('integrations.replayFrom')} error={replay.errors.from}>
                                        {({ id, describedBy, invalid }) => <Input id={id} aria-describedby={describedBy} aria-invalid={invalid} type="datetime-local" value={replay.data.from} onChange={(event) => replay.setData('from', event.target.value)} />}
                                    </FormField>
                                    <FormField label={t('integrations.replayTo')} error={replay.errors.to}>
                                        {({ id, describedBy, invalid }) => <Input id={id} aria-describedby={describedBy} aria-invalid={invalid} type="datetime-local" value={replay.data.to} onChange={(event) => replay.setData('to', event.target.value)} />}
                                    </FormField>
                                </div>
                            )}
                            {replay.data.mode === 'events' && (
                                <FormField className="sm:col-span-2" label={t('integrations.replayEventIds')} description={t('integrations.replayEventIdsHint')} error={replay.errors.event_ids}>
                                    {({ id, describedBy, invalid }) => <Textarea id={id} aria-describedby={describedBy} aria-invalid={invalid} rows={3} className="font-mono text-xs" value={replay.data.event_ids} onChange={(event) => replay.setData('event_ids', event.target.value)} />}
                                </FormField>
                            )}
                            <FormField className="sm:col-span-2" label={t('integrations.replayReason')} required error={replay.errors.reason ?? (replay.errors as Record<string, string | undefined>).criteria}>
                                {({ id, describedBy, invalid }) => <Input id={id} aria-describedby={describedBy} aria-invalid={invalid} maxLength={500} required value={replay.data.reason} onChange={(event) => replay.setData('reason', event.target.value)} />}
                            </FormField>
                            <div className="sm:col-span-2 flex justify-end">
                                <Button type="submit" variant="primary" loading={replay.processing}>{t('integrations.replaySubmit')}</Button>
                            </div>
                        </form>
                    </Card>
                )}

                {replays.length > 0 && (
                    <Card className="overflow-hidden p-0">
                        <div className="border-b border-(--app-border) px-5 py-4">
                            <h2 className="text-base font-semibold text-(--app-foreground)">{t('integrations.replayHistory')}</h2>
                        </div>
                        <Table>
                            <THead>
                                <Tr>
                                    <Th>{t('integrations.created')}</Th>
                                    <Th>{t('integrations.replayBy')}</Th>
                                    <Th>{t('integrations.replayReason')}</Th>
                                    <Th align="right">{t('integrations.replayCount')}</Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {replays.map((row) => (
                                    <Tr key={row.id}>
                                        <Td muted>{row.created_at}</Td>
                                        <Td>{row.by ?? '—'}</Td>
                                        <Td>{row.reason}</Td>
                                        <Td numeric>{row.event_count}</Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    </Card>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
