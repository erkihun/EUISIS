import { useCallback, useEffect, useId, useRef, useState } from 'react';
import { Html5Qrcode, Html5QrcodeSupportedFormats } from 'html5-qrcode';
import { useLocale } from '@/hooks/useLocale';

interface Props {
    /** Called once with the decoded text; the scanner stops itself first. */
    onDecoded: (value: string) => void;
}

/**
 * Camera QR scanner for the public ID Checker.
 *
 * Kept in its own module so the page can lazy-load it: html5-qrcode is ~335 kB
 * and is useless to a visitor who arrived by scanning a QR with their phone's
 * own camera, which is the common path on mobile.
 *
 * The video container is always mounted at a fixed height — html5-qrcode
 * measures the element on start, and mounting into a zero-size box produces a
 * running camera with a blank screen.
 */
export default function QrScanner({ onDecoded }: Props) {
    const { t } = useLocale();
    const regionId = useId().replace(/:/g, '');
    const scannerRef = useRef<Html5Qrcode | null>(null);

    const [active, setActive] = useState(false);
    const [starting, setStarting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [torchOn, setTorchOn] = useState(false);
    const [torchSupported, setTorchSupported] = useState(false);

    const stop = useCallback(async () => {
        try {
            await scannerRef.current?.stop();
        } catch {
            // Already stopped, or the element went away — nothing to recover.
        }

        scannerRef.current = null;
        setActive(false);
        setTorchOn(false);
        setTorchSupported(false);
    }, []);

    // Release the camera when the component unmounts, or the stream stays live.
    useEffect(() => () => void stop(), [stop]);

    async function start() {
        setError(null);

        // mediaDevices exists only on a secure origin. Without this guard an
        // http:// visitor gets an unexplained TypeError.
        if (typeof navigator === 'undefined' || navigator.mediaDevices === undefined) {
            setError(t('idChecker.cameraInsecureOrigin'));

            return;
        }

        setStarting(true);

        try {
            // Probe permission first: html5-qrcode fails opaquely when
            // permission has never been granted. Release the probe stream
            // immediately so the camera is not held twice.
            const probe = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
            probe.getTracks().forEach((track) => track.stop());

            const scanner = new Html5Qrcode(regionId, {
                formatsToSupport: [Html5QrcodeSupportedFormats.QR_CODE],
                verbose: false,
            });
            scannerRef.current = scanner;

            await scanner.start(
                // Rear camera on a phone; falls back to the only camera on a laptop.
                { facingMode: 'environment' },
                {
                    fps: 10,
                    // Square box sized to the viewport, so the target stays
                    // reachable on a 320px screen without overflowing.
                    qrbox: (viewfinderWidth, viewfinderHeight) => {
                        const edge = Math.floor(Math.min(viewfinderWidth, viewfinderHeight) * 0.7);

                        return { width: edge, height: edge };
                    },
                },
                (decoded) => {
                    // This runs inside html5-qrcode's scan loop. Anything that
                    // throws here escapes into the library and surfaces as the
                    // app-wide error boundary, so the handler is deferred to a
                    // clean tick and guarded.
                    void stop();

                    window.setTimeout(() => {
                        try {
                            onDecoded(decoded);
                        } catch {
                            setError(t('idChecker.cameraError'));
                        }
                    }, 0);
                },
                () => undefined,
            );

            setActive(true);
            detectTorch(scanner);
        } catch (caught) {
            // Name the obstacle: a generic failure leaves the user unsure
            // whether to grant permission, close an app, or type the token.
            const name = (caught as { name?: string } | null)?.name ?? '';

            setError(
                t(
                    name === 'NotAllowedError' || name === 'SecurityError'
                        ? 'idChecker.cameraPermissionDenied'
                        : name === 'NotFoundError' || name === 'OverconstrainedError'
                          ? 'idChecker.cameraNotFound'
                          : name === 'NotReadableError'
                            ? 'idChecker.cameraInUse'
                            : 'idChecker.cameraError',
                ),
            );
        } finally {
            setStarting(false);
        }
    }

    /** Torch exists only on some rear cameras; hide the control otherwise. */
    function detectTorch(scanner: Html5Qrcode) {
        try {
            const capabilities = scanner.getRunningTrackCapabilities() as MediaTrackCapabilities & { torch?: boolean };
            setTorchSupported(capabilities.torch === true);
        } catch {
            setTorchSupported(false);
        }
    }

    async function toggleTorch() {
        const next = !torchOn;

        try {
            // `torch` is a real constraint on Android/Chrome but is absent
            // from the standard MediaTrackConstraints type, so it needs the
            // double cast rather than a lint suppression.
            await scannerRef.current?.applyVideoConstraints({
                advanced: [{ torch: next }],
            } as unknown as MediaTrackConstraints);
            setTorchOn(next);
        } catch {
            // Some devices advertise torch but refuse to switch it.
            setTorchSupported(false);
        }
    }

    return (
        <div className="w-full">
            <div className="relative w-full overflow-hidden rounded-2xl border border-gray-200 bg-slate-950 dark:border-slate-800">
                <div
                    id={regionId}
                    className="aspect-square w-full [&_video]:h-full [&_video]:w-full [&_video]:object-cover"
                />

                {!active && (
                    <div className="absolute inset-0 flex flex-col items-center justify-center gap-3 px-6 text-center">
                        <svg
                            className="h-12 w-12 text-slate-500"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            strokeWidth={1.5}
                            aria-hidden="true"
                        >
                            <path strokeLinecap="round" strokeLinejoin="round" d="M4 8V6a2 2 0 012-2h2M16 4h2a2 2 0 012 2v2M20 16v2a2 2 0 01-2 2h-2M8 20H6a2 2 0 01-2-2v-2" />
                            <path strokeLinecap="round" strokeLinejoin="round" d="M8 12h8" />
                        </svg>
                        <p className="text-sm text-slate-300">
                            {starting ? t('idChecker.startingCamera') : t('idChecker.cameraIdle')}
                        </p>
                    </div>
                )}

                {active && (
                    <>
                        {/* Aiming frame — dimmed surround focuses attention. */}
                        <div className="pointer-events-none absolute inset-0 flex items-center justify-center">
                            <div className="h-[65%] w-[65%] rounded-2xl border-2 border-white/80 shadow-[0_0_0_9999px_rgba(0,0,0,0.4)]" />
                        </div>

                        {torchSupported && (
                            <button
                                type="button"
                                onClick={toggleTorch}
                                aria-pressed={torchOn}
                                className="absolute bottom-3 right-3 flex h-11 w-11 items-center justify-center rounded-full bg-black/60 text-white backdrop-blur transition hover:bg-black/75 focus:outline-none focus-visible:ring-2 focus-visible:ring-white"
                            >
                                <span className="sr-only">{t('idChecker.toggleTorch')}</span>
                                <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8} aria-hidden="true">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M13 2L4 14h7l-1 8 9-12h-7l1-8z" />
                                </svg>
                            </button>
                        )}
                    </>
                )}
            </div>

            <div className="mt-3 flex flex-wrap gap-2">
                <button
                    type="button"
                    onClick={active ? stop : start}
                    disabled={starting}
                    className="min-h-[48px] flex-1 rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white transition hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 disabled:opacity-60"
                >
                    {active
                        ? t('idChecker.stopCamera')
                        : starting
                          ? t('idChecker.startingCamera')
                          : error
                            ? t('idChecker.scanAgain')
                            : t('idChecker.startCamera')}
                </button>
            </div>

            {error && (
                <p role="alert" className="mt-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800 dark:bg-red-950/40 dark:text-red-300">
                    {error}
                </p>
            )}
        </div>
    );
}
