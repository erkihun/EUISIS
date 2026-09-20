import PublicLayout from '@/Layouts/PublicLayout';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Link, usePage } from '@inertiajs/react';
import { StatusBadge } from '@euisis/ui';
import {
    PublicCardDecor,
    PublicContainer,
    PublicPageHeader,
    publicButtonPrimary,
    publicCardClass,
} from '@/Components/public/PublicPage';
import { useLocale } from '@/hooks/useLocale';
import { SVGProps } from 'react';
import type { PageProps } from '@/types';

type IconProps = SVGProps<SVGSVGElement>;

function CalendarIcon(p: IconProps) {
    return (
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" {...p}>
            <rect x="3" y="4" width="18" height="18" rx="2" ry="2" />
            <line x1="16" y1="2" x2="16" y2="6" />
            <line x1="8" y1="2" x2="8" y2="6" />
            <line x1="3" y1="10" x2="21" y2="10" />
        </svg>
    );
}

function CheckCircleIcon(p: IconProps) {
    return (
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" {...p}>
            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
            <polyline points="22 4 12 14.01 9 11.01" />
        </svg>
    );
}

function FileTextIcon(p: IconProps) {
    return (
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" {...p}>
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
            <polyline points="14 2 14 8 20 8" />
            <line x1="16" y1="13" x2="8" y2="13" />
            <line x1="16" y1="17" x2="8" y2="17" />
            <polyline points="10 9 9 9 8 9" />
        </svg>
    );
}

type Announcement = {
    id: string;
    organization_name_en: string | null;
    organization_name_am: string | null;
    position_title_en: string | null;
    position_title_am: string | null;
    grade_level: string | null;
    salary_min: string | null;
    salary_max: string | null;
    number_of_vacancies: number;
    opening_date: string | null;
    closing_date: string | null;
    eligibility_rules: string[] | null;
    required_documents: string[] | null;
    status: string;
    is_open: boolean;
    published_at: string | null;
};

interface Props extends PageProps {
    announcement: Announcement;
    already_applied: boolean;
    is_authenticated: boolean;
    apply_url: string;
    login_url: string;
}

export default function TransferAnnouncementShow({ announcement: a, already_applied, is_authenticated, apply_url, login_url }: Props) {
    const { locale, t } = useLocale();
    const useAmharic = locale === 'am';
    const { props } = usePage<Props>();

    const flash = (props as any).flash as { message: string; type: string } | null;

    const orgName = (useAmharic ? a.organization_name_am : null) ?? a.organization_name_en ?? '—';
    const posTitle = (useAmharic ? a.position_title_am : null) ?? a.position_title_en ?? '—';

    const pageTitle = posTitle + ' — ' + orgName;

    return (
        <PublicLayout title={pageTitle}>
            <PublicPageHeader
                title={posTitle}
                description={orgName}
                breadcrumbs={[
                    { label: t('nav.announcements'), href: route('public.announcements') },
                    { label: t('home.announcementsPageTitle'), href: route('public.transfer-announcements') },
                    { label: posTitle },
                ]}
            />

            <div className="bg-gray-50 py-12 sm:py-16 dark:bg-slate-900">
                <PublicContainer narrow>
                    {/* Flash message */}
                    {flash && (
                        <div className={`mb-6 rounded-card px-4 py-3 text-sm font-medium ${flash.type === 'success' ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300' : 'bg-red-50 text-red-800 dark:bg-red-950/40 dark:text-red-300'}`}>
                            {flash.message}
                        </div>
                    )}

                    {/* Header card */}
                    <div className={`${publicCardClass} sm:p-6`}>
                        <PublicCardDecor glow={false} />
                        <div className="relative">
                            {/* Status badge */}
                            <div className="mb-4 flex items-center gap-2">
                                {/* Status in words, not an animated dot. */}
                                <StatusBadge tone={a.is_open ? 'success' : 'neutral'}>
                                    {a.is_open ? t('transfers.statusOpen') : t('publicSite.closed')}
                                </StatusBadge>
                            </div>

                            {/* Key details */}
                            <div className="mt-5 grid grid-cols-2 gap-4 sm:grid-cols-3">
                                {a.grade_level && (
                                    <div className="rounded-card bg-gray-50 p-3 dark:bg-slate-800">
                                        <p className="text-[10px] font-semibold text-gray-400 dark:text-slate-500">{t('transfers.gradeLevel')}</p>
                                        <p className="mt-1 text-sm font-semibold text-gray-900 dark:text-slate-100">{a.grade_level}</p>
                                    </div>
                                )}
                                <div className="rounded-card bg-gray-50 p-3 dark:bg-slate-800">
                                    <p className="text-[10px] font-semibold text-gray-400 dark:text-slate-500">{t('transfers.vacancies')}</p>
                                    <p className="mt-1 text-sm font-semibold text-gray-900 dark:text-slate-100">{a.number_of_vacancies}</p>
                                </div>
                                {(a.salary_min || a.salary_max) && (
                                    <div className="rounded-card bg-gray-50 p-3 dark:bg-slate-800">
                                        <p className="text-[10px] font-semibold text-gray-400 dark:text-slate-500">{t('transfers.salary')}</p>
                                        <p className="mt-1 text-sm font-semibold text-gray-900 dark:text-slate-100">
                                            {a.salary_min && a.salary_max
                                                ? `${a.salary_min} – ${a.salary_max}`
                                                : a.salary_min ?? a.salary_max}
                                        </p>
                                    </div>
                                )}
                            </div>

                            {/* Dates */}
                            {(a.opening_date || a.closing_date) && (
                                <div className="mt-4 flex items-center gap-2 text-sm text-gray-500 dark:text-slate-400">
                                    <CalendarIcon className="h-4 w-4 shrink-0" />
                                    <span>
                                        <LocalizedDateDisplay value={a.opening_date} /> → <LocalizedDateDisplay value={a.closing_date} />
                                    </span>
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Eligibility rules */}
                    {a.eligibility_rules && a.eligibility_rules.length > 0 && (
                        <div className={`${publicCardClass} mt-6`}>
                            <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-slate-100">
                                <CheckCircleIcon className="h-4 w-4 text-blue-500" />
                                {t('transfers.eligibilityRequirements')}
                            </h2>
                            <ul className="space-y-2">
                                {a.eligibility_rules.map((rule, i) => (
                                    <li key={i} className="flex items-start gap-2 text-sm text-gray-700 dark:text-slate-300">
                                        <span className="mt-0.5 h-1.5 w-1.5 shrink-0 rounded-full bg-blue-500" />
                                        {rule}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {/* Required documents */}
                    {a.required_documents && a.required_documents.length > 0 && (
                        <div className={`${publicCardClass} mt-4`}>
                            <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-slate-100">
                                <FileTextIcon className="h-4 w-4 text-orange-500" />
                                {t('transfers.requiredDocuments')}
                            </h2>
                            <ul className="space-y-2">
                                {a.required_documents.map((doc, i) => (
                                    <li key={i} className="flex items-start gap-2 text-sm text-gray-700 dark:text-slate-300">
                                        <span className="mt-0.5 h-1.5 w-1.5 shrink-0 rounded-full bg-orange-400" />
                                        {doc}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {/* Apply button section */}
                    <div className="mt-8">
                        {already_applied ? (
                            <div className="flex items-center gap-3 rounded-panel border border-emerald-200 bg-emerald-50 px-5 py-4 dark:border-emerald-900/50 dark:bg-emerald-950/30">
                                <CheckCircleIcon className="h-5 w-5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                                <p className="text-sm font-medium text-emerald-800 dark:text-emerald-300">
                                    {t('transfers.applicationAlreadySubmitted')} — {t('transfers.applicationUnderReview')}
                                </p>
                            </div>
                        ) : a.is_open ? (
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                                {is_authenticated ? (
                                    <Link href={apply_url} className={publicButtonPrimary}>
                                        {t('transfers.applyForTransfer')}
                                    </Link>
                                ) : (
                                    <a href={login_url} className={publicButtonPrimary}>
                                        {t('transfers.signInToApply')}
                                    </a>
                                )}
                                <p className="text-xs text-gray-400 dark:text-slate-500">
                                    {t('transfers.closingOn')} <LocalizedDateDisplay value={a.closing_date} />
                                </p>
                            </div>
                        ) : (
                            <div className="rounded-panel border border-gray-200 bg-gray-50 px-5 py-4 dark:border-slate-800 dark:bg-slate-900">
                                <p className="text-sm text-gray-500 dark:text-slate-400">
                                    {t('transfers.notAcceptingApplicationsInfo')}
                                </p>
                            </div>
                        )}
                    </div>
                </PublicContainer>
            </div>
        </PublicLayout>
    );
}
