import PageHeader from '@/Components/PageHeader';
import StatCard from '@/Components/StatCard';
import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ArrowLeftRightIcon, ChevronRight, ClipboardCheckIcon, MegaphoneIcon, Plus, SettingsIcon } from '@/Components/Icons';
import { useDisplayFormat } from '@/hooks/useDisplayFormat';
import { useLocale } from '@/hooks/useLocale';
import { buttonClassName, cx } from '@/Components/ui';
import { Card, CardHeader, CardTitle } from '@euisis/ui';
import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import type { TransferApplicationRecord } from '@/Components/transfers/types';

type Props = {
    stats: {
        active_announcements: number;
        pending_applications: number;
        release_pending: number;
        receiving_pending: number;
        final_pending: number;
    };
    recent_transfers: TransferApplicationRecord[];
    can: {
        manage_settings: boolean;
        create_announcement: boolean;
        view_announcements: boolean;
        view_applications: boolean;
        approve_release: boolean;
        approve_receiving: boolean;
        approve_final: boolean;
    };
};

/** A stat card that opens its list only for viewers who may see that list. */
function StatLink({ href, children }: { href: string | null; children: ReactNode }) {
    return href ? (
        <Link href={href} className="block rounded-card focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-primary *:h-full">{children}</Link>
    ) : (
        <div className="*:h-full">{children}</div>
    );
}

export default function TransferDashboard({ stats, recent_transfers, can }: Props) {
    const { t } = useLocale();
    const { number, organizationName } = useDisplayFormat();
    const applicationsWith = (status: string) => `${route('transfer-applications.index')}?status=${status}`;

    // Each stage the viewer may decide, with what waits on them.
    const queues = [
        { key: 'release', show: can.approve_release, count: stats.release_pending, status: 'release_pending', label: t('transfers.releaseApproval') },
        { key: 'receiving', show: can.approve_receiving, count: stats.receiving_pending, status: 'receiving_pending', label: t('transfers.receivingApproval') },
        { key: 'final', show: can.approve_final, count: stats.final_pending, status: 'final_approval_pending', label: t('transfers.finalApproval') },
    ].filter((queue) => queue.show);

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('transfers.dashboard')}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {can.manage_settings && (
                                <Link href={route('transfer-settings.show')} className={buttonClassName({ variant: 'outline' })}>
                                    <SettingsIcon className="size-4" aria-hidden="true" />{t('transfers.settings')}
                                </Link>
                            )}
                            {can.create_announcement && (
                                <Link href={route('transfer-announcements.create')} className={buttonClassName({ variant: 'primary' })}>
                                    <Plus className="size-4" aria-hidden="true" />{t('transfers.createAnnouncement')}
                                </Link>
                            )}
                        </div>
                    }
                />
            }
        >
            <Head title={t('transfers.dashboard')} />

            <div className="space-y-6">
                <section className="@container" aria-label={t('transfers.overview')}>
                    <div className="grid gap-4 @lg:grid-cols-2 @4xl:grid-cols-4">
                        <StatLink href={can.view_announcements ? route('transfer-announcements.index') + '?status=published' : null}>
                            <StatCard label={t('transfers.activeAnnouncements')} value={number(stats.active_announcements)} icon={<MegaphoneIcon className="size-4" />} tone="primary" />
                        </StatLink>
                        <StatLink href={can.view_applications ? route('transfer-applications.index') : null}>
                            <StatCard label={t('transfers.pendingApplications')} value={number(stats.pending_applications)} icon={<ClipboardCheckIcon className="size-4" />} tone="neutral" hint={t('transfers.pendingApplicationsHint')} />
                        </StatLink>
                        <StatLink href={can.view_applications ? applicationsWith('release_pending') : null}>
                            <StatCard label={t('transfers.releasePending')} value={number(stats.release_pending)} tone={stats.release_pending > 0 ? 'warning' : 'neutral'} icon={<ArrowLeftRightIcon className="size-4" />} />
                        </StatLink>
                        <StatLink href={can.view_applications ? applicationsWith('receiving_pending') : null}>
                            <StatCard label={t('transfers.receivingPending')} value={number(stats.receiving_pending)} tone={stats.receiving_pending > 0 ? 'warning' : 'neutral'} icon={<ArrowLeftRightIcon className="size-4" />} />
                        </StatLink>
                    </div>
                </section>

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.6fr)]">
                    <Card className="self-start">
                        <CardHeader>
                            <CardTitle>{t('transfers.yourApprovals')}</CardTitle>
                        </CardHeader>
                        {queues.length === 0 ? (
                            <p className="text-sm text-(--app-muted-foreground)">{t('transfers.noApprovalRole')}</p>
                        ) : (
                            <ul className="divide-y divide-(--app-border)">
                                {queues.map((queue) => (
                                    <li key={queue.key}>
                                        <Link
                                            href={applicationsWith(queue.status)}
                                            className="-mx-2 flex items-center justify-between gap-3 rounded-control px-2 py-3 text-sm hover:bg-(--app-surface-muted) focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-primary"
                                        >
                                            <span className="min-w-0 text-(--app-foreground)">{queue.label}</span>
                                            <span className="flex shrink-0 items-center gap-2">
                                                <span className={cx('font-semibold tabular-nums', queue.count > 0 ? 'text-(--app-foreground)' : 'text-(--app-muted-foreground)')}>
                                                    {queue.count > 0 ? t('transfers.waitingCount').replace(':count', number(queue.count)) : t('transfers.nothingWaiting')}
                                                </span>
                                                <ChevronRight className="size-4 text-(--app-muted-foreground) rtl:rotate-180" aria-hidden="true" />
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>{t('transfers.recentTransfers')}</CardTitle>
                            {can.view_applications && (
                                <Link href={applicationsWith('transferred')} className="text-sm font-medium text-primary hover:underline">{t('common.viewAll')}</Link>
                            )}
                        </CardHeader>
                        {recent_transfers.length === 0 ? (
                            <p className="text-sm text-(--app-muted-foreground)">{t('transfers.noTransfersYet')}</p>
                        ) : (
                            <ul className="divide-y divide-(--app-border)">
                                {recent_transfers.map((transfer) => (
                                    <li key={transfer.id} className="flex items-start justify-between gap-3 py-3 first:pt-0 last:pb-0">
                                        <div className="min-w-0">
                                            {can.view_applications ? (
                                                <Link href={route('transfer-applications.show', transfer.id)} className="text-sm font-medium text-primary hover:underline">
                                                    {transfer.employee?.full_name ?? '—'}
                                                </Link>
                                            ) : (
                                                <span className="text-sm font-medium text-(--app-foreground)">{transfer.employee?.full_name ?? '—'}</span>
                                            )}
                                            <p className="text-xs wrap-break-word text-(--app-muted-foreground)">
                                                {transfer.releasing_organization ? organizationName(transfer.releasing_organization.name_en, transfer.releasing_organization.name_am) : '—'}
                                                <span aria-hidden="true"> → </span>
                                                <span className="sr-only">{t('transfers.to')}</span>
                                                {transfer.receiving_organization ? organizationName(transfer.receiving_organization.name_en, transfer.receiving_organization.name_am) : '—'}
                                            </p>
                                        </div>
                                        <div className="shrink-0 text-end">
                                            <StatusBadge status={transfer.status} />
                                            <p className="mt-1 text-xs text-(--app-muted-foreground)"><LocalizedDateDisplay value={transfer.implemented_at} fallback="—" /></p>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
