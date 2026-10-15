import axios, { AxiosError } from 'axios';
import { Alert, Button, Card, FormField, Select, StatusBadge, Textarea } from '@euisis/ui';
import { Suspense, lazy, useMemo, useState, type FormEvent } from 'react';
import { useTransportLabels } from './labels';
import type { ProviderOption, ScanResult, TransportRoute, TransportTrip } from '@/types/transport';

const QrScanner = lazy(() => import('@/Components/public/QrScanner'));

/**
 * The boarding terminal: scan a card with the camera (or a handheld scanner
 * typing into the reference box), optionally on a route and today's trip.
 * The admin terminal also picks the provider. Every scan carries a fresh
 * nonce, so a resent request is answered, never recorded twice.
 */
export default function TransportScanPanel({ providers, routes, trips, scanRouteName }: {
    providers?: ProviderOption[];
    routes: TransportRoute[];
    trips: TransportTrip[];
    scanRouteName: string;
}) {
    const { t, name, label } = useTransportLabels();
    const [providerId, setProviderId] = useState(providers?.[0]?.id ?? '');
    const [routeId, setRouteId] = useState('');
    const [tripId, setTripId] = useState('');
    const [reference, setReference] = useState('');
    const [camera, setCamera] = useState(false);
    const [busy, setBusy] = useState(false);
    const [result, setResult] = useState<ScanResult | null>(null);
    const [failed, setFailed] = useState(false);

    const ownRoutes = useMemo(() => routes.filter((r) => r.is_active && (!providers || r.provider_id === providerId)), [routes, providers, providerId]);
    const ownTrips = useMemo(() => trips.filter((trip) => (!providers || trip.provider_id === providerId) && (!routeId || trip.transport_route_id === routeId)), [trips, providers, providerId, routeId]);

    async function record(value: string) {
        if (!value.trim() || busy) return;
        setBusy(true);
        setFailed(false);
        setResult(null);
        try {
            const response = await axios.post<ScanResult>(route(scanRouteName), {
                ...(providers ? { provider_id: providerId } : {}),
                qr_token: value.trim(),
                scan_nonce: crypto.randomUUID(),
                transport_route_id: tripId ? null : routeId || null,
                transport_trip_id: tripId || null,
            });
            setResult(response.data);
            setReference('');
        } catch (caught) {
            const response = (caught as AxiosError<ScanResult>).response;
            // 422 carries the refusal and its reason; anything else is a failed request.
            if (response?.status === 422 && response.data && 'accepted' in response.data) setResult(response.data);
            else setFailed(true);
        } finally {
            setBusy(false);
        }
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        void record(reference);
    }

    return (
        <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]">
            <Card className="space-y-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    {providers && (
                        <FormField label={t('transport.provider')} required className="sm:col-span-2">
                            {({ id }) => (
                                <Select id={id} value={providerId} onChange={(e) => { setProviderId(e.target.value); setRouteId(''); setTripId(''); }}>
                                    {providers.map((p) => <option key={p.id} value={p.id}>{p.provider_code} — {name(p)}</option>)}
                                </Select>
                            )}
                        </FormField>
                    )}
                    <FormField label={t('transport.route')}>
                        {({ id }) => (
                            <Select id={id} value={routeId} onChange={(e) => { setRouteId(e.target.value); setTripId(''); }}>
                                <option value="">{t('transport.selectRoute')}</option>
                                {ownRoutes.map((r) => <option key={r.id} value={r.id}>{r.route_code} — {name(r)}</option>)}
                            </Select>
                        )}
                    </FormField>
                    <FormField label={t('transport.scanTrip')}>
                        {({ id }) => (
                            <Select id={id} value={tripId} onChange={(e) => setTripId(e.target.value)}>
                                <option value="">{t('transport.noTrip')}</option>
                                {ownTrips.map((trip) => (
                                    <option key={trip.id} value={trip.id}>
                                        {trip.departure_time ?? '—'} · {trip.trip_number} · {label('statuses', trip.status)}{trip.capacity ? ` (${trip.boarded_count}/${trip.capacity})` : ''}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </FormField>
                </div>

                {camera ? (
                    <div className="space-y-2">
                        <Suspense fallback={<p className="text-sm text-[color:var(--app-muted-foreground)]">{t('transport.scanReady')}</p>}>
                            <QrScanner autoStart onDecoded={(value) => { setCamera(false); void record(value); }} />
                        </Suspense>
                        <Button type="button" variant="outline" onClick={() => setCamera(false)}>{t('transport.scanStop')}</Button>
                    </div>
                ) : (
                    <Button type="button" variant="primary" className="w-full sm:w-auto" onClick={() => setCamera(true)} disabled={busy || (providers !== undefined && !providerId)}>{t('transport.scanCamera')}</Button>
                )}

                <form onSubmit={submit} className="space-y-2">
                    <FormField label={t('transport.scanManual')}>
                        {({ id }) => <Textarea id={id} rows={2} value={reference} onChange={(e) => setReference(e.target.value)} placeholder={t('transport.scanManualPlaceholder')} autoComplete="off" spellCheck={false} />}
                    </FormField>
                    <Button type="submit" variant="outline" disabled={busy || !reference.trim() || (providers !== undefined && !providerId)}>{busy ? t('transport.scanning') : t('transport.scanSubmit')}</Button>
                </form>
            </Card>

            <div aria-live="polite" className="space-y-3">
                {failed && <Alert tone="danger">{t('transport.scanFailed')}</Alert>}
                {result && (
                    <Alert tone={result.accepted ? 'success' : 'danger'} title={result.accepted ? t('transport.boardingAccepted') : t('transport.boardingRefused')}>
                        {/* A plain acceptance needs no second line; a reason (pay-as-you-go, a refusal) does. */}
                        {result.result_code !== 'transport_scan_accepted' && <p>{result.message ?? label('reasons', result.result_code)}</p>}
                        {result.transaction?.employee && (
                            <div className="mt-3 space-y-1">
                                <p className="text-base font-semibold">{result.transaction.employee.full_name}</p>
                                <p className="text-xs">{result.transaction.employee.employee_number}{result.transaction.employee.organization ? ` · ${result.transaction.employee.organization}` : ''}</p>
                                {result.transaction.route && <p className="text-xs">{result.transaction.route.route_code} — {name(result.transaction.route)}</p>}
                                {result.transaction.result_code !== 'transport_scan_accepted' && result.transaction.result_code !== result.result_code && (
                                    <StatusBadge tone={result.accepted ? 'success' : 'danger'}>{label('reasons', result.transaction.result_code)}</StatusBadge>
                                )}
                            </div>
                        )}
                    </Alert>
                )}
                {!result && !failed && <Card className="text-sm text-[color:var(--app-muted-foreground)]">{t('transport.scanReady')}</Card>}
            </div>
        </div>
    );
}
