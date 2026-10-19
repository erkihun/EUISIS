import { Card } from '@euisis/ui';
import { useTransportLabels } from './labels';
import { ActionDialogButton, Field, Percent, Pill } from './TransportShared';
import type { TransportDriver, TransportTrip, TransportVehicle } from '@/types/transport';

/**
 * Dispatch for one trip: the actions its status allows, each posted to the
 * dispatch endpoint (admin or portal) with the reason or vehicle it needs.
 * The server re-checks the state machine; this only hides what cannot apply.
 */
export default function TripDispatchPanel({ trip, dispatchUrl, vehicles, drivers, canDispatch }: {
    trip: TransportTrip;
    dispatchUrl: string;
    vehicles: TransportVehicle[];
    drivers: TransportDriver[];
    canDispatch: boolean;
}) {
    const { t, label } = useTransportLabels();
    const vehicleOptions = [{ value: '', label: t('transport.selectVehicle') }, ...vehicles.filter((v) => v.id !== trip.transport_vehicle_id).map((v) => ({ value: v.id, label: `${v.vehicle_code} · ${v.plate_number}${v.capacity ? ` (${v.capacity})` : ''}` }))];
    const driverOptions = [{ value: '', label: t('transport.selectDriver') }, ...drivers.map((d) => ({ value: d.id, label: d.full_name }))];
    const status = trip.status;
    const action = (a: string, extra: Record<string, unknown> = {}) => ({ url: dispatchUrl, data: { action: a, ...extra } });

    return (
        <Card className="space-y-4">
            <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <Field label={t('transport.status')}><Pill value={status} /></Field>
                <Field label={t('transport.direction')}>{trip.direction ? label('directions', trip.direction) : '—'}</Field>
                <Field label={t('transport.vehicle')}>{trip.vehicle ? `${trip.vehicle.plate_number}` : '—'}</Field>
                <Field label={t('transport.driver')}>{trip.driver?.full_name ?? '—'}</Field>
                <Field label={t('transport.boarded')}>{trip.boarded_count}{trip.effective_capacity ? ` / ${trip.effective_capacity}` : ''}</Field>
                <Field label={t('transport.utilization')}><Percent value={trip.utilization_percent} /></Field>
                <Field label={t('transport.occupancyLabel')}>{label('occupancy', trip.occupancy_status)}</Field>
                <Field label={t('transport.expectedRiders')}>{trip.expected_riders ?? '—'}</Field>
            </dl>
            {trip.cancellation_reason && <p className="text-sm text-red-600">{t('transport.cancellationReason')}: {trip.cancellation_reason}</p>}
            {trip.vehicle_changes && trip.vehicle_changes.length > 0 && (
                <ul className="space-y-1 text-sm text-(--app-muted-foreground)">
                    {trip.vehicle_changes.map((change) => <li key={change.id}>{t('transport.vehicleReplaced')}: {change.from_plate ?? '—'} → {change.to_plate ?? '—'} — {change.reason}</li>)}
                </ul>
            )}
            {canDispatch && (
                <div className="flex flex-wrap gap-2 border-t border-(--app-border) pt-4">
                    {(status === 'planned' || status === 'assigned') && (
                        <ActionDialogButton label={t('transport.assignVehicle')} title={t('transport.assignVehicle')} variant="primary" {...action('assign')}
                            fields={[{ name: 'transport_vehicle_id', label: t('transport.vehicle'), type: 'select', options: [{ value: '', label: t('transport.selectVehicle') }, ...vehicles.map((v) => ({ value: v.id, label: `${v.vehicle_code} · ${v.plate_number}${v.capacity ? ` (${v.capacity})` : ''}` }))] }, { name: 'transport_driver_id', label: t('transport.driver'), type: 'select', options: driverOptions }]} />
                    )}
                    {(status === 'planned' || status === 'assigned') && <ActionDialogButton label={t('transport.startBoarding')} variant="primary" {...action('start')} />}
                    {status === 'boarding' && <ActionDialogButton label={t('transport.depart')} variant="primary" {...action('depart')} />}
                    {(status === 'boarding' || status === 'in_progress') && (
                        <ActionDialogButton label={t('transport.completeTrip')} title={t('transport.completeTrip')} variant="primary" {...action('complete')}
                            fields={[{ name: 'distance_km', label: t('transport.distanceKm'), type: 'number' }, { name: 'notes', label: t('transport.notes'), type: 'textarea' }]} />
                    )}
                    {status !== 'completed' && status !== 'cancelled' && status !== 'failed' && (
                        <ActionDialogButton label={t('transport.replaceVehicle')} title={t('transport.replaceVehicle')} {...action('replace_vehicle')}
                            fields={[{ name: 'transport_vehicle_id', label: t('transport.replacementVehicle'), type: 'select', required: true, options: vehicleOptions }, { name: 'transport_driver_id', label: t('transport.driver'), type: 'select', options: driverOptions }, { name: 'reason', label: t('transport.reason'), type: 'textarea', required: true }]} />
                    )}
                    {status !== 'completed' && status !== 'cancelled' && status !== 'failed' && (
                        <ActionDialogButton label={t('transport.cancelTrip')} title={t('transport.cancelTrip')} description={t('transport.cancelTripHelp')} destructive {...action('cancel')}
                            fields={[{ name: 'reason', label: t('transport.reason'), type: 'textarea', required: true }]} />
                    )}
                    {(status === 'boarding' || status === 'in_progress') && (
                        <ActionDialogButton label={t('transport.markFailed')} title={t('transport.markFailed')} destructive {...action('fail')}
                            fields={[{ name: 'reason', label: t('transport.reason'), type: 'textarea', required: true }]} />
                    )}
                </div>
            )}
            {!canDispatch && status === 'planned' && <p className="text-sm text-(--app-muted-foreground)">{t('transport.dispatchNoPermission')}</p>}
        </Card>
    );
}
