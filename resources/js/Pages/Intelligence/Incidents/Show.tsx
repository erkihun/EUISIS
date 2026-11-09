import { useState, type FormEvent } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { ConfidenceBadge, SeverityBadge } from '@/Components/intelligence/Badges';
import { IncidentStatusBadge, ReviewBadge, RiskBadge } from '@/Components/intelligence/StatusBadges';
import { useSituationLabels } from '@/Components/SituationRoom/useSituationLabels';
import { Alert, Button, Card, CardTitle, FormField, Select, TBody, THead, Table, Td, Textarea, Th, Tr, badgeClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import type { PageProps } from '@/types';
import type { SrLabels } from '@/types/situationRoom';
import type { FingerprintRow, IncidentDetail, IncidentEvent, RecommendationRow } from '@/types/intelligenceSystem';

type Props = {
    labels: SrLabels;
    incident: IncidentDetail;
    events: IncidentEvent[];
    recommendations: RecommendationRow[];
    fingerprints: FingerprintRow[];
    runbook: string | null;
    options: { statuses: string[]; verifications: string[] };
    assessment: Assessment | null;
    assessment_meta: { model: string | null; prompt_version: string | null; generated_at: string | null } | null;
    ai: { available: boolean };
    can: { manage: boolean; review: boolean; diagnostics: boolean; runbooks: boolean };
};

type Assessment = {
    observed_symptom: string;
    likely_causes: Array<{ cause: string; confidence: 'high' | 'medium' | 'low'; why: string }>;
    alternative_explanations: string[];
    recommended_next_checks: string[];
    suggested_areas_to_inspect: string[];
    overall_confidence: 'high' | 'medium' | 'low';
    caveats: string;
};

const CATEGORY_ORDER = ['immediate', 'short_term', 'permanent', 'verification', 'management'];

function Section({ title, children, aside }: { title: string; children: React.ReactNode; aside?: React.ReactNode }) {
    return (
        <Card className="p-0">
            <div className="flex items-center justify-between gap-3 border-b border-(--app-border) px-(--card-padding) py-3">
                <CardTitle>{title}</CardTitle>
                {aside}
            </div>
            <div className="p-(--card-padding)">{children}</div>
        </Card>
    );
}

/**
 * One incident (brief §98–99): summary, severity, status, timeline, affected
 * component, business impact, evidence, probable cause with its confidence,
 * related errors, related deployment (a correlation, never a cause),
 * recommendations, runbook, owner, resolution and verification.
 */
export default function IncidentShow({ incident, events, recommendations, fingerprints, runbook, options, assessment, assessment_meta, ai, can }: PageProps<Props>) {
    const { t } = useLocale();
    const { sr } = useSituationLabels();
    const params = incident.params as Record<string, string | number>;
    const title = `${incident.reference} · ${sr(incident.title, params)}`;
    const [explaining, setExplaining] = useState(false);
    const explain = () => router.post(route('intelligence.incidents.explain', incident.id), {}, { preserveScroll: true, onStart: () => setExplaining(true), onFinish: () => setExplaining(false) });

    const form = useForm({ status: incident.status, note: '', resolution_summary: incident.resolution_summary ?? '', verification_status: incident.verification_status ?? '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.patch(route('intelligence.incidents.update', incident.id), { preserveScroll: true, onSuccess: () => form.setData('note', '') });
    };
    const takeOwnership = () => router.patch(route('intelligence.incidents.update', incident.id), { owner: 'me' }, { preserveScroll: true });

    const text = (r: RecommendationRow, field: 'title' | 'body') => (r.generated_by === 'rule' ? sr(r[field], r.params as Record<string, string | number>) : r[field]);

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={title}
                    description={incident.impact ? sr(incident.impact, params) : undefined}
                    backHref={route('intelligence.incidents.index')}
                    actions={<div className="flex flex-wrap gap-1.5"><SeverityBadge severity={incident.severity} /><IncidentStatusBadge status={incident.status} /></div>}
                />
            }
        >
            <Head title={`${incident.reference} · ${t('intelligence.title')}`} />

            <div className="grid gap-5 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                <div className="space-y-5">
                    <Section title={t('intelligence.incidents.summary')}>
                        <dl className="grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
                            {[
                                [t('intelligence.incidents.component'), sr(`intelligence.components.${incident.component}`)],
                                [t('intelligence.incidents.endpoint'), incident.endpoint ?? '—'],
                                [t('intelligence.incidents.rule'), incident.rule],
                                [t('intelligence.incidents.occurrences'), String(incident.occurrences)],
                                [t('intelligence.incidents.owner'), incident.owner ?? '—'],
                                [t('intelligence.incidents.verification'), incident.verification_status ? sr(`intelligence.verification.${incident.verification_status}`) : '—'],
                            ].map(([label, value]) => (
                                <div key={label}><dt className="text-(--app-muted-foreground)">{label}</dt><dd className="font-medium text-(--app-foreground)">{value}</dd></div>
                            ))}
                            {([['detected_at', 'detected'], ['first_seen_at', 'firstSeen'], ['last_seen_at', 'lastSeen'], ['mitigated_at', 'mitigated'], ['resolved_at', 'resolved'], ['closed_at', 'closed']] as const).map(([field, key]) => (
                                <div key={field}><dt className="text-(--app-muted-foreground)">{t(`intelligence.incidents.${key}`)}</dt><dd className="font-medium text-(--app-foreground)"><LocalizedDateDisplay value={incident[field]} withTime /></dd></div>
                            ))}
                        </dl>
                        {incident.deployment && (
                            <Alert tone="warning" className="mt-4" title={t('intelligence.incidents.deploymentTitle')}>
                                {t('intelligence.incidents.deploymentBody')
                                    .replace('{minutes}', String(incident.deployment.minutes_before))
                                    .replace('{release}', incident.deployment.release)}
                                {incident.deployment.commit_hash && <span className="ml-1 font-mono text-xs">{incident.deployment.commit_hash.slice(0, 10)}</span>}
                            </Alert>
                        )}
                        {incident.probable_cause && (
                            <div className="mt-4 rounded-control bg-(--app-surface-muted) p-3 text-sm">
                                <div className="mb-1 flex items-center gap-2"><span className="font-medium">{t('intelligence.incidents.probableCause')}</span>{incident.cause_confidence && <ConfidenceBadge confidence={incident.cause_confidence} />}</div>
                                <p className="whitespace-pre-line text-(--app-foreground)">{incident.probable_cause}</p>
                            </div>
                        )}
                        {incident.resolution_summary && (
                            <p className="mt-4 text-sm"><span className="font-medium">{t('intelligence.incidents.resolution')}: </span>{incident.resolution_summary}</p>
                        )}
                    </Section>

                    {can.diagnostics && (assessment || ai.available) && (
                        <Section
                            title={t('intelligence.incidents.assessment.title')}
                            aside={ai.available ? <Button size="sm" variant="outline" loading={explaining} onClick={explain} title={t('intelligence.incidents.explainHint')}>{t('intelligence.incidents.explain')}</Button> : undefined}
                        >
                            {assessment ? (
                                <div className="space-y-4 text-sm">
                                    <div className="flex flex-wrap items-center gap-2 text-xs text-(--app-muted-foreground)">
                                        <ConfidenceBadge confidence={assessment.overall_confidence} />
                                        {assessment_meta && <span>{t('intelligence.incidents.assessment.meta').replace('{model}', assessment_meta.model ?? '—').replace('{version}', assessment_meta.prompt_version ?? '—')}</span>}
                                        {assessment_meta?.generated_at && <LocalizedDateDisplay value={assessment_meta.generated_at} withTime />}
                                    </div>
                                    <div><h3 className="font-medium">{t('intelligence.incidents.assessment.symptom')}</h3><p className="text-(--app-foreground)">{assessment.observed_symptom}</p></div>
                                    <div>
                                        <h3 className="font-medium">{t('intelligence.incidents.assessment.causes')}</h3>
                                        <ol className="mt-1 space-y-2">
                                            {assessment.likely_causes.map((c, i) => (
                                                <li key={i} className="rounded-control border border-(--app-border) p-3">
                                                    <div className="flex flex-wrap items-center gap-2"><span className="font-medium text-(--app-foreground)">{i + 1}. {c.cause}</span><ConfidenceBadge confidence={c.confidence} /></div>
                                                    <p className="mt-1 text-(--app-muted-foreground)">{c.why}</p>
                                                </li>
                                            ))}
                                        </ol>
                                    </div>
                                    {([['alternative_explanations', 'alternatives'], ['recommended_next_checks', 'checks'], ['suggested_areas_to_inspect', 'areas']] as const).filter(([field]) => assessment[field].length > 0).map(([field, key]) => (
                                        <div key={field}>
                                            <h3 className="font-medium">{t(`intelligence.incidents.assessment.${key}`)}</h3>
                                            <ul className="mt-1 list-disc space-y-1 pl-5">{assessment[field].map((item, i) => <li key={i} className={field === 'suggested_areas_to_inspect' ? 'font-mono text-xs' : undefined}>{item}</li>)}</ul>
                                        </div>
                                    ))}
                                    {assessment.caveats && <p className="text-(--app-muted-foreground)"><span className="font-medium">{t('intelligence.incidents.assessment.caveats')}: </span>{assessment.caveats}</p>}
                                    <Alert tone="info">{t('intelligence.incidents.assessment.disclaimer')}</Alert>
                                </div>
                            ) : (
                                <p className="text-sm text-(--app-muted-foreground)">{t('intelligence.incidents.explainHint')}</p>
                            )}
                        </Section>
                    )}

                    <Section title={t('intelligence.incidents.evidence')} aside={<span className="text-xs text-(--app-muted-foreground)">{incident.source_signals.join(' · ')}</span>}>
                        <dl className="divide-y divide-(--app-border) rounded-control border border-(--app-border) text-sm">
                            {incident.evidence.rows.map((row, index) => (
                                <div key={index} className="flex items-baseline justify-between gap-3 px-3 py-1.5">
                                    <dt className="text-(--app-muted-foreground)">{sr(`intelligence.evidence.${row.label}`)}</dt>
                                    <dd className="tabular-nums font-medium text-(--app-foreground)">{row.value === null || row.value === undefined ? '—' : String(row.value)}</dd>
                                </div>
                            ))}
                        </dl>
                    </Section>

                    {fingerprints.length > 0 && (
                        <Section title={t('intelligence.incidents.relatedErrors')}>
                            <Table>
                                <THead><Tr><Th>{t('intelligence.incidents.exception')}</Th><Th>{t('intelligence.incidents.route')}</Th><Th align="right">{t('intelligence.incidents.occurrences')}</Th><Th>{t('intelligence.incidents.lastSeen')}</Th></Tr></THead>
                                <TBody>
                                    {fingerprints.map((f, i) => (
                                        <Tr key={i}>
                                            <Td>
                                                <span className="block font-mono text-xs">{f.exception_class}</span>
                                                <span className="block text-xs text-(--app-muted-foreground)">{f.message}</span>
                                                {f.sample_context?.file && <span className="block text-xs text-(--app-muted-foreground)">{t('intelligence.incidents.suggestedArea')}: <span className="font-mono">{f.sample_context.file}:{f.sample_context.line}</span></span>}
                                                {f.last_error_id && <span className="block font-mono text-xs text-(--app-muted-foreground)">{f.last_error_id}</span>}
                                            </Td>
                                            <Td muted>{f.route_name ?? f.route_group}</Td>
                                            <Td numeric>{f.occurrences}</Td>
                                            <Td muted><LocalizedDateDisplay value={f.last_seen_at} withTime /></Td>
                                        </Tr>
                                    ))}
                                </TBody>
                            </Table>
                        </Section>
                    )}

                    <Section title={t('intelligence.areas.recommendations.title')}>
                        <div className="space-y-4">
                            {CATEGORY_ORDER.filter((c) => recommendations.some((r) => r.category === c)).map((category) => (
                                <div key={category}>
                                    <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-(--app-muted-foreground)">{sr(`intelligence.category.${category}`)}</h3>
                                    <ul className="space-y-2">
                                        {recommendations.filter((r) => r.category === category).map((r) => (
                                            <li key={r.id}><RecommendationItem recommendation={r} canReview={can.review} text={text} /></li>
                                        ))}
                                    </ul>
                                </div>
                            ))}
                        </div>
                    </Section>
                </div>

                <div className="space-y-5">
                    {can.manage && (
                        <Section title={t('intelligence.incidents.manage')}>
                            <form onSubmit={submit} className="space-y-4">
                                <FormField id="incident-status" label={t('intelligence.incidents.status')}>
                                    {({ id }) => (
                                        <Select id={id} value={form.data.status} onChange={(e) => form.setData('status', e.target.value as typeof incident.status)}>
                                            {options.statuses.map((s) => <option key={s} value={s}>{sr(`intelligence.status.${s}`)}</option>)}
                                        </Select>
                                    )}
                                </FormField>
                                <FormField id="incident-verification" label={t('intelligence.incidents.verification')}>
                                    {({ id }) => (
                                        <Select id={id} value={form.data.verification_status} onChange={(e) => form.setData('verification_status', e.target.value)}>
                                            <option value="">—</option>
                                            {options.verifications.map((v) => <option key={v} value={v}>{sr(`intelligence.verification.${v}`)}</option>)}
                                        </Select>
                                    )}
                                </FormField>
                                <FormField id="incident-resolution" label={t('intelligence.incidents.resolution')} error={form.errors.resolution_summary}>
                                    {({ id }) => <Textarea id={id} value={form.data.resolution_summary} onChange={(e) => form.setData('resolution_summary', e.target.value)} />}
                                </FormField>
                                <FormField id="incident-note" label={t('intelligence.incidents.note')} error={form.errors.note}>
                                    {({ id }) => <Textarea id={id} value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} />}
                                </FormField>
                                <div className="flex flex-wrap gap-2">
                                    <Button type="submit" loading={form.processing}>{t('common.save')}</Button>
                                    <Button variant="outline" onClick={takeOwnership}>{t('intelligence.incidents.takeOwnership')}</Button>
                                </div>
                            </form>
                        </Section>
                    )}

                    {runbook && can.runbooks && (
                        <Section title={t('intelligence.areas.runbooks.title')}>
                            <Link href={route('intelligence.runbooks.show', runbook)} className="font-medium text-primary hover:underline">{sr(`intelligence.runbooks.${runbook}.title`)}</Link>
                            <p className="mt-1 text-sm text-(--app-muted-foreground)">{sr(`intelligence.runbooks.${runbook}.summary`)}</p>
                        </Section>
                    )}

                    <Section title={t('intelligence.incidents.timeline')}>
                        <ol className="space-y-3 text-sm">
                            {events.map((event) => (
                                <li key={event.id} className="flex gap-3">
                                    <span className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-primary" aria-hidden="true" />
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-medium text-(--app-foreground)">{sr(`intelligence.events.${event.event}`)}</span>
                                            {event.to && <span className={badgeClassName('neutral')}>{event.event === 'escalated' || event.payload?.severity ? sr(`intelligence.severity.${event.to}`) : sr(`intelligence.status.${event.to}`)}</span>}
                                        </div>
                                        <div className="text-xs text-(--app-muted-foreground)"><LocalizedDateDisplay value={event.occurred_at} withTime />{event.actor ? ` · ${event.actor}` : ` · ${t('intelligence.incidents.system')}`}</div>
                                        {event.note && <p className="mt-0.5 text-(--app-foreground)">{event.note}</p>}
                                    </div>
                                </li>
                            ))}
                        </ol>
                    </Section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function RecommendationItem({ recommendation: r, canReview, text }: { recommendation: RecommendationRow; canReview: boolean; text: (r: RecommendationRow, f: 'title' | 'body') => string }) {
    const { t } = useLocale();
    const { sr } = useSituationLabels();
    const [open, setOpen] = useState(false);
    const form = useForm({ review_status: 'useful', note: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.patch(route('intelligence.recommendations.review', r.id), { preserveScroll: true, onSuccess: () => setOpen(false) });
    };

    return (
        <div className="rounded-control border border-(--app-border) p-3">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <span className="font-medium text-(--app-foreground)">{text(r, 'title')}</span>
                <span className="flex flex-wrap gap-1.5"><RiskBadge risk={r.risk} />{r.confidence && <ConfidenceBadge confidence={r.confidence} />}<ReviewBadge status={r.review_status} /></span>
            </div>
            <p className="mt-1 text-sm text-(--app-foreground)">{text(r, 'body')}</p>
            {r.reviewer && <p className="mt-1 text-xs text-(--app-muted-foreground)">{sr(`intelligence.review.${r.review_status}`)} · {r.reviewer}{r.review_note ? ` · ${r.review_note}` : ''}</p>}
            {canReview && (
                open ? (
                    <form onSubmit={submit} className="mt-3 flex flex-wrap items-end gap-2">
                        <div className="w-48">
                            <Select aria-label={t('intelligence.recommendations.verdict')} value={form.data.review_status} onChange={(e) => form.setData('review_status', e.target.value)}>
                                {['useful', 'not_useful', 'accepted', 'rejected', 'incorrect_diagnosis'].map((s) => <option key={s} value={s}>{sr(`intelligence.review.${s}`)}</option>)}
                            </Select>
                        </div>
                        <div className="min-w-0 flex-1"><Textarea aria-label={t('intelligence.incidents.note')} className="min-h-10" value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} /></div>
                        <Button type="submit" size="sm" loading={form.processing}>{t('intelligence.recommendations.record')}</Button>
                        <Button variant="ghost" size="sm" onClick={() => setOpen(false)}>{t('common.cancel')}</Button>
                    </form>
                ) : (
                    <Button variant="ghost" size="sm" className="mt-2" onClick={() => setOpen(true)}>{t('intelligence.recommendations.review')}</Button>
                )
            )}
        </div>
    );
}
