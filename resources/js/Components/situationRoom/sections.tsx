import { MultiSeriesLineChart, StackedBarChart } from '@/Components/dashboard/charts/SimpleCharts';
import { useLocale } from '@/hooks/useLocale';
import { Alert, Button, Card, Select, StatusBadge, TBody, THead, Table, Td, Th, Tr, cx } from '@euisis/ui';
import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { MeterRow, Section, TYPES, fmt, institutionHref, useDateLabel, useNamer, viewQuery, type Counts, type Filters, type Name, type ScopeProps } from './shared';

export type Kpis = { current: Counts & { avg_rating: number | null }; previous: Counts & { avg_rating: number | null } };
export type Trend = { unit: 'day' | 'week' | 'month'; points: { bucket: string; feedback: number; suggestion: number; complaint: number }[] };
export type InstitutionRow = { id: string; code: string | null } & Name & Counts;
export type Institutions = { rows: InstitutionRow[]; own: Counts };
export type AttentionItem = { code: string; tone: 'info' | 'warning'; count: number; params: Record<string, string | number | null>; filters: Record<string, string> };
export type SummarySentence = { code: string; params: Record<string, string | number | null> };
export type CategoryRow = { id: string | null; uncategorized: boolean } & Name & Counts;
export type ServiceRow = { id: string; service_no: string | null; name: string | null } & Counts;
export type Resolution = { statuses: Record<string, number>; by_type: Record<string, { unresolved: number; resolved: number }> };
export type Ratings = { distribution: Record<string, number>; average: number | null; total: number };

/* ---------------------------------------------------------------- KPIs */

export function KpiRow({ kpis }: { kpis: Kpis }) {
    const { t } = useLocale();
    const cards: { key: keyof Counts; label: string; hint?: string }[] = [
        { key: 'feedback', label: t('situationRoom.kpiFeedback') },
        { key: 'suggestion', label: t('situationRoom.kpiSuggestions') },
        { key: 'complaint', label: t('situationRoom.kpiComplaints') },
        { key: 'unresolved', label: t('situationRoom.kpiUnresolved'), hint: t('situationRoom.unresolvedHint') },
        { key: 'resolved', label: t('situationRoom.kpiResolved'), hint: t('situationRoom.resolvedHint') },
        { key: 'unrouted', label: t('situationRoom.kpiUnrouted') },
    ];

    return (
        <section aria-label={t('situationRoom.title')} className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
            {cards.map((card) => {
                const current = kpis.current[card.key];
                const previous = kpis.previous[card.key];
                return (
                    <Card key={card.key} className="p-4" title={card.hint}>
                        <p className="text-sm text-[color:var(--app-muted-foreground)]">{card.label}</p>
                        <p className="mt-1 text-2xl font-semibold tabular-nums">{current.toLocaleString()}</p>
                        <p className="mt-1 text-xs text-[color:var(--app-muted-foreground)]">{changeText(t, current, previous)}</p>
                    </Card>
                );
            })}
        </section>
    );
}

/** Neutral comparison wording; no percentage without a baseline. */
function changeText(t: (key: string) => string, current: number, previous: number): string {
    if (previous === 0) return t('situationRoom.noBaseline');
    if (current === previous) return t('situationRoom.changeSame');
    const percent = Math.round((Math.abs(current - previous) / previous) * 100);
    return fmt(t(current > previous ? 'situationRoom.changeUp' : 'situationRoom.changeDown'), { percent, previous });
}

/* ---------------------------------------------------------------- Trend */

export function TrendSection({ trend, kpis }: { trend: Trend; kpis: Kpis }) {
    const { t } = useLocale();
    const { date } = useDateLabel();
    const series = TYPES.map((type) => ({ key: type, label: t(`situationRoom.type_${type}`) }));

    return (
        <Section id="sr-trend" title={t('situationRoom.trendTitle')} intro={fmt(t('situationRoom.trendIntro'), { unit: t(`situationRoom.unit_${trend.unit}`) })}>
            <p className="sr-only">{fmt(t('situationRoom.trendSummary'), kpis.current)}</p>
            <div aria-hidden="true">
                <MultiSeriesLineChart data={trend.points} xKey="bucket" series={series} xLabel={(value) => date(value)} emptyTitle={t('situationRoom.summary_no_submissions')} />
            </div>
        </Section>
    );
}

/* ---------------------------------------------------------------- Institutions */

export function InstitutionSection({ institutions, scope, filters }: { institutions: Institutions; scope: ScopeProps; filters: Filters }) {
    const { t } = useLocale();
    const name = useNamer();
    const top = institutions.rows.slice(0, 10);
    const rest = institutions.rows.slice(10);
    const other = rest.reduce((sum, row) => ({ feedback: sum.feedback + row.feedback, suggestion: sum.suggestion + row.suggestion, complaint: sum.complaint + row.complaint }), { feedback: 0, suggestion: 0, complaint: 0 });
    const ownLabel = scope.node ? fmt(t('situationRoom.ownBucket'), { name: name(scope.node) }) : t('situationRoom.outsideHierarchy');

    const data = [
        ...top.map((row) => ({ label: name(row), feedback: row.feedback, suggestion: row.suggestion, complaint: row.complaint })),
        ...(rest.length > 0 ? [{ label: fmt(t('situationRoom.otherInstitutions'), { count: rest.length }), ...other }] : []),
        ...(institutions.own.total > 0 ? [{ label: ownLabel, feedback: institutions.own.feedback, suggestion: institutions.own.suggestion, complaint: institutions.own.complaint }] : []),
    ].filter((row) => row.feedback + row.suggestion + row.complaint > 0);
    const series = TYPES.map((type) => ({ key: type, label: t(`situationRoom.type_${type}`) }));

    return (
        <Section id="sr-institutions" title={t('situationRoom.institutionsTitle')} intro={t('situationRoom.institutionsIntro')}>
            {scope.hierarchyPublished === false && <Alert tone="warning" className="mb-4">{t('situationRoom.hierarchyNotPublished')}</Alert>}
            {institutions.rows.length === 0 ? (
                <p className="text-sm text-[color:var(--app-muted-foreground)]">{t('situationRoom.noChildren')}</p>
            ) : (
                <>
                    <div aria-hidden="true"><StackedBarChart data={data} labelKey="label" series={series} emptyTitle={t('situationRoom.summary_no_submissions')} /></div>
                    <div className="mt-4 overflow-x-auto">
                        <Table>
                            <caption className="sr-only">{t('situationRoom.institutionsTitle')}</caption>
                            <THead>
                                <Tr>
                                    <Th>{t('situationRoom.institutionColumn')}</Th>
                                    <Th align="right">{t('situationRoom.kpiFeedback')}</Th>
                                    <Th align="right">{t('situationRoom.kpiSuggestions')}</Th>
                                    <Th align="right">{t('situationRoom.kpiComplaints')}</Th>
                                    <Th align="right">{t('situationRoom.kpiUnresolved')}</Th>
                                    <Th align="right">{t('situationRoom.kpiUnrouted')}</Th>
                                    <Th align="right">{t('situationRoom.totalColumn')}</Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {institutions.rows.map((row) => (
                                    <Tr key={row.id}>
                                        <Td><Link href={institutionHref(row.id, filters, scope)} className="font-medium text-[color:var(--color-primary)] underline-offset-4 hover:underline">{name(row)}</Link></Td>
                                        <Td align="right" numeric>{row.feedback.toLocaleString()}</Td>
                                        <Td align="right" numeric>{row.suggestion.toLocaleString()}</Td>
                                        <Td align="right" numeric>{row.complaint.toLocaleString()}</Td>
                                        <Td align="right" numeric>{row.unresolved.toLocaleString()}</Td>
                                        <Td align="right" numeric>{row.unrouted.toLocaleString()}</Td>
                                        <Td align="right" numeric className="font-semibold">{row.total.toLocaleString()}</Td>
                                    </Tr>
                                ))}
                                {institutions.own.total > 0 && (
                                    <Tr>
                                        <Td className="italic">{ownLabel}</Td>
                                        <Td align="right" numeric>{institutions.own.feedback.toLocaleString()}</Td>
                                        <Td align="right" numeric>{institutions.own.suggestion.toLocaleString()}</Td>
                                        <Td align="right" numeric>{institutions.own.complaint.toLocaleString()}</Td>
                                        <Td align="right" numeric>{institutions.own.unresolved.toLocaleString()}</Td>
                                        <Td align="right" numeric>{institutions.own.unrouted.toLocaleString()}</Td>
                                        <Td align="right" numeric className="font-semibold">{institutions.own.total.toLocaleString()}</Td>
                                    </Tr>
                                )}
                            </TBody>
                        </Table>
                    </div>
                </>
            )}
        </Section>
    );
}

/* ---------------------------------------------------------------- Attention */

export function AttentionSection({ items, rules, scope, filters, canManageActions }: { items: AttentionItem[]; rules: Record<string, number | null>; scope: ScopeProps; filters: Filters; canManageActions: boolean }) {
    const { t, locale } = useLocale();
    const { date } = useDateLabel();
    const rulesOff = Object.values(rules).every((value) => value === null);

    const text = (item: AttentionItem): string => {
        const p = item.params;
        const local = (en: unknown, am: unknown) => String((locale === 'am' ? am || en : en || am) ?? '—');
        return fmt(t(`situationRoom.attention_${item.code}`), {
            count: item.count,
            oldest: p.oldest ? date(String(p.oldest)) : '—',
            type: p.type ? t(`situationRoom.type_${p.type}`) : undefined,
            days: p.days ?? undefined,
            previous: p.previous ?? undefined,
            percent: p.percent ?? undefined,
            category: local(p.category_en, p.category_am),
            institution: local(p.organization_en, p.organization_am),
        });
    };

    return (
        <Section id="sr-attention" title={t('situationRoom.attentionTitle')}>
            {items.length === 0 ? <p className="text-sm text-[color:var(--app-muted-foreground)]">{t('situationRoom.attentionEmpty')}</p> : (
                <ul className="space-y-3">
                    {items.map((item, index) => (
                        <li key={`${item.code}-${index}`} className="rounded-[var(--radius-card)] border border-[color:var(--app-border)] p-3">
                            <div className="flex items-start gap-2">
                                <StatusBadge tone={item.tone === 'warning' ? 'warning' : 'info'}>{t(item.tone === 'warning' ? 'situationRoom.toneWarning' : 'situationRoom.toneInfo')}</StatusBadge>
                                <p className="text-sm">{text(item)}</p>
                            </div>
                            <div className="mt-2 flex flex-wrap gap-3 text-sm">
                                <Link href={route(scope.node ? 'situation-room.organization' : 'situation-room.index', { ...viewQuery(scope, filters, item.filters), ...(scope.node ? { organization: scope.node.id } : {}) })} className="font-medium text-[color:var(--color-primary)] underline-offset-4 hover:underline">{t('situationRoom.showMatching')}</Link>
                                {canManageActions && scope.node && (
                                    <Link href={route('situation-room.actions.index', { organization: scope.node.id, new: 1, source_type: 'attention', source_reference: item.code })} className="font-medium text-[color:var(--color-primary)] underline-offset-4 hover:underline">{t('situationRoom.recordAction')}</Link>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
            {rulesOff && <p className="mt-4 text-xs text-[color:var(--app-muted-foreground)]">{t('situationRoom.attentionRulesOff')}</p>}
        </Section>
    );
}

/* ---------------------------------------------------------------- Summary */

export function SummarySection({ sentences }: { sentences: SummarySentence[] }) {
    const { t, locale } = useLocale();
    const local = (en: unknown, am: unknown) => String((locale === 'am' ? am || en : en || am) ?? '—');

    return (
        <Section id="sr-summary" title={t('situationRoom.summaryTitle')}>
            <ul className="list-disc space-y-1.5 ps-5 text-sm">
                {sentences.map((sentence, index) => {
                    const p = sentence.params;
                    return (
                        <li key={`${sentence.code}-${index}`}>
                            {fmt(t(`situationRoom.summary_${sentence.code}`), {
                                ...Object.fromEntries(Object.entries(p).filter(([, v]) => typeof v === 'number')) as Record<string, number>,
                                type: p.type ? t(`situationRoom.type_${p.type}`) : undefined,
                                category: local(p.category_en, p.category_am),
                                institution: local(p.institution_en, p.institution_am),
                            })}
                        </li>
                    );
                })}
            </ul>
        </Section>
    );
}

/* ---------------------------------------------------------------- Breakdowns */

export function CategorySection({ categories }: { categories: CategoryRow[] }) {
    const { t } = useLocale();
    const name = useNamer();
    const max = Math.max(1, ...categories.map((row) => row.total));

    return (
        <Section id="sr-categories" title={t('situationRoom.categoriesTitle')}>
            {categories.length === 0 ? <p className="text-sm text-[color:var(--app-muted-foreground)]">{t('situationRoom.summary_no_submissions')}</p> : (
                <ul className="space-y-3">
                    {categories.slice(0, 10).map((row) => (
                        <MeterRow key={row.id ?? 'none'} label={row.uncategorized ? t('situationRoom.uncategorized') : name(row)} value={row.total} max={max}
                            hint={`${t('situationRoom.kpiFeedback')} ${row.feedback} · ${t('situationRoom.kpiSuggestions')} ${row.suggestion} · ${t('situationRoom.kpiComplaints')} ${row.complaint}`} />
                    ))}
                </ul>
            )}
        </Section>
    );
}

export function ServiceSection({ services }: { services: ServiceRow[] }) {
    const { t } = useLocale();
    const max = Math.max(1, ...services.map((row) => row.total));

    return (
        <Section id="sr-services" title={t('situationRoom.servicesTitle')}>
            {services.length === 0 ? <p className="text-sm text-[color:var(--app-muted-foreground)]">{t('situationRoom.servicesEmpty')}</p> : (
                <ul className="space-y-3">
                    {services.map((row) => (
                        <MeterRow key={row.id} label={[row.service_no, row.name].filter(Boolean).join(' · ') || '—'} value={row.total} max={max}
                            hint={`${t('situationRoom.kpiComplaints')} ${row.complaint} · ${t('situationRoom.kpiUnresolved')} ${row.unresolved}`} />
                    ))}
                </ul>
            )}
        </Section>
    );
}

export function ResolutionSection({ resolution, targets }: { resolution: Resolution; targets: Record<string, Record<string, number>> }) {
    const { t } = useLocale();
    const max = Math.max(1, ...Object.values(resolution.statuses));

    return (
        <Section id="sr-resolution" title={t('situationRoom.resolutionTitle')}>
            <ul className="space-y-3">
                {Object.entries(resolution.statuses).map(([status, value]) => <MeterRow key={status} label={t(`situationRoom.status_${status}`)} value={value} max={max} />)}
            </ul>
            <dl className="mt-5 grid gap-2 text-sm sm:grid-cols-3">
                {TYPES.map((type) => (
                    <div key={type} className="rounded-[var(--radius-card)] border border-[color:var(--app-border)] p-3">
                        <dt className="font-medium">{t(`situationRoom.type_${type}`)}</dt>
                        <dd className="mt-1 text-[color:var(--app-muted-foreground)]">{fmt(t('situationRoom.resolvedVsUnresolved'), resolution.by_type[type] ?? { resolved: 0, unresolved: 0 })}</dd>
                    </div>
                ))}
            </dl>
            <h3 className="mt-5 text-sm font-semibold">{t('situationRoom.targetsTitle')}</h3>
            <dl className="mt-2 grid gap-2 text-sm sm:grid-cols-2">
                {Object.entries(targets).map(([target, counts]) => (
                    <div key={target} className="rounded-[var(--radius-card)] border border-[color:var(--app-border)] p-3">
                        <dt className="font-medium">{t(`situationRoom.target_${target}`)}</dt>
                        <dd className="mt-1 text-[color:var(--app-muted-foreground)]">{TYPES.map((type) => `${t(`situationRoom.type_${type}`)} ${(counts[type] ?? 0).toLocaleString()}`).join(' · ')}</dd>
                    </div>
                ))}
            </dl>
        </Section>
    );
}

export function RatingsSection({ ratings }: { ratings: Ratings }) {
    const { t } = useLocale();
    const max = Math.max(1, ...Object.values(ratings.distribution));

    return (
        <Section id="sr-ratings" title={t('situationRoom.ratingsTitle')} intro={ratings.average !== null ? fmt(t('situationRoom.ratingsAverage'), { average: ratings.average.toFixed(2), total: ratings.total }) : undefined}>
            {ratings.total === 0 ? <p className="text-sm text-[color:var(--app-muted-foreground)]">{t('situationRoom.ratingsEmpty')}</p> : (
                <ul className="space-y-3">
                    {[5, 4, 3, 2, 1].map((stars) => <MeterRow key={stars} label={fmt(t('situationRoom.starsLabel'), { stars })} value={ratings.distribution[String(stars)] ?? 0} max={max} />)}
                </ul>
            )}
        </Section>
    );
}

/* ---------------------------------------------------------------- Supporting */

type CardData = Record<string, number | boolean>;

export function SupportingSection({ cards, configuration }: { cards: Record<string, CardData>; configuration: { without_qr: number; without_public_phone: number } }) {
    const { t } = useLocale();
    const entries = Object.entries(cards);
    const labelFor = (card: string, key: string) => (card === 'field_work' && key === 'approved' ? t('situationRoom.approved_field_work') : t(`situationRoom.${key}`));

    return (
        <details className="group rounded-[var(--radius-card)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)]">
            <summary className="cursor-pointer list-none p-5 focus-visible:outline focus-visible:outline-2 focus-visible:outline-[color:var(--color-primary)]">
                <span className="font-semibold">{t('situationRoom.supportingTitle')}</span>
                <span className="ms-2 text-sm text-[color:var(--app-muted-foreground)]" aria-hidden="true">▾</span>
                <p className="mt-1 text-sm text-[color:var(--app-muted-foreground)]">{t('situationRoom.supportingIntro')}</p>
            </summary>
            <div className="grid gap-4 px-5 pb-5 sm:grid-cols-2 xl:grid-cols-3">
                <Card className="p-4">
                    <h3 className="text-sm font-semibold">{t('situationRoom.configurationTitle')}</h3>
                    <ul className="mt-2 space-y-1 text-sm">
                        {configuration.without_qr === 0 && configuration.without_public_phone === 0 ? <li>{t('situationRoom.configurationComplete')}</li> : (
                            <>
                                {configuration.without_qr > 0 && <li>{fmt(t('situationRoom.withoutQr'), { count: configuration.without_qr })}</li>}
                                {configuration.without_public_phone > 0 && <li>{fmt(t('situationRoom.withoutPhone'), { count: configuration.without_public_phone })}</li>}
                            </>
                        )}
                    </ul>
                </Card>
                {entries.length === 0 && <p className="text-sm text-[color:var(--app-muted-foreground)]">{t('situationRoom.supportingEmpty')}</p>}
                {entries.map(([card, data]) => (
                    <Card key={card} className="p-4">
                        <h3 className="text-sm font-semibold">{fmt(t(`situationRoom.card_${card}`), { days: typeof data.days === 'number' ? data.days : 7 })}</h3>
                        {data.error ? <p className="mt-2 text-sm text-[color:var(--app-muted-foreground)]">{t('situationRoom.cardError')}</p> : (
                            <dl className="mt-2 grid grid-cols-2 gap-2 text-sm">
                                {Object.entries(data).filter(([key]) => key !== 'days' && key !== 'error').map(([key, value]) => (
                                    <div key={key}>
                                        <dt className="text-[color:var(--app-muted-foreground)]">{labelFor(card, key)}</dt>
                                        <dd className="text-lg font-semibold tabular-nums">{Number(value).toLocaleString()}</dd>
                                    </div>
                                ))}
                            </dl>
                        )}
                    </Card>
                ))}
            </div>
        </details>
    );
}

/* ---------------------------------------------------------------- Exports */

export type ExportRow = { id: string; format: string; status: 'queued' | 'ready' | 'failed'; filename: string | null; row_count: number | null; created_at: string | null };

export function ExportsSection({ exports, scope, filters }: { exports: ExportRow[]; scope: ScopeProps; filters: Filters }) {
    const { t } = useLocale();
    const { dateTime } = useDateLabel();
    const form = useForm({ format: 'csv' as 'csv' | 'xlsx' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({ ...data, ...viewQuery(scope, filters), period: 'custom', from: filters.from, to: filters.to }));
        form.post(route('situation-room.exports.store'), { preserveScroll: true });
    }

    return (
        <Section id="sr-exports" title={t('situationRoom.exportsTitle')} intro={t('situationRoom.exportsIntro')}
            actions={(
                <form onSubmit={submit} className="flex items-center gap-2">
                    <Select aria-label={t('situationRoom.exportFormat')} value={form.data.format} onChange={(e) => form.setData('format', e.target.value as 'csv' | 'xlsx')} className="w-auto">
                        <option value="csv">CSV</option>
                        <option value="xlsx">Excel</option>
                    </Select>
                    <Button type="submit" variant="primary" loading={form.processing}>{t('situationRoom.requestExport')}</Button>
                </form>
            )}
        >
            {exports.length === 0 ? <p className="text-sm text-[color:var(--app-muted-foreground)]">{t('situationRoom.noExports')}</p> : (
                <ul className="divide-y divide-[color:var(--app-border)] text-sm">
                    {exports.map((row) => (
                        <li key={row.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                            <span>{dateTime(row.created_at)} · <span className="uppercase">{row.format}</span>{row.row_count !== null && ` · ${fmt(t('situationRoom.rowsColumn'), {})} ${row.row_count}`}</span>
                            <span className="flex items-center gap-3">
                                <StatusBadge tone={row.status === 'ready' ? 'success' : row.status === 'failed' ? 'danger' : 'neutral'}>{t(`situationRoom.export_${row.status}`)}</StatusBadge>
                                {row.status === 'ready' && <a href={route('situation-room.exports.download', row.id)} className={cx('font-medium text-[color:var(--color-primary)] underline underline-offset-4')}>{t('situationRoom.download')}</a>}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </Section>
    );
}
