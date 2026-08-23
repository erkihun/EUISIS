import { FormEvent, useCallback, useEffect, useId, useMemo, useRef, useState } from 'react';
import { Html5Qrcode, Html5QrcodeSupportedFormats, type CameraDevice } from 'html5-qrcode';
import AppLayout from '@/Layouts/AppLayout';
import { useLocale } from '@/hooks/useLocale';
import {
    ethiopianMonthLength,
    ethiopianToGregorian,
    ethiopianToJdn,
    gregorianToEthiopian,
} from '@/lib/calendar/ethiopianCalendar';

type Snapshot = {
    employee_number: string | null;
    employee_name: string | null;
    organization_name: string | null;
    position_name: string | null;
    card_status: string | null;
    /** Served by EUISIS for identity confirmation; never stored locally. */
    photo_url?: string | null;
};

type ScanResult = {
    served: boolean;
    result_code: string;
    employee: Snapshot;
    transaction_number: string | null;
};

type TodayScan = {
    transaction_number: string;
    employee_number: string;
    employee_name: string | null;
    served_at: string | null;
    status?: string;
};

type Provider = { id: string; code: string; name: string };

type OrganizationRow = { code: string; name: string | null };

/** Per-day status supplied by CafeteriaCalendarService. */
type CalendarDay = {
    date: string;
    day_name: string;
    is_today: boolean;
    is_working_day: boolean;
    is_open: boolean;
    is_subsidy_day: boolean;
    is_public_holiday: boolean;
    is_special_day: boolean;
    is_employee_excluded: boolean;
    is_consumed: boolean;
    is_available: boolean;
    reason_code: string;
    label: string;
};

function resultLabel(code: string, t: (key: string) => string): string {
    const translated = t(`result.${code}`);

    // t() returns the key path when it is unknown; fall back to the raw code
    // made readable rather than showing `result.some_new_code` to an operator.
    return translated === `result.${code}` ? code.replace(/_/g, ' ') : translated;
}

/** The QR encodes the EUISIS verification URL; accept the URL or a bare UUID. */
function extractToken(value: string): string {
    const match = value.match(/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i);

    return match ? match[1] : value.trim();
}

const inputCls =
    'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500';

// ─── Calendar helpers ───────────────────────────────────────────────────────
// Ported from the EUISIS scan terminal so both systems show the same calendar.

type CalCell = { day: number; gregorianIso: string };

const ETH_MONTHS_AM = [
    'መስከረም', 'ጥቅምት', 'ህዳር', 'ታህሳስ', 'ጥር', 'የካቲት', 'መጋቢት',
    'ሚያዚያ', 'ግንቦት', 'ሰኔ', 'ሐምሌ', 'ነሀሴ', 'ጳጉሜ',
];

const DAY_LABELS = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];

function isoDate(year: number, month: number, day: number): string {
    return `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

function ethiopianToGregorianIso(year: number, month: number, day: number): string {
    const gregorian = ethiopianToGregorian(year, month, day);

    return isoDate(gregorian.year, gregorian.month - 1, gregorian.day);
}

function monthLabel(year: number, month: number, isEthiopian: boolean): string {
    if (isEthiopian) {
        return `${ETH_MONTHS_AM[month - 1] ?? ''} ${year} ዓ.ም`;
    }

    return new Intl.DateTimeFormat('en', { month: 'long', year: 'numeric' })
        .format(new Date(year, month, 1));
}

/** Leading blanks for the first weekday, then one cell per day of the month. */
function buildCalendarCells(year: number, month: number, isEthiopian: boolean): (CalCell | null)[] {
    if (isEthiopian) {
        const firstDow = (ethiopianToJdn(year, month, 1) + 1) % 7;
        const total = ethiopianMonthLength(year, month);
        const cells: (CalCell | null)[] = Array(firstDow).fill(null);

        for (let day = 1; day <= total; day++) {
            cells.push({ day, gregorianIso: ethiopianToGregorianIso(year, month, day) });
        }

        return cells;
    }

    const firstDow = new Date(year, month, 1).getDay();
    const total = new Date(year, month + 1, 0).getDate();
    const cells: (CalCell | null)[] = Array(firstDow).fill(null);

    for (let day = 1; day <= total; day++) {
        cells.push({ day, gregorianIso: isoDate(year, month, day) });
    }

    return cells;
}

export default function Scan({
    today_scans = [],
    provider = null,
    organizations = [],
    calendar_days = [],
    usage_modes = ['single_day', 'use_remaining_week'],
    default_usage_mode = 'single_day',
}: {
    today_scans?: TodayScan[];
    provider?: Provider | null;
    organizations?: OrganizationRow[];
    calendar_days?: CalendarDay[];
    usage_modes?: string[];
    default_usage_mode?: string;
}) {
    const { t, isAmharic } = useLocale();
    const scannerRegionId = useId().replace(/:/g, '');
    const scannerRef = useRef<Html5Qrcode | null>(null);
    const busyRef = useRef(false);
    const lastTokenRef = useRef<string | null>(null);

    const [cameraActive, setCameraActive] = useState(false);
    const [cameraStarting, setCameraStarting] = useState(false);
    const [cameraProcessing, setCameraProcessing] = useState(false);
    const [cameraError, setCameraError] = useState<string | null>(null);
    const [qrToken, setQrToken] = useState('');
    const [processing, setProcessing] = useState(false);
    const [countdown, setCountdown] = useState<number | null>(null);
    const [result, setResult] = useState<ScanResult | null>(null);
    const [todayScans, setTodayScans] = useState<TodayScan[]>(today_scans);

    // ── Calendar ────────────────────────────────────────────────────────
    const now = new Date();
    const todayIso = isoDate(now.getFullYear(), now.getMonth(), now.getDate());
    const todayEth = gregorianToEthiopian(now.getFullYear(), now.getMonth() + 1, now.getDate());

    // Ethiopian calendar by default in Amharic; Gregorian in English.
    const [isEthiopian, setIsEthiopian] = useState(isAmharic);
    const [calYear, setCalYear] = useState(todayEth.year);
    const [calMonth, setCalMonth] = useState(todayEth.month);

    const cells = useMemo(
        () => buildCalendarCells(calYear, calMonth, isEthiopian),
        [calYear, calMonth, isEthiopian],
    );

    const [usageMode, setUsageMode] = useState(default_usage_mode);
    // Reset per scan, so one broken image does not hide the next employee's photo.
    const [photoFailed, setPhotoFailed] = useState(false);

    /** Day metadata keyed by ISO date, so a cell lookup is O(1). */
    const dayMetaByDate = useMemo(() => {
        const map = new Map<string, CalendarDay>();

        calendar_days.forEach((day) => map.set(day.date, day));

        return map;
    }, [calendar_days]);

    /** Dates already served, so the calendar can mark consumed days. */
    const servedDates = useMemo(() => {
        const set = new Set<string>();

        // Scans recorded this session are not yet in calendar_days, which is
        // built server-side on page load.
        todayScans.forEach(() => set.add(todayIso));

        return set;
    }, [todayScans, todayIso]);

    function switchCalendar(toEthiopian: boolean) {
        setIsEthiopian(toEthiopian);

        if (toEthiopian) {
            setCalYear(todayEth.year);
            setCalMonth(todayEth.month);
        } else {
            setCalYear(now.getFullYear());
            setCalMonth(now.getMonth());
        }
    }

    function shiftMonth(delta: number) {
        const maxMonth = isEthiopian ? 13 : 12;
        const minMonth = isEthiopian ? 1 : 0;
        let month = calMonth + delta;
        let year = calYear;

        if (month > (isEthiopian ? maxMonth : maxMonth - 1)) {
            month = minMonth;
            year += 1;
        } else if (month < minMonth) {
            month = isEthiopian ? maxMonth : maxMonth - 1;
            year -= 1;
        }

        setCalMonth(month);
        setCalYear(year);
    }

    /** Submit a token; the backend performs the EUISIS verification. */
    const verify = useCallback(async (rawToken: string) => {
        const token = extractToken(rawToken);

        // busyRef gates the whole cooldown window; lastTokenRef additionally
        // stops the camera re-submitting the SAME card while it stays in frame,
        // which otherwise fires a request every frame.
        if (!token || busyRef.current || lastTokenRef.current === token) {
            return;
        }

        lastTokenRef.current = token;
        busyRef.current = true;
        setProcessing(true);
        setCameraProcessing(true);
        setResult(null);
        setPhotoFailed(false);

        try {
            const response = await fetch('/scan/verify', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
                },
                body: JSON.stringify({ card_token: token, usage_mode: usageMode }),
            });

            const payload: ScanResult = await response.json();
            setResult(payload);

            if (payload.served && payload.transaction_number) {
                setTodayScans((current) => [
                    {
                        transaction_number: payload.transaction_number as string,
                        employee_number: payload.employee.employee_number ?? '',
                        employee_name: payload.employee.employee_name,
                        served_at: new Date().toLocaleTimeString(),
                        status: 'accepted',
                    },
                    ...current,
                ]);
            }

            // Cooldown mirrors the EUISIS terminal so one card in frame is not
            // read repeatedly while the operator hands it back.
            setCountdown(3);
        } catch {
            setResult({
                served: false,
                result_code: 'verification_unavailable',
                employee: {
                    employee_number: null, employee_name: null,
                    organization_name: null, position_name: null, card_status: null,
                },
                transaction_number: null,
            });
        } finally {
            setProcessing(false);
            setCameraProcessing(false);
            setQrToken('');
        }
        // usageMode is read inside, so the callback must be rebuilt when the
        // operator changes it — otherwise every scan sends the initial mode.
    }, [usageMode]);

    // Tick the re-scan cooldown down to zero, then re-arm the scanner.
    useEffect(() => {
        if (countdown === null) {
            return;
        }

        if (countdown <= 0) {
            setCountdown(null);
            busyRef.current = false;
            // Allow the same card again once the cooldown has elapsed.
            lastTokenRef.current = null;

            return;
        }

        const timer = window.setTimeout(() => setCountdown(countdown - 1), 1000);

        return () => window.clearTimeout(timer);
    }, [countdown]);

    /**
     * Turn a camera failure into something the operator can act on. A bare
     * "unable to start" hides the difference between a denied permission, a
     * camera already in use, and an insecure origin — which need different
     * fixes.
     */
    function cameraErrorMessage(error: unknown): string {
        if (!window.isSecureContext) {
            return 'Camera needs a secure origin. Use http://localhost:8001 (not an IP address) or serve over HTTPS.';
        }

        if (!navigator.mediaDevices?.getUserMedia) {
            return 'This browser does not support camera access.';
        }

        if (error instanceof DOMException) {
            if (error.name === 'NotAllowedError' || error.name === 'PermissionDeniedError') {
                return 'Camera permission was denied. Allow camera access for this site, then try again.';
            }

            if (error.name === 'NotFoundError' || error.name === 'OverconstrainedError') {
                return 'No camera found on this device.';
            }

            if (error.name === 'NotReadableError' || error.name === 'TrackStartError') {
                return 'The camera is already in use by another application.';
            }

            return error.message || 'Camera unavailable.';
        }

        if (error instanceof Error) {
            return error.message || 'Camera unavailable.';
        }

        return typeof error === 'string' && error.trim() !== '' ? error : 'Camera unavailable.';
    }

    async function startCamera() {
        if (cameraActive || cameraStarting) {
            return;
        }

        setCameraStarting(true);
        setCameraError(null);

        if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
            setCameraError(cameraErrorMessage(null));
            setCameraStarting(false);

            return;
        }

        try {
            // Request permission BEFORE enumerating: getCameras() returns an
            // empty list (or throws) on most browsers until access is granted.
            const stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: { ideal: 'environment' } },
                audio: false,
            });
            stream.getTracks().forEach((track) => track.stop());

            const devices: CameraDevice[] = await Html5Qrcode.getCameras();

            if (devices.length === 0) {
                setCameraError('No camera found on this device.');

                return;
            }

            const rear = devices.find((device) => /back|rear|environment/i.test(device.label))
                ?? devices[0];

            const scanner = new Html5Qrcode(scannerRegionId, {
                formatsToSupport: [Html5QrcodeSupportedFormats.QR_CODE],
                verbose: false,
            });

            scannerRef.current = scanner;

            await scanner.start(
                rear.id,
                { fps: 10, qrbox: { width: 250, height: 250 } },
                (decoded) => void verify(decoded),
                () => undefined,
            );

            setCameraActive(true);
        } catch (error) {
            setCameraError(cameraErrorMessage(error));
            scannerRef.current = null;
        } finally {
            setCameraStarting(false);
        }
    }

    async function stopCamera() {
        const scanner = scannerRef.current;

        if (!scanner) {
            return;
        }

        try {
            await scanner.stop();
            await scanner.clear();
        } catch {
            // Already stopped — nothing to clean up.
        } finally {
            scannerRef.current = null;
            setCameraActive(false);
        }
    }

    // Release the camera when leaving the page.
    useEffect(() => () => void stopCamera(), []);

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        void verify(qrToken);
    }

    return (
        <AppLayout title={t('scan.title')}>
            <div className="mx-auto max-w-7xl">
                <form onSubmit={submit} className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                    {/* Header */}
                    <div className="border-b border-gray-200 px-6 py-4">
                        <h2 className="text-base font-semibold text-slate-900">{t('extra.scanEmployeeCard')}</h2>
                    </div>

                    {/* Full-width result banner */}
                    {result && (
                        <div className={`border-b px-6 py-4 ${
                            result.served
                                ? 'border-emerald-200 bg-emerald-50'
                                : 'border-red-200 bg-red-50'
                        }`}>
                            <p className={`font-semibold ${result.served ? 'text-emerald-700' : 'text-red-700'}`}>
                                {result.served
                                    ? 'Scan recorded'
                                    : `Scan denied — ${resultLabel(result.result_code, t)}`}
                            </p>
                            {countdown !== null && (
                                <p className="mt-1 text-xs text-slate-500">
                                    Ready to scan again in {countdown}s
                                </p>
                            )}
                        </div>
                    )}

                    {/* Body: scanner | employee | today */}
                    <div className="grid gap-6 p-6 lg:grid-cols-3 lg:items-start">

                        {/* ── LEFT: QR scanner ── */}
                        <div className="space-y-4">
                            <div className="relative overflow-hidden rounded-lg border border-gray-200 bg-gray-950">
                                <div
                                    id={scannerRegionId}
                                    className="min-h-[300px] w-full [&_video]:min-h-[300px] [&_video]:w-full [&_video]:object-cover"
                                />
                                {!cameraActive && (
                                    <div className="absolute inset-0 flex items-center justify-center bg-gray-950 px-6 text-center text-sm text-slate-300">
                                        {cameraProcessing ? 'Processing scan…' : 'Camera ready'}
                                    </div>
                                )}
                            </div>

                            {cameraError && (
                                <div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2">
                                    <p className="text-sm font-medium text-amber-800">{cameraError}</p>
                                    <p className="mt-1 text-xs text-amber-700">
                                        You can still serve employees using manual token entry below.
                                    </p>
                                </div>
                            )}

                            <div className="flex flex-wrap gap-3">
                                <button
                                    type="button"
                                    onClick={() => void startCamera()}
                                    disabled={cameraStarting || cameraActive || cameraProcessing || processing}
                                    className="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-60"
                                >
                                    {cameraStarting || cameraActive ? 'Scanning…' : 'Start camera'}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => void stopCamera()}
                                    disabled={!cameraActive}
                                    className="rounded-lg border border-gray-300 px-5 py-2.5 text-sm font-semibold text-slate-700 hover:bg-gray-50 disabled:opacity-60"
                                >
                                    Stop camera
                                </button>
                            </div>

                            {/* Provider context (fixed per installation, not selectable) */}
                            {provider && (
                                <div className="rounded-lg border border-blue-100 bg-blue-50/70 p-4">
                                    <p className="text-xs font-semibold uppercase tracking-wide text-blue-700">
                                        Selected Provider
                                    </p>
                                    <h3 className="mt-1 text-base font-bold text-slate-900">{provider.name}</h3>

                                    <dl className="mt-3 space-y-1.5 text-xs">
                                        <div className="flex items-start justify-between gap-3">
                                            <dt className="text-slate-500">{t('scan.providerCode')}</dt>
                                            <dd className="text-right font-semibold text-slate-900">{provider.code}</dd>
                                        </div>
                                        <div className="flex items-start justify-between gap-3">
                                            <dt className="shrink-0 text-slate-500">{t('scan.organization')}</dt>
                                            <dd className="text-right font-semibold text-slate-900">
                                                {organizations.length === 0
                                                    ? '—'
                                                    : organizations.map((org) => org.name ?? org.code).join(', ')}
                                            </dd>
                                        </div>
                                    </dl>
                                </div>
                            )}

                            {/* Manual token fallback */}
                            <div className="space-y-1.5">
                                <label className="text-sm font-medium text-slate-700">{t('scan.qrToken')}</label>
                                <input
                                    className={inputCls}
                                    placeholder={t('scan.qrTokenPlaceholder')}
                                    value={qrToken}
                                    onChange={(event) => setQrToken(event.target.value)}
                                />
                                <p className="text-xs text-slate-500">
                                    {t('scan.manualHint')}
                                </p>
                            </div>

                            {/* Usage mode — recorded with the transaction. */}
                            <div className="space-y-1.5">
                                <label className="text-sm font-medium text-slate-700">{t('scan.usageMode')}</label>
                                <select
                                    className={inputCls}
                                    value={usageMode}
                                    onChange={(event) => setUsageMode(event.target.value)}
                                >
                                    {usage_modes.map((mode) => (
                                        <option key={mode} value={mode}>
                                            {mode === 'single_day' ? t('scan.usageModeSingleDay') : mode === 'use_remaining_week' ? t('scan.usageModeRemainingWeek') : mode}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>

                        {/* ── MIDDLE: calendar + verified employee ── */}
                        <div className="space-y-4">
                            {/* Calendar */}
                            <div className="shrink-0 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                                <div className="border-b border-gray-100 bg-gray-50/70 px-4 py-3">
                                    <div className="flex items-center justify-between">
                                        <button
                                            type="button"
                                            onClick={() => shiftMonth(-1)}
                                            aria-label="Previous month"
                                            className="flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 transition hover:bg-white hover:shadow-sm"
                                        >
                                            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                                            </svg>
                                        </button>

                                        <div className="text-center">
                                            <p className="text-sm font-semibold text-slate-900">
                                                {monthLabel(calYear, calMonth, isEthiopian)}
                                            </p>
                                            <button
                                                type="button"
                                                onClick={() => switchCalendar(!isEthiopian)}
                                                className={`text-[10px] font-medium hover:underline ${isEthiopian ? 'text-indigo-500' : 'text-slate-400'}`}
                                            >
                                                {isEthiopian ? t('scan.ethiopianCalendar') : t('scan.gregorianCalendar')}
                                            </button>
                                        </div>

                                        <button
                                            type="button"
                                            onClick={() => shiftMonth(1)}
                                            aria-label="Next month"
                                            className="flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 transition hover:bg-white hover:shadow-sm"
                                        >
                                            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>

                                <div className="px-3 pb-3 pt-2">
                                    <div className="mb-1 grid grid-cols-7">
                                        {DAY_LABELS.map((label) => (
                                            <div key={label} className="py-1.5 text-center text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                                                {label}
                                            </div>
                                        ))}
                                    </div>

                                    <div className="grid grid-cols-7">
                                        {cells.map((cell, index) => {
                                            if (cell === null) {
                                                return <div key={`empty-${index}`} />;
                                            }

                                            const isToday = cell.gregorianIso === todayIso;
                                            const dayMeta = dayMetaByDate.get(cell.gregorianIso);
                                            const isConsumed = dayMeta?.is_consumed || servedDates.has(cell.gregorianIso);

                                            // Same precedence as the EUISIS terminal, so an
                                            // operator reads one colour scheme across both.
                                            let cellCls: string;
                                            if (isConsumed) {
                                                cellCls = 'bg-emerald-500 font-bold text-white shadow-sm';
                                            } else if (dayMeta?.is_employee_excluded) {
                                                cellCls = 'bg-red-100 text-red-600';
                                            } else if (dayMeta?.is_public_holiday || dayMeta?.reason_code === 'special_no_subsidy_day') {
                                                cellCls = 'bg-amber-100 text-amber-700';
                                            } else if (dayMeta?.reason_code === 'special_open_day') {
                                                cellCls = 'bg-purple-100 text-purple-700';
                                            } else if (dayMeta?.is_available) {
                                                cellCls = 'bg-blue-50 font-medium text-blue-700';
                                            } else if (dayMeta !== undefined && !dayMeta.is_open) {
                                                cellCls = 'bg-gray-100 text-gray-400';
                                            } else {
                                                cellCls = 'text-slate-700 hover:bg-gray-50';
                                            }

                                            return (
                                                <div
                                                    key={cell.gregorianIso}
                                                    title={dayMeta?.label ?? cell.gregorianIso}
                                                    className={`relative mx-auto my-0.5 flex h-8 w-8 flex-col items-center justify-center rounded-lg text-[13px] transition-colors
                                                        ${isToday ? 'ring-2 ring-emerald-500 ring-offset-1' : ''} ${cellCls}`}
                                                >
                                                    <span className="leading-none">{cell.day}</span>
                                                    {isConsumed && (
                                                        <svg
                                                            className={`absolute -bottom-0.5 -right-0.5 h-3.5 w-3.5 ${
                                                                isToday ? 'text-emerald-300' : 'text-emerald-600'
                                                            }`}
                                                            viewBox="0 0 20 20"
                                                            fill="currentColor"
                                                            aria-hidden="true"
                                                        >
                                                            <path
                                                                fillRule="evenodd"
                                                                d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.06l2.5 2.5a.75.75 0 001.137-.089l4-5.5z"
                                                                clipRule="evenodd"
                                                            />
                                                        </svg>
                                                    )}
                                                </div>
                                            );
                                        })}
                                    </div>
                                </div>

                                {/* Legend */}
                                <div className="border-t border-gray-100 px-4 py-3">
                                    <div className="grid grid-cols-2 gap-x-3 gap-y-1.5">
                                        {[
                                            ['bg-blue-50 border border-blue-200', t('scan.legendAvailable')],
                                            ['bg-emerald-500', t('scan.legendConsumed')],
                                            ['bg-gray-100', t('scan.legendClosed')],
                                            ['bg-amber-100', t('scan.legendPublicHoliday')],
                                            ['bg-purple-100', t('scan.legendSpecialOpenDay')],
                                            ['bg-red-100', t('scan.legendEmployeeLeave')],
                                        ].map(([color, label]) => (
                                            <span key={label} className="inline-flex items-center gap-1.5 text-[11px] text-slate-500">
                                                <span className={`h-2.5 w-2.5 shrink-0 rounded-sm ${color}`} />
                                                {label}
                                            </span>
                                        ))}
                                    </div>
                                </div>
                            </div>

                            {/* Verified employee */}
                            {result && result.employee.employee_number ? (
                                <div className="rounded-xl border border-gray-200 bg-gray-50 p-5">
                                    <div className="flex flex-col items-center text-center">
                                        {/*
                                          * ID photo when EUISIS supplies one, so the operator
                                          * can confirm the presenter is the card holder.
                                          * Falls back to initials when there is no photo, or
                                          * when the image fails to load.
                                          */}
                                        {result.employee.photo_url && !photoFailed ? (
                                            <img
                                                src={result.employee.photo_url}
                                                alt={result.employee.employee_name ?? 'Employee photo'}
                                                onError={() => setPhotoFailed(true)}
                                                className={`h-24 w-24 rounded-full object-cover ring-4 ${
                                                    result.served ? 'ring-emerald-400' : 'ring-red-400'
                                                }`}
                                            />
                                        ) : (
                                            <div className={`flex h-24 w-24 items-center justify-center rounded-full text-3xl font-bold ring-4 ${
                                                result.served
                                                    ? 'bg-emerald-100 text-emerald-600 ring-emerald-400'
                                                    : 'bg-red-100 text-red-600 ring-red-400'
                                            }`}>
                                                {(result.employee.employee_name ?? '?').charAt(0).toUpperCase()}
                                            </div>
                                        )}

                                        <div className={`mt-3 inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold ${
                                            result.served
                                                ? 'bg-emerald-100 text-emerald-700'
                                                : 'bg-red-100 text-red-700'
                                        }`}>
                                            {result.served ? t('scan.scanRecorded') : t('scan.denied')}
                                        </div>

                                        <h3 className="mt-3 text-base font-bold text-slate-900">
                                            {result.employee.employee_name ?? '—'}
                                        </h3>
                                        <p className="font-mono text-sm text-slate-500">
                                            #{result.employee.employee_number}
                                        </p>
                                    </div>

                                    <dl className="mt-4 space-y-2 border-t border-gray-200 pt-4 text-sm">
                                        {[
                                            [t('scan.organization'), result.employee.organization_name],
                                            [t('scan.position'), result.employee.position_name],
                                            [t('scan.cardStatus'), result.employee.card_status],
                                        ].map(([label, value]) => (
                                            <div key={label} className="flex justify-between gap-3">
                                                <dt className="text-slate-500">{label}</dt>
                                                <dd className="truncate font-medium text-slate-800">{value ?? '—'}</dd>
                                            </div>
                                        ))}
                                        {result.transaction_number && (
                                            <div className="flex justify-between gap-3 border-t border-gray-200 pt-2">
                                                <dt className="text-slate-500">{t('extra.transaction')}</dt>
                                                <dd className="font-mono text-xs text-slate-700">{result.transaction_number}</dd>
                                            </div>
                                        )}
                                    </dl>
                                </div>
                            ) : (
                                <div className="flex h-full min-h-[200px] flex-col items-center justify-center rounded-xl border border-dashed border-gray-300 bg-gray-50 p-8 text-center">
                                    <div className="mb-3 flex h-14 w-14 items-center justify-center rounded-full bg-gray-100 text-2xl text-slate-400">
                                        ⌁
                                    </div>
                                    <p className="text-sm font-medium text-slate-600">{t('extra.noEmployeeScanned')}</p>
                                    <p className="mt-1 text-xs text-slate-400">
                                        Scan a card or enter a token to verify.
                                    </p>
                                </div>
                            )}
                        </div>

                        {/* ── RIGHT: today's scans ── */}
                        <div className="flex flex-1 flex-col rounded-xl border border-gray-200 bg-gray-50 p-4">
                            <h3 className="mb-3 text-sm font-semibold text-slate-900">
                                Served today <span className="text-slate-400">({todayScans.length})</span>
                            </h3>

                            {todayScans.length === 0 ? (
                                <p className="py-8 text-center text-xs text-slate-400">{t('extra.noScansToday')}</p>
                            ) : (
                                <ul className="max-h-[420px] space-y-2 overflow-y-auto">
                                    {todayScans.map((scan) => (
                                        <li key={scan.transaction_number} className="rounded-lg border border-gray-200 bg-white p-3 text-sm">
                                            <div className="flex items-start justify-between gap-2">
                                                <div className="min-w-0">
                                                    <p className="truncate font-medium text-slate-900">
                                                        {scan.employee_name ?? '—'}
                                                    </p>
                                                    <div className="mt-1 flex flex-wrap gap-x-3 text-[11px] text-slate-500">
                                                        <span className="font-mono">{scan.employee_number}</span>
                                                        <span className="font-mono">{scan.served_at ?? ''}</span>
                                                    </div>
                                                </div>
                                                <span className="shrink-0 rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">
                                                    Accepted
                                                </span>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </div>

                    {/* Footer: submit */}
                    <div className="flex justify-end border-t border-gray-200 px-6 py-4">
                        <button
                            type="submit"
                            disabled={processing || !qrToken.trim()}
                            className="rounded-lg bg-emerald-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-60"
                        >
                            {processing ? 'Processing…' : 'Process scan'}
                        </button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
