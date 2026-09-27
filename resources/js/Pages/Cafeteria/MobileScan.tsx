import { Head, Link, router, usePage } from '@inertiajs/react';
import { FormEvent, useCallback, useEffect, useRef, useState } from 'react';
import type { Html5QrcodeCameraScanConfig } from 'html5-qrcode';
import { Button, Input, Select, cx } from '@euisis/ui';
import { useLocale } from '@/hooks/useLocale';
import NfcTapPanel from '@/Components/Cafeteria/NfcTapPanel';
import UserAvatar from '@/Components/UserAvatar';
import { AlertTriangle, CameraIcon, CheckCircle, ChevronLeft, KeyboardIcon, LayoutDashboard, QrCodeIcon, XCircle } from '@/Components/Icons';
import {
    type OutcomeTone, type ScanOutcome, denialText, newScanNonce, outcomeTone, rememberScanView, useQrCamera, useRestartCountdown,
} from '@/Components/Cafeteria/scanTerminal';

type Provider = {
    id: string;
    name_en: string;
    name_am: string | null;
    code: string;
    is_active: boolean;
};

type ScanResult = ScanOutcome & {
    employee: { full_name: string; employee_number: string; photo_url: string | null; position: string | null; organization: string | null } | null;
    subsidy_applied?: number | null;
};

type Props = {
    providers: Provider[];
    scanOptions?: { default_usage_mode: string; allow_upfront_weekday_usage: boolean };
    provider_locked?: boolean;
    selected_provider_id?: string | null;
    today_scan_count?: number;
    scan_result?: ScanResult | null;
};

const cameraScanConfig: Html5QrcodeCameraScanConfig = {
    fps: 15,
    qrbox: (width: number, height: number) => {
        const edge = Math.floor(Math.min(width, height) * 0.78);
        return { width: edge, height: edge };
    },
    aspectRatio: 1,
    disableFlip: false,
};

/** Solid outcome colours: the result has to read at arm's length, in either theme. */
const TONE_SURFACE: Record<OutcomeTone, string> = {
    allowed: 'bg-emerald-700',
    extra: 'bg-amber-700',
    denied: 'bg-red-700',
};
const TONE_DOT: Record<OutcomeTone, string> = {
    allowed: 'bg-emerald-400',
    extra: 'bg-amber-400',
    denied: 'bg-red-400',
};

/**
 * Handheld scan screen: the camera fills the screen, controls sit within thumb
 * reach, and each result takes over the viewfinder until the next scan starts.
 */
export default function MobileScan({ providers, scanOptions, provider_locked, selected_provider_id, today_scan_count, scan_result }: Props) {
    const { t, locale } = useLocale();
    const { errors } = usePage().props as { errors?: Record<string, string> };
    const nfcSupported = typeof window !== 'undefined' && 'NDEFReader' in window;
    const upfrontAllowed = scanOptions?.allow_upfront_weekday_usage !== false;

    const [providerId, setProviderId] = useState(() => providers.find((p) => p.id === selected_provider_id)?.id ?? providers[0]?.id ?? '');
    const [usageMode, setUsageMode] = useState(scanOptions?.default_usage_mode ?? 'single_day');
    const [method, setMethod] = useState<'qr' | 'nfc'>('qr');
    const [processing, setProcessing] = useState(false);
    const [manualOpen, setManualOpen] = useState(false);
    const [manualToken, setManualToken] = useState('');
    const [dismissedResult, setDismissedResult] = useState<ScanResult | null>(null);

    // The camera callback outlives renders, so it reads the current selection from here.
    const selection = useRef({ providerId, usageMode });
    const nonce = useRef(newScanNonce());
    useEffect(() => { selection.current = { providerId, usageMode }; }, [providerId, usageMode]);

    const submitCredential = useCallback((credential: { qr_token: string } | { nfc_credential: string }, onSuccess?: () => void) => {
        setProcessing(true);
        router.post(route('cafeteria.scan.process'), {
            ...credential,
            provider_id: selection.current.providerId,
            usage_mode: selection.current.usageMode,
            scan_nonce: nonce.current,
            source: 'mobile',
        }, {
            preserveScroll: true,
            onSuccess,
            onFinish: () => {
                setProcessing(false);
                nonce.current = newScanNonce();
            },
        });
    }, []);

    const camera = useQrCamera(cameraScanConfig, (text) => submitCredential({ qr_token: text.trim() }));
    const { start: startCamera, stop: stopCamera, fail: cameraFail } = camera;

    const startScanning = useCallback(() => {
        setDismissedResult(scan_result ?? null);
        if (!selection.current.providerId) {
            cameraFail(t('cafeteria.selectProviderFirst'));
            return;
        }
        void startCamera();
    }, [cameraFail, scan_result, startCamera, t]);

    // Open straight into the camera, and again after switching provider.
    useEffect(() => {
        if (providerId && method === 'qr' && !scan_result) void startCamera();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [providerId]);

    const cameraBusy = camera.active || camera.starting;
    const countdown = useRestartCountdown(scan_result, method === 'qr' && !processing && !cameraBusy, startScanning);
    const showResult = Boolean(scan_result) && scan_result !== dismissedResult && !processing && !cameraBusy;
    const tone = scan_result ? outcomeTone(scan_result) : 'allowed';
    const errorMessage = errors ? Object.values(errors)[0] : undefined;

    function changeProvider(id: string) {
        void stopCamera();
        setProviderId(id);
        // The scan count belongs to the provider; the URL keeps the choice across a refresh.
        router.reload({ data: { provider_id: id }, only: ['today_scan_count'] });
    }

    async function changeMethod(next: 'qr' | 'nfc') {
        if (next === method) return;
        await stopCamera();
        setDismissedResult(scan_result ?? null);
        setMethod(next);
    }

    function submitManual(event: FormEvent) {
        event.preventDefault();
        const token = manualToken.trim();
        if (!token || !providerId || processing) return;
        void stopCamera();
        submitCredential({ qr_token: token }, () => setManualToken(''));
    }

    const providerLabel = (provider: Provider) => (locale === 'am' && provider.name_am ? provider.name_am : provider.name_en);
    const selectedProvider = providers.find((provider) => provider.id === providerId);
    const toolbar = nfcSupported || upfrontAllowed;
    const iconLink = 'inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-[var(--radius-control)] text-[color:var(--app-muted-foreground)] transition-colors hover:bg-[color:var(--app-surface-muted)] hover:text-[color:var(--app-foreground)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)]';

    return (
        <div className="flex h-dvh flex-col bg-[color:var(--app-background)] text-[color:var(--app-foreground)]">
            <Head title={t('cafeteria.scanQr')} />

            <header className="border-b border-[color:var(--app-border)] bg-[color:var(--app-surface)] px-2 pb-2 pt-[max(0.5rem,env(safe-area-inset-top))]">
                <div className="flex items-center gap-1">
                    <Link href={route('dashboard')} className={iconLink} aria-label={t('common.back')} title={t('common.back')}>
                        <ChevronLeft className="h-5 w-5" aria-hidden="true" />
                    </Link>
                    <div className="min-w-0 flex-1 px-1">
                        {provider_locked || providers.length <= 1 ? (
                            <p className="truncate text-sm font-semibold">
                                {selectedProvider ? providerLabel(selectedProvider) : t('cafeteria.noProvidersAvailable')}
                                {selectedProvider && <span className="ms-1.5 font-normal text-[color:var(--app-muted-foreground)]">{selectedProvider.code}</span>}
                            </p>
                        ) : (
                            <Select aria-label={t('cafeteria.selectProvider')} value={providerId} disabled={processing}
                                onChange={(event) => changeProvider(event.target.value)} className="!h-10 !text-base font-medium sm:!text-sm">
                                {providers.map((provider) => <option key={provider.id} value={provider.id}>{providerLabel(provider)}</option>)}
                            </Select>
                        )}
                    </div>
                    <div className="shrink-0 px-2 text-right leading-tight" aria-live="polite">
                        <p className="text-lg font-semibold tabular-nums">{(today_scan_count ?? 0).toLocaleString()}</p>
                        <p className="text-[11px] text-[color:var(--app-muted-foreground)]">{t('cafeteria.todayScans')}</p>
                    </div>
                    <Link href={route('cafeteria.scan')} onClick={() => rememberScanView('full')} className={iconLink}
                        aria-label={t('cafeteria.fullTerminal')} title={t('cafeteria.fullTerminal')}>
                        <LayoutDashboard className="h-5 w-5" aria-hidden="true" />
                    </Link>
                </div>

                {toolbar && (
                    <div className="mt-2 flex items-center gap-2 px-1">
                        {nfcSupported && (
                            <div role="group" aria-label={t('cafeteria.scanCredential')} className="inline-flex rounded-[var(--radius-control)] bg-[color:var(--app-surface-muted)] p-0.5">
                                {(['qr', 'nfc'] as const).map((option) => (
                                    <button key={option} type="button" aria-pressed={method === option} disabled={processing}
                                        onClick={() => void changeMethod(option)}
                                        className={cx('h-9 rounded-[calc(var(--radius-control)-2px)] px-4 text-sm font-medium transition-colors',
                                            method === option ? 'bg-[color:var(--app-surface)] text-[color:var(--app-foreground)] shadow-sm' : 'text-[color:var(--app-muted-foreground)]')}>
                                        {option === 'qr' ? t('nfc.scanMethodQr') : t('nfc.scanMethodNfc')}
                                    </button>
                                ))}
                            </div>
                        )}
                        {upfrontAllowed && (
                            <Select aria-label={t('cafeteria.usageModeLabel')} value={usageMode} disabled={processing}
                                onChange={(event) => setUsageMode(event.target.value)} className="ms-auto !h-9 !w-auto min-w-0 !text-base sm:!text-sm">
                                <option value="single_day">{t('cafeteria.usageModeSingleDay')}</option>
                                <option value="use_remaining_week">{t('cafeteria.usageModeRemainingWeek')}</option>
                            </Select>
                        )}
                    </div>
                )}
            </header>

            {/* The stage is a size container so the viewfinder can be the largest square that fits. */}
            <main className="relative flex min-h-0 flex-1 items-center justify-center overflow-hidden bg-black text-white [container-type:size]">
                <div id={camera.regionId} className="w-[min(100cqw,100cqh)] [&_video]:block [&_video]:w-full" />

                {camera.active && (
                    <p className="pointer-events-none absolute inset-x-0 bottom-4 mx-auto w-fit max-w-[90%] rounded-full bg-black/60 px-4 py-2 text-center text-sm text-white/90 backdrop-blur">
                        {t('cafeteria.holdQrInFrame')}
                    </p>
                )}

                {camera.active && scan_result && (
                    <p className="pointer-events-none absolute inset-x-3 top-3 mx-auto flex w-fit max-w-full items-center gap-2 rounded-full bg-black/60 px-3 py-1.5 text-sm backdrop-blur">
                        <span aria-hidden="true" className={cx('h-2 w-2 shrink-0 rounded-full', TONE_DOT[tone])} />
                        <span className="text-white/70">{t('cafeteria.lastScan')}</span>
                        <span className="truncate font-medium">{scan_result.employee?.full_name ?? t(tone === 'denied' ? 'cafeteria.statusDenied' : 'cafeteria.statusAllowed')}</span>
                    </p>
                )}

                {method === 'nfc' && !showResult && !processing && (
                    <div className="absolute inset-0 overflow-y-auto bg-[color:var(--app-background)] p-4 text-[color:var(--app-foreground)]">
                        <NfcTapPanel disabled={!providerId} onCredential={(credential) => submitCredential({ nfc_credential: credential })} />
                    </div>
                )}

                {method === 'qr' && !cameraBusy && !showResult && !processing && (
                    <div className="absolute inset-0 flex flex-col items-center justify-center gap-4 px-8 text-center">
                        {camera.error ? (
                            <>
                                <span className="flex h-16 w-16 items-center justify-center rounded-full bg-red-500/15 text-red-300"><AlertTriangle className="h-8 w-8" aria-hidden="true" /></span>
                                <p role="alert" className="max-w-xs text-sm leading-6 text-white/90">{camera.error}</p>
                            </>
                        ) : (
                            <>
                                <span className="flex h-16 w-16 items-center justify-center rounded-full bg-white/10 text-white/70"><QrCodeIcon className="h-8 w-8" aria-hidden="true" /></span>
                                <p className="max-w-xs text-sm leading-6 text-white/80">{providers.length ? t('cafeteria.cameraReady') : t('cafeteria.noProvidersAvailable')}</p>
                            </>
                        )}
                    </div>
                )}

                {(camera.starting || processing) && (
                    <div role="status" className="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-black/80">
                        <span aria-hidden="true" className="h-10 w-10 animate-spin rounded-full border-4 border-white/20 border-t-white" />
                        <p className="text-sm text-white/80">{processing ? t('cafeteria.processingScan') : t('cafeteria.startingCamera')}</p>
                    </div>
                )}

                {showResult && scan_result && <ResultPanel result={scan_result} tone={tone} countdown={countdown} />}
            </main>

            {(showResult || method === 'qr' || errorMessage) && <footer className="space-y-2 border-t border-[color:var(--app-border)] bg-[color:var(--app-surface)] px-3 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
                {errorMessage && <p role="alert" className="text-sm text-red-700 dark:text-red-300">{errorMessage}</p>}

                {method === 'qr' && manualOpen && (
                    <form onSubmit={submitManual} className="flex gap-2">
                        <Input value={manualToken} onChange={(event) => setManualToken(event.target.value)} aria-label={t('cafeteria.enterQrToken')}
                            placeholder={t('cafeteria.enterQrToken')} autoComplete="off" autoCapitalize="off" spellCheck={false} enterKeyHint="go" autoFocus
                            className="!h-12 min-w-0 flex-1 !text-base" />
                        <Button type="submit" variant="primary" disabled={!manualToken.trim() || !providerId || processing} className="!h-12 px-4">
                            {t('cafeteria.processScan')}
                        </Button>
                    </form>
                )}

                <div className="flex gap-2">
                    {showResult ? (
                        <Button variant="primary" onClick={() => (method === 'qr' ? startScanning() : setDismissedResult(scan_result ?? null))}
                            icon={<CameraIcon className="h-5 w-5" />} className="!h-12 flex-1 !text-base">
                            {t('cafeteria.scanNext')}
                        </Button>
                    ) : method === 'nfc' ? null : camera.active ? (
                        <Button variant="outline" onClick={() => void stopCamera()} className="!h-12 flex-1 !text-base">{t('cafeteria.stopCamera')}</Button>
                    ) : (
                        <Button variant="primary" onClick={startScanning} disabled={camera.starting || processing || !providers.length}
                            icon={<CameraIcon className="h-5 w-5" />} className="!h-12 flex-1 !text-base">
                            {camera.error ? t('cafeteria.tryAgain') : t('cafeteria.startCamera')}
                        </Button>
                    )}
                    {method === 'qr' && (
                        <Button variant={manualOpen ? 'secondary' : 'outline'} onClick={() => setManualOpen((open) => !open)} aria-expanded={manualOpen}
                            icon={<KeyboardIcon className="h-5 w-5" />} className="!h-12 px-4">
                            {t('cafeteria.enterCode')}
                        </Button>
                    )}
                </div>
            </footer>}
        </div>
    );
}

function ResultPanel({ result, tone, countdown }: { result: ScanResult; tone: OutcomeTone; countdown: number | null }) {
    const { t } = useLocale();
    const Icon = tone === 'denied' ? XCircle : tone === 'extra' ? AlertTriangle : CheckCircle;
    const headline = tone === 'denied' ? t('cafeteria.statusDenied') : tone === 'extra' ? t('cafeteria.statusExtraScan') : t('cafeteria.statusAllowed');
    const detail = tone === 'denied' ? denialText(result, t) : tone === 'extra' ? t('cafeteria.extraScanRecorded') : t('cafeteria.scanRecorded');
    const employee = result.employee;

    return (
        <div role="status" aria-live="assertive" className={cx('absolute inset-0 flex flex-col items-center justify-center gap-5 overflow-y-auto px-6 py-8 text-center text-white', TONE_SURFACE[tone])}>
            <span className="flex h-20 w-20 shrink-0 items-center justify-center rounded-full bg-white/15"><Icon className="h-11 w-11" aria-hidden="true" /></span>
            <div>
                <p className="text-3xl font-bold tracking-tight">{headline}</p>
                <p className="mx-auto mt-2 max-w-sm text-base leading-6 text-white/90">{detail}</p>
            </div>
            {employee && (
                <div className="flex w-full max-w-sm items-center gap-3 rounded-2xl bg-black/20 p-3 text-left">
                    <UserAvatar src={employee.photo_url} name={employee.full_name} size={56} className="shrink-0 !bg-white/20 !text-white" />
                    <div className="min-w-0 flex-1">
                        <p className="break-words font-semibold leading-snug">{employee.full_name}</p>
                        <p className="mt-0.5 break-words text-sm text-white/75">{[employee.employee_number, employee.position].filter(Boolean).join(' · ')}</p>
                    </div>
                    {result.allowed && result.subsidy_applied != null && (
                        <div className="shrink-0 text-right">
                            <p className="text-xl font-bold tabular-nums">{result.subsidy_applied.toFixed(2)}</p>
                            <p className="text-[11px] text-white/75">{t('cafeteria.subsidyApplied')}</p>
                        </div>
                    )}
                </div>
            )}
            {countdown !== null && <p className="text-sm text-white/80">{t('cafeteria.scanAgainIn').replace('{{count}}', String(countdown))}</p>}
        </div>
    );
}
