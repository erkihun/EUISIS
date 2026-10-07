import { useCallback, useEffect, useId, useRef, useState } from 'react';
import { Html5Qrcode, Html5QrcodeSupportedFormats } from 'html5-qrcode';
import { publicButtonPrimary, publicButtonSecondary } from './PublicPage';
import { useLocale } from '@/hooks/useLocale';

interface Props {
    /** Called once with the decoded text; the scanner stops itself first. */
    onDecoded: (value: string) => void;
    /**
     * Start the camera as soon as the scanner mounts.
     *
     * The page lazy-loads this component behind its own "Start camera"
     * button. Without this, that press only downloaded the scanner, which then
     * showed a second "Start camera" button — two presses, two different
     * buttons, for one action.
     */
    autoStart?: boolean;
}

/**
 * Higher resolution than the browser default (often 640x480): a printed card
 * code is small, and more pixels let the phone read it from further back,
 * where it can focus. "ideal" never makes a camera refuse to open.
 */
const PREFERRED_CAMERA: MediaTrackConstraints = {
    facingMode: 'environment',
    width: { ideal: 1920 },
    height: { ideal: 1080 },
};

/** The plain request, used if a camera will not open with the preferred one. */
const BASIC_CAMERA: MediaTrackConstraints = { facingMode: 'environment' };

const SECONDARY_LENS = /ultra|tele|depth|macro|infrared|\bir\b/i;

/** html5-qrcode rejects with a string that embeds the DOMException name. */
function errorName(caught: unknown): string {
    const direct = (caught as { name?: string } | null)?.name;

    if (direct && direct !== 'Error') {
        return direct;
    }

    return String(caught).match(/\b(NotAllowedError|SecurityError|NotFoundError|OverconstrainedError|NotReadableError|AbortError)\b/)?.[1] ?? '';
}

/** Chat and social apps open links in a built-in browser without the camera. */
function isInAppBrowser(): boolean {
    return typeof navigator !== 'undefined'
        && /FBAN|FBAV|FB_IAB|Instagram|Line\/|Telegram|TikTok|musical_ly|Snapchat|MicroMessenger|; wv\)/i.test(navigator.userAgent);
}

/** The main rear lens: Android names it "camera2 0", iOS "Back Camera". */
function pickMainRearCamera(cameras: MediaDeviceInfo[]): MediaDeviceInfo | undefined {
    const rear = cameras.filter((camera) => /back|rear|environment|camera2 0/i.test(camera.label) && !SECONDARY_LENS.test(camera.label));

    return rear.find((camera) => /camera2 0\b|^back camera$/i.test(camera.label)) ?? rear[0];
}

/** Switch only from a lens that is known to be wrong for close-up codes. */
function needsBetterLens(current: MediaDeviceInfo, main: MediaDeviceInfo): boolean {
    if (SECONDARY_LENS.test(current.label)) {
        return true;
    }

    // Android: the main rear camera is "camera2 0"; another rear index is
    // usually a wide or zoom lens, though the label does not say so.
    return /camera2 0\b/i.test(main.label) && /camera2 \d+.*back/i.test(current.label);
}

/**
 * Camera QR scanner for the public ID Checker.
 *
 * Kept in its own module so the page can lazy-load it: html5-qrcode is ~335 kB
 * and is useless to a visitor who arrived by scanning a QR with their phone's
 * own camera, which is the common path on mobile.
 *
 * The camera area is a square that fills the width on a phone and is capped
 * on a wide screen. The video inside keeps its own shape and is centred, so a
 * tall phone picture is clipped top and bottom, never squeezed: html5-qrcode
 * maps its scan box from the video element to the camera frame, and an
 * earlier fixed 220px height that distorted the video made it decode the
 * wrong region and miss codes. Because the picture may be clipped, the
 * library's own shading is hidden and a frame measured from the video is
 * drawn instead, centred where the scan box is.
 */
export default function QrScanner({ onDecoded, autoStart = false }: Props) {
    const { t } = useLocale();
    const regionId = useId().replace(/:/g, '');
    const scannerRef = useRef<Html5Qrcode | null>(null);

    const [active, setActive] = useState(false);
    const [starting, setStarting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [torchOn, setTorchOn] = useState(false);
    const [torchSupported, setTorchSupported] = useState(false);
    // Edge of the aiming frame in pixels: the scan box html5-qrcode reads.
    const [frame, setFrame] = useState(0);

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

    /*
     * The aiming frame matches the scan box: 75% of the shorter side of the
     * video as rendered. Measured from the video itself, because the camera
     * decides its shape (portrait on most phones, landscape on laptops).
     */
    useEffect(() => {
        const video = active ? document.getElementById(regionId)?.querySelector('video') : null;

        if (!video) {
            setFrame(0);

            return;
        }

        const measure = () => {
            const shorter = Math.min(video.clientWidth, video.clientHeight);

            if (shorter > 0) {
                setFrame(Math.max(120, Math.floor(shorter * 0.75)));
            }
        };
        const observer = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(measure);

        measure();
        observer?.observe(video);
        video.addEventListener('loadedmetadata', measure);
        video.addEventListener('playing', measure);

        return () => {
            observer?.disconnect();
            video.removeEventListener('loadedmetadata', measure);
            video.removeEventListener('playing', measure);
        };
    }, [active, regionId]);

    useEffect(() => {
        if (autoStart) {
            void start();
        }
        // Once, on mount: the press that loaded the scanner is the request.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    /** Open the camera with these constraints and begin scanning. */
    async function run(constraints: MediaTrackConstraints) {
        const scanner = new Html5Qrcode(regionId, {
            formatsToSupport: [Html5QrcodeSupportedFormats.QR_CODE],
            // The browser's own detector where it exists (Chrome on Android):
            // far faster and more tolerant of glare and small codes than the
            // bundled JavaScript decoder, which remains the fallback.
            useBarCodeDetectorIfSupported: true,
            verbose: false,
        });
        scannerRef.current = scanner;

        await scanner.start(
            { facingMode: 'environment' },
            {
                fps: 10,
                // The full request, resolution hints included. Only "ideal"
                // values: a camera that cannot meet them still opens.
                videoConstraints: constraints,
                // Square scan box at 75% of the shorter side: on a phone
                // a card held at a comfortable distance fits inside it.
                qrbox: (viewfinderWidth, viewfinderHeight) => {
                    const edge = Math.max(120, Math.floor(Math.min(viewfinderWidth, viewfinderHeight) * 0.75));

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
    }

    /** Tear down a scanner that failed to start, so a retry starts clean. */
    async function discard() {
        const scanner = scannerRef.current;
        scannerRef.current = null;

        try {
            await scanner?.stop();
        } catch {
            // It never started.
        }

        try {
            scanner?.clear();
        } catch {
            // Nothing was rendered.
        }
    }

    async function start() {
        setError(null);

        // The camera exists only on a secure origin (https or localhost).
        if (typeof window === 'undefined' || !window.isSecureContext) {
            setError(t('idChecker.cameraInsecureOrigin'));

            return;
        }

        // A secure page without the camera API is a browser that does not
        // offer it, most often the built-in browser of a chat or social app.
        if (navigator.mediaDevices?.getUserMedia === undefined) {
            setError(t(isInAppBrowser() ? 'idChecker.cameraInAppBrowser' : 'idChecker.cameraUnsupported'));

            return;
        }

        setStarting(true);

        try {
            // No separate permission probe: opening the camera, releasing it
            // and opening it again at once is refused by some Android phones
            // ("could not start video source"). The scanner's own request
            // asks for permission and reports the same errors.
            let failure: unknown = null;

            for (const constraints of [PREFERRED_CAMERA, BASIC_CAMERA]) {
                try {
                    await run(constraints);
                    failure = null;
                    break;
                } catch (caught) {
                    failure = caught;
                    await discard();

                    // A refusal or a missing camera will not change on retry;
                    // anything else may be the resolution hint or a camera
                    // still being released, so try once more, plainly.
                    if (['NotAllowedError', 'SecurityError', 'NotFoundError'].includes(errorName(caught))) {
                        break;
                    }

                    await new Promise((resolve) => window.setTimeout(resolve, 400));
                }
            }

            if (failure !== null) {
                throw failure;
            }

            setActive(true);
            await switchToMainLens();
            tuneCamera();
        } catch (caught) {
            // Name the obstacle: a generic failure leaves the user unsure
            // whether to grant permission, close an app, or type the token.
            const name = errorName(caught);

            setError(
                t(
                    name === 'NotAllowedError' || name === 'SecurityError'
                        ? 'idChecker.cameraPermissionDenied'
                        : name === 'NotFoundError' || name === 'OverconstrainedError'
                          ? 'idChecker.cameraNotFound'
                          : name === 'NotReadableError' || name === 'AbortError'
                            ? 'idChecker.cameraInUse'
                            : isInAppBrowser()
                              ? 'idChecker.cameraInAppBrowser'
                              : 'idChecker.cameraError',
                ),
            );
        } finally {
            setStarting(false);
        }
    }

    /**
     * Phones with several rear lenses do not all hand the page the main one.
     * An ultra-wide, telephoto or depth lens cannot focus on a card held
     * close, so the code never sharpens. When the lens in use is one of those
     * and a main rear lens exists, switch to it. Labels are readable only now
     * that permission is granted. Any failure keeps the camera already open.
     */
    async function switchToMainLens() {
        const scanner = scannerRef.current;

        try {
            const currentId = scanner?.getRunningTrackSettings().deviceId;
            const cameras = (await navigator.mediaDevices.enumerateDevices()).filter((device) => device.kind === 'videoinput');
            const current = cameras.find((camera) => camera.deviceId === currentId);
            const main = pickMainRearCamera(cameras);

            if (!current || !main || main.deviceId === current.deviceId || !needsBetterLens(current, main)) {
                return;
            }

            await stop();
            setStarting(true);

            try {
                await run({ width: PREFERRED_CAMERA.width, height: PREFERRED_CAMERA.height, deviceId: { exact: main.deviceId } });
            } catch {
                // That lens would not open: fall back to what worked before.
                await discard();
                await run(BASIC_CAMERA);
            }

            setActive(true);
        } catch {
            // Device listing or settings unavailable: keep scanning as is.
        }
    }

    /** Continuous focus where the camera offers it; some default to fixed. */
    function tuneCamera() {
        const scanner = scannerRef.current;

        if (!scanner) {
            return;
        }

        detectTorch(scanner);

        try {
            const capabilities = scanner.getRunningTrackCapabilities() as MediaTrackCapabilities & { focusMode?: string[] };

            if (capabilities.focusMode?.includes('continuous')) {
                void scanner
                    .applyVideoConstraints({ advanced: [{ focusMode: 'continuous' }] } as unknown as MediaTrackConstraints)
                    .catch(() => undefined);
            }
        } catch {
            // Capabilities are not exposed on every browser.
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
        <div className="min-w-0 w-full">
            <div className="relative mx-auto w-full max-w-md overflow-hidden rounded-panel border border-gray-200 bg-slate-950 dark:border-slate-800">
                <div
                    id={regionId}
                    className="flex aspect-square w-full items-center justify-center overflow-hidden [&_#qr-shaded-region]:hidden [&_video]:block [&_video]:shrink-0"
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
                        {frame > 0 && (
                            <div
                                aria-hidden="true"
                                className="pointer-events-none absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 rounded-panel border-2 border-white/90 shadow-[0_0_0_9999px_rgba(0,0,0,0.45)]"
                                style={{ width: frame, height: frame }}
                            />
                        )}

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

            <div className="mx-auto mt-3 flex w-full max-w-md flex-wrap gap-2">
                <button
                    type="button"
                    onClick={active ? stop : start}
                    disabled={starting}
                    className={`${active ? publicButtonSecondary : publicButtonPrimary} min-h-[48px] flex-1`}
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
                <p role="alert" className="mx-auto mt-2 max-w-md rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800 dark:bg-red-950/40 dark:text-red-300">
                    {error}
                </p>
            )}
        </div>
    );
}
