import { StatusBadge } from '@euisis/ui';
import TransportProviderLayout from '@/Layouts/TransportProviderLayout';
import PortalList from '@/Components/transport/PortalList';
import { useTransportLabels } from '@/Components/transport/labels';
import type { TransportVehicle } from '@/types/transport';

export default function Index({ vehicles }: { vehicles: TransportVehicle[] }) {
    const { t, name, label, tone } = useTransportLabels();

    return (
        <TransportProviderLayout title={t('transport.vehicles')}>
            <PortalList
                rows={vehicles}
                createHref={route('provider.portal.transport.vehicles.create')}
                createLabel={t('transport.newVehicle')}
                editHref={(v) => route('provider.portal.transport.vehicles.edit', v.id)}
                columns={[
                    { header: t('transport.plateNumber'), cell: (v) => <><div className="font-medium">{v.plate_number}</div><div className="text-xs text-[color:var(--app-muted-foreground)]">{v.vehicle_code}</div></> },
                    { header: t('transport.vehicleType'), cell: (v) => `${label('vehicleTypes', v.vehicle_type)}${v.capacity ? ` · ${v.capacity}` : ''}` },
                    { header: t('transport.assignedRoute'), cell: (v) => (v.route ? `${v.route.route_code} — ${name(v.route)}` : '—') },
                    { header: t('transport.status'), cell: (v) => <StatusBadge tone={tone(v.status)}>{label('statuses', v.status)}</StatusBadge> },
                ]}
            />
        </TransportProviderLayout>
    );
}
