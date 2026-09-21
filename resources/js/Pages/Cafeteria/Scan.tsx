import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import UserAvatar from '@/Components/UserAvatar';
import { CheckCircle, UserIcon } from '@/Components/Icons';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEvent, useCallback, useEffect, useId, useRef, useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { CameraDevice, Html5Qrcode, Html5QrcodeCameraScanConfig, Html5QrcodeSupportedFormats } from 'html5-qrcode';
import NfcTapPanel from '@/Components/Cafeteria/NfcTapPanel';
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

function formatTime(iso: string, locale: string): string {
    return new Date(iso).toLocaleTimeString(locale === 'am' ? 'am-ET' : 'en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
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
    scanOptions,
    provider_locked,
    today_scans,
    calendar_days,
    scan_result,
}: {
    providers: Provider[];
    scanOptions?: { default_usage_mode: string; allow_upfront_weekday_usage: boolean };
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

    const [credentialMethod, setCredentialMethod] = useState<'qr' | 'nfc'>('qr');
    const [cameraActive,     setCameraActive]     = useState(false);
    const [cameraError,      setCameraError]      = useState<string | null>(null);
    const [cameraProcessing, setCameraProcessing] = useState(false);
    const [cameraStarting,   setCameraStarting]   = useState(false);
    const [countdown,        setCountdown]        = useState<number | null>(null);
    const [todayScans, setTodayScans] = useState<TodayScan[]>(today_scans ?? []);
    const [historyState, setHistoryState] = useState<'loading' | 'ready' | 'error'>('ready');
    const [calendarState, setCalendarState] = useState<'loading' | 'ready' | 'error'>('ready');
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
        usage_mode: scanOptions?.default_usage_mode ?? 'single_day',
    });

    const inputCls =
        'w-full min-w-0 rounded-lg border border-[var(--app-border-strong)] bg-[var(--app-surface)] px-3 py-2.5 text-sm text-[var(--app-foreground)] focus:border-[var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-primary)] disabled:opacity-70';

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
            if (error.name === 'NotFoundError' || error.name === 'OverconstrainedError') return t('cafeteria.noCameraFound');
            if (error.name === 'NotReadableError' || error.name === 'TrackStartError') return t('cafeteria.cameraInUse');
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

    // An NFC reference goes to the same endpoint as a QR token, so the server
    // applies one identical set of cafeteria rules to both.
    const submitNfcCredential = useCallback((credential: string) => {
        router.post(
            route('cafeteria.scan.process'),
            {
                provider_id: form.data.provider_id,
                nfc_credential: credential,
                scan_nonce: form.data.scan_nonce,
                scanned_at: form.data.scanned_at,
                usage_mode: form.data.usage_mode,
            },
            {
                preserveScroll: true,
                onFinish: () => form.setData('scan_nonce', newScanNonce()),
            },
        );
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [form.data.provider_id, form.data.scan_nonce, form.data.scanned_at, form.data.usage_mode]);

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
                setCameraProcessing(true);
                // Stop decoding before posting, so one card cannot submit twice.
                // Inertia preserves this component; the result effect restarts it.
                void stopCamera().then(() => submitScannedToken(decodedText));
            };

            let started = false;
            try {
                await scanner.start({ facingMode: 'environment' }, cameraScanConfig, onScanSuccess, () => undefined);
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
    }, [cameraActive, cameraProcessing, form.data.provider_id, getCameraErrorMessage, scannerRegionId, selectPreferredCamera, stopCamera, submitScannedToken, t, verifyCameraAccess]);

    const startCameraRef = useRef(startCamera);
    useEffect(() => { startCameraRef.current = startCamera; }, [startCamera]);

    function submit(e: FormEvent) {
        e.preventDefault();

        // Manual entry submits on Enter, so these guards live here rather than
        // on a submit button's disabled state.
        if (!form.data.provider_id || !form.data.qr_token.trim() || form.processing || cameraProcessing) {
            return;
        }

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
        if (!scan_result || credentialMethod !== 'qr' || cameraProcessing) { setCountdown(null); return; }
        let count = 3;
        setCountdown(count);
        const countInterval = window.setInterval(() => {
            count -= 1;
            setCountdown(count > 0 ? count : null);
            if (count <= 0) clearInterval(countInterval);
        }, 1000);
        const autoStart = window.setTimeout(() => void startCameraRef.current(), 3000);
        return () => { clearInterval(countInterval); clearTimeout(autoStart); };
    }, [scan_result, credentialMethod, cameraProcessing]);

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
        setHistoryState('loading');
        setCalendarState('loading');

        void window.axios
            .get<{ calendar_days: CalendarDay[] }>(route('cafeteria.scan.calendar'), {
                params: {
                    provider_id: form.data.provider_id,
                    employee_id: scan_result?.employee_id ?? undefined,
                    date: todayDateStr,
                },
            })
            .then((response) => {
                if (!cancelled) { setCalendarMeta(response.data.calendar_days); setCalendarState('ready'); }
            })
            .catch(() => {
                if (!cancelled) setCalendarState('error');
            });

        void window.axios
            .get<{ data: TodayScan[] }>(route('cafeteria.scan.today'), {
                params: { provider_id: form.data.provider_id },
            })
            .then((response) => {
                if (!cancelled) { setTodayScans(response.data.data); setHistoryState('ready'); }
            })
            .catch(() => {
                if (!cancelled) setHistoryState('error');
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


    const panelCls = 'min-w-0 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)]';
    const mutedCls = 'text-[var(--app-muted-foreground)]';
    const buttonCls = 'inline-flex min-h-10 items-center justify-center gap-2 rounded-lg px-3 py-2 text-sm font-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-primary)] focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50';
    const resultTone = !scan_result?.allowed
        ? 'border-red-200 bg-red-50 text-red-800 dark:border-red-900/60 dark:bg-red-950/30 dark:text-red-200'
        : scan_result.is_extra_scan
            ? 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-200'
            : 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900/60 dark:bg-emerald-950/30 dark:text-emerald-200';
    const denialKey = DENIAL_REASON_KEY[scan_result?.denial_reason ?? ''];
    const employee = scan_result?.employee;

    return (
        <AuthenticatedLayout header={<PageHeader title={t('cafeteria.scanTerminal')} />}>
            <Head title={t('cafeteria.scanTerminal')} />
            <div data-cafeteria-terminal className="space-y-5 text-[var(--app-foreground)]">
                <section aria-label={t('cafeteria.selectedProvider')} className={panelCls}>
                    <div className="grid gap-4 p-4 sm:grid-cols-2 xl:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_auto] xl:items-end">
                        <div className="min-w-0">
                            <label htmlFor="scan-provider" className={`mb-1.5 block text-xs font-medium ${mutedCls}`}>{t('cafeteria.selectProvider')}</label>
                            <select id="scan-provider" className={inputCls} value={form.data.provider_id}
                                onChange={(e) => form.setData('provider_id', e.target.value)}
                                disabled={provider_locked || cameraStarting || cameraActive || cameraProcessing || form.processing} required>
                                {providers.length === 0 && <option value="">{t('cafeteria.noProvidersAvailable')}</option>}
                                {providers.map((provider) => <option key={provider.id} value={provider.id}>{locale === 'am' && provider.name_am ? provider.name_am : provider.name_en} ({provider.code})</option>)}
                            </select>
                            {form.errors.provider_id && <p role="alert" className="mt-1 text-xs text-red-600 dark:text-red-300">{form.errors.provider_id}</p>}
                        </div>
                        <div className="min-w-0">
                            <label htmlFor="scan-usage" className={`mb-1.5 block text-xs font-medium ${mutedCls}`}>{t('cafeteria.usageModeLabel')}</label>
                            <select id="scan-usage" className={inputCls} value={form.data.usage_mode}
                                disabled={cameraStarting || cameraActive || cameraProcessing || form.processing}
                                onChange={(e) => form.setData('usage_mode', e.target.value)}>
                                <option value="single_day">{t('cafeteria.usageModeSingleDay')}</option>
                                <option value="use_remaining_week" disabled={scanOptions?.allow_upfront_weekday_usage === false}>{t('cafeteria.usageModeRemainingWeek')}</option>
                            </select>
                            {form.errors.usage_mode && <p role="alert" className="mt-1 text-xs text-red-600 dark:text-red-300">{form.errors.usage_mode}</p>}
                        </div>
                        <div className={`flex min-h-10 items-center gap-2 text-xs sm:col-span-2 xl:col-span-1 ${mutedCls}`}>
                            <span aria-hidden="true" className="h-1.5 w-1.5 rounded-full bg-current" />
                            <LocalizedDateDisplay value={todayDateStr} />
                        </div>
                    </div>
                    {selectedProvider && <details className="border-t border-[var(--app-border)] px-4 py-2.5">
                        <summary className={`cursor-pointer break-words text-xs leading-5 focus-visible:outline-2 focus-visible:outline-[var(--color-primary)] ${mutedCls}`}>
                            {t('cafeteria.providerDetails')} · {selectedOrganizationName || selectedProviderName}
                        </summary>
                        {provider_locked && <p className={`mt-2 text-xs ${mutedCls}`}>{t('cafeteria.providerScopedNotice')}</p>}
                        <dl className="mt-3 grid gap-x-6 gap-y-3 pb-1 sm:grid-cols-2 xl:grid-cols-3">
                            {selectedProviderDetails.map((detail) => <div key={detail.label} className="min-w-0 text-xs">
                                <dt className={mutedCls}>{detail.label}</dt><dd className="mt-0.5 break-words font-medium">{detail.value}</dd>
                            </div>)}
                        </dl>
                    </details>}
                </section>

                <div className="grid grid-cols-1 items-start gap-4 xl:grid-cols-12">
                    <section aria-labelledby="capture-heading" className={`${panelCls} xl:col-span-5`}>
                        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-[var(--app-border)] px-4 py-3">
                            <h2 id="capture-heading" className="text-sm font-semibold">{t('cafeteria.scanCredential')}</h2>
                            <span className={`text-xs ${mutedCls}`} aria-live="polite">
                                {cameraProcessing || form.processing ? t('cafeteria.processingScan') : cameraActive ? t('cafeteria.cameraScanning') : t('cafeteria.readyToScan')}
                            </span>
                        </div>
                        <div className="space-y-4 p-4">
                            <div className="flex gap-1 rounded-lg bg-[var(--app-surface-muted)] p-1" role="group" aria-label={t('cafeteria.scanCredential')}>
                                {(['qr', 'nfc'] as const).map((method) => <button key={method} type="button"
                                    disabled={cameraStarting || cameraProcessing || form.processing}
                                    onClick={async () => { if (method !== credentialMethod) { await stopCamera(); setCredentialMethod(method); } }}
                                    aria-pressed={credentialMethod === method}
                                    className={`${buttonCls} flex-1 ${credentialMethod === method ? 'bg-[var(--app-surface)] text-[var(--app-foreground)] shadow-sm' : mutedCls}`}>
                                    {method === 'qr' ? t('nfc.scanMethodQr') : t('nfc.scanMethodNfc')}
                                </button>)}
                            </div>
                            {credentialMethod === 'nfc' ? <NfcTapPanel disabled={!form.data.provider_id} onCredential={submitNfcCredential} /> : <>
                                <div className="relative overflow-hidden rounded-lg bg-slate-950">
                                    {/* The decoder uses video element dimensions: never crop or force its height. */}
                                    <div id={scannerRegionId} className="mx-auto min-h-[240px] w-full max-w-[340px] [&_video]:h-auto [&_video]:w-full" />
                                    {!cameraActive && <div className="absolute inset-0 flex flex-col items-center justify-center gap-5 px-6 text-center">
                                        <svg aria-hidden="true" className="h-16 w-16 text-slate-400" fill="none" viewBox="0 0 64 64" stroke="currentColor" strokeWidth="2">
                                            <path d="M20 6H6v14M44 6h14v14M6 44v14h14M58 44v14H44" />
                                            <path d="M20 20h8v8h-8zM36 20h8v8h-8zM20 36h8v8h-8zM36 36h4v4h4v4h-8z" />
                                        </svg>
                                        <p className="max-w-xs text-sm leading-6 text-slate-300">{cameraProcessing ? t('cafeteria.processingScan') : t('cafeteria.cameraReady')}</p>
                                    </div>}
                                </div>
                                {cameraError && <p role="alert" className="rounded-lg border border-red-200 bg-red-50 p-3 text-xs leading-5 text-red-700 dark:border-red-900/60 dark:bg-red-950/30 dark:text-red-300">{cameraError}</p>}
                                <div className="flex flex-wrap gap-2">
                                    <button type="button" onClick={() => void startCamera()}
                                        disabled={!form.data.provider_id || cameraStarting || cameraActive || cameraProcessing || form.processing}
                                        className={`${buttonCls} flex-1 bg-[var(--app-foreground)] text-[var(--app-surface)] hover:opacity-90`}>
                                        {cameraStarting || cameraActive ? t('cafeteria.cameraScanning') : t('cafeteria.startCamera')}
                                    </button>
                                    <button type="button" onClick={() => void stopCamera()} disabled={!cameraActive}
                                        className={`${buttonCls} border border-[var(--app-border-strong)] hover:bg-[var(--app-surface-muted)]`}>{t('cafeteria.stopCamera')}</button>
                                </div>
                                <form onSubmit={submit} className="space-y-2 border-t border-[var(--app-border)] pt-4">
                                    <label htmlFor="scan-token" className="block text-xs font-medium">{t('cafeteria.enterQrToken')}</label>
                                    <div className="flex flex-wrap gap-2">
                                        <input id="scan-token" className={`${inputCls} min-w-0 flex-1 basis-40`} placeholder="xxxx-xxxx|token"
                                            autoComplete="off" spellCheck={false} aria-invalid={!!form.errors.qr_token}
                                            value={form.data.qr_token} onChange={(e) => form.setData('qr_token', e.target.value)} />
                                    </div>
                                    {form.errors.qr_token && <p role="alert" className="text-xs text-red-600 dark:text-red-300">{form.errors.qr_token}</p>}
                                </form>
                            </>}
                        </div>
                    </section>

                    <section aria-labelledby="result-heading" className={`${panelCls} xl:col-span-7`}>
                        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-[var(--app-border)] px-4 py-3">
                            <h2 id="result-heading" className="text-sm font-semibold">{t('cafeteria.scanResult')}</h2>
                            {scan_result && <span className={`text-xs ${mutedCls}`}>{t('cafeteria.latest')}</span>}
                        </div>
                        {scan_result ? <div className="space-y-5 p-4">
                            <div role="status" aria-live="polite" className={`rounded-lg border p-3 ${resultTone}`}>
                                <p className="text-sm font-semibold">{!scan_result.allowed ? t('cafeteria.scanDenied') : scan_result.is_extra_scan ? t('cafeteria.extraScanRecorded') : t('cafeteria.scanRecorded')}</p>
                                {!scan_result.allowed && <p className="mt-1 text-sm leading-6">{scan_result.denial_message || (denialKey ? t(`cafeteria.${denialKey}`) : scan_result.denial_reason)}</p>}
                                {countdown !== null && <p className="mt-1 text-xs">{t('cafeteria.scanAgainIn').replace('{{count}}', String(countdown))}</p>}
                            </div>
                            {employee && <div>
                                <div className="flex items-center gap-4">
                                    <UserAvatar key={employee.employee_number} src={employee.photo_url} name={employee.full_name} size={72} className="shrink-0 rounded-xl !bg-[var(--app-surface-muted)] !text-[var(--app-foreground)]" />
                                    <div className="min-w-0">
                                        <h3 className="break-words text-lg font-semibold leading-7">{employee.full_name}</h3>
                                        <p className={`mt-1 break-words text-sm ${mutedCls}`}>{employee.employee_number}</p>
                                    </div>
                                </div>
                                <dl className="mt-4 grid gap-x-6 gap-y-3 sm:grid-cols-2">
                                    {[
                                        [t('cafeteria.position'), employee.position],
                                        [t('cafeteria.organization'), employee.organization],
                                        [t('cafeteria.department'), employee.organization_unit],
                                        [t('cafeteria.cardNo'), scan_result.card_number],
                                    ].filter(([, value]) => value).map(([label, value]) => <div key={label} className="min-w-0">
                                        <dt className={`text-xs ${mutedCls}`}>{label}</dt>
                                        <dd className="mt-1 break-words text-sm">{value}</dd>
                                    </div>)}
                                </dl>
                            </div>}
                            {scan_result.allowed && scan_result.week_start && <div className="border-t border-[var(--app-border)] pt-4">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <h3 className="text-sm font-medium">{t('cafeteria.weeklyWindowTitle')}</h3>
                                    <p className={`text-xs ${mutedCls}`}><LocalizedDateDisplay value={scan_result.week_start} /> – <LocalizedDateDisplay value={scan_result.week_end} /></p>
                                </div>
                                <dl className="mt-4 grid grid-cols-2 gap-x-5 gap-y-4">
                                    {[
                                        [t('cafeteria.subsidyApplied'), scan_result.subsidy_applied?.toFixed(2)],
                                        [t('cafeteria.employeePayableAmount'), scan_result.employee_payable?.toFixed(2)],
                                        [t('cafeteria.consumedDays'), scan_result.consumed_days_count != null && scan_result.available_days_count != null ? `${scan_result.consumed_days_count} / ${scan_result.available_days_count}` : null],
                                        [t('cafeteria.remainingWeekBalance'), scan_result.remaining_after?.toFixed(2)],
                                    ].map(([label, value]) => <div key={label}>
                                        <dt className={`text-xs leading-5 ${mutedCls}`}>{label}</dt>
                                        <dd className="mt-1 text-xl font-semibold tabular-nums">{value ?? '—'}</dd>
                                    </div>)}
                                </dl>
                            </div>}
                        </div> : <div className="flex min-h-[280px] flex-col items-center justify-center gap-3 p-6 text-center xl:min-h-[450px]">
                            <UserIcon className={`h-10 w-10 ${mutedCls}`} />
                            <h3 className="text-sm font-medium">{t('cafeteria.readyToScan')}</h3>
                            <p className={`max-w-xs text-sm leading-6 ${mutedCls}`}>{t('cafeteria.scanToSeeEmployee')}</p>
                        </div>}
                    </section>

                    <section aria-labelledby="history-heading" className={`${panelCls} overflow-hidden xl:col-span-8`}>
                        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-[var(--app-border)] px-4 py-3">
                            <h2 id="history-heading" className="text-sm font-semibold">{t('cafeteria.todayScans')}</h2>
                            {historyState === 'ready' && <span className={`text-xs ${mutedCls}`}>{t('cafeteria.latest')} · {todayScans.length}</span>}
                        </div>
                        {historyState !== 'ready' ? <p role="status" className={`px-4 py-10 text-center text-sm ${mutedCls}`}>{t(historyState === 'loading' ? 'common.loading' : 'cafeteria.historyUnavailable')}</p> : todayScans.length === 0 ? <p className={`px-4 py-10 text-center text-sm ${mutedCls}`}>{t('cafeteria.noScansForProviderToday')}</p> :
                            <ul className="max-h-[440px] divide-y divide-[var(--app-border)] overflow-y-auto">
                                {todayScans.map((scan) => <li key={scan.id} className="grid gap-2 px-4 py-3 sm:grid-cols-[minmax(0,1fr)_auto]">
                                    <div className="flex min-w-0 items-start gap-3">
                                        <UserAvatar src={scan.employee?.photo_url} name={scan.employee?.display_name ?? '?'} size={32} className="mt-0.5 !bg-[var(--app-surface-muted)] !text-[var(--app-foreground)]" />
                                        <div className="min-w-0">
                                            <p className="break-words text-sm font-medium">{scan.employee?.display_name ?? t('cafeteria.employeeName')}</p>
                                            <p className={`mt-0.5 break-words text-xs ${mutedCls}`}>{[scan.employee?.employee_number, scan.employee?.organization_name].filter(Boolean).join(' · ')}</p>
                                            <p className={`mt-0.5 break-words text-xs ${mutedCls}`}>{[scan.employee?.organization_unit_name, scan.employee?.position_title].filter(Boolean).join(' · ')}</p>
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-2 sm:flex-col sm:items-end">
                                        <span className={`inline-flex rounded-md px-2 py-1 text-xs font-medium ${scan.status === 'accepted' ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300' : scan.status === 'rejected' ? 'bg-red-50 text-red-800 dark:bg-red-950/40 dark:text-red-300' : 'bg-[var(--app-surface-muted)] text-[var(--app-muted-foreground)]'}`}>
                                            {scan.status === 'accepted' ? t('cafeteria.statusAccepted') : scan.status === 'rejected' ? t('cafeteria.statusDenied') : scan.status === 'reversed' ? t('cafeteria.statusReversed') : scan.status === 'pending_review' ? t('cafeteria.statusPendingReview') : '—'}
                                        </span>
                                        <span className={`text-xs tabular-nums ${mutedCls}`}>{scan.scanned_at ? formatTime(scan.scanned_at, locale) : '—'}</span>
                                    </div>
                                    <div className={`flex flex-wrap gap-x-4 gap-y-1 text-xs sm:col-span-2 sm:pl-11 ${mutedCls}`}>
                                        <span>{scan.usage_mode === 'use_remaining_week' ? t('cafeteria.usageModeRemainingWeek') : t('cafeteria.usageModeSingleDay')}</span>
                                        <span>{t('cafeteria.subsidyApplied')}: <span className="tabular-nums">{scan.subsidy_amount_applied.toFixed(2)}</span></span>
                                        <span>{t('cafeteria.consumedDays')}: {scan.consumed_days_count}</span>
                                        {scan.is_extra_scan && <span className="text-amber-700 dark:text-amber-300">{t('cafeteria.extraScanBadge')}</span>}
                                    </div>
                                </li>)}
                            </ul>}
                    </section>
                    <section aria-label={t('cafeteria.serviceCalendar')} className="min-w-0 xl:col-span-4">
                        {calendarState !== 'ready' ? <p role="status" className={`${panelCls} p-4 text-sm ${mutedCls}`}>{t(calendarState === 'loading' ? 'common.loading' : 'cafeteria.calendarUnavailable')}</p> : <>
                        <div className={`${panelCls} overflow-hidden`}>

                            {/* Calendar header */}
                            <div className="border-b border-gray-100 bg-gray-50/70 px-4 py-3 dark:border-slate-800 dark:bg-slate-800/40">
                                <div className="flex items-center justify-between">
                                    <button
                                        type="button"
                                        onClick={prevMonth}
                                        className="flex h-8 w-8 items-center justify-center rounded-lg text-gray-500 transition hover:bg-white hover:shadow-sm dark:text-slate-400 dark:hover:bg-slate-700"
                                        aria-label={t('cafeteria.previousMonth')}
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
                                        aria-label={t('cafeteria.nextMonth')}
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
                                        <div key={d} className="py-1.5 text-center text-[11px] font-semibold text-gray-400 dark:text-slate-500">
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
                                            cellCls = 'bg-emerald-700 text-white font-semibold';
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
                                                    ${isToday ? 'ring-2 ring-[color:var(--color-primary)] ring-offset-1 dark:ring-offset-slate-900' : ''}
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
                        </>}
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
