import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import RatingStars from '@/Components/ServiceFeedback/RatingStars';
import FeedbackFilterBar, { type FeedbackFilterOptions, type FeedbackFilters } from '@/Components/ServiceFeedback/FeedbackFilterBar';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import type { JSX } from 'react';

type NamePair = { en: string | null; am: string | null } | null;

type FeedbackRow = {
    id: string;
    rating: number;
    comment: string | null;
    status: string;
    created_at: string | null;
    employee: { id: string; name: string | null; employee_number: string | null } | null;
    organization: NamePair;
    organization_unit: NamePair;
    service_type: NamePair;
};

type Props = {
    feedback: { data: FeedbackRow[]; links: { url: string | null; label: string; active: boolean }[] };
    filters: FeedbackFilters;
    filterOptions: FeedbackFilterOptions;
    statuses: string[];
    can: { review: boolean; hide: boolean; delete: boolean; export: boolean };
};

export default function ServiceFeedbackIndex({ feedback, filters, filterOptions, statuses, can }: Props): JSX.Element {
    const { locale, t } = useLocale();
    const am = locale === 'am';

    const label = (pair: NamePair): string => (am ? (pair?.am ?? pair?.en) : pair?.en) ?? '—';

    const exportHref = `${route('service-feedback.admin.export')}?${new URLSearchParams(
        Object.entries(filters).filter(([, v]) => v !== undefined && v !== '') as [string, string][],
    ).toString()}`;

    return (
        <AuthenticatedLayout>
            <Head title={t('serviceFeedback.feedbackList')} />

            <div className="space-y-5">
                <PageHeader
                    title={t('serviceFeedback.feedbackList')}
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
                    routeName="service-feedback.admin.index"
                    filters={filters}
                    filterOptions={filterOptions}
                    statuses={statuses}
                />

                {/* Table */}
                <div className="overflow-x-auto rounded-card border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <table className="min-w-full divide-y divide-gray-200 text-sm dark:divide-slate-800">
                        <thead className="bg-gray-50 dark:bg-slate-950">
                            <tr>
                                <Th>{t('serviceFeedback.satisfactionRating')}</Th>
                                <Th>{t('serviceFeedback.filterEmployee')}</Th>
                                <Th>{t('serviceFeedback.filterServiceType')}</Th>
                                <Th>{t('serviceFeedback.filterOrganization')}</Th>
                                <Th>{t('serviceFeedback.comment')}</Th>
                                <Th>{t('serviceFeedback.filterStatus')}</Th>
                                <Th>{t('serviceFeedback.submittedDate')}</Th>
                                <Th />
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                            {feedback.data.length === 0 && (
                                <tr>
                                    <td colSpan={8} className="px-4 py-8 text-center text-gray-500 dark:text-slate-400">
                                        {t('serviceFeedback.noFeedbackYet')}
                                    </td>
                                </tr>
                            )}

                            {feedback.data.map((row) => (
                                <tr key={row.id} className="hover:bg-gray-50 dark:hover:bg-slate-800/50">
                                    <td className="whitespace-nowrap px-4 py-2.5">
                                        <RatingStars rating={row.rating} />
                                    </td>
                                    <td className="px-4 py-2.5">
                                        <div className="text-gray-900 dark:text-slate-100">{row.employee?.name ?? '—'}</div>
                                        <div className="text-xs text-gray-500 dark:text-slate-400">
                                            {row.employee?.employee_number}
                                        </div>
                                    </td>
                                    <td className="px-4 py-2.5 text-gray-700 dark:text-slate-300">{label(row.service_type)}</td>
                                    <td className="px-4 py-2.5 text-gray-700 dark:text-slate-300">{label(row.organization)}</td>
                                    <td className="max-w-xs truncate px-4 py-2.5 text-gray-600 dark:text-slate-400">
                                        {row.comment ?? '—'}
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-2.5">
                                        <StatusBadge status={row.status} />
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-2.5 text-gray-600 dark:text-slate-400">
                                        {row.created_at ? <LocalizedDateDisplay value={row.created_at} /> : '—'}
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-2.5 text-right">
                                        <Link
                                            href={route('service-feedback.admin.show', row.id)}
                                            className="text-xs font-medium text-[color:var(--color-primary)] hover:underline dark:text-[color:var(--color-primary)]"
                                        >
                                            {t('common.view')}
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {/* Pagination */}
                {feedback.links.length > 3 && (
                    <div className="flex flex-wrap gap-1">
                        {feedback.links.map((link, index) => (
                            <Link
                                key={index}
                                href={link.url ?? '#'}
                                preserveState
                                preserveScroll
                                className={`rounded-md px-3 py-1.5 text-sm ${
                                    link.active
                                        ? 'bg-[color:var(--color-primary)] text-white'
                                        : link.url
                                          ? 'border border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800'
                                          : 'cursor-not-allowed border border-gray-200 text-gray-400 dark:border-slate-800 dark:text-slate-600'
                                }`}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

function Th({ children }: { children?: React.ReactNode }): JSX.Element {
    return (
        <th className="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-slate-400">
            {children}
        </th>
    );
}
