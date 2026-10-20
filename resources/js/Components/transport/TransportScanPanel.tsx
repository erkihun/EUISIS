import axios, { AxiosError } from 'axios';
import { router } from '@inertiajs/react';
import { Alert, Button, Card, FormField, Input, Select, StatusBadge, Textarea } from '@euisis/ui';
import { Suspense, lazy, useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import { useTransportLabels } from './labels';
import { Percent } from './TransportShared';
import type { ProviderOption, ScanResult, TransportRoute, TransportRouteStop, TransportTrip } from '@/types/transport';

const QrScanner = lazy(() => import('@/Components/public/QrScanner'));

export type TerminalConfig = { manualAllowed: boolean; canDispatch: boolean; tripRequired: boolean; nfcEnabled: boolean };

/** How long a result stays on screen before the terminal is ready for the next card. */
const RESET_MS = 3000;
/** A handheld scanner "types" a whole code in well under this gap between keys. */
const WEDGE_GAP_MS = 40;

/**
 * The boarding terminal: an operator on a bus, a scanner in hand, a line of
 * employees. Continuous camera scanning, a keyboard-wedge reader typing into
 * the page, a sound and a buzz per decision, auto-reset for the next card,
 * the trip's occupancy as it fills, and the one-tap trip activation the
 * engine needs before it accepts a boarding. The admin terminal also picks
 * the provider. Every scan carries a fresh nonce, so a resent request is
 * answered, never recorded twice.
 */
export default function TransportScanPanel({ providers, routes, trips, stops = [], scanRouteName, lookupRouteName, dispatchRouteName, terminal }: {
    providers?: ProviderOption[];
    routes: TransportRoute[];
    trips: TransportTrip[];
    stops?: TransportRouteStop[];
    scanRouteName: string;
    lookupRouteName?: string;
    dispatchRouteName?: string;
    terminal?: TerminalConfig;
}) {
    const { t, name, label } = useTransportLabels();
    const config: TerminalConfig = terminal ?? { manualAllowed: false, canDispatch: false, tripRequired: false, nfcEnabled: false };
    const [providerId, setProviderId] = useState(providers?.[0]?.id ?? '');
    const [routeId, setRouteId] = useState('');
    const [tripId, setTripId] = useState('');
    const [stopId, setStopId] = useState('');
    const [reference, setReference] = useState('');
    const [camera, setCamera] = useState(false);
    const [scanKey, setScanKey] = useState(0);
    const [busy, setBusy] = useState(false);
    const [result, setResult] = useState<ScanResult | null>(null);
    const [failed, setFailed] = useState(false);
    const [sound, setSound] = useState(true);
    const [manual, setManual] = useState(false);
    const [session, setSession] = useState({ accepted: 0, refused: 0 });
    const [occupancy, setOccupancy] = useState<{ boarded: number; capacity: number | null } | null>(null);
    const resetTimer = useRef<number | null>(null);

    const ownRoutes = useMemo(() => routes.filter((r) => r.is_active && (!providers || r.provider_id === providerId)), [routes, providers, providerId]);
    const ownTrips = useMemo(() => trips.filter((trip) => (!providers || trip.provider_id === providerId) && (!routeId || trip.transport_route_id === routeId)), [trips, providers, providerId, routeId]);
    const trip = useMemo(() => trips.find((x) => x.id === tripId) ?? null, [trips, tripId]);
    const ownStops = useMemo(() => stops.filter((s) => s.transport_route_id === (trip?.transport_route_id ?? routeId)), [stops, trip, routeId]);
    const boardable = trip ? trip.status === 'boarding' || trip.status === 'in_progress' : false;
    const canScan = !busy && (providers === undefined || Boolean(providerId)) && (!config.tripRequired || (Boolean(trip) && boardable));

    useEffect(() => {
        setOccupancy(trip ? { boarded: trip.boarded_count, capacity: trip.effective_capacity ?? null } : null);
    }, [trip]);

    useEffect(() => () => { if (resetTimer.current) window.clearTimeout(resetTimer.current); }, []);

    const feedback = useCallback((kind: 'accepted' | 'duplicate' | 'refused') => {
        try { navigator.vibrate?.(kind === 'accepted' ? 80 : kind === 'duplicate' ? [60, 60, 60] : [200, 80, 200]); } catch { /* unsupported */ }
        if (!sound) return;
        try {
            const Ctx = window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;
            if (!Ctx) return;
            const ctx = new Ctx();
            const tones = kind === 'accepted' ? [[880, 0, 0.12]] : kind === 'duplicate' ? [[660, 0, 0.1], [660, 0.15, 0.1]] : [[220, 0, 0.18], [180, 0.22, 0.25]];
            for (const [freq, at, dur] of tones) {
                const osc = ctx.createOscillator(); const gain = ctx.createGain();
                osc.type = 'square'; osc.frequency.value = freq; gain.gain.value = 0.08;
                osc.connect(gain).connect(ctx.destination); osc.start(ctx.currentTime + at); osc.stop(ctx.currentTime + at + dur);
            }
            window.setTimeout(() => void ctx.close(), 800);
        } catch { /* no audio */ }
    }, [sound]);

    const armReset = useCallback(() => {
        if (resetTimer.current) window.clearTimeout(resetTimer.current);
        resetTimer.current = window.setTimeout(() => { setResult(null); setFailed(false); if (camera) setScanKey((k) => k + 1); }, RESET_MS);
    }, [camera]);

    const record = useCallback(async (payload: Record<string, string | null>) => {
        if (busy) return;
        setBusy(true); setFailed(false); setResult(null);
        try {
            const response = await axios.post<ScanResult>(route(scanRouteName), {
                ...(providers ? { provider_id: providerId } : {}),
                scan_nonce: crypto.randomUUID(),
                transport_route_id: tripId ? null : routeId || null,
                transport_trip_id: tripId || null,
                transport_route_stop_id: stopId || null,
                scanned_at: new Date().toISOString(),
                ...payload,
            });
            setResult(response.data);
            setSession((s) => ({ ...s, accepted: s.accepted + 1 }));
            if (response.data.trip) setOccupancy({ boarded: response.data.trip.boarded_count, capacity: response.data.trip.effective_capacity });
            feedback('accepted');
        } catch (caught) {
            const response = (caught as AxiosError<ScanResult>).response;
            // 422 carries the refusal and its reason; anything else is a failed request.
            if (response?.status === 422 && response.data && 'accepted' in response.data) {
                setResult(response.data);
                setSession((s) => ({ ...s, refused: s.refused + 1 }));
                feedback(response.data.result_code === 'already_boarded' || response.data.result_code === 'duplicate_scan_nonce' ? 'duplicate' : 'refused');
            } else { setFailed(true); feedback('refused'); }
        } finally {
            setBusy(false);
            setReference('');
            armReset();
        }
    }, [busy, scanRouteName, providers, providerId, tripId, routeId, stopId, feedback, armReset]);

    /* Keyboard-wedge readers type the code quickly and press Enter; keystrokes with no gap go to the terminal even when nothing is focused. */
    useEffect(() => {
        let buffer = ''; let last = 0;
        const onKey = (event: KeyboardEvent) => {
            const target = event.target as HTMLElement | null;
            const typing = target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.tagName === 'SELECT' || target.isContentEditable);
            const now = Date.now();
            if (now - last > WEDGE_GAP_MS * 4) buffer = '';
            last = now;
            if (event.key === 'Enter') {
                if (buffer.length >= 10 && !typing) { event.preventDefault(); const value = buffer; buffer = ''; if (canScan) void record({ qr_token: value }); }
                buffer = '';
                return;
            }
            if (event.key.length === 1 && !typing) buffer += event.key;
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [canScan, record]);

    function submit(event: FormEvent) {
        event.preventDefault();
        if (reference.trim()) void record({ qr_token: reference.trim(), ...(config.nfcEnabled && /^[0-9a-f]{8,}$/i.test(reference.trim()) && !reference.includes(':') ? { verification_method: 'nfc' } : {}) });
    }

    function activate(action: 'start' | 'depart' | 'complete') {
        if (!trip || !dispatchRouteName) return;
        router.post(route(dispatchRouteName, trip.id), { action }, { preserveScroll: true, preserveState: true, only: ['trips'] });
    }

    const employee = result?.transaction?.employee;

    return (
        <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,24rem)]">
            <Card className="space-y-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    {providers && (
                        <FormField label={t('transport.provider')} required className="sm:col-span-2">
                            {({ id }) => (
                                <Select id={id} value={providerId} onChange={(e) => { setProviderId(e.target.value); setRouteId(''); setTripId(''); setStopId(''); }}>
                                    {providers.map((p) => <option key={p.id} value={p.id}>{p.provider_code} — {name(p)}</option>)}
                                </Select>
                            )}
                        </FormField>
                    )}
                    <FormField label={t('transport.route')}>
                        {({ id }) => (
                            <Select id={id} value={routeId} onChange={(e) => { setRouteId(e.target.value); setTripId(''); setStopId(''); }}>
                                <option value="">{t('transport.selectRoute')}</option>
                                {ownRoutes.map((r) => <option key={r.id} value={r.id}>{r.route_code} — {name(r)}</option>)}
                            </Select>
                        )}
                    </FormField>
                    <FormField label={t('transport.scanTrip')} required={config.tripRequired}>
                        {({ id }) => (
                            <Select id={id} value={tripId} onChange={(e) => { setTripId(e.target.value); setStopId(''); }}>
                                <option value="">{t('transport.noTrip')}</option>
                                {ownTrips.map((x) => (
                                    <option key={x.id} value={x.id}>
                                        {x.departure_time ?? '—'} · {x.route?.route_code ?? ''} {label('directions', x.direction)} · {label('statuses', x.status)}{x.effective_capacity ? ` (${x.boarded_count}/${x.effective_capacity})` : ''}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </FormField>
                    {ownStops.length > 0 && (
                        <FormField label={t('transport.stop')} className="sm:col-span-2">
                            {({ id }) => (
                                <Select id={id} value={stopId} onChange={(e) => setStopId(e.target.value)}>
                                    <option value="">{t('transport.anyStop')}</option>
                                    {ownStops.map((s) => <option key={s.id} value={s.id}>{s.sequence}. {name(s)}</option>)}
                                </Select>
                            )}
                        </FormField>
                    )}
                </div>

                {trip && (
                    <div className="flex flex-wrap items-center justify-between gap-3 rounded-card border border-(--app-border) bg-(--app-surface-muted) p-3">
                        <div className="text-sm">
                            <span className="font-medium">{trip.trip_number}</span> · <StatusBadge tone={boardable ? 'success' : 'warning'}>{label('statuses', trip.status)}</StatusBadge>
                            {occupancy && <span className="ml-2 tabular-nums">{t('transport.boarded')}: {occupancy.boarded}{occupancy.capacity ? ` / ${occupancy.capacity}` : ''} {occupancy.capacity ? <Percent value={Math.round((occupancy.boarded / occupancy.capacity) * 100)} /> : null}</span>}
                            {trip.vehicle && <span className="ml-2 text-(--app-muted-foreground)">{trip.vehicle.plate_number}</span>}
                        </div>
                        {config.canDispatch && dispatchRouteName && (
                            <div className="flex gap-2">
                                {(trip.status === 'planned' || trip.status === 'assigned') && <Button type="button" size="sm" variant="primary" onClick={() => activate('start')}>{t('transport.startBoarding')}</Button>}
                                {trip.status === 'boarding' && <Button type="button" size="sm" variant="outline" onClick={() => activate('depart')}>{t('transport.depart')}</Button>}
                                {(trip.status === 'boarding' || trip.status === 'in_progress') && <Button type="button" size="sm" variant="outline" onClick={() => activate('complete')}>{t('transport.completeTrip')}</Button>}
                            </div>
                        )}
                        {!boardable && !config.canDispatch && <p className="text-xs text-amber-700">{t('transport.tripNotActivated')}</p>}
                    </div>
                )}
                {config.tripRequired && !trip && <Alert tone="warning">{t('transport.tripRequiredHelp')}</Alert>}

                {camera ? (
                    <div className="space-y-2">
                        <Suspense fallback={<p className="text-sm text-(--app-muted-foreground)">{t('transport.scanReady')}</p>}>
                            <QrScanner key={scanKey} autoStart onDecoded={(value) => { if (canScan) void record({ qr_token: value }); else armReset(); }} />
                        </Suspense>
                        <div className="flex flex-wrap gap-2">
                            <Button type="button" variant="outline" onClick={() => setCamera(false)}>{t('transport.scanStop')}</Button>
                            <Button type="button" variant="ghost" onClick={() => setScanKey((k) => k + 1)} disabled={busy}>{t('transport.scanAgain')}</Button>
                        </div>
                    </div>
                ) : (
                    <Button type="button" variant="primary" className="w-full sm:w-auto" onClick={() => { setCamera(true); setScanKey((k) => k + 1); }} disabled={!canScan}>{t('transport.scanCamera')}</Button>
                )}

                <form onSubmit={submit} className="space-y-2">
                    <FormField label={config.nfcEnabled ? t('transport.scanManualOrNfc') : t('transport.scanManual')} description={t('transport.wedgeHelp')}>
                        {({ id }) => <Textarea id={id} rows={2} value={reference} onChange={(e) => setReference(e.target.value)} placeholder={t('transport.scanManualPlaceholder')} autoComplete="off" spellCheck={false} />}
                    </FormField>
                    <div className="flex flex-wrap items-center gap-3">
                        <Button type="submit" variant="outline" disabled={!canScan || !reference.trim()}>{busy ? t('transport.scanning') : t('transport.scanSubmit')}</Button>
                        <label className="flex items-center gap-2 text-sm text-(--app-muted-foreground)"><input type="checkbox" className="h-4 w-4" checked={sound} onChange={(e) => setSound(e.target.checked)} />{t('transport.soundOn')}</label>
                        {config.manualAllowed && lookupRouteName && <Button type="button" variant="ghost" size="sm" onClick={() => setManual((m) => !m)}>{t('transport.manualException')}</Button>}
                    </div>
                </form>

                {manual && config.manualAllowed && lookupRouteName && (
                    <ManualExceptionForm lookupRouteName={lookupRouteName} disabled={!canScan} onSubmit={(employeeId, reason) => { setManual(false); void record({ manual_employee_id: employeeId, manual_reason: reason, qr_token: null }); }} onCancel={() => setManual(false)} />
                )}
            </Card>

            <div aria-live="polite" className="space-y-3">
                <Card className="flex items-center justify-between p-3 text-sm">
                    <span>{t('transport.thisSession')}</span>
                    <span className="tabular-nums"><span className="text-emerald-600">{session.accepted}</span> / <span className="text-red-600">{session.refused}</span></span>
                </Card>
                {failed && <Alert tone="danger">{t('transport.scanFailed')}</Alert>}
                {result && (
                    <div className={`rounded-card border-2 p-4 ${result.accepted ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-950/30' : 'border-red-500 bg-red-50 dark:bg-red-950/30'}`}>
                        <p className={`text-lg font-bold ${result.accepted ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-700 dark:text-red-300'}`}>{result.accepted ? t('transport.boardingAccepted') : t('transport.boardingRefused')}</p>
                        {result.result_code !== 'transport_scan_accepted' && <p className="mt-1 text-sm">{result.message ?? label('reasons', result.result_code)}</p>}
                        {(result.warnings ?? []).length > 0 && <ul className="mt-1 text-xs text-amber-700">{(result.warnings ?? []).map((w) => <li key={w}>{label('reasons', w)}</li>)}</ul>}
                        {employee && (
                            <div className="mt-3 space-y-1">
                                <p className="text-xl font-semibold">{employee.full_name}</p>
                                <p className="text-sm">{employee.employee_number}{employee.organization ? ` · ${employee.organization}` : ''}</p>
                                {result.transaction?.route && <p className="text-xs">{result.transaction.route.route_code} — {name(result.transaction.route)}</p>}
                            </div>
                        )}
                        <p className="mt-3 text-xs text-(--app-muted-foreground)">{t('transport.autoResetHelp')}</p>
                    </div>
                )}
                {!result && !failed && <Card className="text-sm text-(--app-muted-foreground)">{canScan ? t('transport.scanReady') : t('transport.scanNotReady')}</Card>}
            </div>
        </div>
    );
}

/** A boarding without a working card: the operator names the employee by number and gives a reason; the record is flagged and audited. */
function ManualExceptionForm({ lookupRouteName, disabled, onSubmit, onCancel }: { lookupRouteName: string; disabled: boolean; onSubmit: (employeeId: string, reason: string) => void; onCancel: () => void }) {
    const { t } = useTransportLabels();
    const [number, setNumber] = useState('');
    const [reason, setReason] = useState('');
    const [hit, setHit] = useState<{ id: string; employee_number: string; full_name: string } | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [looking, setLooking] = useState(false);

    async function lookup() {
        setLooking(true); setError(null); setHit(null);
        try {
            const response = await axios.post<{ id: string; employee_number: string; full_name: string }>(route(lookupRouteName), { employee_number: number.trim() });
            setHit(response.data);
        } catch (caught) {
            const response = (caught as AxiosError<{ message?: string }>).response;
            setError(response?.data?.message ?? t('transport.noMatches'));
        } finally { setLooking(false); }
    }

    return (
        <div className="space-y-3 rounded-card border border-amber-300 bg-amber-50 p-4 dark:bg-amber-950/20">
            <p className="text-sm font-medium">{t('transport.manualException')}</p>
            <p className="text-xs text-(--app-muted-foreground)">{t('transport.manualExceptionHelp')}</p>
            <div className="flex flex-wrap items-end gap-2">
                <FormField label={t('transport.employeeNumber')} error={error ?? undefined}>{({ id }) => <Input id={id} value={number} onChange={(e) => setNumber(e.target.value)} autoComplete="off" />}</FormField>
                <Button type="button" variant="outline" size="sm" onClick={() => void lookup()} disabled={looking || number.trim().length < 3}>{looking ? t('transport.searching') : t('transport.findEmployee')}</Button>
            </div>
            {hit && <p className="text-sm"><span className="font-semibold">{hit.full_name}</span> · {hit.employee_number}</p>}
            <FormField label={t('transport.reason')} required>{({ id }) => <Textarea id={id} rows={2} value={reason} onChange={(e) => setReason(e.target.value)} />}</FormField>
            <div className="flex gap-2">
                <Button type="button" variant="primary" size="sm" disabled={disabled || !hit || reason.trim().length < 5} onClick={() => hit && onSubmit(hit.id, reason.trim())}>{t('transport.recordManualBoarding')}</Button>
                <Button type="button" variant="ghost" size="sm" onClick={onCancel}>{t('transport.cancel')}</Button>
            </div>
        </div>
    );
}
