import PageHeader from '@/Components/PageHeader';
import AppMetricCard from '@/Components/ui/AppMetricCard';
import RatingStars from '@/Components/ServiceFeedback/RatingStars';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { MessageSquare, Star, AlertTriangle } from 'lucide-react';
import type { JSX } from 'react';

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
    filters: Record<string, string | undefined>;
    can: { export: boolean };
};

export default function ServiceFeedbackReports({
    summary,
    byEmployee,
    byOrganization,
    byServiceType,
    lowRated,
    filters,
    can,
}: Props): JSX.Element {
    const { locale, t } = useLocale();
    const am = locale === 'am';

    const label = (pair: NamePair): string => (am ? (pair?.am ?? pair?.en) : pair?.en) ?? '—';

    const exportHref = `${route('service-feedback.admin.export')}?${new URLSearchParams(
        Object.entries(filters).filter(([, v]) => v !== undefined && v !== '') as [string, string][],
    ).toString()}`;

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

                <div className="grid gap-4 sm:grid-cols-3">
                    <AppMetricCard
                        label={t('serviceFeedback.totalFeedback')}
                        value={summary.total}
                        icon={<MessageSquare className="h-5 w-5" />}
                        variant="primary"
                    />
                    <AppMetricCard
                        label={t('serviceFeedback.averageRating')}
                        value={summary.average.toFixed(2)}
                        icon={<Star className="h-5 w-5" />}
                        variant="success"
                    />
                    <AppMetricCard
                        label={t('serviceFeedback.lowRatingReport')}
                        value={summary.low_rated}
                        icon={<AlertTriangle className="h-5 w-5" />}
                        variant="danger"
                    />
                </div>

                {/*
                 * Employee performance is ordered worst-average-first by the
                 * query service: the point of this table is to surface the
                 * desks that need attention, not to rank the best.
                 */}
                <PerformanceTable
                    title={t('serviceFeedback.averageRatingByEmployee')}
                    rows={byEmployee}
                    nameHeader={t('serviceFeedback.filterEmployee')}
                    t={t}
                    showLowColumn
                />

                <PerformanceTable
                    title={t('serviceFeedback.averageRatingByOrganization')}
                    rows={byOrganization}
                    nameHeader={t('serviceFeedback.filterOrganization')}
                    t={t}
                />

                <PerformanceTable
                    title={t('serviceFeedback.serviceTypePerformance')}
                    rows={byServiceType}
                    nameHeader={t('serviceFeedback.filterServiceType')}
                    t={t}
                />

                {/* Low rating watchlist */}
                <div className="rounded-xl border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <h2 className="border-b border-gray-100 px-5 py-3 text-sm font-semibold text-gray-900 dark:border-slate-800 dark:text-slate-100">
                        {t('serviceFeedback.lowRatingReport')}
                    </h2>

                    {lowRated.length === 0 ? (
                        <p className="px-5 py-6 text-sm text-gray-500 dark:text-slate-400">
                            {t('serviceFeedback.noFeedbackYet')}
                        </p>
                    ) : (
                        <ul className="divide-y divide-gray-100 dark:divide-slate-800">
                            {lowRated.map((row) => (
                                <li key={row.id} className="flex items-start justify-between gap-3 px-5 py-3">
                                    <div className="min-w-0">
                                        <RatingStars rating={row.rating} />
                                        <p className="mt-1 text-sm text-gray-700 dark:text-slate-300">{row.comment ?? '—'}</p>
                                        <p className="mt-1 text-xs text-gray-500 dark:text-slate-400">
                                            {row.employee?.name ?? '—'} · {label(row.service_type)} · {label(row.organization)} ·{' '}
                                            <LocalizedDateDisplay value={row.created_at} />
                                        </p>
                                    </div>
                                    <Link
                                        href={route('service-feedback.admin.show', row.id)}
                                        className="shrink-0 text-xs font-medium text-blue-600 hover:underline dark:text-blue-400"
                                    >
                                        {t('common.view')}
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
    title,
    rows,
    nameHeader,
    t,
    showLowColumn = false,
}: {
    title: string;
    rows: PerformanceRow[];
    nameHeader: string;
    t: (key: string) => string;
    showLowColumn?: boolean;
}): JSX.Element {
    return (
        <div className="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <h2 className="border-b border-gray-100 px-5 py-3 text-sm font-semibold text-gray-900 dark:border-slate-800 dark:text-slate-100">
                {title}
            </h2>

            {rows.length === 0 ? (
                <p className="px-5 py-6 text-sm text-gray-500 dark:text-slate-400">{t('serviceFeedback.noFeedbackYet')}</p>
            ) : (
                <table className="min-w-full divide-y divide-gray-200 text-sm dark:divide-slate-800">
                    <thead className="bg-gray-50 dark:bg-slate-950">
                        <tr>
                            <th className="px-5 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                                {nameHeader}
                            </th>
                            <th className="px-5 py-2.5 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                                {t('serviceFeedback.totalFeedback')}
                            </th>
                            {showLowColumn && (
                                <th className="px-5 py-2.5 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                                    {t('serviceFeedback.lowRatingReport')}
                                </th>
                            )}
                            <th className="px-5 py-2.5 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                                {t('serviceFeedback.averageRating')}
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                        {rows.map((row) => (
                            <tr key={row.id ?? row.name}>
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
                                    <td className="px-5 py-2.5 text-right tabular-nums text-red-600 dark:text-red-400">
                                        {row.low_rated ?? 0}
                                    </td>
                                )}
                                <td className="px-5 py-2.5 text-right">
                                    <span className="inline-flex items-center gap-2">
                                        <RatingStars rating={Math.round(row.average)} />
                                        <span className="font-medium tabular-nums text-gray-900 dark:text-slate-100">
                                            {row.average.toFixed(2)}
                                        </span>
                                    </span>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
        </div>
    );
}
