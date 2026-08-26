import { router } from '@inertiajs/react';
import { useState } from 'react';
import type { JSX, ReactNode } from 'react';
import { useLocale } from '@/hooks/useLocale';

/**
 * The two public QR codes for an employee, shown on the detail page.
 *
 * Each card is rendered only when the server sent data for it, and the server
 * sends data only to a user holding the matching permission — so visibility is
 * decided server-side and this component never has to be trusted as a gate.
 *
 * Both payloads are a bare URL. Nothing about the employee travels inside the
 * code, which is why a printed QR can sit on a public desk safely.
 */

type IdCardQr = {
    url: string;
    qr_svg: string;
    card_number: string | null;
} | null;

type FeedbackQr = {
    url: string;
    qr_svg: string;
    status: string;
} | null;

export type QrCodesProp = {
    canViewIdQr: boolean;
    canManageFeedbackQr: boolean;
    idCard: IdCardQr;
    feedback: FeedbackQr;
};

export default function EmployeeQrCards({
    employeeId,
    qrCodes,
}: {
    employeeId: string;
    qrCodes?: QrCodesProp;
}): JSX.Element | null {
    const { t } = useLocale();

    // Nothing to show for a user who may see neither code.
    if (!qrCodes || (!qrCodes.canViewIdQr && !qrCodes.canManageFeedbackQr)) {
        return null;
    }

    return (
        <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900 sm:p-6">
            <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">
                {t('employees.qrSectionTitle')}
            </h2>
            <p className="mt-1 text-xs text-gray-500 dark:text-slate-400">
                {t('employees.qrSectionHint')}
            </p>

            <div className="mt-4 grid gap-4 sm:grid-cols-2">
                {qrCodes.canViewIdQr && (
                    <QrCard
                        title={t('employees.idVerificationQr')}
                        url={qrCodes.idCard?.url ?? null}
                        svg={qrCodes.idCard?.qr_svg ?? ''}
                        emptyLabel={t('employees.noActiveIdCard')}
                        subtitle={qrCodes.idCard?.card_number ?? undefined}
                    />
                )}

                {qrCodes.canManageFeedbackQr && (
                    <QrCard
                        title={t('employees.feedbackSuggestionQr')}
                        url={qrCodes.feedback?.url ?? null}
                        svg={qrCodes.feedback?.qr_svg ?? ''}
                        emptyLabel={t('employees.noFeedbackQr')}
                        downloadHref={route('employees.feedback-qr.png', employeeId)}
                        printHref={route('employees.feedback-qr.pdf', employeeId)}
                        onRegenerate={() => {
                            // Destructive: any QR already printed stops working.
                            if (!window.confirm(t('serviceFeedback.regenerateQrWarning'))) {
                                return;
                            }

                            router.post(
                                route('employees.feedback-qr.regenerate', employeeId),
                                {},
                                { preserveScroll: true },
                            );
                        }}
                        onRevoke={() => {
                            if (!window.confirm(t('confirmations.deleteWarning'))) {
                                return;
                            }

                            router.post(
                                route('employees.feedback-qr.revoke', employeeId),
                                {},
                                { preserveScroll: true },
                            );
                        }}
                    />
                )}
            </div>
        </div>
    );
}

function QrCard({
    title,
    subtitle,
    url,
    svg,
    emptyLabel,
    downloadHref,
    printHref,
    onRegenerate,
    onRevoke,
}: {
    title: string;
    subtitle?: string;
    url: string | null;
    svg: string;
    emptyLabel: string;
    downloadHref?: string;
    printHref?: string;
    onRegenerate?: () => void;
    onRevoke?: () => void;
}): JSX.Element {
    const { t } = useLocale();
    const [copied, setCopied] = useState(false);

    async function copyLink() {
        if (url === null) {
            return;
        }

        try {
            await navigator.clipboard.writeText(url);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        } catch {
            // Clipboard access is denied on insecure origins; the URL is shown
            // beneath the code for manual copying, so failing quietly is fine.
        }
    }

    return (
        <div className="rounded-xl border border-gray-100 bg-gray-50 p-4 text-center dark:border-slate-800 dark:bg-slate-950">
            <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                {title}
            </h3>

            {url === null ? (
                <p className="py-10 text-sm text-gray-500 dark:text-slate-400">{emptyLabel}</p>
            ) : (
                <>
                    {svg !== '' && (
                        <img
                            src={svg}
                            alt={title}
                            className="mx-auto mt-3 h-40 w-40 rounded-lg bg-white p-2"
                        />
                    )}

                    {subtitle && (
                        <p className="mt-2 font-mono text-xs text-gray-500 dark:text-slate-400">{subtitle}</p>
                    )}

                    <p className="mt-2 break-all text-[11px] leading-snug text-gray-400 dark:text-slate-500">
                        {url}
                    </p>

                    <div className="mt-3 grid gap-2">
                        <div className="grid grid-cols-2 gap-2">
                            <ActionButton onClick={copyLink}>
                                {copied ? t('serviceFeedback.linkCopied') : t('serviceFeedback.copyLink')}
                            </ActionButton>

                            {printHref ? (
                                <ActionLink href={printHref}>{t('serviceFeedback.printQr')}</ActionLink>
                            ) : (
                                <ActionButton onClick={() => window.print()}>
                                    {t('serviceFeedback.printQr')}
                                </ActionButton>
                            )}
                        </div>

                        {downloadHref && (
                            <ActionLink href={downloadHref}>{t('serviceFeedback.exportQrPng')}</ActionLink>
                        )}

                        {(onRegenerate || onRevoke) && (
                            <div className="grid grid-cols-2 gap-2 border-t border-gray-200 pt-2 dark:border-slate-800">
                                {onRegenerate && (
                                    <button
                                        type="button"
                                        onClick={onRegenerate}
                                        className="rounded-lg border border-amber-300 px-2 py-1.5 text-xs font-medium text-amber-700 transition hover:bg-amber-50 dark:border-amber-900 dark:text-amber-400 dark:hover:bg-amber-950/40"
                                    >
                                        {t('serviceFeedback.regenerateQr')}
                                    </button>
                                )}
                                {onRevoke && (
                                    <button
                                        type="button"
                                        onClick={onRevoke}
                                        className="rounded-lg border border-red-300 px-2 py-1.5 text-xs font-medium text-red-700 transition hover:bg-red-50 dark:border-red-900 dark:text-red-400 dark:hover:bg-red-950/40"
                                    >
                                        {t('serviceFeedback.revokeQr')}
                                    </button>
                                )}
                            </div>
                        )}
                    </div>
                </>
            )}
        </div>
    );
}

const actionCls =
    'block rounded-lg border border-gray-300 px-2 py-1.5 text-center text-xs font-medium text-gray-700 transition hover:bg-white dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800';

function ActionButton({ onClick, children }: { onClick: () => void; children: ReactNode }): JSX.Element {
    return (
        <button type="button" onClick={onClick} className={actionCls}>
            {children}
        </button>
    );
}

function ActionLink({ href, children }: { href: string; children: ReactNode }): JSX.Element {
    return (
        <a href={href} className={actionCls}>
            {children}
        </a>
    );
}
