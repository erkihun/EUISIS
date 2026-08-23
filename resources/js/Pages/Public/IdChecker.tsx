import { Component, FormEvent, ReactNode, Suspense, lazy, useEffect, useRef, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';
import OtpInput from '@/Components/public/OtpInput';
import { useLocale } from '@/hooks/useLocale';

/*
 * html5-qrcode is ~335 kB. Most visitors reach this page by scanning the QR
 * with their phone's own camera app, so they never open the in-page scanner —
 * loading it eagerly would cost them the download for nothing.
 */
const QrScanner = lazy(() => import('@/Components/public/QrScanner'));

interface CardState {
    found: boolean;
    checkable: boolean;
    status_code: string;
    card_number_masked: string | null;
}

/** The only employee fields this page can ever receive. */
interface SafeEmployee {
    full_name: string | null;
    employee_number: string | null;
    organization: string | null;
    organization_unit: string | null;
    position: string | null;
    card_number: string | null;
    card_status: string;
    issued_at: string | null;
    expires_at: string | null;
    verified_at: string | null;
}

interface Props {
    cardUuid: string | null;
    card: CardState | null;
    /** True only when the in-page scanner produced this navigation. */
    autoSend?: boolean;
}

const STATUS_TONE: Record<string, string> = {
    active: 'bg-emerald-50 text-emerald-900 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-200 dark:border-emerald-900',
    expired: 'bg-amber-50 text-amber-900 border-amber-200 dark:bg-amber-950/40 dark:text-amber-200 dark:border-amber-900',
    revoked: 'bg-red-50 text-red-900 border-red-200 dark:bg-red-950/40 dark:text-red-200 dark:border-red-900',
    lost: 'bg-red-50 text-red-900 border-red-200 dark:bg-red-950/40 dark:text-red-200 dark:border-red-900',
    replaced: 'bg-slate-50 text-slate-800 border-slate-200 dark:bg-slate-900 dark:text-slate-200 dark:border-slate-700',
    inactive: 'bg-slate-50 text-slate-800 border-slate-200 dark:bg-slate-900 dark:text-slate-200 dark:border-slate-700',
    invalid: 'bg-red-50 text-red-900 border-red-200 dark:bg-red-950/40 dark:text-red-200 dark:border-red-900',
};

const RESEND_SECONDS = 60;

/**
 * Contains any failure from the lazily-loaded scanner.
 *
 * Written as a class because React error boundaries have no hook equivalent.
 * Without it, a chunk-load failure or an unsupported camera API bubbles to the
 * app-wide boundary and replaces the whole page with "Something went wrong" —
 * even though the manual token field would still have worked.
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

/** Accept a pasted verification URL or a bare UUID. */
function extractUuid(value: string): string {
    const match = value.match(/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i);

    return match ? match[0] : value.trim();
}

const cardCls = 'rounded-2xl border border-gray-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900 sm:p-5';
const primaryBtn =
    'min-h-[48px] w-full rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white transition hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 disabled:opacity-60';
const secondaryBtn =
    'min-h-[48px] w-full rounded-xl border border-gray-300 px-4 text-sm font-medium text-slate-700 transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 disabled:opacity-60 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800';

export default function IdChecker({ cardUuid, card, autoSend = false }: Props) {
    const { t } = useLocale();

    const [manualToken, setManualToken] = useState('');
    const [showScanner, setShowScanner] = useState(false);

    const [otp, setOtp] = useState('');
    const [otpSent, setOtpSent] = useState(false);
    const [busy, setBusy] = useState(false);
    const [notice, setNotice] = useState<{ key: string; tone: 'ok' | 'error' } | null>(null);
    const [employee, setEmployee] = useState<SafeEmployee | null>(null);
    const [resendIn, setResendIn] = useState(0);

    const resultRef = useRef<HTMLDivElement | null>(null);
    const autoSentRef = useRef(false);

    // Countdown gates the resend button, so a visitor cannot spam an
    // employee's phone by tapping repeatedly.
    useEffect(() => {
        if (resendIn <= 0) {
            return;
        }

        const timer = window.setTimeout(() => setResendIn((seconds) => seconds - 1), 1000);

        return () => window.clearTimeout(timer);
    }, [resendIn]);

    // Move focus to the result so a screen-reader user is not left at the form.
    useEffect(() => {
        if (employee) {
            resultRef.current?.focus();
        }
    }, [employee]);

    function openManual(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const uuid = extractUuid(manualToken);

        if (uuid !== '') {
            router.visit(`/id-checker/${uuid}`);
        }
    }

    async function post(url: string, body: Record<string, string>) {
        const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(body),
        });

        return { ok: response.ok, status: response.status, data: await response.json().catch(() => ({})) };
    }

    async function sendOtp() {
        if (!cardUuid) {
            return;
        }

        setBusy(true);
        setNotice(null);

        const result = await post(`/id-checker/${cardUuid}/send-otp`, {});

        if (result.status === 429) {
            setNotice({ key: 'idChecker.tooManyRequests', tone: 'error' });
        } else if (result.ok) {
            setOtpSent(true);
            setResendIn(RESEND_SECONDS);
            setNotice({ key: 'idChecker.otpSent', tone: 'ok' });
        } else {
            setNotice({ key: result.data.message_key ?? 'idChecker.cannotVerifyCard', tone: 'error' });
        }

        setBusy(false);
    }

    /*
     * Fire once when the in-page scanner brought us here, so an operator
     * holding the card does not have to tap Send OTP immediately after
     * scanning it. The ref guards against a re-render dispatching twice and
     * burning the employee's 3-per-10-minute budget.
     */
    useEffect(() => {
        if (!autoSend || autoSentRef.current || !cardUuid || card?.checkable !== true) {
            return;
        }

        autoSentRef.current = true;
        void sendOtp();
        // sendOtp is stable for the life of this page; re-running on each
        // render would re-send the code.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [autoSend, cardUuid, card?.checkable]);

    async function verifyOtp(code?: string) {
        const submitted = code ?? otp;

        if (!cardUuid || submitted.length !== 6 || busy) {
            return;
        }

        setBusy(true);
        setNotice(null);

        const result = await post(`/id-checker/${cardUuid}/verify-otp`, { otp: submitted });

        if (result.status === 429) {
            setNotice({ key: 'idChecker.tooManyRequests', tone: 'error' });
        } else if (result.ok && result.data.verified) {
            setEmployee(result.data.employee as SafeEmployee);
            setNotice({ key: 'idChecker.verificationSuccessful', tone: 'ok' });
        } else {
            setNotice({ key: result.data.message_key ?? 'idChecker.verificationFailed', tone: 'error' });
        }

        setOtp('');
        setBusy(false);
    }

    const awaitingOtp = Boolean(cardUuid) && card?.checkable === true && !employee;

    return (
        <PublicLayout title={t('idChecker.title')}>
            <Head title={t('idChecker.title')} />

            {/* Bottom padding leaves room for the sticky mobile action bar. */}
            <div className="mx-auto w-full max-w-2xl px-4 py-6 pb-28 sm:px-6 sm:py-8 sm:pb-8">
                <header>
                    <h1 className="text-xl font-bold text-slate-900 dark:text-slate-100 sm:text-2xl">
                        {t('idChecker.title')}
                    </h1>
                    <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">{t('idChecker.subtitle')}</p>
                </header>

                {/* ── Step 1: choose a card ── */}
                {!cardUuid && (
                    <section className={`mt-5 ${cardCls}`}>
                        <h2 className="mb-3 font-semibold text-slate-900 dark:text-slate-100">
                            {t('idChecker.scanEmployeeId')}
                        </h2>

                        {showScanner ? (
                            // A failure inside the lazily-loaded scanner — a chunk
                            // that will not download, or a camera API the browser
                            // does not implement — must not take down the page.
                            // The manual token field below is a complete
                            // alternative path, so the checker stays usable.
                            <ScannerBoundary onFailure={() => setShowScanner(false)} fallbackLabel={t('idChecker.scannerUnavailable')}>
                                <Suspense
                                    fallback={
                                        <div className="flex aspect-square w-full items-center justify-center rounded-2xl border border-gray-200 bg-slate-950 text-sm text-slate-300 dark:border-slate-800">
                                            {t('idChecker.loadingScanner')}
                                        </div>
                                    }
                                >
                                    {/*
                                      * `scanned=1` marks a live in-page scan, which
                                      * auto-sends the code. An external QR or a
                                      * pasted link arrives without it and still
                                      * requires the button, so holding the URL alone
                                      * cannot trigger messages to the employee.
                                      */}
                                    <QrScanner
                                        onDecoded={(decoded) =>
                                            router.visit(`/id-checker/${extractUuid(decoded)}?scanned=1`)
                                        }
                                    />
                                </Suspense>
                            </ScannerBoundary>
                        ) : (
                            <button type="button" onClick={() => setShowScanner(true)} className={primaryBtn}>
                                {t('idChecker.startCamera')}
                            </button>
                        )}

                        <div className="my-4 flex items-center gap-3">
                            <span className="h-px flex-1 bg-gray-200 dark:bg-slate-800" />
                            <span className="text-xs uppercase tracking-wide text-slate-400">{t('idChecker.or')}</span>
                            <span className="h-px flex-1 bg-gray-200 dark:bg-slate-800" />
                        </div>

                        <form onSubmit={openManual} className="space-y-2">
                            <label
                                htmlFor="card-token"
                                className="block text-sm font-medium text-slate-700 dark:text-slate-300"
                            >
                                {t('idChecker.enterCardToken')}
                            </label>
                            <input
                                id="card-token"
                                value={manualToken}
                                onChange={(event) => setManualToken(event.target.value)}
                                autoComplete="off"
                                spellCheck={false}
                                placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
                                className="min-h-[48px] w-full rounded-xl border border-gray-300 bg-white px-3 text-base text-slate-900 placeholder:text-slate-400 focus:border-blue-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            />
                            <button type="submit" disabled={manualToken.trim() === ''} className={secondaryBtn}>
                                {t('idChecker.checkCard')}
                            </button>
                        </form>
                    </section>
                )}

                {/* ── Step 2: card state, with no holder information ── */}
                {cardUuid && card && (
                    <section className={`mt-5 rounded-2xl border p-4 sm:p-5 ${STATUS_TONE[card.status_code] ?? STATUS_TONE.invalid}`}>
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <p className="text-xs font-semibold uppercase tracking-wide opacity-70">
                                {t('idChecker.cardStatus')}
                            </p>
                            <span className="rounded-full bg-white/70 px-2.5 py-1 text-xs font-bold dark:bg-black/30">
                                {t(`idChecker.status_${card.status_code}`)}
                            </span>
                        </div>

                        {card.card_number_masked && (
                            <p className="mt-2 break-all font-mono text-sm opacity-80">{card.card_number_masked}</p>
                        )}

                        {card.checkable ? (
                            <>
                                {/* Names the state plainly, so a visitor who arrived
                                    from any external QR app knows the next step. */}
                                <p className="mt-3 text-sm font-semibold">{t('idChecker.cardDetected')}</p>
                                <p className="mt-1 text-sm opacity-90">{t('idChecker.cardFoundNoInfo')}</p>
                            </>
                        ) : (
                            <p className="mt-3 text-sm">{t('idChecker.cardNotActive')}</p>
                        )}

                        {!card.checkable && (
                            <button
                                type="button"
                                onClick={() => router.visit('/id-checker')}
                                className={`${secondaryBtn} mt-4 bg-white/70 dark:bg-black/20`}
                            >
                                {t('idChecker.scanAnotherCard')}
                            </button>
                        )}
                    </section>
                )}

                {/* ── Step 3: consent by OTP ── */}
                {awaitingOtp && (
                    <section className={`mt-4 ${cardCls}`}>
                        <p id="otp-explainer" className="text-sm text-slate-600 dark:text-slate-400">
                            {t('idChecker.otpExplainer')}
                        </p>

                        {!otpSent ? (
                            <button type="button" onClick={sendOtp} disabled={busy} className={`${primaryBtn} mt-4 hidden sm:block`}>
                                {busy ? t('idChecker.sending') : t('idChecker.sendOtp')}
                            </button>
                        ) : (
                            <div className="mt-4 space-y-3">
                                <label className="block text-sm font-medium text-slate-700 dark:text-slate-300">
                                    {t('idChecker.enterOtp')}
                                </label>

                                <OtpInput
                                    value={otp}
                                    onChange={setOtp}
                                    onComplete={(code) => void verifyOtp(code)}
                                    disabled={busy}
                                    label={t('idChecker.enterOtp')}
                                    describedBy="otp-explainer"
                                />

                                <button
                                    type="button"
                                    onClick={() => void verifyOtp()}
                                    disabled={busy || otp.length !== 6}
                                    className={`${primaryBtn} hidden sm:block`}
                                >
                                    {busy ? t('idChecker.verifying') : t('idChecker.verifyOtp')}
                                </button>

                                <button
                                    type="button"
                                    onClick={sendOtp}
                                    disabled={busy || resendIn > 0}
                                    className={secondaryBtn}
                                >
                                    {resendIn > 0
                                        ? `${t('idChecker.resendOtp')} (${resendIn}s)`
                                        : t('idChecker.resendOtp')}
                                </button>
                            </div>
                        )}
                    </section>
                )}

                {/* Announced to assistive tech as it changes. */}
                <div aria-live="polite" aria-atomic="true">
                    {notice && (
                        <p
                            role={notice.tone === 'error' ? 'alert' : 'status'}
                            className={`mt-4 rounded-xl border px-4 py-3 text-sm ${
                                notice.tone === 'ok'
                                    ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200'
                                    : 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200'
                            }`}
                        >
                            {t(notice.key)}
                        </p>
                    )}
                </div>

                {/* ── Step 4: the only place employee data appears ── */}
                {employee && (
                    <section
                        ref={resultRef}
                        tabIndex={-1}
                        className="mt-4 rounded-2xl border border-emerald-200 bg-white p-4 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 dark:border-emerald-900 dark:bg-slate-900 sm:p-5"
                    >
                        <h2 className="mb-4 font-semibold text-slate-900 dark:text-slate-100">
                            {t('idChecker.verifiedDetails')}
                        </h2>

                        <dl className="divide-y divide-gray-100 dark:divide-slate-800">
                            {[
                                [t('idChecker.fullName'), employee.full_name],
                                [t('idChecker.employeeNumber'), employee.employee_number],
                                [t('idChecker.organization'), employee.organization],
                                [t('idChecker.organizationUnit'), employee.organization_unit],
                                [t('idChecker.position'), employee.position],
                                [t('idChecker.cardNumber'), employee.card_number],
                                [t('idChecker.cardStatus'), t(`idChecker.status_${employee.card_status}`)],
                                [t('idChecker.issuedAt'), employee.issued_at],
                                [t('idChecker.expiresAt'), employee.expires_at],
                                [t('idChecker.verifiedAt'), employee.verified_at],
                            ].map(([label, value]) => (
                                // Stacked on a phone, two columns from `sm`.
                                <div key={label} className="flex flex-col gap-0.5 py-2.5 sm:flex-row sm:justify-between sm:gap-4">
                                    <dt className="text-xs text-slate-500 dark:text-slate-400 sm:text-sm">{label}</dt>
                                    <dd className="break-words text-sm font-medium text-slate-900 dark:text-slate-100 sm:text-right">
                                        {value ?? '—'}
                                    </dd>
                                </div>
                            ))}
                        </dl>

                        <p className="mt-4 text-xs text-slate-500 dark:text-slate-400">{t('idChecker.privacyNote')}</p>

                        <button type="button" onClick={() => router.visit('/id-checker')} className={`${secondaryBtn} mt-4`}>
                            {t('idChecker.scanAnotherCard')}
                        </button>
                    </section>
                )}
            </div>

            {/*
              * Sticky action bar, phones only. The primary action stays under
              * the thumb instead of scrolling away below the card details.
              */}
            {awaitingOtp && (
                <div className="fixed inset-x-0 bottom-0 z-20 border-t border-gray-200 bg-white/95 p-3 backdrop-blur dark:border-slate-800 dark:bg-slate-900/95 sm:hidden">
                    {!otpSent ? (
                        <button type="button" onClick={sendOtp} disabled={busy} className={primaryBtn}>
                            {busy ? t('idChecker.sending') : t('idChecker.sendOtp')}
                        </button>
                    ) : (
                        <button
                            type="button"
                            onClick={() => void verifyOtp()}
                            disabled={busy || otp.length !== 6}
                            className={primaryBtn}
                        >
                            {busy ? t('idChecker.verifying') : t('idChecker.verifyOtp')}
                        </button>
                    )}
                </div>
            )}
        </PublicLayout>
    );
}
