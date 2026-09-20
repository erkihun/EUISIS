import { FormEvent, Suspense, lazy, useState } from 'react';
import { router } from '@inertiajs/react';
import { FormField, Input } from '@euisis/ui';
import { useLocale } from '@/hooks/useLocale';
import PublicLayout, { type PublicPageMeta } from '@/Layouts/PublicLayout';
import {
    PublicContainer,
    PublicPageHeader,
    RichText,
    publicButtonPrimary,
    publicButtonSecondary,
    publicCardClass,
} from '@/Components/public/PublicPage';
import ScannerBoundary from '@/Components/public/ScannerBoundary';
import { useBilingual } from '@/Components/public/bilingual';
import type { PageSection } from '@/Components/public/PublicPage';

/*
 * html5-qrcode is ~335 kB. Visitors who arrive here having already scanned with
 * their phone's own camera never open the in-page scanner, so loading it
 * eagerly would cost them the download for nothing.
 */
const QrScanner = lazy(() => import('@/Components/public/QrScanner'));

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

/**
 * Verify an employee ID card: scan the QR, or type the printed reference.
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
        const destination = resolveDestination(raw);
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
            <PublicPageHeader title={title} description={subtitle} breadcrumbs={[{ label: title }]} />

            <div className="bg-gray-50 py-12 sm:py-16 dark:bg-slate-900">
                <PublicContainer narrow className="py-6 sm:py-8">
                    {notice && (
                        <div role="note" className={`${publicCardClass} mb-6 text-sm`}>
                            <RichText html={notice} className="text-sm" />
                        </div>
                    )}

                    <div className="mx-auto max-w-md space-y-6">
                        {/* Scanner first: this page exists to point a camera at a code. */}
                        <section aria-labelledby="scan-heading">
                            <h2 id="scan-heading" className="sr-only">{t('idChecker.startCamera')}</h2>

                            {showScanner ? (
                                <ScannerBoundary onFailure={() => setShowScanner(false)} fallbackLabel={t('idChecker.scannerUnavailable')}>
                                    <Suspense
                                        fallback={
                                            <div className="flex aspect-square w-full items-center justify-center rounded-panel border border-gray-200 bg-slate-950 text-sm text-slate-300 dark:border-slate-800">
                                                {t('idChecker.loadingScanner')}
                                            </div>
                                        }
                                    >
                                        {/* autoStart: the tap that loaded the scanner
                                            already asked for the camera. */}
                                        <QrScanner autoStart onDecoded={go} />
                                    </Suspense>
                                </ScannerBoundary>
                            ) : (
                                <button
                                    type="button"
                                    className={`${publicButtonPrimary} min-h-[48px] w-full`}
                                    onClick={() => setShowScanner(true)}
                                >
                                    {t('idChecker.startCamera')}
                                </button>
                            )}

                            {scanHelp && <RichText html={scanHelp} className="mt-3 text-sm text-gray-600 dark:text-slate-400" />}
                        </section>

                        {/* Manual entry stays available: cameras get denied, and a
                            damaged code still has a readable reference printed on it. */}
                        <div className="flex items-center gap-3" aria-hidden="true">
                            <span className="h-px flex-1 bg-gray-200 dark:bg-slate-800" />
                            <span className="text-xs text-gray-500 dark:text-slate-400">{t('idChecker.or')}</span>
                            <span className="h-px flex-1 bg-gray-200 dark:bg-slate-800" />
                        </div>

                        <form onSubmit={handleSubmit} className="space-y-3">
                            <FormField id="card-ref" label={t('home.verifyInputLabel')}>
                                <Input
                                    id="card-ref"
                                    type="text"
                                    inputMode="text"
                                    autoComplete="off"
                                    autoCapitalize="off"
                                    spellCheck={false}
                                    value={value}
                                    onChange={(e) => setValue(e.target.value)}
                                    placeholder={t('home.verifyInputPlaceholder')}
                                    className="h-12 text-base"
                                />
                            </FormField>
                            <button type="submit" className={`${publicButtonSecondary} min-h-[48px] w-full`} disabled={!value.trim()}>
                                {t('home.verifyButton')}
                            </button>
                        </form>
                    </div>
                </PublicContainer>
            </div>
        </PublicLayout>
    );
}
