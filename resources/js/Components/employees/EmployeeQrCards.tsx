import { router } from '@inertiajs/react';
import { useState } from 'react';
import type { JSX, ReactNode } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';

import { buttonClassName } from '@/Components/ui';
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
    const { confirm } = useConfirm();

    // Nothing to show for a user who may see neither code.
    if (!qrCodes || (!qrCodes.canViewIdQr && !qrCodes.canManageFeedbackQr)) {
        return null;
    }

    return (
        <div className="rounded-panel border border-(--app-border) bg-(--app-surface) p-5 sm:p-6">
            <h2 className="text-sm font-semibold text-(--app-foreground)">
                {t('employees.qrSectionTitle')}
            </h2>
            <p className="mt-1 text-xs text-(--app-muted-foreground)">
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
                        onRegenerate={async () => {
                            // Destructive: any QR already printed stops working.
                            const result = await confirm({ title: t('serviceFeedback.regenerateQrWarning'), variant: 'warning' });
                            if (!result.confirmed) return;

                            router.post(
                                route('employees.feedback-qr.regenerate', employeeId),
                                {},
                                { preserveScroll: true },
                            );
                        }}
                        onRevoke={async () => {
                            const result = await confirm({ title: t('confirmations.deleteWarning'), variant: 'danger' });
                            if (!result.confirmed) return;

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
        <div className="rounded-card border border-(--app-border) bg-(--app-surface-muted) p-4 text-center">
            <h3 className="text-xs font-semibold text-(--app-muted-foreground)">
                {title}
            </h3>

            {url === null ? (
                <p className="py-10 text-sm text-(--app-muted-foreground)">{emptyLabel}</p>
            ) : (
                <>
                    {svg !== '' && (
                        <img
                            src={svg}
                            alt={title}
                            className="mx-auto mt-3 h-40 w-40 rounded-lg bg-(--app-surface) p-2"
                        />
                    )}

                    {subtitle && (
                        <p className="mt-2 font-mono text-xs text-(--app-muted-foreground)">{subtitle}</p>
                    )}

                    <p className="mt-2 break-all text-[11px] leading-snug text-(--app-muted-foreground)">
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
                            <div className="grid grid-cols-2 gap-2 border-t border-(--app-border) pt-2">
                                {onRegenerate && (
                                    <button
                                        type="button"
                                        onClick={onRegenerate}
                                        className={buttonClassName({ variant: 'outline', size: 'sm' })}
                                    >
                                        {t('serviceFeedback.regenerateQr')}
                                    </button>
                                )}
                                {onRevoke && (
                                    <button
                                        type="button"
                                        onClick={onRevoke}
                                        className={buttonClassName({ variant: 'outline', size: 'sm' })}
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

const actionCls = buttonClassName({ variant: 'outline', size: 'sm', className: 'block text-center' });

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
