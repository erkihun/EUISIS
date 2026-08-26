import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import RatingStars from '@/Components/ServiceFeedback/RatingStars';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
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

type Filters = {
    organization_id?: string;
    organization_unit_id?: string;
    employee_id?: string;
    service_type_id?: string;
    rating?: string;
    status?: string;
    date_from?: string;
    date_to?: string;
};

type Props = {
    feedback: { data: FeedbackRow[]; links: { url: string | null; label: string; active: boolean }[] };
    filters: Filters;
    filterOptions: {
        organizations: { id: string; name_en: string; name_am: string | null }[];
        serviceTypes: { id: string; name_en: string; name_am: string | null }[];
    };
    statuses: string[];
    can: { review: boolean; hide: boolean; delete: boolean; export: boolean };
};

const inputCls =
    'rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-900 focus:border-blue-500 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

export default function ServiceFeedbackIndex({ feedback, filters, filterOptions, statuses, can }: Props): JSX.Element {
    const { locale, t } = useLocale();
    const am = locale === 'am';

    const label = (pair: NamePair): string => (am ? (pair?.am ?? pair?.en) : pair?.en) ?? '—';

    /*
     * Filters round-trip through the server rather than filtering in the
     * browser: the result set is scoped and paginated server-side, so a
     * client-side filter would only ever narrow the current page.
     */
    function applyFilter(key: keyof Filters, value: string) {
        router.get(
            route('service-feedback.admin.index'),
            { ...filters, [key]: value || undefined },
            { preserveState: true, preserveScroll: true },
        );
    }

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

                {/* Filters */}
                <div className="flex flex-wrap gap-2 rounded-xl border border-gray-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-900">
                    <select
                        className={inputCls}
                        value={filters.organization_id ?? ''}
                        onChange={(e) => applyFilter('organization_id', e.target.value)}
                        aria-label={t('serviceFeedback.filterOrganization')}
                    >
                        <option value="">{t('serviceFeedback.filterOrganization')}</option>
                        {filterOptions.organizations.map((org) => (
                            <option key={org.id} value={org.id}>
                                {am ? (org.name_am ?? org.name_en) : org.name_en}
                            </option>
                        ))}
                    </select>

                    <select
                        className={inputCls}
                        value={filters.service_type_id ?? ''}
                        onChange={(e) => applyFilter('service_type_id', e.target.value)}
                        aria-label={t('serviceFeedback.filterServiceType')}
                    >
                        <option value="">{t('serviceFeedback.filterServiceType')}</option>
                        {filterOptions.serviceTypes.map((type) => (
                            <option key={type.id} value={type.id}>
                                {am ? (type.name_am ?? type.name_en) : type.name_en}
                            </option>
                        ))}
                    </select>

                    <select
                        className={inputCls}
                        value={filters.rating ?? ''}
                        onChange={(e) => applyFilter('rating', e.target.value)}
                        aria-label={t('serviceFeedback.filterRating')}
                    >
                        <option value="">{t('serviceFeedback.allRatings')}</option>
                        {[5, 4, 3, 2, 1].map((star) => (
                            <option key={star} value={star}>
                                {star} ★
                            </option>
                        ))}
                    </select>

                    <select
                        className={inputCls}
                        value={filters.status ?? ''}
                        onChange={(e) => applyFilter('status', e.target.value)}
                        aria-label={t('serviceFeedback.filterStatus')}
                    >
                        <option value="">{t('serviceFeedback.allStatuses')}</option>
                        {statuses.map((status) => (
                            <option key={status} value={status}>
                                {t(`serviceFeedback.status${status.charAt(0).toUpperCase()}${status.slice(1)}`)}
                            </option>
                        ))}
                    </select>

                    <input
                        type="date"
                        className={inputCls}
                        value={filters.date_from ?? ''}
                        onChange={(e) => applyFilter('date_from', e.target.value)}
                        aria-label={t('serviceFeedback.filterDateRange')}
                    />
                    <input
                        type="date"
                        className={inputCls}
                        value={filters.date_to ?? ''}
                        onChange={(e) => applyFilter('date_to', e.target.value)}
                        aria-label={t('serviceFeedback.filterDateRange')}
                    />
                </div>

                {/* Table */}
                <div className="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
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
                                            className="text-xs font-medium text-blue-600 hover:underline dark:text-blue-400"
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
                                        ? 'bg-blue-600 text-white'
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
        <th className="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
            {children}
        </th>
    );
}
