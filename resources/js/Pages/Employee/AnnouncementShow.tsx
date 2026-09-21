import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { localizedName } from '@/utils/localizedName';
import type { PageProps } from '@/types';

/**
 * One transfer announcement, inside the portal.
 *
 * Mirrors the public detail page's fields, but in the signed-in chrome and
 * with the apply action pointing at the portal's own route, so the employee
 * never leaves /my-portal between browsing and applying.
 */
type Announcement = {
    id: string;
    organization_name_en: string | null;
    organization_name_am: string | null;
    position_title_en: string | null;
    position_title_am: string | null;
    grade_level: string | null;
    salary_min: number | string | null;
    salary_max: number | string | null;
    number_of_vacancies: number;
    opening_date: string | null;
    closing_date: string | null;
    eligibility_rules: string | null;
    required_documents: string | null;
    is_open: boolean;
};

type Props = PageProps & {
    announcement: Announcement;
    already_applied: boolean;
    has_employee: boolean;
};

function Detail({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="min-w-0">
            <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">{label}</dt>
            <dd className="mt-0.5 text-sm font-semibold text-gray-900 dark:text-slate-100">{children}</dd>
        </div>
    );
}

export default function AnnouncementShow({ announcement, already_applied, has_employee }: Props) {
    const { t, locale } = useLocale();
    const name = (en: string | null, am: string | null) => localizedName(en ?? '', am, locale) || '—';

    const position = name(announcement.position_title_en, announcement.position_title_am);
    const canApply = announcement.is_open && has_employee && !already_applied;

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={position}
                    description={name(announcement.organization_name_en, announcement.organization_name_am)}
                    backHref={route('employee.announcements')}
                    actions={
                        canApply ? (
                            <Link
                                href={route('employee.announcements.apply', { announcement: announcement.id })}
                                className="rounded-lg bg-[var(--color-primary)] px-3 py-1.5 text-sm font-medium text-white hover:opacity-90"
                            >
                                {t('transfers.applyForTransfer')}
                            </Link>
                        ) : undefined
                    }
                />
            }
        >
            <Head title={position} />

            <div className="space-y-4">
                {already_applied && (
                    <div className="rounded-panel border border-blue-200 bg-blue-50 p-4 text-sm dark:border-blue-900/50 dark:bg-blue-950/20">
                        <p className="font-medium text-blue-800 dark:text-blue-300">
                            {t('transfers.applicationAlreadySubmitted')}
                        </p>
                        <p className="mt-0.5 text-xs text-blue-700 dark:text-blue-400">
                            {t('transfers.applicationUnderReview')}
                        </p>
                        <Link
                            href={route('employee.transfer-applications')}
                            className="mt-2 inline-block text-xs font-medium text-[var(--color-primary)] hover:underline"
                        >
                            {t('transfers.myApplications')}
                        </Link>
                    </div>
                )}

                {!announcement.is_open && !already_applied && (
                    <div className="rounded-panel border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/20 dark:text-amber-300">
                        {t('transfers.notAcceptingApplicationsInfo')}
                    </div>
                )}

                {!has_employee && (
                    <div className="rounded-panel border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/20 dark:text-amber-300">
                        {t('transfers.noEmployeeProfile')}
                    </div>
                )}

                <div className="rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <dl className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        <Detail label={t('transfers.vacancies')}>{announcement.number_of_vacancies}</Detail>

                        {announcement.grade_level && (
                            <Detail label={t('transfers.gradeLevel')}>{announcement.grade_level}</Detail>
                        )}

                        {(announcement.salary_min || announcement.salary_max) && (
                            <Detail label={t('transfers.salary')}>
                                {[announcement.salary_min, announcement.salary_max].filter(Boolean).join(' – ')}
                            </Detail>
                        )}

                        {announcement.opening_date && (
                            <Detail label={t('transfers.openingDate')}>
                                <LocalizedDateDisplay value={announcement.opening_date} />
                            </Detail>
                        )}

                        {announcement.closing_date && (
                            <Detail label={t('transfers.closingOn')}>
                                <LocalizedDateDisplay value={announcement.closing_date} />
                            </Detail>
                        )}
                    </dl>
                </div>

                {announcement.eligibility_rules && (
                    <div className="rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">
                            {t('transfers.eligibilityRequirements')}
                        </h2>
                        <p className="mt-2 whitespace-pre-line text-sm text-gray-700 dark:text-slate-300">
                            {announcement.eligibility_rules}
                        </p>
                    </div>
                )}

                {announcement.required_documents && (
                    <div className="rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">
                            {t('transfers.requiredDocuments')}
                        </h2>
                        <p className="mt-2 whitespace-pre-line text-sm text-gray-700 dark:text-slate-300">
                            {announcement.required_documents}
                        </p>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
