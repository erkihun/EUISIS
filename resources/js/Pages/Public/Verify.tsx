import { Component, FormEvent, ReactNode, Suspense, lazy, useState } from 'react';
import { router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import PublicLayout from '@/Layouts/PublicLayout';
import { SVGProps } from 'react';

/*
 * html5-qrcode is ~335 kB. Visitors who arrive here having already scanned with
 * their phone's own camera never open the in-page scanner, so loading it
 * eagerly would cost them the download for nothing.
 */
const QrScanner = lazy(() => import('@/Components/public/QrScanner'));

type IconProps = SVGProps<SVGSVGElement>;

function BadgeCheckIcon(p: IconProps) {
    return (
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" {...p}>
            <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" />
            <polyline points="9 12 11 14 15 10" />
        </svg>
    );
}

/**
 * Contains any failure from the lazily-loaded scanner.
 *
 * Written as a class because React error boundaries have no hook equivalent.
 * A chunk-load failure or an unsupported camera API must not blank the page —
 * the manual entry field below is a complete alternative path.
 */
class ScannerBoundary extends Component<
    { children: ReactNode; onFailure: () => void; fallbackLabel: string },
    { failed: boolean }
> {
    state = { failed: false };

    static getDerivedStateFromError() {
        return { failed: true };
    }

    componentDidCatch() {
        this.props.onFailure();
    }

    render() {
        if (this.state.failed) {
            return (
                <p role="alert" className="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                    {this.props.fallbackLabel}
                </p>
            );
        }

        return this.props.children;
    }
}

/**
 * Route a scanned or pasted value to the page that can handle it.
 *
 * Two different public QR codes exist and a visitor standing here does not know
 * which one they are holding:
 *
 *  - ID card QR  -> /id-checker/{uuid}          (dashed UUID)
 *  - Feedback QR -> /service-feedback/{token}   (64 hex characters)
 *
 * Sending a feedback QR to the ID checker would fail with a confusing "card not
 * found", so the shape of the value decides the destination. A full URL is
 * handled too, since people paste links as often as they scan them.
 */
function resolveDestination(raw: string): string | null {
    const value = raw.trim();

    if (value === '') {
        return null;
    }

    // An already-complete link to either public page: follow its own path.
    const pathMatch = value.match(/\/(id-checker|service-feedback)\/([^/?#\s]+)/i);

    if (pathMatch) {
        return `/${pathMatch[1].toLowerCase()}/${pathMatch[2]}`;
    }

    // A bare 64-character hex string is a feedback token.
    if (/^[0-9a-f]{64}$/i.test(value)) {
        return `/service-feedback/${value}`;
    }

    // A bare UUID is a card reference.
    const uuidMatch = value.match(/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i);

    if (uuidMatch) {
        return `/id-checker/${uuidMatch[0]}`;
    }

    // Unrecognised shape: let the ID checker answer, which fails uniformly and
    // reveals nothing about which references exist.
    return `/id-checker/${encodeURIComponent(value)}`;
}

export default function PublicVerify() {
    const { t } = useLocale();
    const [value, setValue] = useState('');
    const [showScanner, setShowScanner] = useState(false);

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();

        const destination = resolveDestination(value);

        if (destination !== null) {
            router.visit(destination);
        }
    };

    return (
        <PublicLayout title={t('home.verifyPageTitle')}>
            <div className="mx-auto max-w-lg px-4 py-10 sm:px-6 sm:py-16">
                <div className="mb-6 flex items-center gap-3">
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-600 text-white">
                        <BadgeCheckIcon className="h-5 w-5" aria-hidden="true" />
                    </div>
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900 dark:text-slate-100">{t('home.verifyPageTitle')}</h1>
                        <p className="text-sm text-gray-500 dark:text-slate-400">{t('home.verifyPageSubtitle')}</p>
                    </div>
                </div>

                {/* Scanner first: this page exists to point a camera at a code. */}
                <div className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-6">
                    {showScanner ? (
                        <ScannerBoundary
                            onFailure={() => setShowScanner(false)}
                            fallbackLabel={t('idChecker.scannerUnavailable')}
                        >
                            <Suspense
                                fallback={
                                    <div className="flex aspect-square w-full items-center justify-center rounded-2xl border border-gray-200 bg-slate-950 text-sm text-slate-300 dark:border-slate-800">
                                        {t('idChecker.loadingScanner')}
                                    </div>
                                }
                            >
                                <QrScanner
                                    onDecoded={(decoded) => {
                                        const destination = resolveDestination(decoded);

                                        if (destination !== null) {
                                            router.visit(destination);
                                        }
                                    }}
                                />
                            </Suspense>
                        </ScannerBoundary>
                    ) : (
                        <>
                            <div className="flex aspect-square w-full items-center justify-center rounded-2xl border border-dashed border-gray-300 bg-gray-50 dark:border-slate-700 dark:bg-slate-950">
                                <div className="px-6 text-center">
                                    <QrIcon className="mx-auto h-12 w-12 text-gray-400 dark:text-slate-600" aria-hidden="true" />
                                    <p className="mt-3 text-sm text-gray-500 dark:text-slate-400">
                                        {t('idChecker.cameraIdle')}
                                    </p>
                                </div>
                            </div>

                            <button
                                type="button"
                                onClick={() => setShowScanner(true)}
                                className="mt-4 min-h-[48px] w-full rounded-xl bg-emerald-600 px-4 text-sm font-semibold text-white transition hover:bg-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2"
                            >
                                {t('idChecker.startCamera')}
                            </button>
                        </>
                    )}

                    {/* Manual entry stays available: cameras get denied, and a
                        damaged code still has a readable reference printed on it. */}
                    <div className="my-5 flex items-center gap-3">
                        <span className="h-px flex-1 bg-gray-200 dark:bg-slate-800" />
                        <span className="text-xs uppercase tracking-wide text-gray-400 dark:text-slate-500">
                            {t('idChecker.or')}
                        </span>
                        <span className="h-px flex-1 bg-gray-200 dark:bg-slate-800" />
                    </div>

                    <form onSubmit={handleSubmit}>
                        <label htmlFor="card-ref" className="block text-sm font-medium text-gray-700 dark:text-slate-300">
                            {t('home.verifyInputLabel')}
                        </label>
                        <input
                            id="card-ref"
                            type="text"
                            inputMode="text"
                            autoComplete="off"
                            value={value}
                            onChange={(e) => setValue(e.target.value)}
                            placeholder={t('home.verifyInputPlaceholder')}
                            className="mt-1.5 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-base text-gray-900 placeholder-gray-400 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder-slate-500"
                        />
                        <button
                            type="submit"
                            disabled={!value.trim()}
                            className="mt-4 min-h-[48px] w-full rounded-lg border border-gray-300 px-4 text-sm font-semibold text-gray-700 transition-colors hover:bg-gray-50 disabled:opacity-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                        >
                            {t('home.verifyButton')}
                        </button>
                    </form>
                </div>
            </div>
        </PublicLayout>
    );
}

function QrIcon(p: IconProps) {
    return (
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" strokeLinejoin="round" {...p}>
            <rect x="3" y="3" width="7" height="7" rx="1" />
            <rect x="14" y="3" width="7" height="7" rx="1" />
            <rect x="3" y="14" width="7" height="7" rx="1" />
            <path d="M14 14h3v3h-3zM20 14v.01M14 20v.01M20 20v.01M17 20v.01M20 17v.01" />
        </svg>
    );
}
