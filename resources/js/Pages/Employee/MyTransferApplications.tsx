import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import type { PageProps } from '@/types';

type Application = {
    id: string;
    status: string;
    status_label: string;
    submitted_at: string | null;
    applicant_notes: string | null;
    organization_name: string | null;
    position_title: string | null;
    announcement_id: string;
    closing_date: string | null;
    rejected_reason: string | null;
};

type Props = PageProps & {
    applications: Application[];
    has_employee: boolean;
};

const STATUS_COLOR: Record<string, string> = {
    submitted:              'bg-blue-100 text-blue-700 dark:bg-blue-950/50 dark:text-blue-300',
    under_review:           'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300',
    verified:               'bg-purple-100 text-purple-700 dark:bg-purple-950/50 dark:text-purple-300',
    selected:               'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300',
    approved:               'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300',
    rejected:               'bg-red-100 text-red-700 dark:bg-red-950/50 dark:text-red-300',
    withdrawn:              'bg-gray-100 text-gray-500 dark:bg-slate-800 dark:text-slate-400',
    transferred:            'bg-teal-100 text-teal-700 dark:bg-teal-950/50 dark:text-teal-300',
    release_pending:        'bg-orange-100 text-orange-700 dark:bg-orange-950/50 dark:text-orange-300',
    receiving_pending:      'bg-orange-100 text-orange-700 dark:bg-orange-950/50 dark:text-orange-300',
    final_approval_pending: 'bg-orange-100 text-orange-700 dark:bg-orange-950/50 dark:text-orange-300',
};

export default function MyTransferApplications({ applications, has_employee }: Props) {
    const { t } = useLocale();

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('transfers.myApplications')}
                    backHref={route('employee.portal')}
                    actions={
                        <Link
                            href={route('public.transfer-announcements')}
                            className="rounded-lg bg-[var(--color-primary,#2563eb)] px-3 py-1.5 text-sm font-medium text-white hover:opacity-90"
                        >
                            Browse Announcements
                        </Link>
                    }
                />
            }
        >
            <Head title={t('transfers.myApplications')} />

            {!has_employee ? (
                <div className="rounded-2xl border border-amber-200 bg-amber-50 p-8 text-center dark:border-amber-900/50 dark:bg-amber-950/20">
                    <p className="font-medium text-amber-800 dark:text-amber-300">{t('transfers.noEmployeeProfile')}</p>
                </div>
            ) : applications.length === 0 ? (
                <div className="rounded-2xl border border-gray-200 bg-white p-10 text-center dark:border-slate-800 dark:bg-slate-900">
                    <p className="text-sm text-gray-400 dark:text-slate-500">{t('transfers.noApplications')}</p>
                    <Link href={route('public.transfer-announcements')} className="mt-4 inline-block text-sm font-medium text-[var(--color-primary,#2563eb)] hover:underline">
                        Browse open announcements →
                    </Link>
                </div>
            ) : (
                <div className="space-y-4">
                    {applications.map(app => {
                        const statusCls = STATUS_COLOR[app.status] ?? 'bg-gray-100 text-gray-500 dark:bg-slate-800 dark:text-slate-400';
                        return (
                            <div key={app.id} className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <Link
                                            href={route('public.transfer-announcements.show', { announcement: app.announcement_id })}
                                            className="font-semibold text-gray-900 hover:text-[var(--color-primary,#2563eb)] dark:text-slate-100"
                                        >
                                            {app.position_title ?? '—'}
                                        </Link>
                                        {app.organization_name && (
                                            <p className="mt-0.5 text-xs text-gray-500 dark:text-slate-400">{app.organization_name}</p>
                                        )}
                                    </div>
                                    <span className={`inline-flex shrink-0 rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${statusCls}`}>
                                        {app.status_label}
                                    </span>
                                </div>

                                <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-400 dark:text-slate-500">
                                    {app.submitted_at && <span>Applied: <LocalizedDateDisplay value={app.submitted_at} /></span>}
                                    {app.closing_date && <span>{t('transfers.closes')}: <LocalizedDateDisplay value={app.closing_date} /></span>}
                                </div>

                                {app.applicant_notes && (
                                    <div className="mt-3 rounded-lg border border-gray-100 bg-gray-50 px-3 py-2 text-xs text-gray-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-400">
                                        <span className="font-medium text-gray-700 dark:text-slate-300">{t('transfers.applicantNotes')}: </span>
                                        {app.applicant_notes}
                                    </div>
                                )}

                                {app.rejected_reason && (
                                    <div className="mt-3 rounded-lg border border-red-100 bg-red-50 px-3 py-2 text-xs text-red-700 dark:border-red-900/50 dark:bg-red-950/20 dark:text-red-300">
                                        <span className="font-medium">{t('transfers.rejectedReason')}: </span>
                                        {app.rejected_reason}
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>
            )}
        </AuthenticatedLayout>
    );
}
