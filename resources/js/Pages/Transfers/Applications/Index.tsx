import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';

type Application = {
    id: string;
    status: string;
    submitted_at: string | null;
    employee: { employee_number: string; full_name: string } | null;
    announcement: { position: { title_en: string; title_am: string | null } | null } | null;
    releasingOrganization: { name_en: string; name_am: string | null } | null;
    receivingOrganization: { name_en: string; name_am: string | null } | null;
};

type Props = {
    applications: { data: Application[] };
    filters: { status?: string };
};

const STATUSES = [
    'submitted', 'under_review', 'verified', 'selected',
    'release_pending', 'receiving_pending', 'final_approval_pending',
    'approved', 'transferred', 'rejected', 'withdrawn', 'cancelled',
] as const;

export default function TransferApplicationsIndex({ applications, filters }: Props) {
    const { locale, t } = useLocale();
    const useAmharic = locale === 'am';

    const inputCls = 'rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-900 focus:border-blue-500 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    backHref={route('transfers.dashboard')}
                    title={t('transfers.applications')}
                />
            }
        >
            <Head title={t('transfers.applications')} />

            <div className="space-y-4">
                <div className="flex flex-wrap items-center gap-2">
                    <select
                        className={inputCls}
                        value={filters.status ?? ''}
                        onChange={(e) => router.get(route('transfer-applications.index'), e.target.value ? { status: e.target.value } : {}, { preserveState: true })}
                    >
                        <option value="">{t('common.status')} — {t('common.all')}</option>
                        {STATUSES.map((s) => {
                            const key = `transfers.status${s.split('_').map((w) => w.charAt(0).toUpperCase() + w.slice(1)).join('')}` as never;
                            return <option key={s} value={s}>{t(key)}</option>;
                        })}
                    </select>
                </div>

                <div className="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <table className="min-w-full text-left text-sm">
                        <thead className="bg-gray-50 dark:bg-slate-950">
                            <tr>
                                {[t('transfers.position'), t('common.employee'), t('transfers.releasingOrganization'), t('transfers.receivingOrganization'), t('common.submittedAt'), t('common.status')].map((h) => (
                                    <th key={h} className="whitespace-nowrap px-4 py-3 text-xs font-semibold uppercase text-gray-500 dark:text-slate-400">{h}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                            {applications.data.map((app) => (
                                <tr key={app.id} className="hover:bg-gray-50 dark:hover:bg-slate-800">
                                    <td className="px-4 py-3">
                                        <Link href={route('transfer-applications.show', app.id)} className="text-blue-600 hover:underline dark:text-blue-400">
                                            {(useAmharic ? app.announcement?.position?.title_am : null) ?? app.announcement?.position?.title_en ?? '-'}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-3 text-gray-700 dark:text-slate-200">
                                        {app.employee?.full_name}
                                        <span className="ml-1 text-xs text-gray-400">{app.employee?.employee_number}</span>
                                    </td>
                                    <td className="px-4 py-3 text-gray-500 dark:text-slate-400">
                                        {(useAmharic ? app.releasingOrganization?.name_am : null) ?? app.releasingOrganization?.name_en ?? '-'}
                                    </td>
                                    <td className="px-4 py-3 text-gray-500 dark:text-slate-400">
                                        {(useAmharic ? app.receivingOrganization?.name_am : null) ?? app.receivingOrganization?.name_en ?? '-'}
                                    </td>
                                    <td className="px-4 py-3 text-gray-500 dark:text-slate-400"><LocalizedDateDisplay value={app.submitted_at} fallback="-" /></td>
                                    <td className="px-4 py-3"><StatusBadge status={app.status} /></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {applications.data.length === 0 && (
                        <p className="px-4 py-8 text-center text-sm text-gray-400 dark:text-slate-500">{t('transfers.noApplications')}</p>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
