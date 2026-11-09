import axios, { AxiosError } from 'axios';
import { router } from '@inertiajs/react';
import { Alert, Button, Card, EmptyState, FormField, Input, Select, StatusBadge, Textarea, cx } from '@euisis/ui';
import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent, type ReactNode } from 'react';
import type { Html5QrcodeCameraScanConfig } from 'html5-qrcode';
import UserAvatar from '@/Components/UserAvatar';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import NfcTapPanel from '@/Components/Cafeteria/NfcTapPanel';
import { AlertTriangle, CalendarIcon, CameraIcon, CheckCircle, QrCodeIcon, UserIcon, XCircle } from '@/Components/Icons';
import { newScanNonce, useQrCamera, useRestartCountdown } from '@/Components/Cafeteria/scanTerminal';
import { useTransportLabels } from './labels';
import { Percent } from './TransportShared';
import type { ProviderOption, ScanResult, TransportRoute, TransportRouteStop, TransportTransaction, TransportTrip } from '@/types/transport';

export type TerminalConfig = { manualAllowed: boolean; canDispatch: boolean; tripRequired: boolean; nfcEnabled: boolean };

type LoadState = 'loading' | 'ready' | 'error';
type Outcome = 'accepted' | 'duplicate' | 'refused';

/** A handheld scanner "types" a whole code in well under this gap between keys. */
const WEDGE_GAP_MS = 40;

const DUPLICATE_CODES = new Set(['already_boarded', 'duplicate_scan_nonce']);

/** The same decoder settings as the cafeteria terminal: a square box, most of the view. */
const cameraScanConfig: Html5QrcodeCameraScanConfig = {
    fps: 10,
    qrbox: (viewfinderWidth: number, viewfinderHeight: number) => {
        const edge = Math.floor(Math.min(viewfinderWidth, viewfinderHeight) * 0.72);
        return { width: edge, height: edge };
    },
    aspectRatio: 1,
    disableFlip: false,
};

const OUTCOME_BANNER: Record<Outcome, string> = {
    accepted: 'bg-emerald-50 text-emerald-900 ring-emerald-600/20 dark:bg-emerald-950/40 dark:text-emerald-100 dark:ring-emerald-400/25',
    duplicate: 'bg-amber-50 text-amber-900 ring-amber-600/25 dark:bg-amber-950/40 dark:text-amber-100 dark:ring-amber-400/25',
    refused: 'bg-red-50 text-red-900 ring-red-600/20 dark:bg-red-950/40 dark:text-red-100 dark:ring-red-400/25',
};

const muted = 'text-(--app-muted-foreground)';

function outcomeOf(result: ScanResult): Outcome {
    if (result.accepted) return 'accepted';
    return DUPLICATE_CODES.has(result.result_code) ? 'duplicate' : 'refused';
}

function formatTime(iso: string, locale: string): string {
    return new Date(iso).toLocaleTimeString(locale === 'am' ? 'am-ET' : 'en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

function isoToday(): string {
    const now = new Date();
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
}

function PanelHeader({ id, title, aside }: { id: string; title: string; aside?: ReactNode }) {
    return (
        <div className="flex min-h-13 flex-wrap items-center justify-between gap-2 border-b border-(--app-border) px-4 py-2.5">
            <h2 id={id} className="text-sm font-semibold text-(--app-foreground)">{title}</h2>
            {aside}
        </div>
    );
}

/**
 * The boarding terminal, laid out like the cafeteria scan terminal: the
 * provider, route, trip and stop across the top with the trip's state and
 * dispatch; then the capture panel (camera, NFC, a handheld reader typing
 * into the page, manual entry), the latest decision with the employee, the
 * day's boardings at this provider, and the trip's occupancy with this
 * session's tally. A sound and a buzz per decision; after each one the
 * camera restarts by itself for the next card. Every scan carries a fresh
 * nonce, so a resent request is answered, never recorded twice.
 */
export default function TransportScanPanel({ providers, routes, trips, stops = [], scanRouteName, lookupRouteName, dispatchRouteName, todayRouteName, terminal }: {
    providers?: ProviderOption[];
    routes: TransportRoute[];
    trips: TransportTrip[];
    stops?: TransportRouteStop[];
    scanRouteName: string;
    lookupRouteName?: string;
    dispatchRouteName?: string;
    /** Lists today's boardings at the provider; without it the list is not shown. */
    todayRouteName?: string;
    terminal?: TerminalConfig;
}) {
    const { t, name, label } = useTransportLabels();
    const config: TerminalConfig = terminal ?? { manualAllowed: false, canDispatch: false, tripRequired: false, nfcEnabled: false };
    const [providerId, setProviderId] = useState(providers?.[0]?.id ?? '');
    const [routeId, setRouteId] = useState('');
    const [tripId, setTripId] = useState('');
    const [stopId, setStopId] = useState('');
    const [reference, setReference] = useState('');
    const [method, setMethod] = useState<'qr' | 'nfc'>('qr');
    const [busy, setBusy] = useState(false);
    const [result, setResult] = useState<ScanResult | null>(null);
    const [failed, setFailed] = useState(false);
    // Changes after every decision; drives the restart countdown.
    const [decision, setDecision] = useState<object | null>(null);
    const [sound, setSound] = useState(true);
    const [manual, setManual] = useState(false);
    const [session, setSession] = useState({ accepted: 0, refused: 0 });
    const [occupancy, setOccupancy] = useState<{ boarded: number; capacity: number | null } | null>(null);
    // The operator turned the camera on; it restarts after each decision until turned off.
    const [cameraWanted, setCameraWanted] = useState(false);
    const [todayScans, setTodayScans] = useState<TransportTransaction[]>([]);
    const [historyState, setHistoryState] = useState<LoadState>('ready');
    const [historyTick, setHistoryTick] = useState(0);

    const ownRoutes = useMemo(() => routes.filter((r) => r.is_active && (!providers || r.provider_id === providerId)), [routes, providers, providerId]);
    const ownTrips = useMemo(() => trips.filter((trip) => (!providers || trip.provider_id === providerId) && (!routeId || trip.transport_route_id === routeId)), [trips, providers, providerId, routeId]);
    const trip = useMemo(() => trips.find((x) => x.id === tripId) ?? null, [trips, tripId]);
    const ownStops = useMemo(() => stops.filter((s) => s.transport_route_id === (trip?.transport_route_id ?? routeId)), [stops, trip, routeId]);
    const boardable = trip ? trip.status === 'boarding' || trip.status === 'in_progress' : false;
    const canScan = !busy && (providers === undefined || Boolean(providerId)) && (!config.tripRequired || (Boolean(trip) && boardable));
    const todayDateStr = isoToday();

    // Camera, NFC and reader callbacks outlive renders; they read the current selection from here.
    const latest = useRef({ providerId, routeId, tripId, stopId, canScan });
    useEffect(() => { latest.current = { providerId, routeId, tripId, stopId, canScan }; });

    useEffect(() => {
        setOccupancy(trip ? { boarded: trip.boarded_count, capacity: trip.effective_capacity ?? trip.capacity ?? null } : null);
    }, [trip]);

    const feedback = useCallback((kind: Outcome) => {
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

    const record = useCallback(async (payload: Record<string, string | null>) => {
        if (busy) return;
        const { providerId: provider, routeId: routeChoice, tripId: tripChoice, stopId: stopChoice } = latest.current;
        setBusy(true); setFailed(false); setResult(null);
        try {
            const response = await axios.post<ScanResult>(route(scanRouteName), {
                ...(providers ? { provider_id: provider } : {}),
                scan_nonce: newScanNonce(),
                transport_route_id: tripChoice ? null : routeChoice || null,
                transport_trip_id: tripChoice || null,
                transport_route_stop_id: stopChoice || null,
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
                feedback(outcomeOf(response.data));
            } else { setFailed(true); feedback('refused'); }
        } finally {
            setBusy(false);
            setReference('');
            setDecision({});
            setHistoryTick((k) => k + 1);
        }
    }, [busy, scanRouteName, providers, feedback]);

    const camera = useQrCamera(cameraScanConfig, (text) => void record({ qr_token: text.trim() }));
    const { start: startCameraSession, stop: stopCameraSession, fail: cameraFail } = camera;

    const notReadyReason = useCallback((): string | null => {
        if (providers && !latest.current.providerId) return t('transport.selectProviderFirst');
        if (config.tripRequired && !latest.current.canScan) return t('transport.selectTripFirst');
        return null;
    }, [providers, config.tripRequired, t]);

    const startCamera = useCallback(() => {
        const reason = notReadyReason();
        if (reason) { cameraFail(reason); return; }
        setCameraWanted(true);
        void startCameraSession();
    }, [notReadyReason, cameraFail, startCameraSession]);

    const stopCamera = useCallback(async () => {
        setCameraWanted(false);
        await stopCameraSession();
    }, [stopCameraSession]);

    const cameraBusy = camera.active || camera.starting;
    // Each camera session yields one decode and stops; after the decision it starts again.
    const countdown = useRestartCountdown(decision, method === 'qr' && cameraWanted && !busy && !cameraBusy, () => {
        if (latest.current.canScan) void startCameraSession();
    });

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
                if (buffer.length >= 10 && !typing) { event.preventDefault(); const value = buffer; buffer = ''; if (latest.current.canScan) void record({ qr_token: value }); }
                buffer = '';
                return;
            }
            if (event.key.length === 1 && !typing) buffer += event.key;
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [record]);

    // Today's boardings follow the provider and the trip, and refresh after every decision.
    useEffect(() => {
        if (!todayRouteName || (providers && !providerId)) { setTodayScans([]); return; }
        let cancelled = false;
        setHistoryState('loading');
        void axios
            .get<{ data: TransportTransaction[] }>(route(todayRouteName), { params: { ...(providers ? { provider_id: providerId } : {}), transport_trip_id: tripId || undefined } })
            .then((response) => { if (!cancelled) { setTodayScans(response.data.data); setHistoryState('ready'); } })
            .catch(() => { if (!cancelled) setHistoryState('error'); });
        return () => { cancelled = true; };
    }, [todayRouteName, providers, providerId, tripId, historyTick]);

    function submit(event: FormEvent) {
        event.preventDefault();
        // Manual entry also submits on Enter, so the guards live here, not only on the button.
        const value = reference.trim();
        if (!value || !canScan) return;
        void record({ qr_token: value, ...(config.nfcEnabled && /^[0-9a-f]{8,}$/i.test(value) && !value.includes(':') ? { verification_method: 'nfc' } : {}) });
    }

    async function changeMethod(next: 'qr' | 'nfc') {
        if (next === method) return;
        await stopCamera();
        setMethod(next);
    }

    function activate(action: 'start' | 'depart' | 'complete') {
        if (!trip || !dispatchRouteName) return;
        router.post(route(dispatchRouteName, trip.id), { action }, { preserveScroll: true, preserveState: true, only: ['trips'] });
    }

    const captureStatus = busy
        ? { tone: 'info' as const, label: t('cafeteria.processing') }
        : camera.active
            ? { tone: 'success' as const, label: t('cafeteria.cameraScanning') }
            : canScan
                ? { tone: 'neutral' as const, label: t('transport.scanReady') }
                : { tone: 'warning' as const, label: t('transport.scanNotReady') };

    const occupancyPercent = occupancy?.capacity ? Math.min(100, Math.round((occupancy.boarded / occupancy.capacity) * 100)) : null;

    return (
        <div data-transport-terminal className="space-y-4 text-(--app-foreground)">
            <Card className="p-0" aria-label={t('transport.trip')}>
                <div className="grid gap-4 p-4 sm:grid-cols-2 xl:flex xl:items-end">
                    {providers && (
                        <FormField label={t('transport.provider')} required className="xl:min-w-0 xl:flex-[1.2]">
                            {({ id }) => (
                                <Select id={id} value={providerId} disabled={cameraBusy || busy} onChange={(e) => { setProviderId(e.target.value); setRouteId(''); setTripId(''); setStopId(''); }}>
                                    {providers.length === 0 && <option value="">—</option>}
                                    {providers.map((p) => <option key={p.id} value={p.id}>{p.provider_code} — {name(p)}</option>)}
                                </Select>
                            )}
                        </FormField>
                    )}
                    <FormField label={t('transport.route')} className="xl:min-w-0 xl:flex-1">
                        {({ id }) => (
                            <Select id={id} value={routeId} disabled={busy} onChange={(e) => { setRouteId(e.target.value); setTripId(''); setStopId(''); }}>
                                <option value="">{t('transport.selectRoute')}</option>
                                {ownRoutes.map((r) => <option key={r.id} value={r.id}>{r.route_code} — {name(r)}</option>)}
                            </Select>
                        )}
                    </FormField>
                    <FormField label={t('transport.scanTrip')} required={config.tripRequired} className="xl:min-w-0 xl:flex-[1.4]">
                        {({ id }) => (
                            <Select id={id} value={tripId} disabled={busy} onChange={(e) => { setTripId(e.target.value); setStopId(''); }}>
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
                        <FormField label={t('transport.stop')} className="xl:min-w-0 xl:flex-1">
                            {({ id }) => (
                                <Select id={id} value={stopId} disabled={busy} onChange={(e) => setStopId(e.target.value)}>
                                    <option value="">{t('transport.anyStop')}</option>
                                    {ownStops.map((s) => <option key={s.id} value={s.id}>{s.sequence}. {name(s)}</option>)}
                                </Select>
                            )}
                        </FormField>
                    )}
                    <p className={cx('flex h-(--control-h-md) items-center gap-2 text-sm sm:col-span-2 xl:flex-none', muted)}>
                        <CalendarIcon className="h-4 w-4 shrink-0" aria-hidden="true" />
                        <LocalizedDateDisplay value={todayDateStr} />
                    </p>
                </div>
                {trip && (
                    <div className="flex flex-wrap items-center justify-between gap-3 border-t border-(--app-border) px-4 py-2.5 text-sm">
                        <div className="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1">
                            <span className="font-medium">{trip.trip_number}</span>
                            <StatusBadge tone={boardable ? 'success' : 'warning'}>{label('statuses', trip.status)}</StatusBadge>
                            {trip.route && <span className={muted}>{trip.route.route_code} — {name(trip.route)}</span>}
                            {trip.vehicle && <span className={muted}>{trip.vehicle.plate_number}</span>}
                            {occupancy && <span className="tabular-nums">{t('transport.boarded')}: {occupancy.boarded}{occupancy.capacity ? ` / ${occupancy.capacity}` : ''} {occupancyPercent !== null ? <Percent value={occupancyPercent} /> : null}</span>}
                        </div>
                        {config.canDispatch && dispatchRouteName && (
                            <div className="flex flex-wrap gap-2">
                                {(trip.status === 'planned' || trip.status === 'assigned') && <Button type="button" size="sm" variant="primary" onClick={() => activate('start')}>{t('transport.startBoarding')}</Button>}
                                {trip.status === 'boarding' && <Button type="button" size="sm" variant="outline" onClick={() => activate('depart')}>{t('transport.depart')}</Button>}
                                {(trip.status === 'boarding' || trip.status === 'in_progress') && <Button type="button" size="sm" variant="outline" onClick={() => activate('complete')}>{t('transport.completeTrip')}</Button>}
                            </div>
                        )}
                        {!boardable && !config.canDispatch && <p className="text-xs text-amber-700 dark:text-amber-300">{t('transport.tripNotActivated')}</p>}
                    </div>
                )}
                {config.tripRequired && !trip && <div className="border-t border-(--app-border) px-4 py-3"><Alert tone="warning">{t('transport.tripRequiredHelp')}</Alert></div>}
            </Card>

            <div className="grid grid-cols-1 items-start gap-4 md:grid-cols-2 xl:grid-cols-12">
                <Card role="region" aria-labelledby="transport-capture-heading" className="min-w-0 overflow-hidden p-0 xl:col-span-5">
                    <PanelHeader id="transport-capture-heading" title={t('transport.scanCredential')}
                        aside={<span aria-live="polite"><StatusBadge tone={captureStatus.tone}>{captureStatus.label}</StatusBadge></span>} />
                    <div className="space-y-4 p-4">
                        {config.nfcEnabled && (
                            <div className="flex gap-1 rounded-control bg-(--app-surface-muted) p-1" role="group" aria-label={t('transport.scanCredential')}>
                                {(['qr', 'nfc'] as const).map((option) => (
                                    <button key={option} type="button" disabled={camera.starting || busy} aria-pressed={method === option}
                                        onClick={() => void changeMethod(option)}
                                        className={cx('h-9 flex-1 rounded-[calc(var(--radius-control)-2px)] text-sm font-medium transition-colors focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-primary disabled:opacity-60',
                                            method === option ? 'bg-(--app-surface) text-(--app-foreground) shadow-xs' : cx(muted, 'hover:text-(--app-foreground)'))}>
                                        {option === 'qr' ? t('nfc.scanMethodQr') : t('nfc.scanMethodNfc')}
                                    </button>
                                ))}
                            </div>
                        )}

                        {method === 'nfc' ? <NfcTapPanel disabled={!canScan} onCredential={(credential) => void record({ qr_token: credential, verification_method: 'nfc' })} /> : <>
                            <div className="relative overflow-hidden rounded-card bg-slate-950">
                                {/* The decoder uses video element dimensions: never crop or force its height. */}
                                <div id={camera.regionId} className="mx-auto min-h-[240px] w-full max-w-[340px] [&_video]:h-auto [&_video]:w-full" />
                                {!camera.active && (
                                    <div className="absolute inset-0 flex flex-col items-center justify-center gap-4 px-6 text-center">
                                        {camera.starting || busy
                                            ? <span aria-hidden="true" className="h-9 w-9 animate-spin rounded-full border-4 border-white/20 border-t-white" />
                                            : <span className="flex h-14 w-14 items-center justify-center rounded-full bg-[color-mix(in_srgb,var(--app-surface)_10%,transparent)] text-(--app-muted-foreground)"><QrCodeIcon className="h-7 w-7" aria-hidden="true" /></span>}
                                        <p className="max-w-xs text-sm leading-6 text-(--app-muted-foreground)">
                                            {busy ? t('cafeteria.processingScan') : camera.starting ? t('cafeteria.startingCamera') : t('cafeteria.cameraReady')}
                                        </p>
                                    </div>
                                )}
                            </div>
                            {camera.error && (
                                <p role="alert" className="flex gap-2 rounded-control bg-red-50 p-3 text-sm leading-5 text-red-800 ring-1 ring-inset ring-red-600/20 dark:bg-red-950/40 dark:text-red-200 dark:ring-red-400/25">
                                    <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />{camera.error}
                                </p>
                            )}
                            <div className="flex flex-wrap gap-2">
                                <Button variant="primary" onClick={startCamera} loading={camera.starting} icon={<CameraIcon className="h-4 w-4" />}
                                    disabled={!canScan || cameraBusy} className="flex-1">
                                    {camera.active ? t('cafeteria.cameraScanning') : t('cafeteria.startCamera')}
                                </Button>
                                <Button variant="outline" onClick={() => void stopCamera()} disabled={!cameraWanted && !camera.active}>{t('cafeteria.stopCamera')}</Button>
                            </div>
                            <form onSubmit={submit} className="border-t border-(--app-border) pt-4">
                                <FormField id="transport-scan-reference" label={config.nfcEnabled ? t('transport.scanManualOrNfc') : t('transport.scanManual')} description={t('transport.wedgeHelp')}>
                                    {({ id, describedBy }) => (
                                        <div className="flex gap-2">
                                            <Input id={id} aria-describedby={describedBy} className="min-w-0 flex-1 font-mono" placeholder={t('transport.scanManualPlaceholder')}
                                                autoComplete="off" spellCheck={false} value={reference} onChange={(e) => setReference(e.target.value)} />
                                            <Button type="submit" variant="outline" disabled={!canScan || !reference.trim()}>{busy ? t('transport.scanning') : t('transport.scanSubmit')}</Button>
                                        </div>
                                    )}
                                </FormField>
                            </form>
                        </>}

                        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-(--app-border) pt-4">
                            <label className={cx('flex items-center gap-2 text-sm', muted)}><input type="checkbox" className="h-4 w-4" checked={sound} onChange={(e) => setSound(e.target.checked)} />{t('transport.soundOn')}</label>
                            {config.manualAllowed && lookupRouteName && <Button type="button" variant="ghost" size="sm" onClick={() => setManual((m) => !m)}>{t('transport.manualException')}</Button>}
                        </div>
                        {manual && config.manualAllowed && lookupRouteName && (
                            <ManualExceptionForm lookupRouteName={lookupRouteName} providerId={providers ? providerId : undefined} disabled={!canScan} onSubmit={(employeeId, reason) => { setManual(false); void record({ manual_employee_id: employeeId, manual_reason: reason, qr_token: null }); }} onCancel={() => setManual(false)} />
                        )}
                    </div>
                </Card>

                <Card role="region" aria-labelledby="transport-result-heading" className="min-w-0 overflow-hidden p-0 xl:col-span-7">
                    <PanelHeader id="transport-result-heading" title={t('transport.scanResult')}
                        aside={countdown !== null ? <span className={cx('text-xs tabular-nums', muted)}>{t('transport.scanAgainIn').replace('{{count}}', String(countdown))}</span> : undefined} />
                    <div aria-live="polite">
                        {failed && <div className="p-4"><Alert tone="danger">{t('transport.scanFailed')}</Alert></div>}
                        {result && <LatestResult result={result} />}
                        {!result && !failed && (
                            <EmptyState className="flex min-h-[280px] flex-col items-center justify-center xl:min-h-[440px]" icon={<UserIcon className="h-10 w-10" />}
                                title={canScan ? t('transport.scanReady') : t('transport.scanNotReady')} description={t('transport.scanToSeeEmployee')} />
                        )}
                    </div>
                </Card>

                {todayRouteName && (
                    <Card role="region" aria-labelledby="transport-history-heading" className="min-w-0 overflow-hidden p-0 xl:col-span-8">
                        <PanelHeader id="transport-history-heading" title={t('transport.todayBoardings')}
                            aside={historyState === 'ready' ? <span className={cx('text-xs tabular-nums', muted)}>{todayScans.length}</span> : undefined} />
                        <TodayList scans={todayScans} state={historyState} />
                    </Card>
                )}

                <Card role="region" aria-labelledby="transport-session-heading" className={cx('min-w-0 overflow-hidden p-0', todayRouteName ? 'xl:col-span-4' : 'xl:col-span-12')}>
                    <PanelHeader id="transport-session-heading" title={t('transport.tripAndSession')} />
                    <div className="space-y-4 p-4">
                        <dl className="grid grid-cols-2 gap-2">
                            <div className="rounded-control bg-(--app-surface-muted) px-3 py-2.5">
                                <dt className={cx('text-xs leading-5', muted)}>{label('statuses', 'accepted')}</dt>
                                <dd className="mt-0.5 text-2xl font-semibold tabular-nums text-emerald-700 dark:text-emerald-300">{session.accepted}</dd>
                            </div>
                            <div className="rounded-control bg-(--app-surface-muted) px-3 py-2.5">
                                <dt className={cx('text-xs leading-5', muted)}>{label('statuses', 'rejected')}</dt>
                                <dd className="mt-0.5 text-2xl font-semibold tabular-nums text-red-700 dark:text-red-300">{session.refused}</dd>
                            </div>
                        </dl>
                        <p className={cx('text-xs', muted)}>{t('transport.thisSession')}</p>
                        {trip ? (
                            <div className="space-y-3 border-t border-(--app-border) pt-4">
                                <div className="flex items-baseline justify-between gap-2">
                                    <h3 className="text-sm font-semibold">{t('transport.occupancyLabel')}</h3>
                                    {occupancy && <span className="text-sm tabular-nums">{occupancy.boarded}{occupancy.capacity ? ` / ${occupancy.capacity}` : ''}</span>}
                                </div>
                                {occupancyPercent !== null && (
                                    <div className="h-2 overflow-hidden rounded-full bg-(--app-surface-muted)" role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={occupancyPercent}>
                                        <div className={cx('h-full rounded-full', occupancyPercent >= 100 ? 'bg-red-600' : occupancyPercent >= 90 ? 'bg-amber-500' : 'bg-primary')} style={{ width: `${occupancyPercent}%` }} />
                                    </div>
                                )}
                                <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2 xl:grid-cols-1">
                                    {[
                                        [t('transport.trip'), trip.trip_number],
                                        [t('transport.route'), trip.route ? `${trip.route.route_code} — ${name(trip.route)}` : null],
                                        [t('transport.direction'), trip.direction ? label('directions', trip.direction) : null],
                                        [t('transport.vehicle'), trip.vehicle?.plate_number ?? null],
                                    ].filter(([, value]) => value).map(([heading, value]) => <div key={heading} className="min-w-0">
                                        <dt className={cx('text-xs', muted)}>{heading}</dt>
                                        <dd className="mt-0.5 wrap-break-word text-sm">{value}</dd>
                                    </div>)}
                                </dl>
                            </div>
                        ) : <p className={cx('border-t border-(--app-border) pt-4 text-sm', muted)}>{t('transport.noTrip')}</p>}
                    </div>
                </Card>
            </div>
        </div>
    );
}

// ─── Latest result ───────────────────────────────────────────────────────────

function LatestResult({ result }: { result: ScanResult }) {
    const { t, locale, name, label } = useTransportLabels();
    const outcome = outcomeOf(result);
    const Icon = outcome === 'refused' ? XCircle : outcome === 'duplicate' ? AlertTriangle : CheckCircle;
    const transaction = result.transaction;
    const employee = transaction?.employee;

    return (
        <div className="space-y-5 p-4">
            <div role="status" className={cx('flex items-start gap-3 rounded-card p-4 ring-1 ring-inset', OUTCOME_BANNER[outcome])}>
                <Icon className="mt-0.5 h-6 w-6 shrink-0" aria-hidden="true" />
                <div className="min-w-0">
                    <p className="text-base font-semibold">{result.accepted ? t('transport.boardingAccepted') : t('transport.boardingRefused')}</p>
                    <p className="mt-0.5 text-sm leading-6">{result.result_code === 'transport_scan_accepted' ? t('transport.autoResetHelp') : (result.message ?? label('reasons', result.result_code))}</p>
                    {(result.warnings ?? []).length > 0 && <ul className="mt-1 text-xs">{(result.warnings ?? []).map((w) => <li key={w}>{label('reasons', w)}</li>)}</ul>}
                </div>
            </div>

            {employee && (
                <div>
                    <div className="flex items-center gap-4">
                        <UserAvatar key={employee.employee_number} name={employee.full_name} size={64}
                            className="shrink-0 rounded-panel bg-(--app-surface-muted)! text-(--app-foreground)!" />
                        <div className="min-w-0">
                            <h3 className="wrap-break-word text-lg font-semibold leading-7">{employee.full_name}</h3>
                            <p className={cx('mt-0.5 wrap-break-word font-mono text-sm', muted)}>{employee.employee_number}</p>
                        </div>
                    </div>
                    <dl className="mt-4 grid gap-x-6 gap-y-3 sm:grid-cols-2">
                        {[
                            [t('transport.organization'), locale === 'am' ? employee.organization_am || employee.organization : employee.organization],
                            [t('transport.route'), transaction?.route ? `${transaction.route.route_code} — ${name(transaction.route)}` : null],
                            [t('transport.trip'), transaction?.trip?.trip_number ?? null],
                            [t('transport.scannedAt'), transaction?.scanned_at ? formatTime(transaction.scanned_at, locale) : null],
                            [t('transport.verificationMethod'), transaction?.verification_method ? label('methods', transaction.verification_method) : null],
                        ].filter(([, value]) => value).map(([heading, value]) => <div key={heading} className="min-w-0">
                            <dt className={cx('text-xs', muted)}>{heading}</dt>
                            <dd className="mt-0.5 wrap-break-word text-sm">{value}</dd>
                        </div>)}
                    </dl>
                </div>
            )}
        </div>
    );
}

// ─── Today's boardings ───────────────────────────────────────────────────────

function TodayList({ scans, state }: { scans: TransportTransaction[]; state: LoadState }) {
    const { t, locale, name, label, tone } = useTransportLabels();

    if (state !== 'ready') {
        return <p role="status" className={cx('px-4 py-10 text-center text-sm', muted)}>{t(state === 'loading' ? 'common.loading' : 'transport.historyUnavailable')}</p>;
    }
    if (scans.length === 0) {
        return <EmptyState icon={<QrCodeIcon className="h-8 w-8" />} title={t('transport.noBoardingsToday')} />;
    }

    return (
        <ul className="max-h-[440px] divide-y divide-(--app-border) overflow-y-auto">
            {scans.map((scan) => (
                <li key={scan.id} className="flex gap-3 px-4 py-3">
                    <UserAvatar name={scan.employee?.full_name ?? '?'} size={36} className="mt-0.5 shrink-0 bg-(--app-surface-muted)! text-(--app-foreground)!" />
                    <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                            <p className="min-w-0 wrap-break-word text-sm font-medium">{scan.employee?.full_name ?? '—'}</p>
                            <span className={cx('shrink-0 text-xs tabular-nums', muted)}>{scan.scanned_at ? formatTime(scan.scanned_at, locale) : '—'}</span>
                        </div>
                        <p className={cx('mt-0.5 wrap-break-word text-xs', muted)}>
                            {[scan.employee?.employee_number, locale === 'am' ? scan.employee?.organization_am || scan.employee?.organization : scan.employee?.organization, scan.route ? `${scan.route.route_code} — ${name(scan.route)}` : null].filter(Boolean).join(' · ')}
                        </p>
                        <div className={cx('mt-2 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-xs', muted)}>
                            <StatusBadge tone={tone(scan.status)}>{label('statuses', scan.status)}</StatusBadge>
                            {scan.status !== 'accepted' && scan.result_code && <span>{label('reasons', scan.result_code)}</span>}
                            {scan.trip && <span>{t('transport.trip')}: <span className="text-(--app-foreground)">{scan.trip.trip_number}</span></span>}
                            {scan.verification_method && <span>{label('methods', scan.verification_method)}</span>}
                        </div>
                    </div>
                </li>
            ))}
        </ul>
    );
}

/** A boarding without a working card: the operator names the employee by number and gives a reason; the record is flagged and audited. */
/** `providerId`: the staff terminal's chosen provider; the lookup answers only for employees it may carry. */
function ManualExceptionForm({ lookupRouteName, providerId, disabled, onSubmit, onCancel }: { lookupRouteName: string; providerId?: string; disabled: boolean; onSubmit: (employeeId: string, reason: string) => void; onCancel: () => void }) {
    const { t } = useTransportLabels();
    const [number, setNumber] = useState('');
    const [reason, setReason] = useState('');
    const [hit, setHit] = useState<{ id: string; employee_number: string; full_name: string } | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [looking, setLooking] = useState(false);

    async function lookup() {
        setLooking(true); setError(null); setHit(null);
        try {
            const response = await axios.post<{ id: string; employee_number: string; full_name: string }>(route(lookupRouteName), { employee_number: number.trim(), ...(providerId ? { provider_id: providerId } : {}) });
            setHit(response.data);
        } catch (caught) {
            const response = (caught as AxiosError<{ message?: string }>).response;
            setError(response?.data?.message ?? t('transport.noMatches'));
        } finally { setLooking(false); }
    }

    return (
        <div className="space-y-3 rounded-card border border-amber-300 bg-amber-50 p-4 dark:border-amber-700 dark:bg-amber-950/20">
            <p className="text-sm font-medium">{t('transport.manualException')}</p>
            <p className={cx('text-xs', muted)}>{t('transport.manualExceptionHelp')}</p>
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
