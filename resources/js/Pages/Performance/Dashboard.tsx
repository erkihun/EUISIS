import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import PageHeader from '@/Components/PageHeader';
import { Bar, Empty, Pill, Section, Table, compactInputCls, formatScore, linkBtn, nameOf, pageCls, panelCls, tdCls, thCls, type Bilingual } from '@/Components/performance/ui';
import { useLocale } from '@/hooks/useLocale';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { ActivityIcon, AlertTriangle, CalendarIcon, CheckCircle, ChevronRight, ClipboardCheckIcon, Layers, LayoutDashboard, RefreshIcon, TrendingUpIcon, Users } from '@/Components/Icons';
import type { ReactNode, SVGProps } from 'react';

type IconComponent = (props: SVGProps<SVGSVGElement>) => JSX.Element;

type Cycle = {
    id: string;
    code: string;
    name_en: string;
    name_am: string | null;
    status: string;
    start_date: string;
    end_date: string;
    is_current: boolean;
};

type PlanRow = {
    id: string;
    title: string;
    type: string;
    organization: Bilingual;
    unit: Bilingual;
    score: string | null;
    as_of: string | null;
};

type RiskRow = {
    plan_id: string;
    objective: string;
    kpi_code: string;
    kpi_name_en: string;
    kpi_name_am: string | null;
    health: string;
    achievement: string | null;
    target: string | null;
    actual: string | null;
};

type Props = {
    cycles: Cycle[];
    cycleId: string;
    organizationPlans: PlanRow[];
    unitPlans: PlanRow[];
    atRisk: RiskRow[];
    agreementStatus: Record<string, number>;
    agreementTotal: number;
    reviews: Record<string, Record<string, number>>;
    distribution: { rating_label_en: string | null; rating_label_am: string | null; total: number }[];
    can: { recalculate: boolean; reports: boolean };
};

type MetricProps = {
    label: string;
    value: ReactNode;
    detail: ReactNode;
    icon: IconComponent;
    tone: 'primary' | 'success' | 'warning' | 'neutral';
};

const metricTones = {
    primary: 'bg-[color:color-mix(in_srgb,var(--color-primary)_12%,transparent)] text-[color:var(--color-primary)]',
    success: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
    warning: 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
    neutral: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
};

function MetricCard({ label, value, detail, icon: Icon, tone }: MetricProps) {
    return (
        <article className={`${panelCls} p-5`}>
            <div className="flex items-start justify-between gap-4">
                <div className="min-w-0">
                    <p className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">{label}</p>
                    <div className="mt-2 text-2xl font-semibold tabular-nums text-gray-950 dark:text-white">{value}</div>
                </div>
                <span className={`grid h-10 w-10 shrink-0 place-items-center rounded-xl ${metricTones[tone]}`}>
                    <Icon className="h-5 w-5" aria-hidden="true" />
                </span>
            </div>
            <div className="mt-3 text-xs leading-5 text-gray-500 dark:text-slate-400">{detail}</div>
        </article>
    );
}

function ProgressLine({ label, complete, total }: { label: string; complete: number; total: number }) {
    const percent = total > 0 ? Math.round((complete / total) * 100) : 0;

    return (
        <div>
            <div className="mb-2 flex items-center justify-between gap-4 text-sm">
                <span className="font-medium text-gray-700 dark:text-slate-200">{label}</span>
                <span className="tabular-nums text-gray-500 dark:text-slate-400">{complete}/{total} · {percent}%</span>
            </div>
            <div className="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-slate-800">
                <div className="h-full rounded-full bg-[color:var(--color-primary)] transition-all" style={{ width: `${percent}%` }} />
            </div>
        </div>
    );
}

function SetupStep({ number, title, description, href }: { number: number; title: string; description: string; href: string }) {
    return (
        <Link href={href} className="group flex gap-4 rounded-xl border border-gray-200 bg-white p-4 transition hover:border-[color:var(--color-primary)] hover:shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <span className="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-[color:var(--color-primary)] text-sm font-semibold text-white">{number}</span>
            <span className="min-w-0">
                <span className="flex items-center gap-2 font-semibold text-gray-900 dark:text-slate-100">
                    {title}<ChevronRight className="h-4 w-4 transition-transform group-hover:translate-x-1" aria-hidden="true" />
                </span>
                <span className="mt-1 block text-sm leading-5 text-gray-500 dark:text-slate-400">{description}</span>
            </span>
        </Link>
    );
}

function ActionRow({ icon: Icon, title, detail, href }: { icon: IconComponent; title: string; detail: string; href: string }) {
    const content = (
        <>
            <span className="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300">
                <Icon className="h-4 w-4" aria-hidden="true" />
            </span>
            <span className="min-w-0 flex-1">
                <span className="block text-sm font-semibold text-gray-900 dark:text-slate-100">{title}</span>
                <span className="mt-1 block text-xs leading-5 text-gray-500 dark:text-slate-400">{detail}</span>
            </span>
            <ChevronRight className="mt-2 h-4 w-4 shrink-0 text-gray-400 transition-transform group-hover:translate-x-1 group-hover:text-[color:var(--color-primary)]" aria-hidden="true" />
        </>
    );

    const className = 'group flex items-start gap-3 py-4 first:pt-0 last:pb-0';

    return href.startsWith('#')
        ? <a href={href} className={className}>{content}</a>
        : <Link href={href} className={className}>{content}</Link>;
}

export default function PerformanceDashboard({ cycles, cycleId, organizationPlans, unitPlans, atRisk, agreementStatus, agreementTotal, reviews, distribution, can }: Props) {
    const { t, locale } = useLocale();
    const selectedCycle = cycles.find((cycle) => cycle.id === cycleId) ?? cycles[0];
    const plans = [...organizationPlans, ...unitPlans];
    const measuredPlans = plans.filter((plan) => plan.score !== null);
    const planCoverage = plans.length > 0 ? Math.round((measuredPlans.length / plans.length) * 100) : 0;
    const averageScore = measuredPlans.length > 0
        ? measuredPlans.reduce((sum, plan) => sum + Number(plan.score), 0) / measuredPlans.length
        : null;
    const totalRated = distribution.reduce((sum, row) => sum + Number(row.total), 0);
    const finalizedAgreements = (agreementStatus.FINALIZED ?? 0) + (agreementStatus.CLOSED ?? 0);
    const pendingAgreements = Math.max(0, agreementTotal - finalizedAgreements);
    const completed = (type: string) => Number(reviews[type]?.COMPLETED ?? 0);
    const reviewTotal = (type: string) => Object.values(reviews[type] ?? {}).reduce((sum, value) => sum + Number(value), 0);
    const reviewBacklog = Math.max(0, reviewTotal('MID_YEAR') - completed('MID_YEAR'))
        + Math.max(0, reviewTotal('YEAR_END') - completed('YEAR_END'));
    const recalculate = (planId: string) => router.post(route('performance.plans.recalculate', planId), {}, { preserveScroll: true });

    return (
        <AuthenticatedLayout header={
            <PageHeader
                title={t('performance.dashboard.title')}
                description={t('performance.dashboard.description')}
                actions={cycles.length > 0 && (
                    <select aria-label={t('performance.fields.cycle')} className={`${compactInputCls} min-w-52`} value={cycleId}
                        onChange={(event) => router.get(route('performance.dashboard'), { cycle_id: event.target.value }, { preserveState: true })}>
                        {cycles.map((cycle) => <option key={cycle.id} value={cycle.id}>{nameOf(cycle, locale)}{cycle.is_current ? ` · ${t('performance.cycles.current')}` : ''}</option>)}
                    </select>
                )}
            />
        }>
            <Head title={t('performance.dashboard.title')} />

            <div className={pageCls}>
                {cycles.length === 0 ? (
                    <section className="overflow-hidden rounded-panel border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                        <div className="border-b border-gray-100 bg-gradient-to-br from-[color:color-mix(in_srgb,var(--color-primary)_12%,white)] to-white px-6 py-8 dark:border-slate-800 dark:from-[color:color-mix(in_srgb,var(--color-primary)_18%,#0f172a)] dark:to-slate-900">
                            <div className="grid h-12 w-12 place-items-center rounded-2xl bg-[color:var(--color-primary)] text-white shadow-sm"><LayoutDashboard className="h-6 w-6" aria-hidden="true" /></div>
                            <h2 className="mt-5 text-xl font-semibold text-gray-950 dark:text-white">{t('performance.dashboard.getStarted')}</h2>
                            <p className="mt-2 max-w-2xl text-sm leading-6 text-gray-600 dark:text-slate-300">{t('performance.dashboard.getStartedHelp')}</p>
                        </div>
                        <div className="grid gap-3 p-5 md:grid-cols-2">
                            <SetupStep number={1} title={t('performance.dashboard.setupCycle')} description={t('performance.dashboard.setupCycleHelp')} href={route('performance.cycles.index')} />
                            <SetupStep number={2} title={t('performance.dashboard.setupGoals')} description={t('performance.dashboard.setupGoalsHelp')} href={route('performance.strategic-goals.index')} />
                            <SetupStep number={3} title={t('performance.dashboard.setupPlans')} description={t('performance.dashboard.setupPlansHelp')} href={route('performance.plans.index')} />
                            <SetupStep number={4} title={t('performance.dashboard.setupAgreements')} description={t('performance.dashboard.setupAgreementsHelp')} href={route('performance.agreements.index')} />
                        </div>
                    </section>
                ) : (
                    <>
                        <section className="relative overflow-hidden rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                            <div className="absolute inset-y-0 left-0 w-1 bg-[color:var(--color-primary)]" />
                            <div className="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
                                <div className="min-w-0 pl-2">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <h2 className="text-lg font-semibold text-gray-950 dark:text-white">{nameOf(selectedCycle, locale)}</h2>
                                        <Pill group="cycle" value={selectedCycle.status} />
                                        {selectedCycle.is_current && <span className="rounded-full bg-[color:color-mix(in_srgb,var(--color-primary)_12%,transparent)] px-2.5 py-1 text-xs font-semibold text-[color:var(--color-primary)]">{t('performance.cycles.current')}</span>}
                                    </div>
                                    <div className="mt-2 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-gray-500 dark:text-slate-400">
                                        <span className="inline-flex items-center gap-2"><CalendarIcon className="h-4 w-4" aria-hidden="true" /><LocalizedDateDisplay value={selectedCycle.start_date} /> – <LocalizedDateDisplay value={selectedCycle.end_date} /></span>
                                        <span>{selectedCycle.code}</span>
                                    </div>
                                </div>
                                <div className="flex flex-wrap gap-2 pl-2 lg:pl-0">
                                    {can.reports && <Link href={route('performance.reports.index')} className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">
                                        <LayoutDashboard className="h-4 w-4" aria-hidden="true" />{t('performance.dashboard.openReports')}
                                    </Link>}
                                    <Link href={route('performance.agreements.index', { cycle_id: cycleId })} className="inline-flex items-center gap-2 rounded-lg bg-[color:var(--color-primary)] px-3 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)]">
                                        <Users className="h-4 w-4" aria-hidden="true" />{t('performance.dashboard.openAgreements')}
                                    </Link>
                                </div>
                            </div>
                        </section>

                        <section aria-label={t('performance.dashboard.snapshot')} className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                            <MetricCard label={t('performance.dashboard.averageScore')} value={averageScore === null ? '—' : `${formatScore(averageScore)}%`}
                                detail={averageScore === null ? t('performance.notCalculated') : t('performance.dashboard.measuredPlans')} icon={ActivityIcon} tone="primary" />
                            <MetricCard label={t('performance.dashboard.scoreCoverage')} value={`${measuredPlans.length}/${plans.length}`}
                                detail={<div><span>{planCoverage}% {t('performance.dashboard.coverage')}</span><div className="mt-2"><Bar value={planCoverage} /></div></div>}
                                icon={Layers} tone={planCoverage === 100 ? 'success' : 'neutral'} />
                            <MetricCard label={t('performance.dashboard.agreements')} value={agreementTotal}
                                detail={`${agreementStatus.ACTIVE ?? 0} ${t('performance.dashboard.active')} · ${finalizedAgreements} ${t('performance.dashboard.finalized')}`}
                                icon={ClipboardCheckIcon} tone="neutral" />
                            <MetricCard label={t('performance.dashboard.needsAttention')} value={atRisk.length + reviewBacklog}
                                detail={`${atRisk.length} ${t('performance.dashboard.riskKpis')} · ${reviewBacklog} ${t('performance.dashboard.pendingReviews')}`}
                                icon={AlertTriangle} tone={atRisk.length + reviewBacklog > 0 ? 'warning' : 'success'} />
                        </section>

                        <div className="grid gap-4 xl:grid-cols-5">
                            <Section title={t('performance.dashboard.actionCenter')} description={t('performance.dashboard.actionCenterHelp')} className="xl:col-span-3">
                                <div className="divide-y divide-gray-100 dark:divide-slate-800">
                                    {plans.length === 0 && <ActionRow icon={TrendingUpIcon} title={t('performance.dashboard.noPublishedPlans')} detail={t('performance.dashboard.noPublishedPlansHelp')} href={route('performance.plans.index', { cycle_id: cycleId })} />}
                                    {plans.length > measuredPlans.length && <ActionRow icon={RefreshIcon} title={`${plans.length - measuredPlans.length} ${t('performance.dashboard.unmeasuredPlans')}`} detail={t('performance.dashboard.unmeasuredPlansHelp')} href={route('performance.plans.index', { cycle_id: cycleId })} />}
                                    {atRisk.length > 0 && <ActionRow icon={AlertTriangle} title={`${atRisk.length} ${t('performance.dashboard.riskKpis')}`} detail={t('performance.dashboard.riskKpisHelp')} href="#attention" />}
                                    {pendingAgreements > 0 && <ActionRow icon={Users} title={`${pendingAgreements} ${t('performance.dashboard.openAgreementWork')}`} detail={t('performance.dashboard.openAgreementWorkHelp')} href={route('performance.agreements.index', { cycle_id: cycleId })} />}
                                    {reviewBacklog > 0 && <ActionRow icon={ClipboardCheckIcon} title={`${reviewBacklog} ${t('performance.dashboard.pendingReviews')}`} detail={t('performance.dashboard.pendingReviewsHelp')} href={route('performance.agreements.index', { cycle_id: cycleId })} />}
                                    {plans.length > 0 && plans.length === measuredPlans.length && atRisk.length === 0 && pendingAgreements === 0 && reviewBacklog === 0 && (
                                        <div className="flex items-center gap-3 py-5 text-sm text-emerald-700 dark:text-emerald-300"><CheckCircle className="h-5 w-5" aria-hidden="true" /><span className="font-medium">{t('performance.dashboard.noOpenActions')}</span></div>
                                    )}
                                </div>
                            </Section>

                            <Section title={t('performance.dashboard.reviewProgress')} description={t('performance.dashboard.reviewProgressHelp')} className="xl:col-span-2">
                                <div className="space-y-5">
                                    <ProgressLine label={t('performance.dashboard.midYear')} complete={completed('MID_YEAR')} total={reviewTotal('MID_YEAR')} />
                                    <ProgressLine label={t('performance.dashboard.yearEnd')} complete={completed('YEAR_END')} total={reviewTotal('YEAR_END')} />
                                </div>
                            </Section>
                        </div>

                        <div className="grid gap-4 xl:grid-cols-2">
                            <Section title={t('performance.dashboard.units')} description={t('performance.dashboard.unitsHelp')} flush>
                                {plans.length === 0 ? <Empty>{t('performance.dashboard.noData')}</Empty> : (
                                    <Table head={<><th className={thCls}>{t('performance.dashboard.plan')}</th><th className={`${thCls} w-40`}>{t('performance.fields.score')}</th>{can.recalculate && <th className={thCls}><span className="sr-only">{t('performance.actions.recalculate')}</span></th>}</>}>
                                        {plans.map((plan) => (
                                            <tr key={plan.id}>
                                                <td className={tdCls}>
                                                    <Link href={route('performance.plans.show', plan.id)} className="font-medium text-[color:var(--color-primary)] hover:text-[color:var(--color-primary-hover)]">{plan.unit ? nameOf(plan.unit, locale) : nameOf(plan.organization, locale)}</Link>
                                                    <p className="mt-0.5 truncate text-xs text-gray-500 dark:text-slate-400">{plan.title}</p>
                                                </td>
                                                <td className={tdCls}>
                                                    <span className="text-sm tabular-nums">{plan.score === null ? <span className="text-xs text-gray-500">{t('performance.notCalculated')}</span> : `${formatScore(plan.score)}%`}</span>
                                                    <div className="mt-1.5"><Bar value={plan.score} /></div>
                                                    {plan.as_of && <div className="mt-1 text-[11px] text-gray-400"><LocalizedDateDisplay value={plan.as_of} /></div>}
                                                </td>
                                                {can.recalculate && <td className={`${tdCls} text-right`}><button type="button" className={linkBtn} onClick={() => recalculate(plan.id)}>{t('performance.actions.recalculate')}</button></td>}
                                            </tr>
                                        ))}
                                    </Table>
                                )}
                            </Section>

                            <div id="attention">
                                <Section title={t('performance.dashboard.atRisk')} description={t('performance.dashboard.atRiskHelp')} flush>
                                    {atRisk.length === 0 ? <Empty>{t('performance.dashboard.noRisk')}</Empty> : (
                                        <Table head={<><th className={thCls}>{t('performance.fields.kpi')}</th><th className={thCls}>{t('performance.fields.target')}</th><th className={thCls}>{t('performance.fields.actual')}</th><th className={thCls}>{t('performance.fields.status')}</th></>}>
                                            {atRisk.map((row, index) => (
                                                <tr key={`${row.plan_id}-${row.kpi_code}-${index}`}>
                                                    <td className={tdCls}>
                                                        <Link href={route('performance.plans.show', row.plan_id)} className="text-[color:var(--color-primary)] hover:text-[color:var(--color-primary-hover)]">{row.kpi_code} — {(locale === 'am' && row.kpi_name_am) || row.kpi_name_en}</Link>
                                                        <p className="mt-0.5 text-xs text-gray-500 dark:text-slate-400">{row.objective}</p>
                                                    </td>
                                                    <td className={`${tdCls} tabular-nums`}>{formatScore(row.target)}</td>
                                                    <td className={`${tdCls} tabular-nums`}>{formatScore(row.actual)}</td>
                                                    <td className={tdCls}><Pill group="health" value={row.health} /></td>
                                                </tr>
                                            ))}
                                        </Table>
                                    )}
                                </Section>
                            </div>
                        </div>

                        <div className="grid gap-4 xl:grid-cols-2">
                            <Section title={t('performance.dashboard.agreementPipeline')} description={t('performance.dashboard.agreementPipelineHelp')}>
                                {agreementTotal === 0 ? <Empty compact>{t('performance.agreements.empty')}</Empty> : (
                                    <ul className="divide-y divide-gray-100 dark:divide-slate-800">
                                        {Object.entries(agreementStatus).map(([status, total]) => <li key={status} className="flex items-center justify-between gap-2 py-2.5 text-sm"><Pill group="agreement" value={status} /><span className="tabular-nums font-semibold text-gray-700 dark:text-slate-200">{total}</span></li>)}
                                    </ul>
                                )}
                            </Section>

                            <Section title={t('performance.dashboard.distribution')} description={t('performance.dashboard.noDistributionNote')}>
                                {totalRated === 0 ? <Empty compact>{t('performance.dashboard.noFinalizedResults')}</Empty> : (
                                    <ul className="space-y-4">
                                        {distribution.map((row) => {
                                            const share = (Number(row.total) / totalRated) * 100;
                                            return <li key={row.rating_label_en ?? 'none'} className="text-sm"><div className="mb-1.5 flex justify-between gap-4 text-gray-700 dark:text-slate-200"><span>{(locale === 'am' && row.rating_label_am) || row.rating_label_en || '—'}</span><span className="tabular-nums">{row.total} ({formatScore(share)}%)</span></div><Bar value={share} /></li>;
                                        })}
                                    </ul>
                                )}
                            </Section>
                        </div>
                    </>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
