import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import RatingStars from '@/Components/ServiceFeedback/RatingStars';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useState } from 'react';
import type { JSX } from 'react';

type Token = {
    id: string;
    status: string;
    url: string;
    qr_svg: string;
    scan_count: number;
    last_scanned_at: string | null;
    created_at: string | null;
};

type Props = {
    employee: { id: string; name: string | null; employee_number: string | null; status?: string };
    token: Token | null;
    /** Why there is no token: an admin disabled it, or the employee is inactive. */
    unavailableReason?: 'disabled' | 'inactive_employee' | null;
    stats: {
        total: number;
        average: number;
        recent: {
            id: string;
            rating: number;
            comment: string | null;
            status: string;
            created_at: string | null;
            service_type: string | null;
        }[];
    };
};

export default function EmployeeFeedbackQr({ employee, token, stats, unavailableReason }: Props): JSX.Element {
    const { t } = useLocale();
    const [copied, setCopied] = useState(false);

    async function copyLink() {
        if (token === null) {
            return;
        }

        try {
            await navigator.clipboard.writeText(token.url);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        } catch {
            // Clipboard access is denied on insecure origins and in some
            // browsers; the URL is displayed beneath the QR for manual copying,
            // so a silent no-op is better than an alert here.
        }
    }

    /*
     * Printing opens the server-rendered PDF sheet rather than window.print()
     * on this admin page, so the printed output is the desk-ready layout with
     * instructions — not a screenshot of the dashboard chrome.
     */
    function printQr() {
        window.open(route('employees.feedback-qr.pdf', employee.id), '_blank');
    }

    function generate() {
        router.post(route('employees.feedback-qr.generate', employee.id), {}, { preserveScroll: true });
    }

    function regenerate() {
        // Destructive: any QR already printed and taped to a desk dies here.
        if (!window.confirm(t('serviceFeedback.regenerateQrWarning'))) {
            return;
        }

        router.post(route('employees.feedback-qr.regenerate', employee.id), {}, { preserveScroll: true });
    }

    function revoke() {
        if (!window.confirm(t('confirmations.deleteWarning'))) {
            return;
        }

        router.post(route('employees.feedback-qr.revoke', employee.id), {}, { preserveScroll: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title={t('serviceFeedback.employeeFeedbackQr')} />

            <div className="space-y-5">
                <PageHeader
                    title={t('serviceFeedback.employeeFeedbackQr')}
                    description={`${employee.name ?? ''} · ${employee.employee_number ?? ''}`}
                    backHref={route('employees.show', employee.id)}
                />

                <div className="grid gap-5 lg:grid-cols-3">
                    {/* QR card */}
                    <div className="rounded-xl border border-gray-200 bg-white p-5 text-center dark:border-slate-800 dark:bg-slate-900">
                        {token === null ? (
                            <>
                                {/*
                                  * An active employee always has a QR by now —
                                  * the server provisions one when this page
                                  * loads. Reaching here means the token was
                                  * deliberately revoked/suspended, or the
                                  * employee is not active, so the copy explains
                                  * which rather than implying a failure.
                                  */}
                                <p className="py-6 text-sm text-gray-500 dark:text-slate-400">
                                    {unavailableReason === 'disabled'
                                        ? t('serviceFeedback.qrDisabledByAdmin')
                                        : t('serviceFeedback.qrInactiveEmployee')}
                                </p>
                                <button
                                    type="button"
                                    onClick={generate}
                                    className="w-full rounded-lg bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700"
                                >
                                    {t('serviceFeedback.generateQr')}
                                </button>
                            </>
                        ) : (
                            <>
                                <div className="flex justify-center">
                                    <StatusBadge status={token.status} />
                                </div>

                                {token.qr_svg !== '' && (
                                    <img
                                        src={token.qr_svg}
                                        alt={t('serviceFeedback.employeeFeedbackQr')}
                                        className="mx-auto mt-4 h-52 w-52 rounded-lg bg-white p-2"
                                    />
                                )}

                                <p className="mt-3 break-all text-xs text-gray-500 dark:text-slate-400">{token.url}</p>

                                <p className="mt-3 text-xs text-gray-500 dark:text-slate-400">
                                    {t('serviceFeedback.feedbackQrDescription')}
                                </p>

                                <div className="mt-4 grid gap-2">
                                    <div className="grid grid-cols-2 gap-2">
                                        <button
                                            type="button"
                                            onClick={copyLink}
                                            className="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                                        >
                                            {copied ? t('serviceFeedback.linkCopied') : t('serviceFeedback.copyLink')}
                                        </button>
                                        <button
                                            type="button"
                                            onClick={printQr}
                                            className="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                                        >
                                            {t('serviceFeedback.printQr')}
                                        </button>
                                    </div>
                                    <div className="grid grid-cols-2 gap-2">
                                        <a
                                            href={route('employees.feedback-qr.png', employee.id)}
                                            className="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                                        >
                                            {t('serviceFeedback.exportQrPng')}
                                        </a>
                                        <a
                                            href={route('employees.feedback-qr.pdf', employee.id)}
                                            className="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                                        >
                                            {t('serviceFeedback.exportQrPdf')}
                                        </a>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={regenerate}
                                        className="rounded-lg border border-amber-300 px-3 py-1.5 text-sm font-medium text-amber-700 hover:bg-amber-50 dark:border-amber-900 dark:text-amber-400 dark:hover:bg-amber-950/40"
                                    >
                                        {t('serviceFeedback.regenerateQr')}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={revoke}
                                        className="rounded-lg border border-red-300 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50 dark:border-red-900 dark:text-red-400 dark:hover:bg-red-950/40"
                                    >
                                        {t('serviceFeedback.revokeQr')}
                                    </button>
                                </div>

                                <dl className="mt-4 space-y-1.5 border-t border-gray-100 pt-3 text-left text-xs dark:border-slate-800">
                                    <div className="flex justify-between">
                                        <dt className="text-gray-500 dark:text-slate-400">{t('serviceFeedback.lastScanned')}</dt>
                                        <dd className="text-gray-800 dark:text-slate-200">
                                            <LocalizedDateDisplay value={token.last_scanned_at} withTime />
                                        </dd>
                                    </div>
                                </dl>
                            </>
                        )}
                    </div>

                    {/* Feedback summary */}
                    <div className="space-y-5 lg:col-span-2">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="rounded-xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                                <div className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                                    {t('serviceFeedback.feedbackCount')}
                                </div>
                                <div className="mt-1 text-2xl font-semibold text-gray-900 dark:text-slate-100">
                                    {stats.total}
                                </div>
                            </div>

                            <div className="rounded-xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                                <div className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                                    {t('serviceFeedback.averageRating')}
                                </div>
                                <div className="mt-1 flex items-center gap-2">
                                    <span className="text-2xl font-semibold text-gray-900 dark:text-slate-100">
                                        {stats.average.toFixed(2)}
                                    </span>
                                    <RatingStars rating={Math.round(stats.average)} />
                                </div>
                            </div>
                        </div>

                        <div className="rounded-xl border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                            <h2 className="border-b border-gray-100 px-5 py-3 text-sm font-semibold text-gray-900 dark:border-slate-800 dark:text-slate-100">
                                {t('serviceFeedback.recentComments')}
                            </h2>

                            {stats.recent.length === 0 ? (
                                <p className="px-5 py-6 text-sm text-gray-500 dark:text-slate-400">
                                    {t('serviceFeedback.noFeedbackYet')}
                                </p>
                            ) : (
                                <ul className="divide-y divide-gray-100 dark:divide-slate-800">
                                    {stats.recent.map((item) => (
                                        <li key={item.id} className="px-5 py-3">
                                            <div className="flex items-center gap-2">
                                                <RatingStars rating={item.rating} />
                                                <span className="text-xs text-gray-500 dark:text-slate-400">
                                                    {item.service_type ?? '—'}
                                                </span>
                                            </div>
                                            {item.comment && (
                                                <p className="mt-1 text-sm text-gray-700 dark:text-slate-300">{item.comment}</p>
                                            )}
                                            <p className="mt-1 text-xs text-gray-500 dark:text-slate-400">
                                                <LocalizedDateDisplay value={item.created_at} withTime />
                                            </p>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
