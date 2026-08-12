import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';

type Application = {
    id: string;
    application_number: string;
    status: string;
    applied_at: string;
    announcement: {
        id: string;
        title_en: string;
        application_closes_at: string | null;
    } | null;
    positionEntry: {
        organization: { name_en: string } | null;
        position: { title_en: string } | null;
    } | null;
};

type Props = {
    applications: { data: Application[]; links: unknown[] };
};

export default function VacancyApplicationsMyApplications({ applications }: Props) {
    const { t } = useLocale();

    return (
        <AuthenticatedLayout
            header={<PageHeader title={t('vacancies.myApplications')} />}
        >
            <Head title={t('vacancies.myApplications')} />

            {applications.data.length === 0 ? (
                <div className="rounded-2xl border border-gray-200 bg-white p-10 text-center dark:border-slate-800 dark:bg-slate-900">
                    <p className="text-sm text-gray-400 dark:text-slate-500">{t('vacancies.noApplications')}</p>
                    <Link href={route('vacancy-announcements.index')} className="mt-4 inline-block text-sm text-blue-600 hover:underline dark:text-blue-400">
                        {t('vacancies.announcements')}
                    </Link>
                </div>
            ) : (
                <div className="space-y-3">
                    {applications.data.map(app => (
                        <div key={app.id} className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                            <div className="flex items-start justify-between gap-4">
                                <div>
                                    <Link href={route('vacancy-applications.show', app.id)} className="font-semibold text-blue-600 hover:underline dark:text-blue-400">
                                        {app.announcement?.title_en ?? app.application_number}
                                    </Link>
                                    <p className="mt-0.5 text-xs text-gray-500 dark:text-slate-400">
                                        {app.positionEntry?.organization?.name_en}
                                        {app.positionEntry?.position?.title_en && (
                                            <> · {app.positionEntry.position.title_en}</>
                                        )}
                                    </p>
                                </div>
                                <div className="flex shrink-0 items-center gap-2">
                                    <StatusBadge status={app.status} />
                                    {app.status === 'submitted' && (
                                        <button
                                            type="button"
                                            onClick={() => router.post(route('vacancy-applications.withdraw', app.id))}
                                            className="text-xs text-red-500 hover:underline dark:text-red-400"
                                        >
                                            {t('vacancies.withdrawApplication')}
                                        </button>
                                    )}
                                </div>
                            </div>
                            <p className="mt-2 text-xs text-gray-400 dark:text-slate-500">
                                {t('vacancies.appliedAt')}: <LocalizedDateDisplay value={app.applied_at} />
                                {app.announcement?.application_closes_at && (
                                    <> · {t('vacancies.applicationClosesAt')}: <LocalizedDateDisplay value={app.announcement.application_closes_at} /></>
                                )}
                            </p>
                        </div>
                    ))}
                </div>
            )}
        </AuthenticatedLayout>
    );
}
