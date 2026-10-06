import { FormEvent, Suspense, lazy, useState } from 'react';
import { router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import PublicLayout, { type PublicPageMeta } from '@/Layouts/PublicLayout';
import {
    PublicCardDecor,
    PublicContainer,
    PublicPageHeader,
    RichText,
    publicButtonPrimary,
    publicCardClass,
} from '@/Components/public/PublicPage';
import ScannerBoundary from '@/Components/public/ScannerBoundary';
import { useBilingual } from '@/Components/public/bilingual';
import { resolveVerifyDestination } from '@/Components/public/verifyDestination';
import type { PageSection } from '@/Components/public/PublicPage';

/*
 * html5-qrcode is ~335 kB. Visitors who arrive here having already scanned with
 * their phone's own camera never open the in-page scanner, so loading it
 * eagerly would cost them the download for nothing.
 */
const QrScanner = lazy(() => import('@/Components/public/QrScanner'));

/**
 * Verify an employee ID card: scan the QR, or type the printed reference.
 *
 * Mobile first (Claude Design canvas "EUISIS Public Site Redesign", Verify —
 * mobile): on a phone the title band is short and the camera, its button and
 * the manual entry all fit in the first screen. On a wide screen the scanner
 * and the manual entry sit side by side.
 *
 * Page wording comes from Public Site Management (Verify). What happens to the
 * value — card lookup, OTP, rate limits, the fields shown afterwards — happens
 * on the ID checker's server side and is not affected by anything here.
 */
export default function PublicVerify({ sections, meta }: { sections: Record<string, PageSection>; meta: PublicPageMeta }) {
    const { t } = useLocale();
    const pick = useBilingual();
    const [value, setValue] = useState('');
    const [showScanner, setShowScanner] = useState(false);

    const header = sections.header;
    const title = pick(header, 'title') || t('home.verifyPageTitle');
    const subtitle = pick(header, 'subtitle') || t('home.verifyPageSubtitle');
    const scanHelp = pick(sections.scan_help, 'body_html');
    const notice = pick(sections.notice, 'body_html');

    const go = (raw: string) => {
        const destination = resolveVerifyDestination(raw);
        if (destination !== null) router.visit(destination);
    };

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        go(value);
    };

    return (
        // Never indexed: a verification tool is reached from a QR or a link,
        // not from search results.
        <PublicLayout title={title} description={subtitle} meta={meta} noindex>
            <PublicPageHeader title={title} description={subtitle} breadcrumbs={[{ label: title }]} compact />

            <div className="bg-gray-50 dark:bg-slate-900">
                <PublicContainer className="max-w-5xl py-4 sm:py-8 lg:py-10">
                    {notice && (
                        <div role="note" className={`${publicCardClass} mb-4 p-4 text-sm sm:mb-6`}>
                            <RichText html={notice} className="text-sm" />
                        </div>
                    )}

                    <div className="grid min-w-0 grid-cols-1 gap-4 lg:grid-cols-12 lg:gap-6">
                        {/* Scanner first: this page exists to point a camera at a code. */}
                        <section aria-labelledby="scan-heading" className={`${publicCardClass} min-w-0 !p-3 sm:!p-5 lg:col-span-7`}>
                            <PublicCardDecor glow={false} />
                            <h2 id="scan-heading" className="sr-only">{t('home.verifyScanQr')}</h2>

                            {showScanner ? (
                                <ScannerBoundary onFailure={() => setShowScanner(false)} fallbackLabel={t('idChecker.scannerUnavailable')}>
                                    <Suspense fallback={<Viewfinder label={t('idChecker.loadingScanner')} />}>
                                        {/* autoStart: the tap that loaded the scanner
                                            already asked for the camera. */}
                                        <QrScanner autoStart compact onDecoded={go} />
                                    </Suspense>
                                </ScannerBoundary>
                            ) : (
                                <>
                                    <Viewfinder label={t('idChecker.cameraIdle')} />
                                    <button
                                        type="button"
                                        className={`${publicButtonPrimary} mt-3 min-h-[52px] w-full text-base`}
                                        onClick={() => setShowScanner(true)}
                                    >
                                        <ScanIcon />
                                        {t('idChecker.startCamera')}
                                    </button>
                                </>
                            )}

                            {scanHelp && <RichText html={scanHelp} className="mt-3 text-sm text-gray-600 dark:text-slate-400" />}
                        </section>

                        <div className="flex min-w-0 flex-col gap-4 lg:col-span-5">
                            {/* Manual entry stays available: cameras get denied, and a
                                damaged code still has a readable reference printed on it. */}
                            <form onSubmit={handleSubmit} className={`${publicCardClass} min-w-0 !p-3 sm:!p-5`}>
                                <PublicCardDecor glow={false} />
                                <label htmlFor="card-ref" className="block text-sm font-semibold text-gray-900 dark:text-slate-100">
                                    <span className="lg:hidden">{t('home.verifyEnterReference')}</span>
                                    <span className="hidden lg:inline">{t('home.verifyInputLabel')}</span>
                                </label>
                                <div className="mt-2 flex min-w-0 flex-col gap-2 sm:flex-row lg:flex-col lg:gap-3">
                                    <input
                                        id="card-ref"
                                        type="text"
                                        inputMode="text"
                                        autoComplete="off"
                                        autoCapitalize="off"
                                        spellCheck={false}
                                        enterKeyHint="go"
                                        value={value}
                                        onChange={(e) => setValue(e.target.value)}
                                        placeholder={t('home.verifyInputPlaceholder')}
                                        className="h-12 min-w-0 flex-1 w-full basis-auto shrink-0 rounded-[10px] border border-gray-300 bg-white px-3 text-base text-gray-900 placeholder:text-gray-400 focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)]/30 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 sm:w-0 sm:shrink lg:w-full lg:flex-none"
                                    />
                                    <button
                                        type="submit"
                                        disabled={!value.trim()}
                                        className="inline-flex min-h-12 w-full shrink-0 items-center justify-center whitespace-normal rounded-[10px] border border-[color:var(--color-primary)] bg-white px-5 py-2 text-base font-bold text-[color:var(--color-primary)] transition-colors hover:bg-[color:var(--color-primary-50)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)] focus-visible:ring-offset-2 disabled:opacity-50 dark:bg-slate-900 dark:hover:bg-slate-800 sm:w-auto lg:w-full"
                                    >
                                        {t('home.verifyButton')}
                                    </button>
                                </div>
                            </form>

                            {/* Phones: one line. Wide screens: the three steps. */}
                            <p className="flex gap-2 px-1 text-[13px] leading-relaxed text-gray-600 lg:hidden dark:text-slate-400">
                                <ShieldIcon />
                                <span>{t('home.verifyPrivacyShort')}</span>
                            </p>
                            <section aria-labelledby="how-heading" className="hidden rounded-[14px] border border-[color:var(--color-primary-100)] bg-[color:var(--color-primary-50)] p-5 lg:block dark:border-slate-800 dark:bg-slate-900">
                                <h2 id="how-heading" className="text-[15px] font-bold text-gray-900 dark:text-slate-100">{t('home.verifyHowTitle')}</h2>
                                <ol className="mt-3 list-decimal space-y-2 ps-5 text-sm leading-relaxed text-gray-700 marker:font-semibold marker:text-[color:var(--color-primary)] dark:text-slate-300">
                                    <li>{t('home.verifyHowStep1')}</li>
                                    <li>{t('home.verifyHowStep2')}</li>
                                    <li>{t('home.verifyHowStep3')}</li>
                                </ol>
                            </section>
                        </div>
                    </div>
                </PublicContainer>
            </div>
        </PublicLayout>
    );
}

/**
 * The idle camera area. Shorter than square on phones so the button and the
 * manual entry stay in the first screen; the live scanner takes its own size.
 */
function Viewfinder({ label }: { label: string }) {
    return (
        <div className="flex h-[clamp(160px,26svh,220px)] aspect-[4/3] w-full min-w-0 flex-col items-center justify-center gap-3 rounded-[10px] bg-slate-950 px-4 text-center sm:h-auto sm:aspect-[16/10] lg:aspect-auto lg:h-80">
            <div aria-hidden="true" className="h-[45%] max-h-44 aspect-square rounded-xl border-2 border-dashed border-white/40" />
            <p className="text-sm text-slate-300">{label}</p>
        </div>
    );
}

function ScanIcon() {
    return (
        <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <path d="M4 8V6a2 2 0 012-2h2M16 4h2a2 2 0 012 2v2M20 16v2a2 2 0 01-2 2h-2M8 20H6a2 2 0 01-2-2v-2M8 12h8" />
        </svg>
    );
}

function ShieldIcon() {
    return (
        <svg className="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
        </svg>
    );
}
