import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { CheckCircle, UserIcon } from '@/Components/Icons';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEvent, useCallback, useEffect, useId, useRef, useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { CameraDevice, Html5Qrcode, Html5QrcodeCameraScanConfig, Html5QrcodeSupportedFormats } from 'html5-qrcode';
import { gregorianToEthiopian, ethiopianToGregorianIso, ethiopianToJdn, ethiopianMonthLength } from '@/lib/calendar/ethiopianCalendar';
import { useCalendarSystem } from '@/lib/calendar/calendarSystem';

type Provider = {
    id: string;
    name_en: string;
    name_am: string;
    code: string;
    contact_person: string | null;
    phone_number: string | null;
    email: string | null;
    location: string | null;
    is_active: boolean;
    organization: {
        id: string;
        name_en: string;
        name_am: string | null;
        code: string;
    } | null;
};

type EmployeeInfo = {
    full_name: string;
    employee_number: string;
    photo_url: string | null;
    position: string | null;
    organization: string | null;
    organization_unit?: string | null;
};

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
    consumed_by_transaction_id: string | null;
    is_available: boolean;
    reason_code: string;
    label: string;
};

type TodayScan = {
    id: string;
    scanned_at: string | null;
    status: string | null;
    usage_mode: string | null;
    subsidy_amount_applied: number;
    employee_payable_amount: number;
    consumed_days_count: number;
    is_extra_scan: boolean;
    employee: {
        display_name: string;
        employee_number: string;
        photo_url: string | null;
        organization_name: string | null;
        organization_unit_name: string | null;
        position_title: string | null;
    } | null;
    provider?: {
        name_en: string | null;
        name_am: string | null;
        code: string | null;
    } | null;
};

type ScanResult = {
    allowed: boolean;
    is_extra_scan: boolean;
    denial_reason: string | null;
    denial_message?: string | null;
    card_status?: string | null;
    employee: EmployeeInfo | null;
    card_number: string | null;
    // Weekly window fields
    usage_mode: string | null;
    subsidy_applied: number | null;
    employee_payable: number | null;
    available_days_count: number | null;
    consumed_days_count: number | null;
    remaining_after: number | null;
    week_start: string | null;
    week_end: string | null;
    consumed_dates?: string[];
    calendar_days?: CalendarDay[];
    employee_id?: string | null;
    transaction_id?: string | null;
    duplicate?: boolean;
};

const cameraScanConfig: Html5QrcodeCameraScanConfig = {
    fps: 10,
    qrbox: (viewfinderWidth: number, viewfinderHeight: number) => {
        const edge = Math.floor(Math.min(viewfinderWidth, viewfinderHeight) * 0.72);
        return { width: edge, height: edge };
    },
    aspectRatio: 1,
    disableFlip: false,
};

// ─── Calendar helpers ────────────────────────────────────────────────────────

type CalCell = { day: number; gregorianIso: string };

const ETH_MONTHS_AM = ['መስከረም','ጥቅምት','ህዳር','ታህሳስ','ጥር','የካቲት','መጋቢት','ሚያዚያ','ግንቦት','ሰኔ','ሐምሌ','ነሀሴ','ጳጉሜ'];

function getMonthLabel(year: number, month: number, locale: string, isEthiopian: boolean): string {
    if (isEthiopian) return `${ETH_MONTHS_AM[month - 1] ?? ''} ${year} ዓ.ም`;
    return new Intl.DateTimeFormat(locale === 'am' ? 'am-ET' : 'en', {
        month: 'long', year: 'numeric',
    }).format(new Date(year, month, 1));
}

function buildCalendarCells(year: number, month: number, isEthiopian: boolean): (CalCell | null)[] {
    if (isEthiopian) {
        const firstJdn = ethiopianToJdn(year, month, 1);
        const firstDow = (firstJdn + 1) % 7;
        const total = ethiopianMonthLength(year, month);
        const cells: (CalCell | null)[] = Array(firstDow).fill(null);
        for (let d = 1; d <= total; d++) {
            cells.push({ day: d, gregorianIso: ethiopianToGregorianIso(year, month, d) ?? '' });
        }
        return cells;
    }
    const firstDow = new Date(year, month, 1).getDay();
    const total    = new Date(year, month + 1, 0).getDate();
    const cells: (CalCell | null)[] = Array(firstDow).fill(null);
    for (let d = 1; d <= total; d++) {
        cells.push({ day: d, gregorianIso: isoDate(year, month, d) });
    }
    return cells;
}

function isoDate(year: number, month: number, day: number): string {
    return `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

function formatTime(iso: string): string {
    return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

function newScanNonce(): string {
    return window.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

const DENIAL_REASON_KEY: Record<string, string> = {
    already_scanned_today:      'denialAlreadyScannedToday',
    wrong_institution:          'denialWrongInstitution',
    employee_on_leave:          'denialEmployeeOnLeave',
    card_inactive:               'denialCardInactive',
    card_expired:                'denialCardExpired',
    not_eligible:                'denialNotEligible',
    cafeteria_closed_weekend:    'denialCafeteriaClosedWeekend',
    cafeteria_closed:            'denialCafeteriaClosed',
    cafeteria_closed_holiday:    'denialCafeteriaClosedHoliday',
    no_subsidy_rule:             'denialNoSubsidyRule',
    no_available_subsidy:        'denialNoAvailableSubsidy',
    invalid_token_format:        'denialInvalidToken',
};

function computeCoveredDates(scanDate: string, result: ScanResult): string[] {
    // Extra scans only count the day they happen
    if (result.is_extra_scan) return [scanDate];

    // Multi-day: mark every calendar day from scan date through week_end
    if (result.usage_mode === 'use_remaining_week' && result.week_end && result.week_end >= scanDate) {
        const dates: string[] = [];
        const curr = new Date(scanDate + 'T00:00:00');
        const end  = new Date(result.week_end  + 'T00:00:00');
        while (curr <= end) {
            dates.push(curr.toISOString().slice(0, 10));
            curr.setDate(curr.getDate() + 1);
        }
        return dates;
    }

    return [scanDate];
}

// ─── Component ───────────────────────────────────────────────────────────────

export default function CafeteriaScan({
    providers,
    provider_locked,
    today_scans,
    calendar_days,
    scan_result,
}: {
    providers: Provider[];
    provider_locked?: boolean;
    today_scans?: TodayScan[];
    calendar_days?: CalendarDay[];
    scan_result?: ScanResult | null;
}) {
    const { t, locale } = useLocale();
    const calendarSystem = useCalendarSystem();
    const isEthiopian = calendarSystem === 'ethiopian';
    const scannerRegionId = useId().replace(/:/g, '');
    const scannerRef         = useRef<Html5Qrcode | null>(null);
    const scannerTransitionRef = useRef(false);
    const scanHandledRef     = useRef(false);
    const submittedTokenRef  = useRef<string | null>(null);
    const prevScanResultRef  = useRef<ScanResult | null | undefined>(undefined);

    const [cameraActive,     setCameraActive]     = useState(false);
    const [cameraError,      setCameraError]      = useState<string | null>(null);
    const [cameraProcessing, setCameraProcessing] = useState(false);
    const [cameraStarting,   setCameraStarting]   = useState(false);
    const [countdown,        setCountdown]        = useState<number | null>(null);
    const [todayScans, setTodayScans] = useState<TodayScan[]>(today_scans ?? []);
    const [calendarMeta, setCalendarMeta] = useState<CalendarDay[]>(scan_result?.calendar_days ?? calendar_days ?? []);

    // Calendar state
    const now   = new Date();
    const todayDateStr = isoDate(now.getFullYear(), now.getMonth(), now.getDate());
    const todayEth = gregorianToEthiopian(now.getFullYear(), now.getMonth() + 1, now.getDate());
    const [calYear,  setCalYear]  = useState(() => isEthiopian ? todayEth.year  : now.getFullYear());
    const [calMonth, setCalMonth] = useState(() => isEthiopian ? todayEth.month : now.getMonth());

    const SCAN_HISTORY_KEY = 'cafeteria_scan_history';

    // Load from localStorage on mount; drop entries older than 30 days
    // coveredDates: all calendar dates this scan "uses" (multi-day = scan → week_end)
    const [scanHistory, setScanHistory] = useState<{ ts: string; isExtra: boolean; coveredDates: string[] }[]>(() => {
        try {
            const raw = sessionStorage.getItem(SCAN_HISTORY_KEY);
            if (!raw) return [];
            const parsed = JSON.parse(raw) as { ts: string; isExtra: boolean; coveredDates?: string[] }[];
            const cutoff = new Date();
            cutoff.setDate(cutoff.getDate() - 30);
            const cutoffStr = cutoff.toISOString().slice(0, 10);
            return parsed
                .filter((e) => e.ts.slice(0, 10) >= cutoffStr)
                .map((e) => ({ ...e, coveredDates: e.coveredDates ?? [e.ts.slice(0, 10)] }));
        } catch {
            return [];
        }
    });

    // Persist scan history to localStorage whenever it changes
    useEffect(() => {
        try {
            sessionStorage.setItem(SCAN_HISTORY_KEY, JSON.stringify(scanHistory));
        } catch { }
    }, [scanHistory]);

    const form = useForm({
        provider_id:               providers[0]?.id ?? '',
        qr_token:                  '',
        scan_nonce:                newScanNonce(),
        scanned_at:                '',
        usage_mode:                'single_day',
    });

    const inputCls =
        'w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-900 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

    const selectedProvider = providers.find((provider) => provider.id === form.data.provider_id) ?? null;
    const selectedProviderName = selectedProvider
        ? (locale === 'am' && selectedProvider.name_am ? selectedProvider.name_am : selectedProvider.name_en)
        : '';
    const selectedOrganizationName = selectedProvider?.organization
        ? (locale === 'am' && selectedProvider.organization.name_am
            ? selectedProvider.organization.name_am
            : selectedProvider.organization.name_en)
        : null;
    const selectedProviderDetails = selectedProvider
        ? [
            { label: t('cafeteria.providerCode'), value: selectedProvider.code },
            { label: t('cafeteria.organization'), value: selectedOrganizationName },
            { label: t('cafeteria.contactPerson'), value: selectedProvider.contact_person },
            { label: t('cafeteria.phoneNumber'), value: selectedProvider.phone_number },
            { label: t('cafeteria.email'), value: selectedProvider.email },
            { label: t('cafeteria.location'), value: selectedProvider.location },
        ].filter((detail) => detail.value && String(detail.value).trim() !== '')
        : [];

    // ── Camera helpers ──────────────────────────────────────────────────────

    const getCameraErrorMessage = useCallback((error: unknown) => {
        if (!window.isSecureContext)              return t('cafeteria.cameraRequiresSecureContext');
        if (!navigator.mediaDevices?.getUserMedia) return t('cafeteria.cameraNotSupported');
        if (error instanceof DOMException) {
            if (error.name === 'NotAllowedError' || error.name === 'PermissionDeniedError') return t('cafeteria.cameraPermissionDenied');
            if (error.name === 'NotFoundError'   || error.name === 'OverconstrainedError')  return t('cafeteria.noCameraFound');
            if (error.name === 'NotReadableError' || error.name === 'TrackStartError')       return t('cafeteria.cameraInUse');
            return error.message || t('cafeteria.cameraUnavailable');
        }
        if (error instanceof Error)               return error.message || t('cafeteria.cameraUnavailable');
        if (typeof error === 'string' && error.trim() !== '') return error;
        return t('cafeteria.cameraUnavailable');
    }, [t]);

    const selectPreferredCamera = useCallback((cameras: CameraDevice[]) => {
        return cameras.find((c) => /back|rear|environment/i.test(c.label)) ?? cameras[0] ?? null;
    }, []);

    const verifyCameraAccess = useCallback(async () => {
        const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
        stream.getTracks().forEach((t) => t.stop());
    }, []);

    const stopCamera = useCallback(async (updateState = true) => {
        const scanner = scannerRef.current;
        scannerRef.current = null;
        scannerTransitionRef.current = false;
        if (!scanner) { if (updateState) setCameraActive(false); return; }
        if (updateState) setCameraActive(false);
        try {
            if (scanner.isScanning) await scanner.stop();
            scanner.clear();
        } catch { }
    }, []);

    const submitScannedToken = useCallback((qrToken: string) => {
        const token = qrToken.trim();
        if (token === '' || submittedTokenRef.current === token) return;
        submittedTokenRef.current = token;
        setCameraProcessing(true);
        form.setData('qr_token', token);
        router.post(
            route('cafeteria.scan.process'),
            {
                provider_id: form.data.provider_id,
                qr_token: token,
                scan_nonce: form.data.scan_nonce,
                scanned_at: form.data.scanned_at,
                usage_mode: form.data.usage_mode,
            },
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

        if (!form.data.provider_id)    { setCameraError(t('cafeteria.selectProviderFirst'));        scannerTransitionRef.current = false; setCameraStarting(false); return; }
        if (!window.isSecureContext)   { setCameraError(t('cafeteria.cameraRequiresSecureContext')); scannerTransitionRef.current = false; setCameraStarting(false); return; }
        if (!navigator.mediaDevices?.getUserMedia) { setCameraError(t('cafeteria.cameraNotSupported')); scannerTransitionRef.current = false; setCameraStarting(false); return; }

        const existing = scannerRef.current;
        if (existing) {
            try { if (existing.isScanning) await existing.stop(); existing.clear(); } catch { }
            finally { scannerRef.current = null; setCameraActive(false); }
        }

        try { await verifyCameraAccess(); }
        catch (error) { setCameraError(getCameraErrorMessage(error)); scannerTransitionRef.current = false; setCameraStarting(false); return; }

        const scanner = new Html5Qrcode(scannerRegionId, { verbose: false, formatsToSupport: [Html5QrcodeSupportedFormats.QR_CODE] });
        scannerRef.current = scanner;

        try {
            const onScanSuccess = (decodedText: string) => {
                if (scanHandledRef.current) return;
                scanHandledRef.current = true;
                submitScannedToken(decodedText);
            };

            let started = false;
            try {
                await scanner.start({ facingMode: { ideal: 'environment' } }, cameraScanConfig, onScanSuccess, () => undefined);
                started = true;
            } catch { }

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
    }, [cameraActive, cameraProcessing, form.data.provider_id, getCameraErrorMessage, scannerRegionId, selectPreferredCamera, submitScannedToken, t, verifyCameraAccess]);

    function submit(e: FormEvent) {
        e.preventDefault();
        form.post(route('cafeteria.scan.process'), {
            preserveScroll: true,
            onFinish: () => form.setData('scan_nonce', newScanNonce()),
        });
    }

    // Auto-redirect mobile devices to the mobile scan page
    useEffect(() => {
        const isMobile = /Mobi|Android|iPhone|iPad|iPod/i.test(navigator.userAgent) || window.innerWidth < 768;
        if (isMobile) router.replace({ url: route('cafeteria.scan.mobile') });
    }, []);

    // Cleanup on unmount
    useEffect(() => { return () => { void stopCamera(false); }; }, [stopCamera]);

    // Auto-restart after scan result
    useEffect(() => {
        if (!scan_result) return;
        let count = 3;
        setCountdown(count);
        const countInterval = window.setInterval(() => {
            count -= 1;
            setCountdown(count > 0 ? count : null);
            if (count <= 0) clearInterval(countInterval);
        }, 1000);
        const autoStart = window.setTimeout(() => void startCamera(), 3000);
        return () => { clearInterval(countInterval); clearTimeout(autoStart); };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Track successful scans → update calendar
    useEffect(() => {
        if (scan_result === prevScanResultRef.current) return;
        prevScanResultRef.current = scan_result;
        if (scan_result?.allowed) {
            if (scan_result.calendar_days) {
                setCalendarMeta(scan_result.calendar_days);
            }
            const scanDate = new Date().toISOString().slice(0, 10);
            const coveredDates = computeCoveredDates(scanDate, scan_result);
            setScanHistory((prev) => [...prev, { ts: new Date().toISOString(), isExtra: scan_result.is_extra_scan, coveredDates }]);
        }
    }, [scan_result]);

    useEffect(() => {
        if (!form.data.provider_id) return;

        let cancelled = false;

        void window.axios
            .get<{ calendar_days: CalendarDay[] }>(route('cafeteria.scan.calendar'), {
                params: {
                    provider_id: form.data.provider_id,
                    employee_id: scan_result?.employee_id ?? undefined,
                    date: todayDateStr,
                },
            })
            .then((response) => {
                if (!cancelled) setCalendarMeta(response.data.calendar_days);
            });

        void window.axios
            .get<{ data: TodayScan[] }>(route('cafeteria.scan.today'), {
                params: { provider_id: form.data.provider_id },
            })
            .then((response) => {
                if (!cancelled) setTodayScans(response.data.data);
            });

        return () => { cancelled = true; };
    }, [form.data.provider_id, scan_result?.employee_id, todayDateStr]);

    // Reset calendar view when calendar system changes (e.g. locale switch)
    useEffect(() => {
        const today = new Date();
        if (isEthiopian) {
            const eth = gregorianToEthiopian(today.getFullYear(), today.getMonth() + 1, today.getDate());
            setCalYear(eth.year);
            setCalMonth(eth.month);
        } else {
            setCalYear(today.getFullYear());
            setCalMonth(today.getMonth());
        }
    }, [isEthiopian]);

    // ── Calendar derived values ─────────────────────────────────────────────

    const scannedDateMap = scanHistory.reduce<Map<string, number>>((acc, { coveredDates }) => {
        for (const d of coveredDates) {
            acc.set(d, (acc.get(d) ?? 0) + 1);
        }
        return acc;
    }, new Map());
    const dayLabels = isEthiopian
        ? ['እሁ', 'ሰኞ', 'ማክ', 'ረቡ', 'ሐሙ', 'ዓር', 'ቅዳ']
        : ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
    const cells = buildCalendarCells(calYear, calMonth, isEthiopian);
    const todayScansToday = scanHistory.filter(({ ts }) => ts.startsWith(todayDateStr));

    function prevMonth() {
        if (isEthiopian) {
            if (calMonth === 1) { setCalYear((y) => y - 1); setCalMonth(13); }
            else setCalMonth((m) => m - 1);
        } else {
            if (calMonth === 0) { setCalYear((y) => y - 1); setCalMonth(11); }
            else setCalMonth((m) => m - 1);
        }
    }
    function nextMonth() {
        if (isEthiopian) {
            if (calMonth === 13) { setCalYear((y) => y + 1); setCalMonth(1); }
            else setCalMonth((m) => m + 1);
        } else {
            if (calMonth === 11) { setCalYear((y) => y + 1); setCalMonth(0); }
            else setCalMonth((m) => m + 1);
        }
    }

    // ── Render ──────────────────────────────────────────────────────────────

    return (
        <AuthenticatedLayout header={<PageHeader title={t('cafeteria.scanTerminal')} />}>
            <Head title={t('cafeteria.scanQr')} />

            <div className="mx-auto max-w-7xl">
                <form
                    onSubmit={submit}
                    className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900"
                >
                    {/* Header */}
                    <div className="border-b border-gray-200 px-6 py-4 dark:border-slate-800">
                        <h2 className="text-base font-semibold text-gray-900 dark:text-white">
                            {t('cafeteria.scanQr')}
                        </h2>
                    </div>

                    {/* Scan result banner */}
                    {scan_result && (
                        <div className={`border-b px-6 py-4 ${
                            scan_result.allowed
                                ? scan_result.is_extra_scan
                                    ? 'border-orange-200 bg-orange-50 dark:border-orange-900 dark:bg-orange-950/30'
                                    : 'border-emerald-200 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/30'
                                : 'border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950/30'
                        }`}>
                            <p className={`font-semibold ${
                                scan_result.allowed
                                    ? scan_result.is_extra_scan
                                        ? 'text-orange-700 dark:text-orange-300'
                                        : 'text-emerald-700 dark:text-emerald-300'
                                    : 'text-red-700 dark:text-red-300'
                            }`}>
                                {scan_result.allowed
                                    ? (scan_result.is_extra_scan ? t('cafeteria.extraScanRecorded') : t('cafeteria.scanRecorded'))
                                    : scan_result.denial_message ?? (() => {
                                        const key = DENIAL_REASON_KEY[scan_result.denial_reason ?? ''];
                                        return key
                                            ? `${t('cafeteria.scanDenied')} — ${t(`cafeteria.${key}`)}`
                                            : `${t('cafeteria.scanDenied')} — ${scan_result.denial_reason ?? ''}`;
                                    })()}
                            </p>
                            {countdown !== null && (
                                <p className="mt-1 text-xs text-gray-500 dark:text-slate-400">
                                    {t('cafeteria.scanAgainIn').replace('{{count}}', String(countdown))}
                                </p>
                            )}
                        </div>
                    )}

                    {/* Main body: scanner | calendar | employee */}
                    <div className="grid gap-6 p-6 lg:grid-cols-3 lg:items-start">

                        {/* ── LEFT: QR Scanner ── */}
                        <div className="space-y-4">
                            {/* Camera viewfinder */}
                            <div className="relative overflow-hidden rounded-lg border border-gray-200 bg-gray-950 dark:border-slate-700">
                                <div
                                    id={scannerRegionId}
                                    className="min-h-[300px] w-full [&_video]:min-h-[300px] [&_video]:w-full [&_video]:object-cover"
                                />
                                {!cameraActive && (
                                    <div className="absolute inset-0 flex items-center justify-center bg-gray-950 px-6 text-center text-sm text-slate-300">
                                        {cameraProcessing ? t('cafeteria.processingScan') : t('cafeteria.cameraReady')}
                                    </div>
                                )}
                            </div>

                            {cameraError && (
                                <p className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-900/60 dark:bg-red-950/30 dark:text-red-300">
                                    {cameraError}
                                </p>
                            )}

                            {/* Camera controls */}
                            <div className="flex flex-wrap gap-3">
                                <button
                                    type="button"
                                    onClick={() => void startCamera()}
                                    disabled={cameraStarting || cameraActive || cameraProcessing || form.processing}
                                    className="rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60"
                                >
                                    {cameraStarting || cameraActive ? t('cafeteria.cameraScanning') : t('cafeteria.startCamera')}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => void stopCamera()}
                                    disabled={!cameraActive}
                                    className="rounded-lg border border-gray-300 px-5 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-60 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                                >
                                    {t('cafeteria.stopCamera')}
                                </button>
                            </div>

                            {/* Provider selector */}
                            <div className="space-y-1.5">
                                <label className="text-sm font-medium text-gray-700 dark:text-slate-300">
                                    {t('cafeteria.selectProvider')}
                                </label>
                                <select
                                    className={inputCls}
                                    value={form.data.provider_id}
                                    onChange={(e) => form.setData('provider_id', e.target.value)}
                                    disabled={provider_locked}
                                    required
                                >
                                    {providers.map((p) => (
                                        <option key={p.id} value={p.id}>
                                            {p.name_en} ({p.code})
                                        </option>
                                    ))}
                                </select>
                                {form.errors.provider_id && (
                                    <p className="text-xs text-red-600">{form.errors.provider_id}</p>
                                )}
                                {provider_locked && (
                                    <p className="text-xs text-blue-600 dark:text-blue-300">
                                        {t('cafeteria.providerScopedNotice')}
                                    </p>
                                )}
                            </div>

                            {selectedProvider && (
                                <div className="rounded-lg border border-blue-100 bg-blue-50/70 p-4 dark:border-blue-900/50 dark:bg-blue-950/20">
                                    <p className="text-xs font-semibold uppercase text-blue-700 dark:text-blue-300">
                                        {t('cafeteria.selectedProvider')}
                                    </p>
                                    <h3 className="mt-1 text-sm font-semibold text-gray-900 dark:text-white">
                                        {selectedProviderName}
                                    </h3>
                                    {selectedProviderDetails.length > 0 && (
                                        <dl className="mt-3 space-y-2">
                                            {selectedProviderDetails.map((detail) => (
                                                <div key={detail.label} className="flex items-start justify-between gap-3 text-xs">
                                                    <dt className="text-gray-500 dark:text-slate-400">{detail.label}</dt>
                                                    <dd className="max-w-[65%] text-right font-medium text-gray-800 dark:text-slate-100">
                                                        {detail.value}
                                                    </dd>
                                                </div>
                                            ))}
                                        </dl>
                                    )}
                                </div>
                            )}

                            {/* Manual token fallback */}
                            <div className="space-y-1.5">
                                <label className="text-sm font-medium text-gray-700 dark:text-slate-300">
                                    {t('cafeteria.enterQrToken')}
                                </label>
                                <input
                                    className={inputCls}
                                    placeholder="xxxx-xxxx|token"
                                    value={form.data.qr_token}
                                    onChange={(e) => form.setData('qr_token', e.target.value)}
                                />
                                {form.errors.qr_token && (
                                    <p className="text-xs text-red-600">{form.errors.qr_token}</p>
                                )}
                                <p className="text-xs text-gray-500 dark:text-slate-400">
                                    {t('cafeteria.manualQrFallback')}
                                </p>
                            </div>

                            {/* Usage mode selector */}
                            <div className="space-y-1.5">
                                <label className="text-sm font-medium text-gray-700 dark:text-slate-300">
                                    {t('cafeteria.usageModeLabel')}
                                </label>
                                <select
                                    className={inputCls}
                                    value={form.data.usage_mode}
                                    onChange={(e) => form.setData('usage_mode', e.target.value)}
                                >
                                    <option value="single_day">{t('cafeteria.usageModeSingleDay')}</option>
                                    <option value="use_remaining_week">{t('cafeteria.usageModeRemainingWeek')}</option>
                                </select>
                            </div>

                            {/* Week availability summary (shown after a successful scan) */}
                            {scan_result?.allowed && scan_result.week_start && (
                                <div className="rounded-xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-900/40 dark:bg-blue-950/20">
                                    <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-blue-600 dark:text-blue-400">
                                        {t('cafeteria.weeklyWindowTitle')} — {scan_result.week_start} → {scan_result.week_end}
                                    </p>
                                    <dl className="space-y-1 text-sm">
                                        <div className="flex justify-between">
                                            <dt className="text-gray-500 dark:text-slate-400">{t('cafeteria.subsidyApplied')}</dt>
                                            <dd className="font-semibold text-emerald-600 dark:text-emerald-400">{scan_result.subsidy_applied?.toFixed(2)}</dd>
                                        </div>
                                        {(scan_result.employee_payable ?? 0) > 0 && (
                                            <div className="flex justify-between">
                                                <dt className="text-gray-500 dark:text-slate-400">{t('cafeteria.employeePayableAmount')}</dt>
                                                <dd className="font-semibold text-orange-600 dark:text-orange-400">{scan_result.employee_payable?.toFixed(2)}</dd>
                                            </div>
                                        )}
                                        <div className="flex justify-between">
                                            <dt className="text-gray-500 dark:text-slate-400">{t('cafeteria.consumedDays')}</dt>
                                            <dd className="font-medium text-gray-700 dark:text-slate-300">{scan_result.consumed_days_count} / {scan_result.available_days_count}</dd>
                                        </div>
                                        <div className="flex justify-between border-t border-blue-200 pt-1 dark:border-blue-900/40">
                                            <dt className="font-medium text-gray-600 dark:text-slate-300">{t('cafeteria.remainingWeekBalance')}</dt>
                                            <dd className="font-bold text-blue-700 dark:text-blue-300">{scan_result.remaining_after?.toFixed(2)}</dd>
                                        </div>
                                    </dl>
                                </div>
                            )}
                        </div>

                        {/* ── MIDDLE: Scan Calendar + Employee Info ── */}
                        <div className="flex flex-col gap-4">
                            <div className="shrink-0 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">

                                {/* Calendar header */}
                                <div className="border-b border-gray-100 bg-gray-50/70 px-4 py-3 dark:border-slate-800 dark:bg-slate-800/40">
                                    <div className="flex items-center justify-between">
                                        <button
                                            type="button"
                                            onClick={prevMonth}
                                            className="flex h-8 w-8 items-center justify-center rounded-lg text-gray-500 transition hover:bg-white hover:shadow-sm dark:text-slate-400 dark:hover:bg-slate-700"
                                            aria-label="Previous month"
                                        >
                                            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                                            </svg>
                                        </button>
                                        <div className="text-center">
                                            <p className="text-sm font-semibold text-gray-900 dark:text-white">
                                                {getMonthLabel(calYear, calMonth, locale, isEthiopian)}
                                            </p>
                                            <span className={`text-[10px] font-medium ${isEthiopian ? 'text-indigo-500 dark:text-indigo-400' : 'text-gray-400 dark:text-slate-500'}`}>
                                                {isEthiopian
                                                    ? (locale === 'am' ? 'የኢትዮጵያ ቀን አቆጣጠር' : 'Ethiopian Calendar')
                                                    : (locale === 'am' ? 'ጎርጎሪያን ቀን አቆጣጠር' : 'Gregorian Calendar')}
                                            </span>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={nextMonth}
                                            className="flex h-8 w-8 items-center justify-center rounded-lg text-gray-500 transition hover:bg-white hover:shadow-sm dark:text-slate-400 dark:hover:bg-slate-700"
                                            aria-label="Next month"
                                        >
                                            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>

                                <div className="px-3 pb-3 pt-2">
                                    {/* Day-of-week headers */}
                                    <div className="mb-1 grid grid-cols-7">
                                        {dayLabels.map((d) => (
                                            <div key={d} className="py-1.5 text-center text-[11px] font-semibold uppercase tracking-wide text-gray-400 dark:text-slate-500">
                                                {d}
                                            </div>
                                        ))}
                                    </div>

                                    {/* Day cells */}
                                    <div className="grid grid-cols-7">
                                        {cells.map((cell, idx) => {
                                            if (cell === null) return <div key={`empty-${idx}`} />;

                                            const { day, gregorianIso: dateStr } = cell;
                                            const isToday  = dateStr === todayDateStr;
                                            const dayMeta = calendarMeta.find((item) => item.date === dateStr);
                                            const scanCount = scannedDateMap.get(dateStr) ?? 0;
                                            const isConsumed = dayMeta?.is_consumed || scanCount > 0;

                                            let cellCls: string;
                                            if (dayMeta?.is_consumed) {
                                                cellCls = 'bg-emerald-500 text-white font-bold shadow-sm';
                                            } else if (dayMeta?.is_employee_excluded) {
                                                cellCls = 'bg-red-100 text-red-600 dark:bg-red-950/50 dark:text-red-400';
                                            } else if (dayMeta?.is_public_holiday || dayMeta?.reason_code === 'special_no_subsidy_day') {
                                                cellCls = 'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-400';
                                            } else if (dayMeta?.reason_code === 'special_open_day') {
                                                cellCls = 'bg-purple-100 text-purple-700 dark:bg-purple-950/50 dark:text-purple-400';
                                            } else if (dayMeta?.is_available) {
                                                cellCls = 'bg-blue-50 text-blue-700 font-medium dark:bg-blue-950/40 dark:text-blue-300';
                                            } else if (dayMeta !== undefined && !dayMeta.is_open) {
                                                cellCls = 'bg-gray-100 text-gray-400 dark:bg-slate-800/70 dark:text-slate-600';
                                            } else {
                                                cellCls = 'text-gray-700 hover:bg-gray-50 dark:text-slate-300 dark:hover:bg-slate-800/60';
                                            }

                                            return (
                                                <div
                                                    key={dateStr || `cell-${idx}`}
                                                    title={dayMeta?.label}
                                                    className={`relative mx-auto my-0.5 flex h-8 w-8 flex-col items-center justify-center rounded-lg text-[13px] transition-colors
                                                        ${isToday ? 'ring-2 ring-blue-500 ring-offset-1 dark:ring-offset-slate-900' : ''}
                                                        ${cellCls}
                                                    `}
                                                >
                                                    <span className="leading-none">{day}</span>
                                                    {isConsumed && (
                                                        scanCount <= 1 ? (
                                                            <CheckCircle
                                                                className={`absolute -bottom-0.5 -right-0.5 h-3.5 w-3.5 ${
                                                                    isToday ? 'text-emerald-300' : 'text-emerald-500'
                                                                }`}
                                                            />
                                                        ) : (
                                                            <span className={`absolute -bottom-1 -right-1 flex h-4 w-4 items-center justify-center rounded-full text-[9px] font-bold ${
                                                                isToday ? 'bg-emerald-300 text-emerald-900' : 'bg-emerald-500 text-white'
                                                            }`}>
                                                                {scanCount}
                                                            </span>
                                                        )
                                                    )}
                                                </div>
                                            );
                                        })}
                                    </div>
                                </div>

                                {/* Legend */}
                                <div className="border-t border-gray-100 px-4 py-3 dark:border-slate-800">
                                    <div className="grid grid-cols-2 gap-x-3 gap-y-1.5">
                                        {[
                                            ['bg-blue-50 border border-blue-200', t('cafeteria.calendar_available')],
                                            ['bg-emerald-500', t('cafeteria.calendar_consumed')],
                                            ['bg-gray-100', t('cafeteria.calendar_closed')],
                                            ['bg-amber-100', t('cafeteria.calendar_public_holiday')],
                                            ['bg-purple-100', t('cafeteria.calendar_special_open_day')],
                                            ['bg-red-100', t('cafeteria.calendar_employee_leave')],
                                        ].map(([color, label]) => (
                                            <span key={label} className="inline-flex items-center gap-1.5 text-[11px] text-gray-500 dark:text-slate-400">
                                                <span className={`h-2.5 w-2.5 shrink-0 rounded-sm ${color}`} />
                                                {label}
                                            </span>
                                        ))}
                                    </div>
                                </div>
                            </div>

                            {/* Employee Info */}
                            {scan_result?.employee ? (
                                <div className="rounded-xl border border-gray-200 bg-gray-50 p-5 dark:border-slate-700 dark:bg-slate-950">
                                    {/* Photo or initials */}
                                    <div className="flex flex-col items-center text-center">
                                        {scan_result.employee.photo_url ? (
                                            <img
                                                src={scan_result.employee.photo_url}
                                                alt={scan_result.employee.full_name}
                                                className={`h-24 w-24 rounded-full object-cover ring-4 ${
                                                    scan_result.is_extra_scan ? 'ring-orange-400' : 'ring-emerald-400'
                                                }`}
                                            />
                                        ) : (
                                            <div className={`flex h-24 w-24 items-center justify-center rounded-full text-3xl font-bold ring-4 ${
                                                scan_result.is_extra_scan
                                                    ? 'bg-orange-100 text-orange-600 ring-orange-400 dark:bg-orange-900/30 dark:text-orange-300'
                                                    : 'bg-emerald-100 text-emerald-600 ring-emerald-400 dark:bg-emerald-900/30 dark:text-emerald-300'
                                            }`}>
                                                {scan_result.employee.full_name.charAt(0).toUpperCase()}
                                            </div>
                                        )}

                                        {/* Allowed / Extra badge */}
                                        <div className={`mt-3 inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold ${
                                            scan_result.is_extra_scan
                                                ? 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400'
                                                : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400'
                                        }`}>
                                            <CheckCircle className="h-3.5 w-3.5" />
                                            {scan_result.is_extra_scan ? t('cafeteria.extraScanBadge') : t('cafeteria.scanRecorded')}
                                        </div>

                                        <h3 className="mt-3 text-base font-bold text-gray-900 dark:text-white">
                                            {scan_result.employee.full_name}
                                        </h3>
                                        <p className="text-sm text-gray-500 dark:text-slate-400">
                                            #{scan_result.employee.employee_number}
                                        </p>
                                    </div>

                                    {/* Details */}
                                    <dl className="mt-4 space-y-2 border-t border-gray-200 pt-4 text-sm dark:border-slate-700">
                                        {scan_result.employee.position && (
                                            <div className="flex gap-2">
                                                <dt className="w-20 shrink-0 text-gray-400 dark:text-slate-500">{t('cafeteria.position')}</dt>
                                                <dd className="font-medium text-gray-700 dark:text-slate-300">{scan_result.employee.position}</dd>
                                            </div>
                                        )}
                                        {scan_result.employee.organization && (
                                            <div className="flex gap-2">
                                                <dt className="w-20 shrink-0 text-gray-400 dark:text-slate-500">{t('cafeteria.department')}</dt>
                                                <dd className="font-medium text-gray-700 dark:text-slate-300">{scan_result.employee.organization}</dd>
                                            </div>
                                        )}
                                        {scan_result.card_number && (
                                            <div className="flex gap-2">
                                                <dt className="w-20 shrink-0 text-gray-400 dark:text-slate-500">{t('cafeteria.cardNo')}</dt>
                                                <dd className="font-mono font-medium text-gray-700 dark:text-slate-300">{scan_result.card_number}</dd>
                                            </div>
                                        )}
                                    </dl>
                                </div>
                            ) : (
                                /* Placeholder when no scan yet */
                                <div className="flex h-full min-h-[200px] flex-col items-center justify-center rounded-xl border border-dashed border-gray-300 bg-gray-50 p-8 text-center dark:border-slate-700 dark:bg-slate-950">
                                    <div className="mb-3 flex h-14 w-14 items-center justify-center rounded-full bg-gray-100 dark:bg-slate-800">
                                        <UserIcon className="h-7 w-7 text-gray-300 dark:text-slate-600" />
                                    </div>
                                    <p className="text-sm text-gray-400 dark:text-slate-500">
                                        {t('cafeteria.scanToSeeEmployee')}
                                    </p>
                                </div>
                            )}
                        </div>

                        {/* ── RIGHT: Today's Scans ── */}
                        <div className="flex flex-col self-stretch">
                            <div className="flex flex-1 flex-col rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-slate-700 dark:bg-slate-950">
                                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                                    {t('cafeteria.todayScans')} - {todayScans.length}
                                </p>
                                {todayScans.length === 0 ? (
                                    <p className="text-sm text-gray-400 dark:text-slate-500">
                                        {t('cafeteria.noScansForProviderToday')}
                                    </p>
                                ) : (
                                    <ul className="min-h-0 flex-1 space-y-2 overflow-y-auto pr-1">
                                        {todayScans.map((scan) => (
                                            <li key={scan.id} className="rounded-lg border border-gray-200 bg-white p-3 text-sm dark:border-slate-800 dark:bg-slate-900">
                                                <div className="flex items-start justify-between gap-3">
                                                    <div className="min-w-0">
                                                        <p className="truncate font-semibold text-gray-900 dark:text-slate-100">
                                                            {scan.employee?.display_name ?? t('cafeteria.employeeName')}
                                                        </p>
                                                        <p className="text-xs text-gray-500 dark:text-slate-400">
                                                            {scan.employee?.employee_number} - {scan.employee?.organization_name ?? ''}
                                                        </p>
                                                        <p className="text-xs text-gray-500 dark:text-slate-400">
                                                            {[scan.employee?.organization_unit_name, scan.employee?.position_title].filter(Boolean).join(' - ')}
                                                        </p>
                                                        <div className="mt-2 flex flex-wrap gap-2 text-[11px] text-gray-500 dark:text-slate-400">
                                                            <span className="font-mono">{scan.scanned_at ? formatTime(scan.scanned_at) : ''}</span>
                                                            <span>{scan.usage_mode === 'use_remaining_week' ? t('cafeteria.usageModeRemainingWeek') : t('cafeteria.usageModeSingleDay')}</span>
                                                            <span>{t('cafeteria.subsidyApplied')}: {scan.subsidy_amount_applied.toFixed(2)}</span>
                                                            <span>{t('cafeteria.consumedDays')}: {scan.consumed_days_count}</span>
                                                        </div>
                                                    </div>
                                                    <span className={`shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold ${
                                                        scan.status === 'accepted'
                                                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300'
                                                            : 'bg-red-100 text-red-700 dark:bg-red-950/40 dark:text-red-300'
                                                    }`}>
                                                        {scan.status === 'accepted' ? t('cafeteria.statusAccepted') : t('cafeteria.statusDenied')}
                                                    </span>
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Footer: submit */}
                    <div className="flex justify-end border-t border-gray-200 px-6 py-4 dark:border-slate-800">
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="rounded-lg bg-blue-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60"
                        >
                            {t('cafeteria.processScan')}
                        </button>
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
