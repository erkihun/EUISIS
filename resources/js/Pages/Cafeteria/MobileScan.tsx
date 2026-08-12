import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEvent, useCallback, useEffect, useId, useRef, useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { CameraDevice, Html5Qrcode, Html5QrcodeCameraScanConfig, Html5QrcodeSupportedFormats } from 'html5-qrcode';
import { CheckCircle, UserIcon } from '@/Components/Icons';

// ── Types (shared with Scan.tsx) ──────────────────────────────────────────────

type Provider = {
    id: string; name_en: string; name_am: string; code: string;
    is_active: boolean;
};

type ScanResult = {
    allowed: boolean; is_extra_scan: boolean; denial_reason: string | null;
    denial_message?: string | null; card_status?: string | null;
    employee: { full_name: string; employee_number: string; photo_url: string | null; position: string | null; organization: string | null } | null;
    card_number: string | null; subsidy_applied: number | null; employee_payable: number | null;
    remaining_after: number | null; usage_mode: string | null; duplicate?: boolean;
};

// ── Config ────────────────────────────────────────────────────────────────────

const cameraScanConfig: Html5QrcodeCameraScanConfig = {
    fps: 15,
    qrbox: (w: number, h: number) => { const e = Math.floor(Math.min(w, h) * 0.78); return { width: e, height: e }; },
    aspectRatio: 1,
    disableFlip: false,
};

function newScanNonce(): string {
    return window.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

const DENIAL_REASON_KEY: Record<string, string> = {
    already_scanned_today:   'denialAlreadyScannedToday',
    wrong_institution:        'denialWrongInstitution',
    employee_on_leave:        'denialEmployeeOnLeave',
    card_inactive:            'denialCardInactive',
    card_expired:             'denialCardExpired',
    not_eligible:             'denialNotEligible',
    cafeteria_closed_weekend: 'denialCafeteriaClosedWeekend',
    cafeteria_closed:         'denialCafeteriaClosed',
    cafeteria_closed_holiday: 'denialCafeteriaClosedHoliday',
    no_subsidy_rule:          'denialNoSubsidyRule',
    no_available_subsidy:     'denialNoAvailableSubsidy',
    invalid_token_format:     'denialInvalidToken',
};

// ── Component ─────────────────────────────────────────────────────────────────

export default function MobileScan({
    providers,
    provider_locked,
    today_scan_count,
    scan_result,
}: {
    providers: Provider[];
    provider_locked?: boolean;
    today_scan_count?: number;
    scan_result?: ScanResult | null;
}) {
    const { t, locale } = useLocale();
    const scannerRegionId = useId().replace(/:/g, '');
    const scannerRef = useRef<Html5Qrcode | null>(null);
    const scannerTransitionRef = useRef(false);
    const scanHandledRef = useRef(false);
    const submittedTokenRef = useRef<string | null>(null);

    const [cameraActive, setCameraActive] = useState(false);
    const [cameraError, setCameraError] = useState<string | null>(null);
    const [cameraStarting, setCameraStarting] = useState(false);
    const [cameraProcessing, setCameraProcessing] = useState(false);
    const [showManual, setShowManual] = useState(false);
    const [countdown, setCountdown] = useState<number | null>(null);
    const [scanCount, setScanCount] = useState(today_scan_count ?? 0);

    const form = useForm({
        provider_id: providers[0]?.id ?? '',
        qr_token: '',
        scan_nonce: newScanNonce(),
        scanned_at: '',
        usage_mode: 'single_day',
        source: 'mobile',
    });

    const providerName = (p: Provider) => locale === 'am' && p.name_am ? p.name_am : p.name_en;

    // ── Camera helpers ────────────────────────────────────────────────────────

    const getCameraErrorMessage = useCallback((error: unknown): string => {
        if (!window.isSecureContext) return t('cafeteria.cameraRequiresSecureContext');
        if (!navigator.mediaDevices?.getUserMedia) return t('cafeteria.cameraNotSupported');
        if (error instanceof DOMException) {
            if (error.name === 'NotAllowedError' || error.name === 'PermissionDeniedError') return t('cafeteria.cameraPermissionDenied');
            if (error.name === 'NotFoundError' || error.name === 'OverconstrainedError') return t('cafeteria.noCameraFound');
            if (error.name === 'NotReadableError' || error.name === 'TrackStartError') return t('cafeteria.cameraInUse');
            return error.message || t('cafeteria.cameraUnavailable');
        }
        if (error instanceof Error) return error.message || t('cafeteria.cameraUnavailable');
        return t('cafeteria.cameraUnavailable');
    }, [t]);

    const selectPreferredCamera = useCallback((cameras: CameraDevice[]) =>
        cameras.find((c) => /back|rear|environment/i.test(c.label)) ?? cameras[0] ?? null, []);

    const stopCamera = useCallback(async (updateState = true) => {
        const scanner = scannerRef.current;
        scannerRef.current = null;
        scannerTransitionRef.current = false;
        if (!scanner) { if (updateState) setCameraActive(false); return; }
        if (updateState) setCameraActive(false);
        try { if (scanner.isScanning) await scanner.stop(); scanner.clear(); } catch { }
    }, []);

    const submitScannedToken = useCallback((qrToken: string) => {
        const token = qrToken.trim();
        if (token === '' || submittedTokenRef.current === token) return;
        submittedTokenRef.current = token;
        setCameraProcessing(true);
        router.post(
            route('cafeteria.scan.process'),
            { provider_id: form.data.provider_id, qr_token: token, scan_nonce: form.data.scan_nonce, scanned_at: form.data.scanned_at, usage_mode: form.data.usage_mode, source: 'mobile' },
            {
                preserveScroll: true,
                onFinish: () => {
                    setCameraProcessing(false);
                    submittedTokenRef.current = null;
                    form.setData('scan_nonce', newScanNonce());
                },
            },
        );
    }, [form]);

    const startCamera = useCallback(async () => {
        if (scannerTransitionRef.current || cameraActive || cameraProcessing) return;
        scannerTransitionRef.current = true;
        setCameraStarting(true);
        setCameraError(null);
        scanHandledRef.current = false;

        if (!form.data.provider_id) { setCameraError(t('cafeteria.selectProviderFirst')); scannerTransitionRef.current = false; setCameraStarting(false); return; }
        if (!window.isSecureContext) { setCameraError(t('cafeteria.cameraRequiresSecureContext')); scannerTransitionRef.current = false; setCameraStarting(false); return; }
        if (!navigator.mediaDevices?.getUserMedia) { setCameraError(t('cafeteria.cameraNotSupported')); scannerTransitionRef.current = false; setCameraStarting(false); return; }

        const existing = scannerRef.current;
        if (existing) {
            try { if (existing.isScanning) await existing.stop(); existing.clear(); } catch { }
            finally { scannerRef.current = null; setCameraActive(false); }
        }

        try { const s = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false }); s.getTracks().forEach((t) => t.stop()); }
        catch (error) { setCameraError(getCameraErrorMessage(error)); scannerTransitionRef.current = false; setCameraStarting(false); return; }

        const scanner = new Html5Qrcode(scannerRegionId, { verbose: false, formatsToSupport: [Html5QrcodeSupportedFormats.QR_CODE] });
        scannerRef.current = scanner;

        try {
            const onScanSuccess = (decodedText: string) => { if (scanHandledRef.current) return; scanHandledRef.current = true; submitScannedToken(decodedText); };
            let started = false;
            try { await scanner.start({ facingMode: { ideal: 'environment' } }, cameraScanConfig, onScanSuccess, () => undefined); started = true; } catch { }
            if (!started) {
                try { scanner.clear(); } catch { }
                scannerRef.current = null;
                const cameras = await Html5Qrcode.getCameras();
                const preferred = selectPreferredCamera(cameras);
                if (!preferred) throw new Error(t('cafeteria.noCameraFound'));
                const fallback = new Html5Qrcode(scannerRegionId, { verbose: false, formatsToSupport: [Html5QrcodeSupportedFormats.QR_CODE] });
                scannerRef.current = fallback;
                await fallback.start(preferred.id, cameraScanConfig, onScanSuccess, () => undefined);
            }
            setCameraActive(true);
        } catch (error) {
            const active = scannerRef.current ?? scanner;
            try { active.clear(); } catch { }
            scannerRef.current = null;
            setCameraActive(false);
            setCameraError(getCameraErrorMessage(error));
        } finally {
            scannerTransitionRef.current = false;
            setCameraStarting(false);
        }
    }, [cameraActive, cameraProcessing, form.data.provider_id, getCameraErrorMessage, scannerRegionId, selectPreferredCamera, submitScannedToken, t]);

    // Cleanup on unmount
    useEffect(() => { return () => { void stopCamera(false); }; }, [stopCamera]);

    // Auto-start camera when provider set & no scan result shown
    useEffect(() => {
        if (form.data.provider_id && !scan_result) {
            void startCamera();
        }
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [form.data.provider_id]);

    // Auto-restart 3s after scan result
    useEffect(() => {
        if (!scan_result) return;
        if (scan_result.allowed) setScanCount((c) => c + 1);
        let count = 3;
        setCountdown(count);
        const interval = window.setInterval(() => {
            count -= 1;
            setCountdown(count > 0 ? count : null);
            if (count <= 0) { clearInterval(interval); void startCamera(); }
        }, 1000);
        scanHandledRef.current = false;
        return () => clearInterval(interval);
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    function submitManual(e: FormEvent) {
        e.preventDefault();
        form.post(route('cafeteria.scan.process'), {
            preserveScroll: true,
            onFinish: () => form.setData('scan_nonce', newScanNonce()),
        });
    }

    const denialMessage = scan_result && !scan_result.allowed
        ? scan_result.denial_message ?? (() => {
            const key = DENIAL_REASON_KEY[scan_result.denial_reason ?? ''];
            return key ? t(`cafeteria.${key}`) : scan_result.denial_reason ?? '';
        })()
        : null;

    // ── Render ────────────────────────────────────────────────────────────────

    return (
        <div className="flex h-dvh flex-col bg-gray-950 text-white">
            <Head title={t('cafeteria.scanQr')} />

            {/* ── Header ── */}
            <div className="flex items-center gap-3 border-b border-white/10 bg-gray-900 px-4 py-3">
                {provider_locked ? (
                    <span className="flex-1 truncate text-sm font-semibold text-white">
                        {providerName(providers[0])}
                    </span>
                ) : (
                    <select
                        value={form.data.provider_id}
                        onChange={(e) => { form.setData('provider_id', e.target.value); void stopCamera(); }}
                        className="flex-1 rounded-lg border border-white/20 bg-gray-800 px-3 py-2 text-sm text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                        {providers.map((p) => (
                            <option key={p.id} value={p.id}>{providerName(p)}</option>
                        ))}
                    </select>
                )}

                {/* Today scan count badge */}
                <div className="flex items-center gap-1.5 rounded-full bg-blue-600/20 px-3 py-1.5 text-xs font-semibold text-blue-300 ring-1 ring-blue-500/30">
                    <span>{scanCount}</span>
                    <span className="text-blue-400/70">{t('cafeteria.todayScans')}</span>
                </div>

                {/* Desktop link */}
                <Link
                    href={route('cafeteria.scan')}
                    className="rounded-lg border border-white/20 px-2 py-1.5 text-[11px] text-gray-400 hover:text-white"
                >
                    🖥
                </Link>
            </div>

            {/* ── Camera viewfinder ── */}
            <div className="relative flex-1 overflow-hidden bg-gray-950">
                <div
                    id={scannerRegionId}
                    className="h-full w-full [&_video]:h-full [&_video]:w-full [&_video]:object-cover"
                />

                {/* Scan frame overlay */}
                {cameraActive && !cameraProcessing && (
                    <div className="pointer-events-none absolute inset-0 flex items-center justify-center">
                        <div className="relative h-56 w-56">
                            <span className="absolute left-0 top-0 h-8 w-8 rounded-tl-lg border-l-4 border-t-4 border-white/80" />
                            <span className="absolute right-0 top-0 h-8 w-8 rounded-tr-lg border-r-4 border-t-4 border-white/80" />
                            <span className="absolute bottom-0 left-0 h-8 w-8 rounded-bl-lg border-b-4 border-l-4 border-white/80" />
                            <span className="absolute bottom-0 right-0 h-8 w-8 rounded-br-lg border-b-4 border-r-4 border-white/80" />
                            <span className="absolute left-1 right-1 top-1/2 h-0.5 -translate-y-1/2 animate-pulse bg-red-400/80" />
                        </div>
                    </div>
                )}

                {/* Processing overlay */}
                {cameraProcessing && (
                    <div className="absolute inset-0 flex items-center justify-center bg-gray-950/80">
                        <div className="flex flex-col items-center gap-3">
                            <div className="h-10 w-10 animate-spin rounded-full border-4 border-white/20 border-t-white" />
                            <p className="text-sm text-gray-300">{t('cafeteria.processingScan')}</p>
                        </div>
                    </div>
                )}

                {/* Idle / error state */}
                {!cameraActive && !cameraProcessing && !cameraStarting && (
                    <div className="absolute inset-0 flex flex-col items-center justify-center gap-4 bg-gray-950 px-6 text-center">
                        {cameraError ? (
                            <>
                                <p className="text-sm text-red-400">{cameraError}</p>
                                <button
                                    type="button"
                                    onClick={() => void startCamera()}
                                    className="rounded-xl bg-blue-600 px-8 py-3 text-base font-semibold text-white active:bg-blue-700"
                                >
                                    {t('cafeteria.startCamera')}
                                </button>
                            </>
                        ) : (
                            <button
                                type="button"
                                onClick={() => void startCamera()}
                                className="flex flex-col items-center gap-3 rounded-2xl bg-blue-600 px-10 py-5 text-white active:bg-blue-700"
                            >
                                <svg className="h-12 w-12 opacity-90" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                                <span className="text-lg font-bold">{t('cafeteria.startCamera')}</span>
                            </button>
                        )}
                    </div>
                )}

                {cameraStarting && (
                    <div className="absolute inset-0 flex items-center justify-center bg-gray-950">
                        <div className="h-10 w-10 animate-spin rounded-full border-4 border-white/20 border-t-white" />
                    </div>
                )}

                {/* Scan result overlay */}
                {scan_result && (
                    <div className={`absolute inset-x-0 bottom-0 rounded-t-2xl px-5 pb-6 pt-5 shadow-2xl ${
                        scan_result.allowed
                            ? scan_result.is_extra_scan
                                ? 'bg-orange-900/95'
                                : 'bg-emerald-900/95'
                            : 'bg-red-900/95'
                    }`}>
                        {/* Status line */}
                        <div className="flex items-center gap-2">
                            {scan_result.allowed ? (
                                <CheckCircle className={`h-6 w-6 shrink-0 ${scan_result.is_extra_scan ? 'text-orange-300' : 'text-emerald-300'}`} />
                            ) : (
                                <svg className="h-6 w-6 shrink-0 text-red-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            )}
                            <p className={`text-base font-bold ${
                                scan_result.allowed
                                    ? scan_result.is_extra_scan ? 'text-orange-200' : 'text-emerald-200'
                                    : 'text-red-200'
                            }`}>
                                {scan_result.allowed
                                    ? (scan_result.is_extra_scan ? t('cafeteria.extraScanRecorded') : t('cafeteria.scanRecorded'))
                                    : `${t('cafeteria.scanDenied')} — ${denialMessage}`}
                            </p>
                        </div>

                        {/* Employee info */}
                        {scan_result.allowed && scan_result.employee && (
                            <div className="mt-3 flex items-center gap-3">
                                {scan_result.employee.photo_url ? (
                                    <img src={scan_result.employee.photo_url} alt="" className="h-12 w-12 shrink-0 rounded-full object-cover ring-2 ring-white/20" />
                                ) : (
                                    <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-white/10 ring-2 ring-white/20">
                                        <UserIcon className="h-7 w-7 text-white/50" />
                                    </div>
                                )}
                                <div className="min-w-0">
                                    <p className="truncate text-sm font-semibold text-white">{scan_result.employee.full_name}</p>
                                    <p className="text-xs text-white/60">{scan_result.employee.employee_number}</p>
                                    {scan_result.employee.position && <p className="truncate text-xs text-white/50">{scan_result.employee.position}</p>}
                                </div>
                                <div className="ml-auto text-right">
                                    <p className="text-lg font-bold text-emerald-300">
                                        {(scan_result.subsidy_applied ?? 0).toFixed(2)}
                                    </p>
                                    <p className="text-[10px] text-white/50">{t('cafeteria.subsidyApplied')}</p>
                                </div>
                            </div>
                        )}

                        {/* Auto-restart countdown */}
                        {countdown !== null && (
                            <p className="mt-2 text-center text-xs text-white/50">
                                {t('cafeteria.scanAgainIn').replace('{{count}}', String(countdown))}
                            </p>
                        )}
                    </div>
                )}
            </div>

            {/* ── Bottom bar ── */}
            <div className="border-t border-white/10 bg-gray-900 px-4 py-3 safe-area-bottom">
                {cameraActive && (
                    <button
                        type="button"
                        onClick={() => void stopCamera()}
                        className="mb-2 w-full rounded-xl border border-white/20 py-2.5 text-sm font-medium text-gray-300 active:bg-white/10"
                    >
                        {t('cafeteria.stopCamera')}
                    </button>
                )}

                <button
                    type="button"
                    onClick={() => setShowManual((v) => !v)}
                    className="w-full text-center text-xs text-gray-500 active:text-gray-300"
                >
                    {showManual ? '▲' : '▼'} {t('cafeteria.enterQrToken')}
                </button>

                {showManual && (
                    <form onSubmit={submitManual} className="mt-2 flex gap-2">
                        <input
                            type="text"
                            value={form.data.qr_token}
                            onChange={(e) => form.setData('qr_token', e.target.value)}
                            placeholder={t('cafeteria.enterQrToken')}
                            className="flex-1 rounded-lg border border-white/20 bg-gray-800 px-3 py-2 text-sm text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                        />
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white active:bg-blue-700 disabled:opacity-60"
                        >
                            {t('cafeteria.processScan')}
                        </button>
                    </form>
                )}
            </div>
        </div>
    );
}
