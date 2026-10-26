import { Head, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { useLocale } from '@/hooks/useLocale';
import { Table, TableCard, TBody, Td, Th, THead, Tr } from '@/Components/ui';
import { Button, Card, CardHeader, CardTitle, EmptyState } from '@euisis/ui';

type Check = { key: string; status: 'ok' | 'warning' | 'error' | 'unknown'; details: Record<string, unknown> };
type FailedJob = { uuid: string; queue: string; connection: string; failed_at: string; job: string; error: string; retry_url: string | null; forget_url: string | null };
type Figure = { name: string; count: number; mean_ms: number; max_ms: number; failed?: number };
type ConfigRow = { key: string; status: 'pass' | 'warning' | 'fail'; value: string; advice: string };

// The service's statuses shown in the brief's words: healthy, warning, critical, unknown.
const LEVEL: Record<Check['status'], { tone: string; key: string }> = {
    ok: { tone: 'active', key: 'healthy' }, warning: { tone: 'warning', key: 'warning' }, error: { tone: 'danger', key: 'critical' }, unknown: { tone: 'inactive', key: 'unknown' },
};

const show = (value: unknown): string => {
    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'boolean') return value ? '✓' : '✗';
    if (typeof value === 'object') return Object.entries(value as Record<string, unknown>).map(([k, v]) => `${k}: ${typeof v === 'object' ? JSON.stringify(v) : String(v ?? '—')}`).join(' · ');
    return String(value);
};

/**
 * Core › System Health (ADR 0011): real measurements only. What the Core
 * cannot see — a backup not integrated, a scheduler never seen — is
 * "unknown", never healthy.
 */
export default function SystemHealth({ checks, failed_jobs, observability, configuration }: {
    checks: Check[];
    failed_jobs: FailedJob[];
    observability: { slow_requests: Figure[]; jobs: Figure[]; api_routes: Array<{ route: string; calls: number; mean_ms: number; max_ms: number; errors: number }>; verification: { calls: number; mean_ms: number; max_ms: number }; threshold_ms: number };
    configuration: ConfigRow[];
}) {
    const { t } = useLocale();
    const label = (key: string) => t(`core.systemHealth.${key}`);

    return (
        <AuthenticatedLayout header={<PageHeader title={label('title')} description={label('description')} />}>
            <Head title={label('title')} />
            <div className="space-y-5">
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {checks.map((check) => (
                        <Card key={check.key}>
                            <CardHeader className="mb-3 items-center">
                                <CardTitle>{label(`checks.${check.key}`)}</CardTitle>
                                <StatusBadge status={LEVEL[check.status].tone} label={label(`levels.${LEVEL[check.status].key}`)} />
                            </CardHeader>
                            <dl className="space-y-1 text-xs">
                                {Object.entries(check.details).map(([key, value]) => (
                                    <div key={key} className="flex justify-between gap-3"><dt className="text-(--app-muted-foreground)">{key}</dt><dd className="text-right font-mono">{show(value)}</dd></div>
                                ))}
                            </dl>
                        </Card>
                    ))}
                </div>

                <TableCard title={label('failedJobs')}>
                    {failed_jobs.length === 0 ? <EmptyState title={label('noFailedJobs')} /> : (
                        <ul className="divide-y divide-(--app-border) text-sm">
                            {failed_jobs.map((job) => (
                                <li key={job.uuid} className="flex flex-wrap items-start justify-between gap-3 px-4 py-3">
                                    <span className="min-w-0"><span className="font-medium">{job.job}</span> · {job.queue} · <LocalizedDateDisplay value={job.failed_at} withTime />
                                        <span className="block truncate text-xs text-(--app-muted-foreground)">{job.error}</span></span>
                                    <span className="flex gap-2">
                                        {job.retry_url && <Button size="sm" variant="outline" onClick={() => router.post(job.retry_url as string, {}, { preserveScroll: true })}>{label('retry')}</Button>}
                                        {job.forget_url && <Button size="sm" variant="outline" onClick={() => router.post(job.forget_url as string, {}, { preserveScroll: true })}>{label('forget')}</Button>}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </TableCard>

                <div className="grid gap-4 lg:grid-cols-2">
                    <TableCard title={`${label('slowRequests')} (≥ ${observability.threshold_ms} ms)`}>
                        <Figures rows={observability.slow_requests} empty={label('none')} />
                    </TableCard>
                    <TableCard title={label('jobs')}>
                        <Figures rows={observability.jobs} empty={label('none')} />
                    </TableCard>
                    <TableCard className="lg:col-span-2" title={label('apiRoutes')} description={`${label('verification')}: ${observability.verification.calls} · ${observability.verification.mean_ms} ms · max ${observability.verification.max_ms} ms`}>
                        {observability.api_routes.length === 0 ? <EmptyState title={label('none')} /> : (
                            <Table>
                                <THead><Tr><Th>{label('route')}</Th><Th align="right">{label('calls')}</Th><Th align="right">{label('mean')}</Th><Th align="right">{label('max')}</Th><Th align="right">{label('errors')}</Th></Tr></THead>
                                <TBody>{observability.api_routes.map((r) => <Tr key={r.route}><Td className="font-mono text-xs">{r.route}</Td><Td numeric>{r.calls}</Td><Td numeric>{r.mean_ms}</Td><Td numeric>{r.max_ms}</Td><Td numeric>{r.errors}</Td></Tr>)}</TBody>
                            </Table>
                        )}
                    </TableCard>
                </div>

                <TableCard title={label('configuration')} description={label('configurationNote')}>
                    <Table className="min-w-[40rem]">
                        <TBody>
                            {configuration.map((row) => (
                                <Tr key={row.key}>
                                    <Td className="font-mono text-xs">{row.key}</Td>
                                    <Td><StatusBadge status={row.status === 'pass' ? 'active' : row.status === 'fail' ? 'danger' : 'warning'} label={label(`config.${row.status}`)} /></Td>
                                    <Td className="font-mono text-xs">{row.value}</Td>
                                    <Td muted className="text-xs">{row.advice}</Td>
                                </Tr>
                            ))}
                        </TBody>
                    </Table>
                </TableCard>
            </div>
        </AuthenticatedLayout>
    );
}

function Figures({ rows, empty }: { rows: Figure[]; empty: string }) {
    if (rows.length === 0) return <EmptyState title={empty} />;

    return (
        <Table>
            <TBody>{rows.map((row) => <Tr key={row.name}><Td className="font-mono text-xs">{row.name}</Td><Td numeric>{row.count}×</Td><Td numeric>{row.mean_ms} ms</Td><Td numeric>max {row.max_ms} ms</Td>{row.failed !== undefined && <Td numeric>{row.failed} ✗</Td>}</Tr>)}</TBody>
        </Table>
    );
}
