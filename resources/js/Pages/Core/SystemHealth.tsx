import { Head, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { useLocale } from '@/hooks/useLocale';
import { Button, Card, EmptyState } from '@euisis/ui';

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
                        <Card key={check.key} className="p-4">
                            <div className="flex items-center justify-between gap-2">
                                <h2 className="font-semibold text-(--app-foreground)">{label(`checks.${check.key}`)}</h2>
                                <StatusBadge status={LEVEL[check.status].tone} label={label(`levels.${LEVEL[check.status].key}`)} />
                            </div>
                            <dl className="mt-3 space-y-1 text-xs">
                                {Object.entries(check.details).map(([key, value]) => (
                                    <div key={key} className="flex justify-between gap-3"><dt className="text-(--app-muted-foreground)">{key}</dt><dd className="text-right font-mono">{show(value)}</dd></div>
                                ))}
                            </dl>
                        </Card>
                    ))}
                </div>

                <Card>
                    <div className="border-b border-(--app-border) px-5 py-3"><h2 className="font-semibold text-(--app-foreground)">{label('failedJobs')}</h2></div>
                    {failed_jobs.length === 0 ? <EmptyState title={label('noFailedJobs')} /> : (
                        <ul className="divide-y divide-(--app-border) text-sm">
                            {failed_jobs.map((job) => (
                                <li key={job.uuid} className="flex flex-wrap items-start justify-between gap-3 px-5 py-3">
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
                </Card>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card className="p-4">
                        <h2 className="font-semibold text-(--app-foreground)">{label('slowRequests')} (≥ {observability.threshold_ms} ms)</h2>
                        <Figures rows={observability.slow_requests} empty={label('none')} />
                    </Card>
                    <Card className="p-4">
                        <h2 className="font-semibold text-(--app-foreground)">{label('jobs')}</h2>
                        <Figures rows={observability.jobs} empty={label('none')} />
                    </Card>
                    <Card className="p-4 lg:col-span-2">
                        <h2 className="font-semibold text-(--app-foreground)">{label('apiRoutes')}</h2>
                        <p className="mt-1 text-xs text-(--app-muted-foreground)">{label('verification')}: {observability.verification.calls} · {observability.verification.mean_ms} ms · max {observability.verification.max_ms} ms</p>
                        {observability.api_routes.length === 0 ? <p className="mt-2 text-sm text-(--app-muted-foreground)">{label('none')}</p> : (
                            <table className="mt-2 w-full text-left text-xs">
                                <thead><tr className="text-(--app-muted-foreground)"><th className="py-1">{label('route')}</th><th>{label('calls')}</th><th>{label('mean')}</th><th>{label('max')}</th><th>{label('errors')}</th></tr></thead>
                                <tbody>{observability.api_routes.map((r) => <tr key={r.route}><td className="py-1 font-mono">{r.route}</td><td>{r.calls}</td><td>{r.mean_ms}</td><td>{r.max_ms}</td><td>{r.errors}</td></tr>)}</tbody>
                            </table>
                        )}
                    </Card>
                </div>

                <Card className="overflow-x-auto">
                    <div className="border-b border-(--app-border) px-5 py-3">
                        <h2 className="font-semibold text-(--app-foreground)">{label('configuration')}</h2>
                        <p className="mt-1 text-xs text-(--app-muted-foreground)">{label('configurationNote')}</p>
                    </div>
                    <table className="w-full min-w-[40rem] text-left text-sm">
                        <tbody className="divide-y divide-(--app-border)">
                            {configuration.map((row) => (
                                <tr key={row.key}>
                                    <td className="px-5 py-2 font-mono text-xs">{row.key}</td>
                                    <td className="px-2 py-2"><StatusBadge status={row.status === 'pass' ? 'active' : row.status === 'fail' ? 'danger' : 'warning'} label={label(`config.${row.status}`)} /></td>
                                    <td className="px-2 py-2 font-mono text-xs">{row.value}</td>
                                    <td className="px-2 py-2 text-xs text-(--app-muted-foreground)">{row.advice}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}

function Figures({ rows, empty }: { rows: Figure[]; empty: string }) {
    if (rows.length === 0) return <p className="mt-2 text-sm text-(--app-muted-foreground)">{empty}</p>;

    return (
        <table className="mt-2 w-full text-left text-xs">
            <tbody>{rows.map((row) => <tr key={row.name}><td className="py-1 font-mono">{row.name}</td><td className="text-right">{row.count}×</td><td className="text-right">{row.mean_ms} ms</td><td className="text-right">max {row.max_ms} ms</td>{row.failed !== undefined && <td className="text-right">{row.failed} ✗</td>}</tr>)}</tbody>
        </table>
    );
}
