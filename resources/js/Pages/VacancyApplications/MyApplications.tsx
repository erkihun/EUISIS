import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { actionLinkClassName } from '@/Components/ui';
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
    position_entry: {
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
                <div className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-10 text-center">
                    <p className="text-sm text-[color:var(--app-muted-foreground)]">{t('vacancies.noApplications')}</p>
                    <Link href={route('vacancy-announcements.index')} className={actionLinkClassName({ size: 'sm', className: 'mt-4 inline-block' })}>
                        {t('vacancies.announcements')}
                    </Link>
                </div>
            ) : (
                <div className="space-y-3">
                    {applications.data.map(app => (
                        <div key={app.id} className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5">
                            <div className="flex items-start justify-between gap-4">
                                <div>
                                    <Link href={route('vacancy-applications.show', app.id)} className="font-semibold text-[color:var(--color-primary)] hover:underline dark:text-[color:var(--color-primary)]">
                                        {app.announcement?.title_en ?? app.application_number}
                                    </Link>
                                    <p className="mt-0.5 text-xs text-[color:var(--app-muted-foreground)]">
                                        {app.position_entry?.organization?.name_en}
                                        {app.position_entry?.position?.title_en && (
                                            <> · {app.position_entry.position.title_en}</>
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
                            <p className="mt-2 text-xs text-[color:var(--app-muted-foreground)]">
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
