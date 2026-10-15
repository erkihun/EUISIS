import { StatusBadge } from '@euisis/ui';
import TransportProviderLayout from '@/Layouts/TransportProviderLayout';
import PortalList from '@/Components/transport/PortalList';
import { useTransportLabels } from '@/Components/transport/labels';
import type { TransportDriver } from '@/types/transport';

export default function Index({ drivers }: { drivers: TransportDriver[] }) {
    const { t, label, tone } = useTransportLabels();

    return (
        <TransportProviderLayout title={t('transport.drivers')}>
            <PortalList
                rows={drivers}
                createHref={route('provider.portal.transport.drivers.create')}
                createLabel={t('transport.newDriver')}
                editHref={(d) => route('provider.portal.transport.drivers.edit', d.id)}
                columns={[
                    { header: t('transport.driverName'), cell: (d) => <><div className="font-medium">{d.full_name}</div><div className="text-xs text-[color:var(--app-muted-foreground)]">{d.phone_number}</div></> },
                    { header: t('transport.licenseNumber'), cell: (d) => <>{d.license_number ?? '—'}{d.license_expiry_date ? <div className="text-xs text-[color:var(--app-muted-foreground)]">{d.license_expiry_date}</div> : null}</> },
                    { header: t('transport.assignedVehicle'), cell: (d) => d.vehicle?.plate_number ?? '—' },
                    { header: t('transport.status'), cell: (d) => <StatusBadge tone={tone(d.status)}>{label('statuses', d.status)}</StatusBadge> },
                ]}
            />
        </TransportProviderLayout>
    );
}
