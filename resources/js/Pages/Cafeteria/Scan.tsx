import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import UserAvatar from '@/Components/UserAvatar';
import NfcTapPanel from '@/Components/Cafeteria/NfcTapPanel';
import { AlertTriangle, CalendarIcon, CameraIcon, CheckCircle, ChevronLeft, ChevronRight, QrCodeIcon, UserIcon, XCircle } from '@/Components/Icons';
import {
    type OutcomeTone, type ScanOutcome, denialText, newScanNonce, outcomeTone, rememberScanView, shouldOpenMobileScanner, useQrCamera, useRestartCountdown,
} from '@/Components/Cafeteria/scanTerminal';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEvent, type ReactNode, useCallback, useEffect, useRef, useState } from 'react';
import type { Html5QrcodeCameraScanConfig } from 'html5-qrcode';
import { Button, Card, EmptyState, FormField, Input, Select, StatusBadge, cx } from '@euisis/ui';
import { useLocale } from '@/hooks/useLocale';
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

type ScanResult = ScanOutcome & {
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

type LoadState = 'loading' | 'ready' | 'error';
type ScanHistoryEntry = { ts: string; isExtra: boolean; coveredDates: string[] };

const cameraScanConfig: Html5QrcodeCameraScanConfig = {
    fps: 10,
    qrbox: (viewfinderWidth: number, viewfinderHeight: number) => {
        const edge = Math.floor(Math.min(viewfinderWidth, viewfinderHeight) * 0.72);
        return { width: edge, height: edge };
    },
    aspectRatio: 1,
    disableFlip: false,
};

const SCAN_HISTORY_KEY = 'cafeteria_scan_history';

const OUTCOME_BANNER: Record<OutcomeTone, string> = {
    allowed: 'bg-emerald-50 text-emerald-900 ring-emerald-600/20 dark:bg-emerald-950/40 dark:text-emerald-100 dark:ring-emerald-400/25',
    extra: 'bg-amber-50 text-amber-900 ring-amber-600/25 dark:bg-amber-950/40 dark:text-amber-100 dark:ring-amber-400/25',
    denied: 'bg-red-50 text-red-900 ring-red-600/20 dark:bg-red-950/40 dark:text-red-100 dark:ring-red-400/25',
};

const SCAN_STATUS: Record<string, { tone: 'success' | 'danger' | 'neutral' | 'warning'; key: string }> = {
    accepted: { tone: 'success', key: 'cafeteria.statusAccepted' },
    rejected: { tone: 'danger', key: 'cafeteria.statusDenied' },
    reversed: { tone: 'neutral', key: 'cafeteria.statusReversed' },
    pending_review: { tone: 'warning', key: 'cafeteria.statusPendingReview' },
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

function loadScanHistory(): ScanHistoryEntry[] {
    try {
        const raw = sessionStorage.getItem(SCAN_HISTORY_KEY);
        if (!raw) return [];
        const parsed = JSON.parse(raw) as { ts: string; isExtra: boolean; coveredDates?: string[] }[];
        // Drop entries older than 30 days.
        const cutoff = new Date();
        cutoff.setDate(cutoff.getDate() - 30);
        const cutoffStr = cutoff.toISOString().slice(0, 10);
        return parsed
            .filter((e) => e.ts.slice(0, 10) >= cutoffStr)
            .map((e) => ({ ...e, coveredDates: e.coveredDates ?? [e.ts.slice(0, 10)] }));
    } catch {
        return [];
    }
}

const muted = 'text-[color:var(--app-muted-foreground)]';

function PanelHeader({ id, title, aside }: { id: string; title: string; aside?: ReactNode }) {
    return (
        <div className="flex min-h-[3.25rem] flex-wrap items-center justify-between gap-2 border-b border-[color:var(--app-border)] px-4 py-2.5">
            <h2 id={id} className="text-sm font-semibold text-[color:var(--app-foreground)]">{title}</h2>
            {aside}
        </div>
    );
}

// ─── Page ────────────────────────────────────────────────────────────────────

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
    const prevScanResultRef = useRef<ScanResult | null | undefined>(undefined);

    const [credentialMethod, setCredentialMethod] = useState<'qr' | 'nfc'>('qr');
    const [cameraProcessing, setCameraProcessing] = useState(false);
    const [todayScans, setTodayScans] = useState<TodayScan[]>(today_scans ?? []);
    const [historyState, setHistoryState] = useState<LoadState>('ready');
    const [calendarState, setCalendarState] = useState<LoadState>('ready');
    const [calendarMeta, setCalendarMeta] = useState<CalendarDay[]>(scan_result?.calendar_days ?? calendar_days ?? []);
    const [scanHistory, setScanHistory] = useState<ScanHistoryEntry[]>(loadScanHistory);

    const now = new Date();
    const todayDateStr = isoDate(now.getFullYear(), now.getMonth(), now.getDate());

    useEffect(() => {
        try {
            sessionStorage.setItem(SCAN_HISTORY_KEY, JSON.stringify(scanHistory));
        } catch { }
    }, [scanHistory]);

    const form = useForm({
        provider_id: providers[0]?.id ?? '',
        qr_token:    '',
        scan_nonce:  newScanNonce(),
        usage_mode:  scanOptions?.default_usage_mode ?? 'single_day',
    });

    // Camera and NFC callbacks outlive renders; they read the current selection from here.
    const latest = useRef(form.data);
    useEffect(() => { latest.current = form.data; }, [form.data]);

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

    // A QR token and an NFC reference go to the same endpoint, so the server
    // applies one identical set of cafeteria rules to both.
    const postCredential = useCallback((credential: { qr_token: string } | { nfc_credential: string }) => {
        const { provider_id, scan_nonce, usage_mode } = latest.current;
        setCameraProcessing(true);
        if ('qr_token' in credential) form.setData('qr_token', credential.qr_token);
        router.post(route('cafeteria.scan.process'), { ...credential, provider_id, scan_nonce, usage_mode }, {
            preserveScroll: true,
            onFinish: () => {
                setCameraProcessing(false);
                form.setData('scan_nonce', newScanNonce());
            },
        });
        // form.setData reads the form's own latest data, so it is safe to capture.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const camera = useQrCamera(cameraScanConfig, (text) => postCredential({ qr_token: text.trim() }));
    const { start: startCameraSession, stop: stopCamera, fail: cameraFail } = camera;

    const startCamera = useCallback(() => {
        if (!latest.current.provider_id) {
            cameraFail(t('cafeteria.selectProviderFirst'));
            return;
        }
        void startCameraSession();
    }, [cameraFail, startCameraSession, t]);

    function submit(e: FormEvent) {
        e.preventDefault();

        // Manual entry also submits on Enter, so the guards live here rather than
        // only on the submit button's disabled state.
        if (!form.data.provider_id || !form.data.qr_token.trim() || form.processing || cameraProcessing) {
            return;
        }

        form.post(route('cafeteria.scan.process'), {
            preserveScroll: true,
            onFinish: () => form.setData('scan_nonce', newScanNonce()),
        });
    }

    // Phones and tablets get the full-screen scanner. This must be a real visit so the
    // server renders that page; a client-side replace would only rewrite the URL.
    useEffect(() => {
        if (shouldOpenMobileScanner()) router.visit(route('cafeteria.scan.mobile'), { replace: true });
    }, []);

    const cameraBusy = camera.active || camera.starting;
    const busy = cameraProcessing || form.processing;
    const countdown = useRestartCountdown(scan_result, credentialMethod === 'qr' && !cameraProcessing && !cameraBusy, startCamera);

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

    async function changeMethod(method: 'qr' | 'nfc') {
        if (method === credentialMethod) return;
        await stopCamera();
        setCredentialMethod(method);
    }

    const scannedDateMap = scanHistory.reduce<Map<string, number>>((acc, { coveredDates }) => {
        for (const d of coveredDates) {
            acc.set(d, (acc.get(d) ?? 0) + 1);
        }
        return acc;
    }, new Map());

    const captureStatus = busy
        ? { tone: 'info' as const, label: t('cafeteria.processing') }
        : camera.active
            ? { tone: 'success' as const, label: t('cafeteria.cameraScanning') }
            : { tone: 'neutral' as const, label: t('cafeteria.readyToScan') };

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('cafeteria.scanTerminal')}
                    description={t('cafeteria.scanTerminalDescription')}
                    actions={
                        <Button as={Link} href={route('cafeteria.scan.mobile')} onClick={() => rememberScanView('mobile')} variant="outline"
                            icon={<QrCodeIcon className="h-4 w-4" />}>
                            {t('cafeteria.mobileScanner')}
                        </Button>
                    }
                />
            }
        >
            <Head title={t('cafeteria.scanTerminal')} />
            <div data-cafeteria-terminal className="space-y-4 text-[color:var(--app-foreground)]">
                <Card className="p-0" aria-label={t('cafeteria.selectedProvider')}>
                    <div className="grid gap-4 p-4 sm:grid-cols-2 xl:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_auto] xl:items-end">
                        <FormField id="scan-provider" label={t('cafeteria.selectProvider')} error={form.errors.provider_id}>
                            {({ id, describedBy, invalid }) => (
                                <Select id={id} aria-describedby={describedBy} aria-invalid={invalid} value={form.data.provider_id} required
                                    onChange={(e) => form.setData('provider_id', e.target.value)}
                                    disabled={provider_locked || cameraBusy || busy}>
                                    {providers.length === 0 && <option value="">{t('cafeteria.noProvidersAvailable')}</option>}
                                    {providers.map((provider) => <option key={provider.id} value={provider.id}>{locale === 'am' && provider.name_am ? provider.name_am : provider.name_en} ({provider.code})</option>)}
                                </Select>
                            )}
                        </FormField>
                        <FormField id="scan-usage" label={t('cafeteria.usageModeLabel')} error={form.errors.usage_mode}>
                            {({ id, describedBy, invalid }) => (
                                <Select id={id} aria-describedby={describedBy} aria-invalid={invalid} value={form.data.usage_mode} disabled={busy}
                                    onChange={(e) => form.setData('usage_mode', e.target.value)}>
                                    <option value="single_day">{t('cafeteria.usageModeSingleDay')}</option>
                                    <option value="use_remaining_week" disabled={scanOptions?.allow_upfront_weekday_usage === false}>{t('cafeteria.usageModeRemainingWeek')}</option>
                                </Select>
                            )}
                        </FormField>
                        <p className={cx('flex h-[var(--control-h-md)] items-center gap-2 text-sm sm:col-span-2 xl:col-span-1', muted)}>
                            <CalendarIcon className="h-4 w-4 shrink-0" aria-hidden="true" />
                            <LocalizedDateDisplay value={todayDateStr} />
                        </p>
                    </div>
                    {selectedProvider && (
                        <details className="group border-t border-[color:var(--app-border)] px-4 py-2.5">
                            <summary className={cx('flex cursor-pointer list-none items-center gap-1.5 break-words text-xs leading-5 hover:text-[color:var(--app-foreground)] focus-visible:outline-2 focus-visible:outline-[color:var(--color-primary)] [&::-webkit-details-marker]:hidden', muted)}>
                                <ChevronRight className="h-3.5 w-3.5 shrink-0 transition-transform group-open:rotate-90" aria-hidden="true" />
                                {t('cafeteria.providerDetails')} · {selectedOrganizationName || selectedProviderName}
                            </summary>
                            {provider_locked && <p className={cx('mt-2 text-xs', muted)}>{t('cafeteria.providerScopedNotice')}</p>}
                            <dl className="mt-3 grid gap-x-6 gap-y-3 pb-1 sm:grid-cols-2 xl:grid-cols-3">
                                {selectedProviderDetails.map((detail) => <div key={detail.label} className="min-w-0 text-xs">
                                    <dt className={muted}>{detail.label}</dt><dd className="mt-0.5 break-words font-medium">{detail.value}</dd>
                                </div>)}
                            </dl>
                        </details>
                    )}
                </Card>

                <div className="grid grid-cols-1 items-start gap-4 md:grid-cols-2 xl:grid-cols-12">
                    <Card role="region" aria-labelledby="capture-heading" className="min-w-0 overflow-hidden p-0 xl:col-span-5">
                        <PanelHeader id="capture-heading" title={t('cafeteria.scanCredential')}
                            aside={<span aria-live="polite"><StatusBadge tone={captureStatus.tone}>{captureStatus.label}</StatusBadge></span>} />
                        <div className="space-y-4 p-4">
                            <div className="flex gap-1 rounded-[var(--radius-control)] bg-[color:var(--app-surface-muted)] p-1" role="group" aria-label={t('cafeteria.scanCredential')}>
                                {(['qr', 'nfc'] as const).map((method) => (
                                    <button key={method} type="button" disabled={camera.starting || busy} aria-pressed={credentialMethod === method}
                                        onClick={() => void changeMethod(method)}
                                        className={cx('h-9 flex-1 rounded-[calc(var(--radius-control)-2px)] text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)] disabled:opacity-60',
                                            credentialMethod === method ? 'bg-[color:var(--app-surface)] text-[color:var(--app-foreground)] shadow-sm' : cx(muted, 'hover:text-[color:var(--app-foreground)]'))}>
                                        {method === 'qr' ? t('nfc.scanMethodQr') : t('nfc.scanMethodNfc')}
                                    </button>
                                ))}
                            </div>

                            {credentialMethod === 'nfc' ? <NfcTapPanel disabled={!form.data.provider_id} onCredential={(credential) => postCredential({ nfc_credential: credential })} /> : <>
                                <div className="relative overflow-hidden rounded-[var(--radius-card)] bg-slate-950">
                                    {/* The decoder uses video element dimensions: never crop or force its height. */}
                                    <div id={camera.regionId} className="mx-auto min-h-[240px] w-full max-w-[340px] [&_video]:h-auto [&_video]:w-full" />
                                    {!camera.active && (
                                        <div className="absolute inset-0 flex flex-col items-center justify-center gap-4 px-6 text-center">
                                            {camera.starting || busy
                                                ? <span aria-hidden="true" className="h-9 w-9 animate-spin rounded-full border-4 border-white/20 border-t-white" />
                                                : <span className="flex h-14 w-14 items-center justify-center rounded-full bg-white/10 text-slate-300"><QrCodeIcon className="h-7 w-7" aria-hidden="true" /></span>}
                                            <p className="max-w-xs text-sm leading-6 text-slate-300">
                                                {busy ? t('cafeteria.processingScan') : camera.starting ? t('cafeteria.startingCamera') : t('cafeteria.cameraReady')}
                                            </p>
                                        </div>
                                    )}
                                </div>
                                {camera.error && (
                                    <p role="alert" className="flex gap-2 rounded-[var(--radius-control)] bg-red-50 p-3 text-sm leading-5 text-red-800 ring-1 ring-inset ring-red-600/20 dark:bg-red-950/40 dark:text-red-200 dark:ring-red-400/25">
                                        <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />{camera.error}
                                    </p>
                                )}
                                <div className="flex flex-wrap gap-2">
                                    <Button variant="primary" onClick={startCamera} loading={camera.starting} icon={<CameraIcon className="h-4 w-4" />}
                                        disabled={!form.data.provider_id || cameraBusy || busy} className="flex-1">
                                        {camera.active ? t('cafeteria.cameraScanning') : t('cafeteria.startCamera')}
                                    </Button>
                                    <Button variant="outline" onClick={() => void stopCamera()} disabled={!camera.active}>{t('cafeteria.stopCamera')}</Button>
                                </div>
                                <form onSubmit={submit} className="border-t border-[color:var(--app-border)] pt-4">
                                    <FormField id="scan-token" label={t('cafeteria.enterQrToken')} error={form.errors.qr_token}>
                                        {({ id, describedBy, invalid }) => (
                                            <div className="flex gap-2">
                                                <Input id={id} aria-describedby={describedBy} aria-invalid={invalid} className="min-w-0 flex-1 font-mono" placeholder="xxxx-xxxx|token"
                                                    autoComplete="off" spellCheck={false} value={form.data.qr_token} onChange={(e) => form.setData('qr_token', e.target.value)} />
                                                <Button type="submit" variant="outline" disabled={!form.data.provider_id || !form.data.qr_token.trim() || busy}>{t('cafeteria.processScan')}</Button>
                                            </div>
                                        )}
                                    </FormField>
                                </form>
                            </>}
                        </div>
                    </Card>

                    <Card role="region" aria-labelledby="result-heading" className="min-w-0 overflow-hidden p-0 xl:col-span-7">
                        <PanelHeader id="result-heading" title={t('cafeteria.scanResult')}
                            aside={countdown !== null ? <span className={cx('text-xs tabular-nums', muted)}>{t('cafeteria.scanAgainIn').replace('{{count}}', String(countdown))}</span> : undefined} />
                        {scan_result
                            ? <LatestResult result={scan_result} />
                            : <EmptyState className="flex min-h-[280px] flex-col items-center justify-center xl:min-h-[440px]" icon={<UserIcon className="h-10 w-10" />}
                                title={t('cafeteria.readyToScan')} description={t('cafeteria.scanToSeeEmployee')} />}
                    </Card>

                    <Card role="region" aria-labelledby="history-heading" className="min-w-0 overflow-hidden p-0 xl:col-span-8">
                        <PanelHeader id="history-heading" title={t('cafeteria.todayScans')}
                            aside={historyState === 'ready' ? <span className={cx('text-xs tabular-nums', muted)}>{todayScans.length}</span> : undefined} />
                        <TodayScanList scans={todayScans} state={historyState} />
                    </Card>

                    <section aria-label={t('cafeteria.serviceCalendar')} className="min-w-0 xl:col-span-4">
                        {calendarState !== 'ready'
                            ? <Card className={cx('p-4 text-sm', muted)}><p role="status">{t(calendarState === 'loading' ? 'common.loading' : 'cafeteria.calendarUnavailable')}</p></Card>
                            : <ServiceCalendar meta={calendarMeta} scannedDates={scannedDateMap} today={todayDateStr} />}
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

// ─── Latest result ───────────────────────────────────────────────────────────

function LatestResult({ result }: { result: ScanResult }) {
    const { t } = useLocale();
    const tone = outcomeTone(result);
    const Icon = tone === 'denied' ? XCircle : tone === 'extra' ? AlertTriangle : CheckCircle;
    const employee = result.employee;

    return (
        <div className="space-y-5 p-4">
            <div role="status" aria-live="polite" className={cx('flex items-start gap-3 rounded-[var(--radius-card)] p-4 ring-1 ring-inset', OUTCOME_BANNER[tone])}>
                <Icon className="mt-0.5 h-6 w-6 shrink-0" aria-hidden="true" />
                <div className="min-w-0">
                    <p className="text-base font-semibold">{tone === 'denied' ? t('cafeteria.statusDenied') : tone === 'extra' ? t('cafeteria.statusExtraScan') : t('cafeteria.statusAllowed')}</p>
                    <p className="mt-0.5 text-sm leading-6">{tone === 'denied' ? denialText(result, t) : tone === 'extra' ? t('cafeteria.extraScanRecorded') : t('cafeteria.scanRecorded')}</p>
                </div>
            </div>

            {employee && (
                <div>
                    <div className="flex items-center gap-4">
                        <UserAvatar key={employee.employee_number} src={employee.photo_url} name={employee.full_name} size={64}
                            className="shrink-0 rounded-xl !bg-[color:var(--app-surface-muted)] !text-[color:var(--app-foreground)]" />
                        <div className="min-w-0">
                            <h3 className="break-words text-lg font-semibold leading-7">{employee.full_name}</h3>
                            <p className={cx('mt-0.5 break-words font-mono text-sm', muted)}>{employee.employee_number}</p>
                        </div>
                    </div>
                    <dl className="mt-4 grid gap-x-6 gap-y-3 sm:grid-cols-2">
                        {[
                            [t('cafeteria.position'), employee.position],
                            [t('cafeteria.organization'), employee.organization],
                            [t('cafeteria.department'), employee.organization_unit],
                            [t('cafeteria.cardNo'), result.card_number],
                        ].filter(([, value]) => value).map(([label, value]) => <div key={label} className="min-w-0">
                            <dt className={cx('text-xs', muted)}>{label}</dt>
                            <dd className="mt-0.5 break-words text-sm">{value}</dd>
                        </div>)}
                    </dl>
                </div>
            )}

            {result.allowed && result.week_start && (
                <div className="border-t border-[color:var(--app-border)] pt-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h3 className="text-sm font-semibold">{t('cafeteria.weeklyWindowTitle')}</h3>
                        <p className={cx('text-xs', muted)}><LocalizedDateDisplay value={result.week_start} /> – <LocalizedDateDisplay value={result.week_end} /></p>
                    </div>
                    <dl className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                        {[
                            [t('cafeteria.subsidyApplied'), result.subsidy_applied?.toFixed(2)],
                            [t('cafeteria.employeePayableAmount'), result.employee_payable?.toFixed(2)],
                            [t('cafeteria.consumedDays'), result.consumed_days_count != null && result.available_days_count != null ? `${result.consumed_days_count} / ${result.available_days_count}` : null],
                            [t('cafeteria.remainingWeekBalance'), result.remaining_after?.toFixed(2)],
                        ].map(([label, value]) => <div key={label} className="rounded-[var(--radius-control)] bg-[color:var(--app-surface-muted)] px-3 py-2.5">
                            <dt className={cx('text-xs leading-5', muted)}>{label}</dt>
                            <dd className="mt-0.5 text-lg font-semibold tabular-nums">{value ?? '—'}</dd>
                        </div>)}
                    </dl>
                </div>
            )}
        </div>
    );
}

// ─── Today's scans ───────────────────────────────────────────────────────────

function TodayScanList({ scans, state }: { scans: TodayScan[]; state: LoadState }) {
    const { t, locale } = useLocale();

    if (state !== 'ready') {
        return <p role="status" className={cx('px-4 py-10 text-center text-sm', muted)}>{t(state === 'loading' ? 'common.loading' : 'cafeteria.historyUnavailable')}</p>;
    }
    if (scans.length === 0) {
        return <EmptyState icon={<QrCodeIcon className="h-8 w-8" />} title={t('cafeteria.noScansForProviderToday')} />;
    }

    return (
        <ul className="max-h-[440px] divide-y divide-[color:var(--app-border)] overflow-y-auto">
            {scans.map((scan) => {
                const status = SCAN_STATUS[scan.status ?? ''];
                return (
                    <li key={scan.id} className="flex gap-3 px-4 py-3">
                        <UserAvatar src={scan.employee?.photo_url} name={scan.employee?.display_name ?? '?'} size={36}
                            className="mt-0.5 shrink-0 !bg-[color:var(--app-surface-muted)] !text-[color:var(--app-foreground)]" />
                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                                <p className="min-w-0 break-words text-sm font-medium">{scan.employee?.display_name ?? t('cafeteria.employeeName')}</p>
                                <span className={cx('shrink-0 text-xs tabular-nums', muted)}>{scan.scanned_at ? formatTime(scan.scanned_at, locale) : '—'}</span>
                            </div>
                            <p className={cx('mt-0.5 break-words text-xs', muted)}>
                                {[scan.employee?.employee_number, scan.employee?.organization_unit_name ?? scan.employee?.organization_name, scan.employee?.position_title].filter(Boolean).join(' · ')}
                            </p>
                            <div className={cx('mt-2 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-xs', muted)}>
                                <StatusBadge tone={status?.tone ?? 'neutral'}>{status ? t(status.key) : '—'}</StatusBadge>
                                {scan.is_extra_scan && <StatusBadge tone="warning">{t('cafeteria.extraScanBadge')}</StatusBadge>}
                                <span>{scan.usage_mode === 'use_remaining_week' ? t('cafeteria.usageModeRemainingWeek') : t('cafeteria.usageModeSingleDay')}</span>
                                <span>{t('cafeteria.subsidyApplied')}: <span className="tabular-nums text-[color:var(--app-foreground)]">{scan.subsidy_amount_applied.toFixed(2)}</span></span>
                                <span>{t('cafeteria.consumedDays')}: <span className="tabular-nums text-[color:var(--app-foreground)]">{scan.consumed_days_count}</span></span>
                            </div>
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}

// ─── Service calendar ────────────────────────────────────────────────────────

function ServiceCalendar({ meta, scannedDates, today }: { meta: CalendarDay[]; scannedDates: Map<string, number>; today: string }) {
    const { t, locale } = useLocale();
    const isEthiopian = useCalendarSystem() === 'ethiopian';
    const [view, setView] = useState(() => currentMonth(isEthiopian));

    // Reset the view when the calendar system changes (e.g. locale switch).
    useEffect(() => { setView(currentMonth(isEthiopian)); }, [isEthiopian]);

    const { year, month } = view;
    const firstMonth = isEthiopian ? 1 : 0;
    const lastMonth = isEthiopian ? 13 : 11;
    const shift = (step: 1 | -1) => setView(({ year: y, month: m }) => {
        if (step === -1 && m === firstMonth) return { year: y - 1, month: lastMonth };
        if (step === 1 && m === lastMonth) return { year: y + 1, month: firstMonth };
        return { year: y, month: m + step };
    });

    const dayLabels = isEthiopian
        ? ['እሁ', 'ሰኞ', 'ማክ', 'ረቡ', 'ሐሙ', 'ዓር', 'ቅዳ']
        : ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
    const cells = buildCalendarCells(year, month, isEthiopian);
    const navButton = cx('flex h-8 w-8 items-center justify-center rounded-[var(--radius-control)] transition-colors hover:bg-[color:var(--app-surface-muted)] hover:text-[color:var(--app-foreground)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)]', muted);

    return (
        <Card className="overflow-hidden p-0">
            <div className="flex items-center justify-between gap-2 border-b border-[color:var(--app-border)] px-3 py-2.5">
                <button type="button" onClick={() => shift(-1)} className={navButton} aria-label={t('cafeteria.previousMonth')}>
                    <ChevronLeft className="h-4 w-4" aria-hidden="true" />
                </button>
                <div className="text-center">
                    <p className="text-sm font-semibold">{getMonthLabel(year, month, locale, isEthiopian)}</p>
                    <p className={cx('text-[11px]', muted)}>
                        {isEthiopian
                            ? (locale === 'am' ? 'የኢትዮጵያ ቀን አቆጣጠር' : 'Ethiopian Calendar')
                            : (locale === 'am' ? 'ጎርጎሪያን ቀን አቆጣጠር' : 'Gregorian Calendar')}
                    </p>
                </div>
                <button type="button" onClick={() => shift(1)} className={navButton} aria-label={t('cafeteria.nextMonth')}>
                    <ChevronRight className="h-4 w-4" aria-hidden="true" />
                </button>
            </div>

            <div className="px-3 pb-3 pt-2">
                <div className="mb-1 grid grid-cols-7">
                    {dayLabels.map((d) => <div key={d} className={cx('py-1.5 text-center text-[11px] font-semibold', muted)}>{d}</div>)}
                </div>
                <div className="grid grid-cols-7">
                    {cells.map((cell, idx) => {
                        if (cell === null) return <div key={`empty-${idx}`} />;

                        const { day, gregorianIso: dateStr } = cell;
                        const isToday = dateStr === today;
                        const dayMeta = meta.find((item) => item.date === dateStr);
                        const scanCount = scannedDates.get(dateStr) ?? 0;
                        const isConsumed = dayMeta?.is_consumed || scanCount > 0;

                        let cellCls: string;
                        if (dayMeta?.is_consumed) {
                            cellCls = 'bg-emerald-700 text-white font-semibold';
                        } else if (dayMeta?.is_employee_excluded) {
                            cellCls = 'bg-red-100 text-red-700 dark:bg-red-950/50 dark:text-red-300';
                        } else if (dayMeta?.is_public_holiday || dayMeta?.reason_code === 'special_no_subsidy_day') {
                            cellCls = 'bg-amber-100 text-amber-800 dark:bg-amber-950/50 dark:text-amber-300';
                        } else if (dayMeta?.reason_code === 'special_open_day') {
                            cellCls = 'bg-purple-100 text-purple-700 dark:bg-purple-950/50 dark:text-purple-300';
                        } else if (dayMeta?.is_available) {
                            cellCls = 'bg-blue-50 text-blue-700 font-medium dark:bg-blue-950/40 dark:text-blue-300';
                        } else if (dayMeta !== undefined && !dayMeta.is_open) {
                            cellCls = 'bg-[color:var(--app-surface-muted)] text-[color:var(--app-muted-foreground)] opacity-70';
                        } else {
                            cellCls = 'text-[color:var(--app-foreground)]';
                        }

                        return (
                            <div
                                key={dateStr || `cell-${idx}`}
                                title={dayMeta?.label}
                                aria-current={isToday ? 'date' : undefined}
                                className={cx('relative mx-auto my-0.5 flex h-8 w-8 flex-col items-center justify-center rounded-[var(--radius-control)] text-[13px]',
                                    isToday && 'ring-2 ring-[color:var(--color-primary)] ring-offset-1 ring-offset-[color:var(--app-surface)]', cellCls)}
                            >
                                <span className="leading-none">{day}</span>
                                {isConsumed && (
                                    scanCount <= 1 ? (
                                        <CheckCircle className={cx('absolute -bottom-0.5 -right-0.5 h-3.5 w-3.5 rounded-full bg-[color:var(--app-surface)]', isToday ? 'text-emerald-500' : 'text-emerald-600')} />
                                    ) : (
                                        <span className="absolute -bottom-1 -right-1 flex h-4 w-4 items-center justify-center rounded-full bg-emerald-600 text-[9px] font-bold text-white">
                                            {scanCount}
                                        </span>
                                    )
                                )}
                            </div>
                        );
                    })}
                </div>
            </div>

            <div className="grid grid-cols-2 gap-x-3 gap-y-1.5 border-t border-[color:var(--app-border)] px-4 py-3">
                {[
                    ['bg-blue-50 ring-1 ring-inset ring-blue-200 dark:bg-blue-950/40 dark:ring-blue-800', t('cafeteria.calendar_available')],
                    ['bg-emerald-700', t('cafeteria.calendar_consumed')],
                    ['bg-[color:var(--app-surface-muted)] ring-1 ring-inset ring-[color:var(--app-border)]', t('cafeteria.calendar_closed')],
                    ['bg-amber-100 dark:bg-amber-950/50', t('cafeteria.calendar_public_holiday')],
                    ['bg-purple-100 dark:bg-purple-950/50', t('cafeteria.calendar_special_open_day')],
                    ['bg-red-100 dark:bg-red-950/50', t('cafeteria.calendar_employee_leave')],
                ].map(([color, label]) => (
                    <span key={label} className={cx('inline-flex items-center gap-1.5 text-[11px]', muted)}>
                        <span className={cx('h-2.5 w-2.5 shrink-0 rounded-sm', color)} aria-hidden="true" />
                        {label}
                    </span>
                ))}
            </div>
        </Card>
    );
}

function currentMonth(isEthiopian: boolean): { year: number; month: number } {
    const today = new Date();
    if (isEthiopian) {
        const eth = gregorianToEthiopian(today.getFullYear(), today.getMonth() + 1, today.getDate());
        return { year: eth.year, month: eth.month };
    }
    return { year: today.getFullYear(), month: today.getMonth() };
}
