import { Head, Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';

type Props = {
    status: number;
    message?: string;
    error_id?: string;
};

/**
 * Map an HTTP status code to the appropriate i18n key pair.
 * ONLY status 419 maps to session_expired — every other error has its own key.
 */
function getErrorKeys(status: number): { titleKey: string; messageKey: string } {
    switch (status) {
        case 400: return { titleKey: 'errors.generic_title', messageKey: 'errors.bad_request' };
        case 401: return { titleKey: 'errors.generic_title', messageKey: 'errors.unauthorized' };
        case 403: return { titleKey: 'errors.generic_title', messageKey: 'errors.forbidden' };
        case 404: return { titleKey: 'errors.generic_title', messageKey: 'errors.not_found' };
        case 405: return { titleKey: 'errors.generic_title', messageKey: 'errors.method_not_allowed' };
        case 409: return { titleKey: 'errors.generic_title', messageKey: 'errors.conflict' };
        case 419: return { titleKey: 'errors.session_expired_title', messageKey: 'errors.session_expired' };
        case 422: return { titleKey: 'errors.generic_title', messageKey: 'errors.validation_failed' };
        case 429: return { titleKey: 'errors.generic_title', messageKey: 'errors.too_many_requests' };
        case 503: return { titleKey: 'errors.generic_title', messageKey: 'errors.service_unavailable' };
        default:  return { titleKey: 'errors.generic_title', messageKey: 'errors.generic' };
    }
}

export default function ErrorPage({ status, error_id }: Props) {
    const { t } = useLocale();

    const { titleKey, messageKey } = getErrorKeys(status);
    const title   = t(titleKey);
    const message = t(messageKey);

    const is419 = status === 419;

    return (
        <>
            <Head title={`${status} – ${title}`} />

            <div className="flex min-h-screen flex-col items-center justify-center bg-gray-50 px-4 dark:bg-slate-950">
                <div className="w-full max-w-md rounded-panel border border-gray-200 bg-white p-8 text-center dark:border-slate-800 dark:bg-slate-900">

                    {/* Status badge */}
                    <p className="mb-2 font-mono text-xs font-semibold text-gray-400 dark:text-slate-500">
                        {t('errors.error_reference_label')} {status}
                    </p>

                    {/* Title */}
                    <h1 className="text-lg font-semibold text-gray-900 dark:text-slate-100">
                        {title}
                    </h1>

                    {/* User-facing message */}
                    <p className="mt-2 text-sm text-gray-600 dark:text-slate-400">
                        {message}
                    </p>

                    {/* Support reference — only shown when an error_id is present */}
                    {error_id && (
                        <p className="mt-4 text-xs text-gray-400 dark:text-slate-500">
                            {t('errors.support_reference')}{' '}
                            <code className="font-mono">{error_id}</code>
                        </p>
                    )}

                    {/* Actions */}
                    <div className="mt-6 flex flex-col gap-2 sm:flex-row sm:justify-center">
                        {is419 ? (
                            /* Session expired: refresh is the primary action */
                            <button
                                type="button"
                                onClick={() => window.location.reload()}
                                className="rounded-lg bg-[color:var(--color-primary)] px-4 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)]"
                            >
                                {t('errors.retry')}
                            </button>
                        ) : (
                            <>
                                <button
                                    type="button"
                                    onClick={() => window.history.back()}
                                    className="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-800"
                                >
                                    {t('errors.go_back')}
                                </button>
                                <Link
                                    href={route('dashboard')}
                                    className="rounded-lg bg-[color:var(--color-primary)] px-4 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)]"
                                >
                                    {t('errors.go_home')}
                                </Link>
                            </>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}
