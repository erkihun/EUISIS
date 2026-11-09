import { Link, usePage } from '@inertiajs/react';
import { Card, EmptyState, StatusBadge, TableCard, buttonClassName } from '@euisis/ui';
import TransportProviderLayout from '@/Layouts/TransportProviderLayout';
import { useTransportLabels } from '@/Components/transport/labels';
import type { PageProps } from '@/types';
import { Pill } from '@/Components/transport/TransportShared';
import type { TransportPortalCan, TransportTransaction, TransportTrip } from '@/types/transport';

type Stats = {
    today_scans: number; accepted_scans: number; rejected_scans: number; active_routes: number; active_vehicles: number;
    active_drivers: number; scheduled_trips: number; completed_trips: number; active_passes: number;
};

export default function Dashboard({ stats, recentTransactions, todayTrips, openExceptions }: { stats: Stats; recentTransactions: TransportTransaction[]; todayTrips: TransportTrip[]; openExceptions: number }) {
    const { t, label, tone, name } = useTransportLabels();
    const can = usePage<PageProps<{ providerPortal?: { can?: Partial<TransportPortalCan> } | null }>>().props.providerPortal?.can ?? {};
    const tiles: [string, number][] = [
        [t('transport.todayBoardings'), stats.today_scans],
        [label('statuses', 'accepted'), stats.accepted_scans],
        [label('statuses', 'rejected'), stats.rejected_scans],
        [t('transport.statTripsToday'), stats.scheduled_trips + stats.completed_trips],
        [t('transport.statActiveRoutes'), stats.active_routes],
        [t('transport.statActiveVehicles'), stats.active_vehicles],
        [t('transport.statActiveDrivers'), stats.active_drivers],
        [t('transport.statActivePasses'), stats.active_passes],
        [t('transport.openExceptions'), openExceptions],
    ];

    return (
        <TransportProviderLayout title={t('transport.dashboard')}>
            <div className="space-y-6">
                {can.transportScan && (
                    <Card className="flex flex-wrap items-center justify-between gap-3">
                        <p className="text-sm text-(--app-muted-foreground)">{t('transport.scanIntro')}</p>
                        <Link href={route('provider.portal.transport.scan')} className={buttonClassName({ variant: 'primary' })}>{t('transport.scan_id')}</Link>
                    </Card>
                )}
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    {tiles.map(([title, value]) => (
                        <Card key={title} className="p-4">
                            <p className="text-xs font-medium text-(--app-muted-foreground)">{title}</p>
                            <p className="mt-1 text-2xl font-semibold tabular-nums">{value}</p>
                        </Card>
                    ))}
                </div>
                {can.transportTripsManage && (
                    <TableCard title={t('transport.todayTrips')} actions={<Link href={route('provider.portal.transport.trips.index')} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('transport.trips')}</Link>}>
                        {todayTrips.length === 0 ? <EmptyState title={t('transport.noTrips')} /> : (
                            <ul className="divide-y divide-(--app-border)">
                                {todayTrips.map((trip) => (
                                    <li key={trip.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm">
                                        <Link href={route('provider.portal.transport.trips.show', trip.id)} className="min-w-0 underline-offset-2 hover:underline">
                                            <p className="font-medium tabular-nums">{trip.departure_time ?? '—'} · {trip.route?.route_code} — {name(trip.route)}</p>
                                            <p className="text-xs text-(--app-muted-foreground)">{label('directions', trip.direction)}{trip.vehicle ? ` · ${trip.vehicle.plate_number}` : ''} · {t('transport.boarded')} {trip.boarded_count}{trip.effective_capacity ? `/${trip.effective_capacity}` : ''}</p>
                                        </Link>
                                        <Pill value={trip.status} />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </TableCard>
                )}
                <TableCard title={t('transport.recentBoardings')} actions={can.transportTransactionsView ? <Link href={route('provider.portal.transport.transactions.index')} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('transport.seeAllBoardings')}</Link> : undefined}>
                    {recentTransactions.length === 0 ? <EmptyState title={t('transport.noBoardings')} /> : (
                        <ul className="divide-y divide-(--app-border)">
                            {recentTransactions.map((item) => (
                                <li key={item.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm">
                                    <div className="min-w-0">
                                        <p className="font-medium">{item.employee?.full_name ?? label('reasons', item.result_code)}</p>
                                        <p className="text-xs text-(--app-muted-foreground)">{item.route ? `${item.route.route_code} — ${name(item.route)} · ` : ''}{label('reasons', item.result_code)}</p>
                                    </div>
                                    <StatusBadge tone={tone(item.status)}>{label('statuses', item.status)}</StatusBadge>
                                </li>
                            ))}
                        </ul>
                    )}
                </TableCard>
            </div>
        </TransportProviderLayout>
    );
}
