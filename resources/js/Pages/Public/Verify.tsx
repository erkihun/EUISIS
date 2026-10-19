import { FormEvent, type ReactNode, Suspense, lazy, useState } from 'react';
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
    publicIconTileClass,
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
 * One console, two equal ways in (Claude Design canvas "Addis One Verify
 * Page"): on a wide screen the card is split down the middle by an "or"
 * divider — scan on the left, type on the right — so neither way looks like
 * the fallback, and the two halves stay the same height. On a phone the
 * halves stack, scan first, and the camera, its button and the entry field
 * all fit in the first screen. The three steps sit under the console.
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
    const [scannerFailed, setScannerFailed] = useState(false);

    const header = sections.header;
    const title = pick(header, 'title') || t('home.verifyPageTitle');
    const subtitle = pick(header, 'subtitle') || t('home.verifyPageSubtitle');
    const scanHelp = pick(sections.scan_help, 'body_html');
    const notice = pick(sections.notice, 'body_html');

    const go = (raw: string, scanned = false) => {
        const destination = resolveVerifyDestination(raw);
        if (destination === null) return;
        // A live in-page scan of an ID card sends the verification code at
        // once, as it does on the ID Checker page. A typed or pasted value
        // still needs the button there, so holding a link alone sends nothing.
        router.visit(scanned && destination.startsWith('/id-checker/') ? `${destination}?scanned=1` : destination);
    };

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        go(value);
    };

    const steps = [t('home.verifyHowStep1'), t('home.verifyHowStep2'), t('home.verifyHowStep3')];

    return (
        // Never indexed: a verification tool is reached from a QR or a link,
        // not from search results.
        <PublicLayout title={title} description={subtitle} meta={meta} noindex>
            <PublicPageHeader title={title} description={subtitle} breadcrumbs={[{ label: title }]} compact />

            <div className="bg-gray-50 dark:bg-slate-900">
                <PublicContainer className="py-4 sm:py-8 lg:py-10">
                    {notice && (
                        <div role="note" className={`${publicCardClass} mb-4 p-4 text-sm sm:mb-6`}>
                            <RichText html={notice} className="text-sm" />
                        </div>
                    )}

                    {/* The console: one card, two halves, an "or" between them. */}
                    <div className={`${publicCardClass} p-0! lg:grid lg:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)]`}>
                        <PublicCardDecor glow={false} />

                        {/* Scanner first: this page exists to point a camera at a code. */}
                        <section aria-labelledby="scan-heading" className="flex min-w-0 flex-col p-4 sm:p-6 lg:p-8">
                            <ConsoleHeading id="scan-heading" title={t('home.verifyScanTitle')} intro={t('home.verifyScanIntro')} icon={<ScanIcon className="h-6 w-6" />} />

                            <div className="mx-auto mt-5 w-full max-w-md lg:max-w-sm">
                                {showScanner ? (
                                    <ScannerBoundary onFailure={() => { setScannerFailed(true); setShowScanner(false); }} fallbackLabel={t('idChecker.scannerUnavailable')}>
                                        <Suspense fallback={<Viewfinder label={t('idChecker.loadingScanner')} />}>
                                            {/* autoStart: the tap that loaded the scanner
                                                already asked for the camera. */}
                                            <QrScanner autoStart onDecoded={(decoded) => go(decoded, true)} />
                                        </Suspense>
                                    </ScannerBoundary>
                                ) : (
                                    <>
                                        <Viewfinder label={t('idChecker.cameraIdle')} />
                                        {scannerFailed && (
                                            <p role="alert" className="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                                                {t('idChecker.scannerUnavailable')}
                                            </p>
                                        )}
                                        <button
                                            type="button"
                                            className={`${publicButtonPrimary} mt-3 min-h-[52px] w-full text-base`}
                                            onClick={() => setShowScanner(true)}
                                        >
                                            <ScanIcon className="h-5 w-5" />
                                            {t('idChecker.startCamera')}
                                        </button>
                                    </>
                                )}

                                {scanHelp
                                    ? <RichText html={scanHelp} className="mt-4 text-sm text-gray-600 dark:text-slate-400" />
                                    : <p className="mt-4 text-sm leading-relaxed text-gray-600 dark:text-slate-400">{t('home.verifyScanHelp')}</p>}
                            </div>
                        </section>

                        {/* "or": a vertical rule between the halves on a wide screen, a horizontal one when they stack. */}
                        <div aria-hidden="true" className="flex items-center gap-3 px-4 sm:px-6 lg:flex-col lg:px-0 lg:py-8">
                            <span className="h-px flex-1 bg-gray-200 lg:h-auto lg:w-px dark:bg-slate-800" />
                            <span className="grid h-10 w-10 shrink-0 place-items-center rounded-full border border-gray-200 bg-white text-[13px] font-semibold text-gray-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400">
                                {t('home.verifyOr')}
                            </span>
                            <span className="h-px flex-1 bg-gray-200 lg:h-auto lg:w-px dark:bg-slate-800" />
                        </div>

                        {/* Manual entry stays available: cameras get denied, and a
                            damaged code still has a readable reference printed on it. */}
                        <section aria-labelledby="reference-heading" className="flex min-w-0 flex-col p-4 sm:p-6 lg:p-8">
                            <ConsoleHeading id="reference-heading" title={t('home.verifyEnterReferenceTitle')} intro={t('home.verifyReferenceIntro')} icon={<CardIcon />} />

                            <form onSubmit={handleSubmit} className="mt-5 flex min-w-0 flex-col">
                                <label htmlFor="card-ref" className="block text-sm font-semibold text-gray-900 dark:text-slate-100">
                                    {t('home.verifyInputLabel')}
                                </label>
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
                                    aria-describedby="card-ref-hint"
                                    className="mt-2 h-12 min-w-0 flex-1 w-full rounded-[10px] border border-gray-300 bg-white px-3 text-base text-gray-900 placeholder:text-gray-400 focus:border-primary focus:outline-hidden focus:ring-2 focus:ring-primary/30 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 sm:h-13"
                                />
                                <p id="card-ref-hint" className="mt-2 text-[13px] text-gray-500 dark:text-slate-400">{t('home.verifyReferenceHint')}</p>
                                <button
                                    type="submit"
                                    disabled={!value.trim()}
                                    className={`${publicButtonPrimary} mt-4 min-h-[52px] w-full text-base`}
                                >
                                    {t('home.verifyButton')}
                                </button>
                            </form>

                            <p className="mt-auto flex gap-2.5 pt-6 text-[13px] leading-relaxed text-gray-600 dark:text-slate-400">
                                <ShieldIcon />
                                <span>{t('home.verifyPrivacyShort')}</span>
                            </p>
                        </section>
                    </div>

                    {/* The three steps, under the console: a row on a wide screen, a short list on a phone. */}
                    <section aria-labelledby="how-heading" className="mt-6 grid gap-3 sm:mt-8 lg:grid-cols-[minmax(0,1.1fr)_repeat(3,minmax(0,1fr))] lg:gap-4">
                        <div className="min-w-0 lg:pe-4 lg:pt-1">
                            <h2 id="how-heading" className="text-[15px] font-bold text-gray-900 lg:text-[17px] dark:text-slate-100">{t('home.verifyHowTitle')}</h2>
                            <p className="mt-1 hidden text-sm text-gray-600 lg:block dark:text-slate-400">{t('home.verifyHowSubtitle')}</p>
                        </div>
                        <ol className="contents">
                            {steps.map((step, index) => (
                                <li key={step} className="flex min-w-0 items-start gap-3 lg:rounded-[12px] lg:border lg:border-gray-200/80 lg:bg-white lg:p-5 dark:lg:border-slate-800/80 dark:lg:bg-slate-900/95">
                                    <span aria-hidden="true" className="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-primary text-[13px] font-bold text-white lg:h-8 lg:w-8">
                                        {index + 1}
                                    </span>
                                    <span className="pt-0.5 text-sm leading-relaxed text-gray-700 dark:text-slate-300">{step}</span>
                                </li>
                            ))}
                        </ol>
                    </section>
                </PublicContainer>
            </div>
        </PublicLayout>
    );
}

/** The heading of one half of the console: an icon tile, the way in, one line on how. */
function ConsoleHeading({ id, title, intro, icon }: { id: string; title: string; intro: string; icon: ReactNode }) {
    return (
        <div className="flex items-center gap-3.5">
            <span className={`${publicIconTileClass} h-11 w-11`} aria-hidden="true">{icon}</span>
            <div className="min-w-0">
                <h2 id={id} className="text-base font-bold text-gray-900 sm:text-lg dark:text-slate-100">{title}</h2>
                <p className="mt-0.5 text-[13px] text-gray-600 sm:text-sm dark:text-slate-400">{intro}</p>
            </div>
        </div>
    );
}

/**
 * The idle camera area: the same square as the live scanner (full width on a
 * phone, capped on a wide screen), so starting the camera does not jump.
 */
function Viewfinder({ label }: { label: string }) {
    return (
        <div className="mx-auto flex aspect-square w-full min-w-0 max-w-md flex-col items-center justify-center gap-4 rounded-[10px] bg-slate-950 px-4 text-center lg:max-w-sm">
            <div aria-hidden="true" className="relative aspect-square w-[70%] rounded-[14px] border-2 border-dashed border-white/35">
                <i className="absolute -left-0.5 -top-0.5 h-6 w-6 rounded-tl border-l-[3px] border-t-[3px] border-white" />
                <i className="absolute -right-0.5 -top-0.5 h-6 w-6 rounded-tr border-r-[3px] border-t-[3px] border-white" />
                <i className="absolute -bottom-0.5 -left-0.5 h-6 w-6 rounded-bl border-b-[3px] border-l-[3px] border-white" />
                <i className="absolute -bottom-0.5 -right-0.5 h-6 w-6 rounded-br border-b-[3px] border-r-[3px] border-white" />
            </div>
            <p className="text-sm text-slate-300">{label}</p>
        </div>
    );
}

function ScanIcon({ className }: { className: string }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <path d="M4 8V6a2 2 0 012-2h2M16 4h2a2 2 0 012 2v2M20 16v2a2 2 0 01-2 2h-2M8 20H6a2 2 0 01-2-2v-2M8 12h8" />
        </svg>
    );
}

function CardIcon() {
    return (
        <svg className="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <rect x="3" y="5" width="18" height="14" rx="2" />
            <path d="M7 15h4M7 11h10" />
        </svg>
    );
}

function ShieldIcon() {
    return (
        <svg className="mt-0.5 h-4 w-4 shrink-0 text-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
        </svg>
    );
}
