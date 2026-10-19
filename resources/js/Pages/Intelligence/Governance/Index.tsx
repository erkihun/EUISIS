import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { useSituationLabels } from '@/Components/SituationRoom/useSituationLabels';
import { Card, CardTitle, ListPagination, TBody, THead, Table, Td, Th, Tr, badgeClassName, type Tone } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import type { PageProps } from '@/types';
import type { SrLabels } from '@/types/situationRoom';
import type { Paginated } from '@/types/intelligenceSystem';

type Run = {
    id: string; feature: string; prompt_key: string; prompt_version: string; provider: string; model: string | null; status: string; user: string | null;
    subject_type: string | null; subject_id: string | null; input_chars: number; output_tokens: number | null; duration_ms: number; error: string | null; created_at: string;
};

type Props = {
    labels: SrLabels;
    gateway: { enabled: boolean; provider: string; model: string | null; configured: boolean; breaker_open: boolean; calls_today: number; daily_cap: number; outbound_classes: string[]; available: boolean };
    features: Record<string, boolean | number | string | null>;
    prompts: Array<{ key: string; version: string; feature: string; data_classes: string[] }>;
    data_classes: string[];
    runs: Paginated<Run>;
    run_counts: Record<string, number>;
    reviews: Record<'rule' | 'ai', Record<string, number>>;
    forecast_quality: Record<string, { evaluated: number; mae: number | null; mape: number | null; last_generated_at: string | null }>;
    inventory: Record<string, number | string | null>;
    can: { manage: boolean; manage_settings: boolean };
};

const RUN_TONE: Record<string, Tone> = { ok: 'success', failed: 'danger', invalid: 'warning', refused: 'warning', unavailable: 'neutral' };

function Section({ title, children, aside }: { title: string; children: React.ReactNode; aside?: React.ReactNode }) {
    return (
        <Card className="p-0">
            <div className="flex items-center justify-between gap-3 border-b border-(--app-border) px-(--card-padding) py-3"><CardTitle>{title}</CardTitle>{aside}</div>
            <div className="p-(--card-padding)">{children}</div>
        </Card>
    );
}

function Facts({ rows }: { rows: Array<[string, React.ReactNode]> }) {
    return (
        <dl className="grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
            {rows.map(([label, value]) => <div key={label}><dt className="text-(--app-muted-foreground)">{label}</dt><dd className="font-medium text-(--app-foreground)">{value}</dd></div>)}
        </dl>
    );
}

/** AI & data governance: read-only account of what the module is, reads, sends, and how its advice has been judged. */
export default function GovernanceIndex({ gateway, features, prompts, data_classes, runs, run_counts, reviews, forecast_quality, inventory, can }: PageProps<Props>) {
    const { t } = useLocale();
    const { sr } = useSituationLabels();
    const g = (key: string) => t(`intelligence.governance.${key}`);
    const yesNo = (v: unknown) => (v ? t('common.yes') : t('common.no'));
    const title = t('intelligence.areas.governance.title');

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={t('intelligence.areas.governance.description')} backHref={route('intelligence.index')}
            actions={can.manage_settings ? <Link href={route('system-settings.index', { tab: 'intelligence' })} className="text-sm font-medium text-primary hover:underline">{g('openSettings')}</Link> : undefined} />}>
            <Head title={`${title} · ${t('intelligence.title')}`} />

            <div className="grid gap-5 xl:grid-cols-2">
                <Section title={g('gateway')} aside={<span className={badgeClassName(gateway.available ? 'success' : 'neutral')}>{gateway.available ? g('available') : g('notAvailable')}</span>}>
                    <Facts rows={[
                        [g('enabled'), yesNo(gateway.enabled)], [g('provider'), gateway.provider], [g('model'), gateway.model ?? '—'], [g('configured'), yesNo(gateway.configured)],
                        [g('breaker'), gateway.breaker_open ? g('breakerOpen') : g('breakerClosed')], [g('callsToday'), `${gateway.calls_today} / ${gateway.daily_cap}`],
                        [g('outboundClasses'), gateway.outbound_classes.length ? gateway.outbound_classes.map((c) => sr(`intelligence.data_classes.${c}`)).join(', ') : '—'],
                        [g('maxInput'), `${features.ai_max_input_chars}`], [g('timeout'), `${features.ai_timeout_seconds} s`],
                    ]} />
                    <p className="mt-3 text-xs text-(--app-muted-foreground)">{g('secretsNote')}</p>
                </Section>

                <Section title={g('features')}>
                    <Facts rows={[
                        [g('f.ai_enabled'), yesNo(features.ai_enabled)], [g('f.knowledge_enabled'), yesNo(features.knowledge_enabled)], [g('f.forecasting_enabled'), yesNo(features.forecasting_enabled)],
                        [g('f.runbook_execution_enabled'), yesNo(features.runbook_execution_enabled)], [g('f.telemetry_enabled'), yesNo(features.telemetry_enabled)],
                        [g('f.retention_days'), `${features.retention_days}`], [g('f.baseline_window_days'), `${features.baseline_window_days}`], [g('f.anomaly_sigma'), `${features.anomaly_sigma}σ`],
                        [g('f.notify_min_severity'), features.notify_min_severity === 'none' ? '—' : sr(`intelligence.severity.${features.notify_min_severity}`)],
                    ]} />
                </Section>

                <Section title={g('prompts')}>
                    <Table>
                        <THead><Tr><Th>{g('promptKey')}</Th><Th>{g('version')}</Th><Th>{g('feature')}</Th><Th>{g('dataClasses')}</Th></Tr></THead>
                        <TBody>{prompts.map((p) => <Tr key={p.key}><Td><span className="font-mono text-xs">{p.key}</span></Td><Td muted>{p.version}</Td><Td muted>{p.feature}</Td><Td muted>{p.data_classes.map((c) => sr(`intelligence.data_classes.${c}`)).join(', ')}</Td></Tr>)}</TBody>
                    </Table>
                    <p className="mt-3 text-xs text-(--app-muted-foreground)">{g('classesNote')}: {data_classes.map((c) => sr(`intelligence.data_classes.${c}`)).join(' · ')}</p>
                </Section>

                <Section title={g('inventory')}>
                    <Facts rows={[
                        [g('i.signals'), `${inventory.signals}`], [g('i.latest_signal_at'), inventory.latest_signal_at ? <LocalizedDateDisplay value={String(inventory.latest_signal_at)} withTime /> : '—'],
                        [g('i.incidents_active'), `${inventory.incidents_active}`], [g('i.alerts_active'), `${inventory.alerts_active}`], [g('i.recommendations_pending'), `${inventory.recommendations_pending}`],
                        [g('i.ai_runs'), `${inventory.ai_runs}`], [g('i.knowledge_documents'), inventory.knowledge_documents === null ? '—' : `${inventory.knowledge_documents}`], [g('i.knowledge_questions'), inventory.knowledge_questions === null ? '—' : `${inventory.knowledge_questions}`],
                    ]} />
                </Section>

                <Section title={g('reviews')}>
                    <Table>
                        <THead><Tr><Th>{g('generatedBy')}</Th>{Object.keys(reviews.rule).map((k) => <Th key={k} align="right">{sr(`intelligence.review.${k}`)}</Th>)}</Tr></THead>
                        <TBody>
                            {(['rule', 'ai'] as const).map((who) => <Tr key={who}><Td>{sr(`intelligence.sources_short.${who}`)}</Td>{Object.values(reviews[who]).map((v, i) => <Td key={i} numeric>{v}</Td>)}</Tr>)}
                        </TBody>
                    </Table>
                </Section>

                <Section title={g('forecastQuality')}>
                    {Object.keys(forecast_quality).length === 0 ? <p className="text-sm text-(--app-muted-foreground)">{g('noForecasts')}</p> : (
                        <Table>
                            <THead><Tr><Th>{g('metric')}</Th><Th align="right">{g('evaluated')}</Th><Th align="right">MAE</Th><Th align="right">MAPE</Th><Th>{g('lastSnapshot')}</Th></Tr></THead>
                            <TBody>
                                {Object.entries(forecast_quality).map(([metric, q]) => (
                                    <Tr key={metric}><Td>{sr(`intelligence.forecast_metrics.${metric}`)}</Td><Td numeric>{q.evaluated}</Td><Td numeric>{q.mae ?? '—'}</Td><Td numeric>{q.mape === null ? '—' : `${q.mape}%`}</Td><Td muted>{q.last_generated_at ? <LocalizedDateDisplay value={q.last_generated_at} withTime /> : '—'}</Td></Tr>
                                ))}
                            </TBody>
                        </Table>
                    )}
                </Section>
            </div>

            <div className="mt-5">
                <Section title={g('runs')} aside={<span className="text-xs text-(--app-muted-foreground)">{g('last30')}: {run_counts.total} · {g('ok')} {run_counts.ok} · {g('failed')} {run_counts.failed + run_counts.invalid} · {g('refused')} {run_counts.refused + run_counts.unavailable}</span>}>
                    {runs.data.length === 0 ? <p className="text-sm text-(--app-muted-foreground)">{g('noRuns')}</p> : (
                        <Table>
                            <THead><Tr><Th>{g('when')}</Th><Th>{g('feature')}</Th><Th>{g('promptKey')}</Th><Th>{g('provider')}</Th><Th>{g('status')}</Th><Th>{g('user')}</Th><Th align="right">{g('inputChars')}</Th><Th align="right">{g('outputTokens')}</Th><Th align="right">ms</Th></Tr></THead>
                            <TBody>
                                {runs.data.map((r) => (
                                    <Tr key={r.id}>
                                        <Td muted><LocalizedDateDisplay value={r.created_at} withTime /></Td>
                                        <Td>{r.feature}</Td>
                                        <Td><span className="font-mono text-xs">{r.prompt_key}@{r.prompt_version}</span></Td>
                                        <Td muted>{r.provider}{r.model ? ` · ${r.model}` : ''}</Td>
                                        <Td><span className={badgeClassName(RUN_TONE[r.status] ?? 'neutral')}>{r.status}</span>{r.error && <span className="ml-1 text-xs text-(--app-muted-foreground)" title={r.error}>ⓘ</span>}</Td>
                                        <Td muted>{r.user ?? t('intelligence.incidents.system')}</Td>
                                        <Td numeric>{r.input_chars}</Td>
                                        <Td numeric>{r.output_tokens ?? '—'}</Td>
                                        <Td numeric>{r.duration_ms}</Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    )}
                    <ListPagination meta={runs} bare />
                </Section>
            </div>
        </AuthenticatedLayout>
    );
}
