import PageHeader from '@/Components/PageHeader';
import AppMetricCard from '@/Components/ui/AppMetricCard';
import RatingStars from '@/Components/ServiceFeedback/RatingStars';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { MessageSquare, Star, AlertTriangle, Inbox } from 'lucide-react';
import type { JSX } from 'react';

type NamePair = { en: string | null; am: string | null } | null;

type GroupRow = {
    id: string | null;
    name: string | null;
    total: number;
    average: number;
    employee_number?: string | null;
};

type Comment = {
    id: string;
    rating: number;
    comment: string | null;
    status: string;
    created_at: string | null;
    employee: { id: string; name: string | null; employee_number: string | null } | null;
    organization: NamePair;
    service_type: NamePair;
};

type Props = {
    summary: { total: number; average: number; low_rated: number; pending: number };
    ratingDistribution: { rating: number; count: number }[];
    byOrganization: GroupRow[];
    byEmployee: GroupRow[];
    byServiceType: GroupRow[];
    recentComments: Comment[];
};

export default function ServiceFeedbackDashboard({
    summary,
    ratingDistribution,
    byOrganization,
    byEmployee,
    byServiceType,
    recentComments,
}: Props): JSX.Element {
    const { locale, t } = useLocale();
    const am = locale === 'am';

    const label = (pair: NamePair): string => (am ? (pair?.am ?? pair?.en) : pair?.en) ?? '—';

    // The widest bar sets the scale, so a low-volume period still reads clearly.
    const maxCount = Math.max(...ratingDistribution.map((row) => row.count), 1);

    return (
        <AuthenticatedLayout>
            <Head title={t('serviceFeedback.dashboard')} />

            <div className="space-y-6">
                <PageHeader
                    title={t('serviceFeedback.dashboard')}
                    description={t('serviceFeedback.moduleSubtitle')}
                    actions={
                        <div className="flex gap-2">
                            <Link
                                href={route('service-feedback.admin.index')}
                                className="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                            >
                                {t('serviceFeedback.feedbackList')}
                            </Link>
                            <Link
                                href={route('service-feedback.admin.reports')}
                                className="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                            >
                                {t('serviceFeedback.reports')}
                            </Link>
                        </div>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
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
                    <AppMetricCard
                        label={t('serviceFeedback.statusPending')}
                        value={summary.pending}
                        icon={<Inbox className="h-5 w-5" />}
                        variant="warning"
                    />
                </div>

                {/* Rating distribution */}
                <div className="rounded-xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">
                        {t('serviceFeedback.ratingDistribution')}
                    </h2>
                    <div className="mt-4 space-y-2">
                        {[...ratingDistribution].reverse().map((row) => (
                            <div key={row.rating} className="flex items-center gap-3">
                                <span className="w-16 shrink-0">
                                    <RatingStars rating={row.rating} />
                                </span>
                                <div className="h-2.5 flex-1 overflow-hidden rounded-full bg-gray-100 dark:bg-slate-800">
                                    <div
                                        className="h-full rounded-full bg-amber-400"
                                        style={{ width: `${(row.count / maxCount) * 100}%` }}
                                    />
                                </div>
                                <span className="w-12 shrink-0 text-right text-sm tabular-nums text-gray-600 dark:text-slate-400">
                                    {row.count}
                                </span>
                            </div>
                        ))}
                    </div>
                </div>

                {/* Breakdowns */}
                <div className="grid gap-4 lg:grid-cols-3">
                    <GroupCard title={t('serviceFeedback.feedbackByOrganization')} rows={byOrganization} emptyLabel={t('serviceFeedback.noFeedbackYet')} />
                    <GroupCard title={t('serviceFeedback.feedbackByEmployee')} rows={byEmployee} emptyLabel={t('serviceFeedback.noFeedbackYet')} />
                    <GroupCard title={t('serviceFeedback.feedbackByServiceType')} rows={byServiceType} emptyLabel={t('serviceFeedback.noFeedbackYet')} />
                </div>

                {/* Recent comments */}
                <div className="rounded-xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">
                        {t('serviceFeedback.recentComments')}
                    </h2>

                    {recentComments.length === 0 ? (
                        <p className="mt-3 text-sm text-gray-500 dark:text-slate-400">{t('serviceFeedback.noFeedbackYet')}</p>
                    ) : (
                        <ul className="mt-3 divide-y divide-gray-100 dark:divide-slate-800">
                            {recentComments.map((item) => (
                                <li key={item.id} className="py-3">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <RatingStars rating={item.rating} />
                                            <p className="mt-1 text-sm text-gray-700 dark:text-slate-300">{item.comment}</p>
                                            <p className="mt-1 text-xs text-gray-500 dark:text-slate-400">
                                                {item.employee?.name ?? '—'} · {label(item.service_type)} · {label(item.organization)}
                                            </p>
                                        </div>
                                        <Link
                                            href={route('service-feedback.admin.show', item.id)}
                                            className="shrink-0 text-xs font-medium text-blue-600 hover:underline dark:text-blue-400"
                                        >
                                            {t('common.view')}
                                        </Link>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function GroupCard({ title, rows, emptyLabel }: { title: string; rows: GroupRow[]; emptyLabel: string }): JSX.Element {
    return (
        <div className="rounded-xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">{title}</h2>

            {rows.length === 0 ? (
                <p className="mt-3 text-sm text-gray-500 dark:text-slate-400">{emptyLabel}</p>
            ) : (
                <ul className="mt-3 space-y-2">
                    {rows.map((row) => (
                        <li key={row.id ?? row.name} className="flex items-center justify-between gap-2 text-sm">
                            <span className="min-w-0 truncate text-gray-700 dark:text-slate-300">{row.name ?? '—'}</span>
                            <span className="flex shrink-0 items-center gap-2">
                                <span className="text-xs text-gray-500 dark:text-slate-400">({row.total})</span>
                                <span className="font-medium tabular-nums text-gray-900 dark:text-slate-100">
                                    {row.average.toFixed(2)}
                                </span>
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
