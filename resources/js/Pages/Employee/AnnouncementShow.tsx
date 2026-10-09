import PortalPage from '@/Components/employees/portal/PortalPage';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Link } from '@inertiajs/react';
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
    eligibility_rules: Array<{ type: string; operator: string; value: string }> | null;
    required_documents: string[] | null;
    is_open: boolean;
    positions: Array<{ id: string | null; position_title_en: string | null; position_title_am: string | null; position_code: string | null; organization_unit_en: string | null; organization_unit_am: string | null; grade_level: string | null; advertised_slots: number; eligible: boolean | null; eligibility_reasons: string[] }>;
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
    const canApply = announcement.is_open && has_employee && !already_applied && announcement.positions.some((position) => position.eligible !== false);

    return (
        <PortalPage
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
        >

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

                <section className="rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">{t('transfers.includedPositions')}</h2>
                    <div className="mt-3 space-y-3">
                        {announcement.positions.map((item, index) => <article key={item.id ?? `legacy-${index}`} className="rounded-lg border border-gray-200 p-4 dark:border-slate-700">
                            <div className="flex flex-wrap items-start justify-between gap-2"><div><h3 className="font-medium text-gray-900 dark:text-white">{name(item.position_title_en, item.position_title_am)}</h3><p className="mt-1 text-xs text-gray-500 dark:text-slate-400">{item.position_code ?? '—'} {item.grade_level ? `· ${t('transfers.gradeLevel')} ${item.grade_level}` : ''} {item.organization_unit_en ? `· ${name(item.organization_unit_en, item.organization_unit_am)}` : ''}</p></div><span className={`rounded-full px-2.5 py-0.5 text-xs font-semibold ${item.eligible === false ? 'bg-amber-100 text-amber-800 dark:bg-amber-950/50 dark:text-amber-300' : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300'}`}>{item.eligible === false ? t('transfers.notEligible') : t('transfers.eligible')}</span></div>
                            <p className="mt-2 text-xs text-gray-500 dark:text-slate-400">{t('transfers.advertisedSlots')}: {item.advertised_slots}</p>
                            {item.eligible === false && <p className="mt-2 text-xs text-amber-800 dark:text-amber-300">{t('transfers.requirementsNotMet')}</p>}
                        </article>)}
                    </div>
                </section>

                {announcement.eligibility_rules && announcement.eligibility_rules.length > 0 && (
                    <div className="rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">
                            {t('transfers.eligibilityRequirements')}
                        </h2>
                        <ul className="mt-2 space-y-1 text-sm text-gray-700 dark:text-slate-300">{announcement.eligibility_rules.map((rule, index) => <li key={index}>• {rule.type.replaceAll('_', ' ')}: {rule.value}</li>)}</ul>
                    </div>
                )}

                {announcement.required_documents && announcement.required_documents.length > 0 && (
                    <div className="rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">
                            {t('transfers.requiredDocuments')}
                        </h2>
                        <ul className="mt-2 space-y-1 text-sm text-gray-700 dark:text-slate-300">{announcement.required_documents.map((document, index) => <li key={index}>• {document}</li>)}</ul>
                    </div>
                )}
            </div>
        </PortalPage>
    );
}
