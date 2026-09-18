import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { ArrowLeftRightIcon } from '@/Components/Icons';

type RecentTransfer = {
    id: string;
    status: string;
    employee: { employee_number: string; full_name: string } | null;
    releasingOrganization: { name_en: string; name_am: string | null } | null;
    receivingOrganization: { name_en: string; name_am: string | null } | null;
    updated_at: string;
};

type Props = {
    stats: {
        active_announcements: number;
        pending_applications: number;
        release_pending: number;
        receiving_pending: number;
        final_pending: number;
    };
    recent_transfers: RecentTransfer[];
    can: {
        manage_settings: boolean;
        create_announcement: boolean;
        view_applications: boolean;
        approve_release: boolean;
        approve_receiving: boolean;
        approve_final: boolean;
    };
};

function StatCard({ label, value, color }: { label: string; value: number; color: string }) {
    return (
        <div className={`rounded-card border p-5 ${color}`}>
            <p className="text-sm font-medium opacity-75">{label}</p>
            <p className="mt-2 text-3xl font-bold">{value.toLocaleString()}</p>
        </div>
    );
}

export default function TransferDashboard({ stats, recent_transfers, can }: Props) {
    const { locale, t } = useLocale();
    const useAmharic = locale === 'am';

    return (
        <AuthenticatedLayout header={<PageHeader title={t('transfers.dashboard')} />}>
            <Head title={t('transfers.dashboard')} />

            <div className="space-y-6">
                {/* Stats */}
                <section className="grid gap-4 md:grid-cols-3 lg:grid-cols-5">
                    <StatCard
                        label={t('transfers.activeAnnouncements')}
                        value={stats.active_announcements}
                        color="border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-900 dark:bg-blue-950/40 dark:text-blue-200"
                    />
                    <StatCard
                        label={t('transfers.pendingApplications')}
                        value={stats.pending_applications}
                        color="border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
                    />
                    <StatCard
                        label={t('transfers.releasePending')}
                        value={stats.release_pending}
                        color="border-orange-200 bg-orange-50 text-orange-800 dark:border-orange-900 dark:bg-orange-950/40 dark:text-orange-200"
                    />
                    <StatCard
                        label={t('transfers.receivingPending')}
                        value={stats.receiving_pending}
                        color="border-violet-200 bg-violet-50 text-violet-800 dark:border-violet-900 dark:bg-violet-950/40 dark:text-violet-200"
                    />
                    <StatCard
                        label={t('transfers.finalPending')}
                        value={stats.final_pending}
                        color="border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200"
                    />
                </section>

                {/* Approval queue shortcuts */}
                {(can.approve_release || can.approve_receiving || can.approve_final) && (
                    <section className="rounded-card border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <h3 className="mb-4 text-sm font-semibold text-gray-900 dark:text-slate-100">
                            {t('transfers.approvalChain')}
                        </h3>
                        <div className="grid gap-3 sm:grid-cols-3">
                            {can.approve_release && stats.release_pending > 0 && (
                                <Link
                                    href={route('transfer-applications.index') + '?status=release_pending'}
                                    className="flex items-center justify-between rounded-lg border border-orange-200 bg-orange-50 px-4 py-3 text-sm text-orange-800 hover:bg-orange-100 dark:border-orange-900 dark:bg-orange-950/30 dark:text-orange-300"
                                >
                                    <span>{t('transfers.releaseApproval')}</span>
                                    <span className="rounded-full bg-orange-200 px-2 py-0.5 text-xs font-bold dark:bg-orange-900">{stats.release_pending}</span>
                                </Link>
                            )}
                            {can.approve_receiving && stats.receiving_pending > 0 && (
                                <Link
                                    href={route('transfer-applications.index') + '?status=receiving_pending'}
                                    className="flex items-center justify-between rounded-lg border border-violet-200 bg-violet-50 px-4 py-3 text-sm text-violet-800 hover:bg-violet-100 dark:border-violet-900 dark:bg-violet-950/30 dark:text-violet-300"
                                >
                                    <span>{t('transfers.receivingApproval')}</span>
                                    <span className="rounded-full bg-violet-200 px-2 py-0.5 text-xs font-bold dark:bg-violet-900">{stats.receiving_pending}</span>
                                </Link>
                            )}
                            {can.approve_final && stats.final_pending > 0 && (
                                <Link
                                    href={route('transfer-applications.index') + '?status=final_approval_pending'}
                                    className="flex items-center justify-between rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 hover:bg-emerald-100 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300"
                                >
                                    <span>{t('transfers.finalApproval')}</span>
                                    <span className="rounded-full bg-emerald-200 px-2 py-0.5 text-xs font-bold dark:bg-emerald-900">{stats.final_pending}</span>
                                </Link>
                            )}
                        </div>
                    </section>
                )}

                {/* Recent transfers */}
                {recent_transfers.length > 0 && (
                    <section className="rounded-card border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <h3 className="mb-4 text-sm font-semibold text-gray-900 dark:text-slate-100">
                            {t('transfers.recentTransfers')}
                        </h3>
                        <div className="space-y-2">
                            {recent_transfers.map(transfer => (
                                <div key={transfer.id} className="flex items-center justify-between rounded-lg border border-gray-100 px-4 py-3 dark:border-slate-700">
                                    <div>
                                        <p className="text-sm font-medium text-gray-900 dark:text-slate-100">
                                            {transfer.employee?.full_name ?? '-'}
                                            <span className="ml-2 text-xs text-gray-400">{transfer.employee?.employee_number}</span>
                                        </p>
                                        <p className="text-xs text-gray-500 dark:text-slate-400">
                                            {(useAmharic ? transfer.releasingOrganization?.name_am : transfer.releasingOrganization?.name_en) ?? transfer.releasingOrganization?.name_en}
                                            {' → '}
                                            {(useAmharic ? transfer.receivingOrganization?.name_am : transfer.receivingOrganization?.name_en) ?? transfer.receivingOrganization?.name_en}
                                        </p>
                                    </div>
                                    <StatusBadge status={transfer.status} />
                                </div>
                            ))}
                        </div>
                    </section>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
