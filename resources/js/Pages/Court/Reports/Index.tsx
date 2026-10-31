import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import DashboardSection from '@/Components/dashboard/DashboardSection';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { AppMetricCard, Button, Card, FilterField, FilterPanel, Select, TableCard, badgeClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import { formatNumber } from '@/utils/displayFormat';

type Pair = { key: string; total: number };
interface Props {
    summaryCards: { key: string; value: number }[];
    breakdowns: Record<'cases_status' | 'cases_types' | 'appeals_status' | 'decisions_status' | 'letters_status' | 'applicants_status' | 'users_status', Pair[]>;
    recentCases: { id: number; case_number: string; title: string | null; status: string; applicant: string | null; case_type: string | null; created_at: string | null }[];
    recentAppeals: { id: number; appeal_number: string; title: string | null; status: string; case_id: number; case_number: string; created_at: string | null }[];
    caseStatuses: string[];
    judges: { id: number; name: string }[];
    filtered: { count: number; status: Pair[]; byJudge: Pair[] };
    filters: { start_date: string; end_date: string; status: string; judge_id: string };
}

/** The system report: every major record counted, breakdowns by state, the latest cases and appeals, and a printable filtered window. */
export default function ReportsIndex({ summaryCards, breakdowns, recentCases, recentAppeals, caseStatuses, judges, filtered, filters }: Props) {
    const { t } = useLocale();
    const [form, setForm] = useState(filters);
    const label = (value: string) => (value ? value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()) : t('cm.reports.unknown'));
    const set = (key: keyof typeof form) => (value: string) => setForm((current) => ({ ...current, [key]: value }));

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        router.get(route('court.reports.index'), Object.fromEntries(Object.entries(form).filter(([, value]) => value !== '')), { preserveState: true, preserveScroll: true });
    };
    const breakdown = (key: keyof Props['breakdowns']) => (
        <Card key={key}>
            <div className="flex items-center justify-between">
                <h2 className="text-sm font-semibold text-(--app-foreground)">{t(`cm.reports.sections.${key}`)}</h2>
                <span className="text-xs uppercase tracking-wide text-(--app-muted-foreground)">{formatNumber(breakdowns[key].reduce((sum, row) => sum + row.total, 0))} {t('cm.reports.total')}</span>
            </div>
            <ul className="mt-4 space-y-2 text-sm">
                {breakdowns[key].length === 0 && <li className="text-xs text-(--app-muted-foreground)">{t('cm.reports.no_data')}</li>}
                {breakdowns[key].map((row) => (
                    <li key={row.key} className="flex items-center justify-between gap-3"><span className="truncate">{label(row.key)}</span><span className="font-semibold tabular-nums text-(--app-foreground)">{formatNumber(row.total)}</span></li>
                ))}
            </ul>
        </Card>
    );

    return (
        <AuthenticatedLayout header={<PageHeader title={t('cm.reports.title')} description={t('cm.reports.description')}
            actions={<Button variant="outline" onClick={() => window.print()}>{t('cm.reports.print')}</Button>} />}>
            <Head title={t('cm.reports.title')} />

            <div className="space-y-8">
                <DashboardSection title={t('cm.reports.summary')}>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        {summaryCards.map((card) => <AppMetricCard key={card.key} label={t(`cm.reports.cards.${card.key}`)} value={formatNumber(card.value)} variant="neutral" />)}
                    </div>
                </DashboardSection>

                <DashboardSection title={t('cm.reports.filter.title')} description={t('cm.reports.filter.subtitle')}>
                    <FilterPanel onSubmit={submit} onReset={() => router.get(route('court.reports.index'))} hasActiveFilters={Object.values(filters).some(Boolean)} label={t('cm.reports.filter.title')} className="print:hidden">
                        <FilterField label={t('cm.reports.filter.start')}><LocalizedDatePicker value={form.start_date} onChange={set('start_date')} /></FilterField>
                        <FilterField label={t('cm.reports.filter.end')}><LocalizedDatePicker value={form.end_date} onChange={set('end_date')} min={form.start_date || undefined} /></FilterField>
                        <FilterField label={t('cm.reports.filter.status')}>
                            <Select value={form.status} onChange={(event) => set('status')(event.target.value)}>
                                <option value="">{t('cm.reports.filter.status_all')}</option>
                                {caseStatuses.map((status) => <option key={status} value={status}>{t(`cm.cases.status.${status}`)}</option>)}
                            </Select>
                        </FilterField>
                        <FilterField label={t('cm.reports.filter.judge')}>
                            <Select value={form.judge_id} onChange={(event) => set('judge_id')(event.target.value)}>
                                <option value="">{t('cm.reports.filter.judge_all')}</option>
                                {judges.map((judge) => <option key={judge.id} value={judge.id}>{judge.name}</option>)}
                            </Select>
                        </FilterField>
                    </FilterPanel>
                    <div className="grid gap-4 lg:grid-cols-3">
                        <Card>
                            <h3 className="text-sm font-semibold text-(--app-foreground)">{t('cm.reports.filter.summary')}</h3>
                            <p className="mt-1 text-sm text-(--app-muted-foreground)">{t('cm.reports.filter.summary_hint')}</p>
                            <dl className="mt-4 space-y-2 text-sm">
                                <div className="flex justify-between"><dt className="text-(--app-muted-foreground)">{t('cm.reports.filter.cases_filtered')}</dt><dd className="font-semibold">{formatNumber(filtered.count)}</dd></div>
                                {filters.start_date && <div className="flex justify-between"><dt className="text-(--app-muted-foreground)">{t('cm.reports.filter.start')}</dt><dd><LocalizedDateDisplay value={filters.start_date} /></dd></div>}
                                {filters.end_date && <div className="flex justify-between"><dt className="text-(--app-muted-foreground)">{t('cm.reports.filter.end')}</dt><dd><LocalizedDateDisplay value={filters.end_date} /></dd></div>}
                                {filters.status && <div className="flex justify-between"><dt className="text-(--app-muted-foreground)">{t('cm.reports.filter.status')}</dt><dd>{t(`cm.cases.status.${filters.status}`)}</dd></div>}
                                {filters.judge_id && <div className="flex justify-between"><dt className="text-(--app-muted-foreground)">{t('cm.reports.filter.judge')}</dt><dd>{judges.find((judge) => String(judge.id) === filters.judge_id)?.name ?? t('cm.reports.unknown')}</dd></div>}
                            </dl>
                        </Card>
                        <Card>
                            <h3 className="text-sm font-semibold text-(--app-foreground)">{t('cm.reports.filter.status_breakdown')}</h3>
                            <p className="mt-1 text-xs text-(--app-muted-foreground)">{t('cm.reports.filter.status_hint')}</p>
                            <ul className="mt-4 space-y-2 text-sm">
                                {filtered.status.length === 0 && <li className="text-xs text-(--app-muted-foreground)">{t('cm.reports.no_data')}</li>}
                                {filtered.status.map((row) => <li key={row.key} className="flex justify-between"><span>{label(row.key)}</span><span className="font-semibold tabular-nums">{formatNumber(row.total)}</span></li>)}
                            </ul>
                        </Card>
                        <Card>
                            <h3 className="text-sm font-semibold text-(--app-foreground)">{t('cm.reports.filter.judge_breakdown')}</h3>
                            <p className="mt-1 text-xs text-(--app-muted-foreground)">{t('cm.reports.filter.judge_hint')}</p>
                            <ul className="mt-4 space-y-2 text-sm">
                                {filtered.byJudge.length === 0 && <li className="text-xs text-(--app-muted-foreground)">{t('cm.reports.no_data')}</li>}
                                {filtered.byJudge.map((row) => <li key={row.key} className="flex justify-between"><span>{row.key}</span><span className="font-semibold tabular-nums">{formatNumber(row.total)}</span></li>)}
                            </ul>
                        </Card>
                    </div>
                </DashboardSection>

                <div className="grid gap-4 lg:grid-cols-2">{breakdown('cases_status')}{breakdown('cases_types')}</div>
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                    {(['appeals_status', 'decisions_status', 'letters_status', 'applicants_status', 'users_status'] as const).map(breakdown)}
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <TableCard title={t('cm.reports.sections.recent_cases')} actions={<Link href={route('court.cases.index')} className="text-xs font-semibold text-primary hover:underline">{t('cm.reports.view_all').replace(':thing', t('cm.reports.cases'))}</Link>}>
                        <ul className="divide-y divide-(--app-border)">
                            {recentCases.length === 0 && <li className="px-4 py-6 text-center text-sm text-(--app-muted-foreground)">{t('cm.reports.no_data')}</li>}
                            {recentCases.map((row) => (
                                <li key={row.id} className="px-4 py-3 text-sm">
                                    <Link href={route('court.cases.show', row.id)} className="flex items-center justify-between gap-3">
                                        <span className="font-mono font-semibold text-primary">{row.case_number}</span>
                                        <span className={badgeClassName('neutral')}>{label(row.status)}</span>
                                    </Link>
                                    <p className="text-xs text-(--app-muted-foreground)">{row.title}</p>
                                    <p className="flex justify-between text-xs text-(--app-muted-foreground)"><span>{row.applicant ?? t('cm.reports.unknown')}</span><LocalizedDateDisplay value={row.created_at} withTime /></p>
                                </li>
                            ))}
                        </ul>
                    </TableCard>
                    <TableCard title={t('cm.reports.sections.recent_appeals')} actions={<Link href={route('court.appeals.index')} className="text-xs font-semibold text-primary hover:underline">{t('cm.reports.view_all').replace(':thing', t('cm.app.Appeals'))}</Link>}>
                        <ul className="divide-y divide-(--app-border)">
                            {recentAppeals.length === 0 && <li className="px-4 py-6 text-center text-sm text-(--app-muted-foreground)">{t('cm.reports.no_data')}</li>}
                            {recentAppeals.map((row) => (
                                <li key={row.id} className="px-4 py-3 text-sm">
                                    <Link href={route('court.appeals.show', row.id)} className="flex items-center justify-between gap-3">
                                        <span className="font-mono font-semibold text-primary">{row.appeal_number}</span>
                                        <span className={badgeClassName('neutral')}>{label(row.status)}</span>
                                    </Link>
                                    <p className="text-xs text-(--app-muted-foreground)">{row.title} · {row.case_number}</p>
                                    <p className="text-right text-xs text-(--app-muted-foreground)"><LocalizedDateDisplay value={row.created_at} withTime /></p>
                                </li>
                            ))}
                        </ul>
                    </TableCard>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
