import PageHeader from '@/Components/PageHeader';
import AppMetricCard from '@/Components/ui/AppMetricCard';
import RatingStars from '@/Components/ServiceFeedback/RatingStars';
import FeedbackFilterBar, { type FeedbackFilterOptions, type FeedbackFilters } from '@/Components/ServiceFeedback/FeedbackFilterBar';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { MessageSquareIcon as MessageSquare, StarIcon as Star, AlertTriangle } from '@/Components/Icons';
import { useState, type JSX } from 'react';

type NamePair = { en: string | null; am: string | null } | null;

type PerformanceRow = {
    id: string | null;
    name: string | null;
    employee_number?: string | null;
    total: number;
    average: number;
    low_rated?: number;
};

type LowRatedRow = {
    id: string;
    rating: number;
    comment: string | null;
    created_at: string | null;
    employee: { id: string; name: string | null; employee_number: string | null } | null;
    organization: NamePair;
    service_type: NamePair;
};

type Props = {
    summary: { total: number; average: number; low_rated: number; pending: number };
    byEmployee: PerformanceRow[];
    byOrganization: PerformanceRow[];
    byServiceType: PerformanceRow[];
    lowRated: LowRatedRow[];
    filters: FeedbackFilters;
    filterOptions: FeedbackFilterOptions;
    statuses: string[];
    can: { export: boolean };
};

type TabKey = 'employee' | 'organization' | 'serviceType';

const MAX_RATING = 5;

/** Red below 2.5, amber below 3.5, green above. */
function ratingTone(average: number): string {
    if (average < 2.5) {
        return 'bg-red-500';
    }

    return average < 3.5 ? 'bg-amber-500' : 'bg-emerald-500';
}

export default function ServiceFeedbackReports({
    summary,
    byEmployee,
    byOrganization,
    byServiceType,
    lowRated,
    filters,
    filterOptions,
    statuses,
    can,
}: Props): JSX.Element {
    const { locale, t } = useLocale();
    const am = locale === 'am';
    const [tab, setTab] = useState<TabKey>('employee');

    const label = (pair: NamePair): string => (am ? (pair?.am ?? pair?.en) : pair?.en) ?? '—';

    const exportHref = `${route('service-feedback.admin.export')}?${new URLSearchParams(
        Object.entries(filters).filter(([, v]) => v !== undefined && v !== '') as [string, string][],
    ).toString()}`;

    /*
     * Each breakdown is ordered worst-average-first by the query service: the
     * point of these tables is to surface the desks that need attention, not
     * to rank the best. They share one panel because they are the same shape —
     * three stacked tables only made the page longer, not clearer.
     */
    const tabs: { key: TabKey; label: string; rows: PerformanceRow[]; nameHeader: string; showLowColumn?: boolean }[] = [
        {
            key: 'employee',
            label: t('serviceFeedback.averageRatingByEmployee'),
            rows: byEmployee,
            nameHeader: t('serviceFeedback.filterEmployee'),
            showLowColumn: true,
        },
        {
            key: 'organization',
            label: t('serviceFeedback.averageRatingByOrganization'),
            rows: byOrganization,
            nameHeader: t('serviceFeedback.filterOrganization'),
        },
        {
            key: 'serviceType',
            label: t('serviceFeedback.serviceTypePerformance'),
            rows: byServiceType,
            nameHeader: t('serviceFeedback.filterServiceType'),
        },
    ];

    const activeTab = tabs.find((entry) => entry.key === tab) ?? tabs[0];
    const lowRatedShare = summary.total > 0 ? Math.round((summary.low_rated / summary.total) * 100) : 0;

    return (
        <AuthenticatedLayout>
            <Head title={t('serviceFeedback.reports')} />

            <div className="space-y-6">
                <PageHeader
                    title={t('serviceFeedback.reports')}
                    description={t('serviceFeedback.moduleSubtitle')}
                    backHref={route('service-feedback.admin.dashboard')}
                    actions={
                        can.export ? (
                            <a
                                href={exportHref}
                                className="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                            >
                                {t('serviceFeedback.exportFeedback')}
                            </a>
                        ) : undefined
                    }
                />

                <FeedbackFilterBar
                    routeName="service-feedback.admin.reports"
                    filters={filters}
                    filterOptions={filterOptions}
                    statuses={statuses}
                />

                <div className="grid gap-4 sm:grid-cols-3">
                    <AppMetricCard
                        label={t('serviceFeedback.totalFeedback')}
                        value={summary.total.toLocaleString()}
                        icon={<MessageSquare className="h-5 w-5" />}
                        variant="primary"
                    />
                    <AppMetricCard
                        label={t('serviceFeedback.averageRating')}
                        value={summary.average.toFixed(2)}
                        detail={<RatingStars rating={Math.round(summary.average)} />}
                        icon={<Star className="h-5 w-5" />}
                        variant="success"
                    />
                    <AppMetricCard
                        label={t('serviceFeedback.lowRatingReport')}
                        value={summary.low_rated.toLocaleString()}
                        detail={summary.total > 0 ? `${lowRatedShare}%` : undefined}
                        icon={<AlertTriangle className="h-5 w-5" />}
                        variant="danger"
                    />
                </div>

                {/* Performance breakdowns share one panel, switched by tab. */}
                <div className="rounded-card border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-3 dark:border-slate-800">
                        <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">{activeTab.label}</h2>

                        <div className="flex flex-wrap gap-1 rounded-lg border border-gray-200 p-1 dark:border-slate-800">
                            {tabs.map((entry) => (
                                <button
                                    key={entry.key}
                                    type="button"
                                    onClick={() => setTab(entry.key)}
                                    className={`rounded-md px-3 py-1.5 text-xs font-medium transition ${
                                        entry.key === activeTab.key
                                            ? 'bg-[color:var(--color-primary)] text-white'
                                            : 'text-gray-600 hover:bg-gray-50 dark:text-slate-300 dark:hover:bg-slate-800'
                                    }`}
                                >
                                    {entry.nameHeader}
                                </button>
                            ))}
                        </div>
                    </div>

                    <PerformanceTable
                        rows={activeTab.rows}
                        nameHeader={activeTab.nameHeader}
                        t={t}
                        showLowColumn={activeTab.showLowColumn}
                    />
                </div>

                {/* Low rating watchlist */}
                <div className="rounded-card border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <div className="flex items-center justify-between gap-3 border-b border-gray-100 px-5 py-3 dark:border-slate-800">
                        <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">
                            {t('serviceFeedback.lowRatingReport')}
                        </h2>
                        {lowRated.length > 0 && (
                            <span className="rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-950/40 dark:text-red-300">
                                {lowRated.length}
                            </span>
                        )}
                    </div>

                    {lowRated.length === 0 ? (
                        <p className="px-5 py-10 text-center text-sm text-gray-500 dark:text-slate-400">
                            {t('serviceFeedback.noFeedbackYet')}
                        </p>
                    ) : (
                        /*
                         * Capped so a long watchlist scrolls inside the card
                         * instead of pushing the rest of the page away.
                         */
                        <ul className="max-h-[28rem] divide-y divide-gray-100 overflow-y-auto dark:divide-slate-800">
                            {lowRated.map((row) => (
                                <li key={row.id}>
                                    <Link
                                        href={route('service-feedback.admin.show', row.id)}
                                        className="flex items-start justify-between gap-3 px-5 py-3 transition hover:bg-gray-50 dark:hover:bg-slate-800/60"
                                    >
                                        <div className="min-w-0">
                                            <RatingStars rating={row.rating} />
                                            <p className="mt-1 text-sm text-gray-700 dark:text-slate-300">
                                                {row.comment ?? '—'}
                                            </p>
                                            <p className="mt-1 text-xs text-gray-500 dark:text-slate-400">
                                                {row.employee?.name ?? '—'} · {label(row.service_type)} · {label(row.organization)} ·{' '}
                                                <LocalizedDateDisplay value={row.created_at} />
                                            </p>
                                        </div>
                                        <span className="shrink-0 text-xs font-medium text-[color:var(--color-primary)]">
                                            {t('common.view')}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function PerformanceTable({
    rows,
    nameHeader,
    t,
    showLowColumn = false,
}: {
    rows: PerformanceRow[];
    nameHeader: string;
    t: (key: string) => string;
    showLowColumn?: boolean;
}): JSX.Element {
    if (rows.length === 0) {
        return (
            <p className="px-5 py-10 text-center text-sm text-gray-500 dark:text-slate-400">
                {t('serviceFeedback.noFeedbackYet')}
            </p>
        );
    }

    return (
        <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200 text-sm dark:divide-slate-800">
                <thead className="bg-gray-50 dark:bg-slate-950">
                    <tr>
                        <th className="w-10 px-5 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-slate-400">#</th>
                        <th className="px-5 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-slate-400">
                            {nameHeader}
                        </th>
                        <th className="px-5 py-2.5 text-right text-xs font-semibold text-gray-500 dark:text-slate-400">
                            {t('serviceFeedback.totalFeedback')}
                        </th>
                        {showLowColumn && (
                            <th className="px-5 py-2.5 text-right text-xs font-semibold text-gray-500 dark:text-slate-400">
                                {t('serviceFeedback.lowRatingReport')}
                            </th>
                        )}
                        <th className="px-5 py-2.5 text-right text-xs font-semibold text-gray-500 dark:text-slate-400">
                            {t('serviceFeedback.averageRating')}
                        </th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                    {rows.map((row, index) => (
                        <tr key={row.id ?? row.name} className="transition hover:bg-gray-50 dark:hover:bg-slate-800/40">
                            <td className="px-5 py-2.5 text-xs tabular-nums text-gray-400 dark:text-slate-500">{index + 1}</td>
                            <td className="px-5 py-2.5">
                                <div className="text-gray-900 dark:text-slate-100">{row.name ?? '—'}</div>
                                {row.employee_number && (
                                    <div className="text-xs text-gray-500 dark:text-slate-400">{row.employee_number}</div>
                                )}
                            </td>
                            <td className="px-5 py-2.5 text-right tabular-nums text-gray-600 dark:text-slate-400">
                                {row.total}
                            </td>
                            {showLowColumn && (
                                <td className="px-5 py-2.5 text-right tabular-nums">
                                    {(row.low_rated ?? 0) > 0 ? (
                                        <span className="rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-950/40 dark:text-red-300">
                                            {row.low_rated}
                                        </span>
                                    ) : (
                                        <span className="text-gray-400 dark:text-slate-600">0</span>
                                    )}
                                </td>
                            )}
                            <td className="px-5 py-2.5">
                                {/*
                                 * Bar first, number second: the bar is what makes
                                 * a weak desk visible without reading decimals.
                                 */}
                                <div className="flex items-center justify-end gap-3">
                                    <div className="h-1.5 w-24 shrink-0 overflow-hidden rounded-full bg-gray-100 dark:bg-slate-800">
                                        <div
                                            className={`h-full rounded-full ${ratingTone(row.average)}`}
                                            style={{ width: `${Math.min(100, (row.average / MAX_RATING) * 100)}%` }}
                                        />
                                    </div>
                                    <span className="w-10 text-right font-medium tabular-nums text-gray-900 dark:text-slate-100">
                                        {row.average.toFixed(2)}
                                    </span>
                                </div>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
