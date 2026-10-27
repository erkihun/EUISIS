import { useCallback, useEffect, useId, useRef, useState } from 'react';
import { CameraDevice, Html5Qrcode, Html5QrcodeCameraScanConfig, Html5QrcodeSupportedFormats } from 'html5-qrcode';
import { useLocale } from '@/hooks/useLocale';

/** The part of a flashed scan result that every scan screen presents. */
export type ScanOutcome = {
    allowed: boolean;
    is_extra_scan: boolean;
    denial_reason: string | null;
    denial_message?: string | null;
};

export type OutcomeTone = 'allowed' | 'extra' | 'denied';

const DENIAL_REASON_KEY: Record<string, string> = {
    already_scanned_today: 'denialAlreadyScannedToday',
    wrong_institution: 'denialWrongInstitution',
    employee_on_leave: 'denialEmployeeOnLeave',
    card_inactive: 'denialCardInactive',
    card_expired: 'denialCardExpired',
    not_eligible: 'denialNotEligible',
    cafeteria_closed_weekend: 'denialCafeteriaClosedWeekend',
    cafeteria_closed: 'denialCafeteriaClosed',
    cafeteria_closed_holiday: 'denialCafeteriaClosedHoliday',
    no_subsidy_rule: 'denialNoSubsidyRule',
    no_available_subsidy: 'denialNoAvailableSubsidy',
    invalid_token_format: 'denialInvalidToken',
};

/** Seconds a result stays on screen before the camera starts again. */
export const RESTART_SECONDS = 3;

/**
 * The server requires a UUID. randomUUID exists only in secure contexts, so a
 * phone opening the terminal over plain HTTP on the LAN builds one itself.
 */
export function newScanNonce(): string {
    if (window.crypto?.randomUUID) return window.crypto.randomUUID();
    const bytes = window.crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

const VIEW_KEY = 'cafeteria_scan_view';

/** Phones and tablets open the mobile scanner unless the operator chose the full terminal in this tab. */
export function shouldOpenMobileScanner(): boolean {
    try {
        if (sessionStorage.getItem(VIEW_KEY) === 'full') return false;
    } catch {
        // Storage blocked: fall through to the device check.
    }
    return /Mobi|Android|iPhone|iPad|iPod/i.test(navigator.userAgent)
        || window.matchMedia('(max-width: 767px) and (pointer: coarse)').matches;
}

export function rememberScanView(view: 'full' | 'mobile'): void {
    try {
        if (view === 'full') sessionStorage.setItem(VIEW_KEY, 'full');
        else sessionStorage.removeItem(VIEW_KEY);
    } catch {
        // Storage blocked: the device check decides each time.
    }
}

export function outcomeTone(result: ScanOutcome): OutcomeTone {
    if (!result.allowed) return 'denied';
    return result.is_extra_scan ? 'extra' : 'allowed';
}

/** Why a scan was denied: the server's own message first, then the translated reason code. */
export function denialText(result: ScanOutcome, t: (key: string) => string): string {
    if (result.denial_message) return result.denial_message;
    const key = DENIAL_REASON_KEY[result.denial_reason ?? ''];
    return key ? t(`cafeteria.${key}`) : result.denial_reason ?? '';
}

export type QrCamera = {
    /** Id for the element the decoder renders its video into; it must stay mounted and visible. */
    regionId: string;
    active: boolean;
    starting: boolean;
    error: string | null;
    start: () => Promise<void>;
    stop: () => Promise<void>;
    /** Shows a reason the camera cannot start, such as a missing provider. */
    fail: (message: string) => void;
};

const DECODER_OPTIONS = { verbose: false, formatsToSupport: [Html5QrcodeSupportedFormats.QR_CODE] };

function preferredCamera(cameras: CameraDevice[]): CameraDevice | null {
    return cameras.find((camera) => /back|rear|environment/i.test(camera.label)) ?? cameras[0] ?? null;
}

/**
 * One camera session at a time. Each session delivers at most one decode and
 * stops itself as it does, so a card held in view cannot submit twice. A stop
 * during start-up abandons that start instead of racing it.
 */
export function useQrCamera(config: Html5QrcodeCameraScanConfig, onDecode: (text: string) => void): QrCamera {
    const { t } = useLocale();
    const regionId = useId().replace(/:/g, '');
    const scannerRef = useRef<Html5Qrcode | null>(null);
    const startingRef = useRef(false);
    const sessionRef = useRef(0);
    const onDecodeRef = useRef(onDecode);
    const [active, setActive] = useState(false);
    const [starting, setStarting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => { onDecodeRef.current = onDecode; });

    const describe = useCallback((reason: unknown): string => {
        if (!window.isSecureContext) return t('cafeteria.cameraRequiresSecureContext');
        if (!navigator.mediaDevices?.getUserMedia) return t('cafeteria.cameraNotSupported');
        if (reason instanceof DOMException) {
            if (reason.name === 'NotAllowedError' || reason.name === 'PermissionDeniedError') return t('cafeteria.cameraPermissionDenied');
            if (reason.name === 'NotFoundError' || reason.name === 'OverconstrainedError') return t('cafeteria.noCameraFound');
            if (reason.name === 'NotReadableError' || reason.name === 'TrackStartError') return t('cafeteria.cameraInUse');
            return reason.message || t('cafeteria.cameraUnavailable');
        }
        if (reason instanceof Error) return reason.message || t('cafeteria.cameraUnavailable');
        if (typeof reason === 'string' && reason.trim() !== '') return reason;
        return t('cafeteria.cameraUnavailable');
    }, [t]);

    const stop = useCallback(async (updateState = true) => {
        sessionRef.current += 1;
        const scanner = scannerRef.current;
        scannerRef.current = null;
        if (updateState) setActive(false);
        if (!scanner) return;
        try {
            if (scanner.isScanning) await scanner.stop();
            scanner.clear();
        } catch {
            // Already stopped or never started.
        }
    }, []);

    const start = useCallback(async () => {
        if (startingRef.current || scannerRef.current) return;
        const session = ++sessionRef.current;
        const abandoned = () => session !== sessionRef.current;
        startingRef.current = true;
        setStarting(true);
        setError(null);
        let handled = false;

        const onSuccess = (text: string) => {
            if (handled || abandoned()) return;
            handled = true;
            onDecodeRef.current(text);
            void stop();
        };

        try {
            if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) throw new Error();
            // Ask for the camera first: permission errors are specific here, where the decoder's are not.
            const probe = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
            probe.getTracks().forEach((track) => track.stop());
            if (abandoned()) return;

            let scanner = new Html5Qrcode(regionId, DECODER_OPTIONS);
            scannerRef.current = scanner;
            try {
                await scanner.start({ facingMode: 'environment' }, config, onSuccess, () => undefined);
            } catch {
                if (abandoned()) return;
                try { scanner.clear(); } catch { /* nothing rendered */ }
                const camera = preferredCamera(await Html5Qrcode.getCameras());
                if (!camera) throw new Error(t('cafeteria.noCameraFound'));
                if (abandoned()) return;
                scanner = new Html5Qrcode(regionId, DECODER_OPTIONS);
                scannerRef.current = scanner;
                await scanner.start(camera.id, config, onSuccess, () => undefined);
            }

            if (abandoned()) {
                try { await scanner.stop(); scanner.clear(); } catch { /* already stopped */ }
                return;
            }
            setActive(true);
        } catch (reason) {
            if (abandoned()) return;
            try { scannerRef.current?.clear(); } catch { /* nothing rendered */ }
            scannerRef.current = null;
            setActive(false);
            setError(describe(reason));
        } finally {
            startingRef.current = false;
            setStarting(false);
        }
    }, [config, describe, regionId, stop, t]);

    useEffect(() => () => { void stop(false); }, [stop]);

    return { regionId, active, starting, error, start, stop, fail: setError };
}

/**
 * Counts down once per scan result, then calls onDone. A result whose count was
 * interrupted (the operator started or stopped the camera) is not counted again.
 */
export function useRestartCountdown(result: unknown, enabled: boolean, onDone: () => void): number | null {
    const [remaining, setRemaining] = useState<number | null>(null);
    const countedRef = useRef<unknown>(null);
    const onDoneRef = useRef(onDone);

    useEffect(() => { onDoneRef.current = onDone; });

    useEffect(() => {
        if (!result || !enabled || countedRef.current === result) {
            setRemaining(null);
            return;
        }
        countedRef.current = result;
        let left = RESTART_SECONDS;
        setRemaining(left);
        const timer = window.setInterval(() => {
            left -= 1;
            if (left > 0) {
                setRemaining(left);
                return;
            }
            window.clearInterval(timer);
            setRemaining(null);
            onDoneRef.current();
        }, 1000);
        return () => window.clearInterval(timer);
    }, [result, enabled]);

    return remaining;
}
