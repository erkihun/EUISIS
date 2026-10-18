import { StatusBadge } from '@euisis/ui';
import TransportProviderLayout from '@/Layouts/TransportProviderLayout';
import PortalList from '@/Components/transport/PortalList';
import { useTransportLabels } from '@/Components/transport/labels';
import type { TransportRoute } from '@/types/transport';

export default function Index({ routes }: { routes: TransportRoute[] }) {
    const { t, name, label } = useTransportLabels();

    return (
        <TransportProviderLayout title={t('transport.routes')}>
            <PortalList
                rows={routes}
                createHref={route('provider.portal.transport.routes.create')}
                createLabel={t('transport.newRoute')}
                editHref={(r) => route('provider.portal.transport.routes.edit', r.id)}
                columns={[
                    { header: t('transport.routeCode'), cell: (r) => <span className="font-medium">{r.route_code}</span> },
                    { header: t('transport.route'), cell: (r) => <><div>{name(r)}</div><div className="text-xs text-(--app-muted-foreground)">{r.origin_en} → {r.destination_en}</div></> },
                    { header: t('transport.status'), cell: (r) => <StatusBadge tone={r.is_active ? 'success' : 'neutral'}>{label('statuses', r.is_active ? 'active' : 'inactive')}</StatusBadge> },
                ]}
            />
        </TransportProviderLayout>
    );
}
