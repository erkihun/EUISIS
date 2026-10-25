import { Link } from '@inertiajs/react';
import { Alert, Button, buttonClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import type { PublicAssistantError } from '@/types/publicAssistant';
import { safeRoute } from './shared';

/** The sentence for an error: the server's own for validation, the dictionary's otherwise. */
export function errorText(error: PublicAssistantError, t: (key: string) => string): string {
    return error.code === 'validation' && error.message ? error.message : t(`publicAssistant.errors.${error.code}`);
}

/** One error with its one remedy: reload after an expired session, Retry when it may pass, otherwise Dismiss. */
export function PublicAssistantErrorLine({ error, onRetry, onDismiss }: { error: PublicAssistantError; onRetry?: () => void; onDismiss?: () => void }) {
    const { t } = useLocale();
    const soft = error.code === 'session_expired' || error.code === 'network' || error.code === 'unavailable' || error.code === 'rate_limited';

    return (
        <Alert tone={soft ? 'warning' : 'danger'} className="p-3 text-xs">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span>{errorText(error, t)}</span>
                <span className="flex shrink-0 gap-1">
                    {error.code === 'session_expired' ? (
                        <Button variant="outline" size="xs" onClick={() => window.location.reload()}>{t('publicAssistant.errors.reload')}</Button>
                    ) : (
                        <>
                            {onRetry && <Button variant="outline" size="xs" onClick={onRetry}>{t('publicAssistant.retry')}</Button>}
                            {onDismiss && <Button variant="ghost" size="xs" onClick={onDismiss}>{t('publicAssistant.errors.dismiss')}</Button>}
                        </>
                    )}
                </span>
            </div>
        </Alert>
    );
}

/** The public pages that always work; built from the route registry, never from a reply. */
const FALLBACK_LINKS = [
    { key: 'home', route: 'home', path: '/' },
    { key: 'services', route: 'public.services', path: '/services' },
    { key: 'support', route: 'public.support', path: '/support' },
    { key: 'verify', route: 'public.verify', path: '/verify' },
] as const;

interface Props {
    /** `limited`: AI narrative off, everything deterministic still works. `failed`: the bootstrap itself failed. */
    mode: 'limited' | 'failed';
    error?: PublicAssistantError | null;
    onRetry?: () => void;
}

/**
 * What the panel shows when help is reduced: a thin notice when only the AI
 * narrative is missing, or — when the bootstrap could not load at all — the
 * reason plus deterministic links to the pages a visitor needs most.
 */
export default function PublicAssistantFallback({ mode, error, onRetry }: Props) {
    const { t } = useLocale();

    if (mode === 'limited') {
        return <Alert tone="warning" className="p-2.5 text-xs">{t('publicAssistant.limitedNotice')}</Alert>;
    }

    const retryable = Boolean(error && ['network', 'unavailable', 'unknown', 'rate_limited'].includes(error.code));

    return (
        <div className="space-y-3">
            {error && <PublicAssistantErrorLine error={error} onRetry={retryable ? onRetry : undefined} />}
            <nav aria-label={t('publicAssistant.fallback.title')} className="rounded-card border border-(--app-border) bg-(--app-surface) p-3">
                <p className="text-xs font-medium text-(--app-foreground)">{t('publicAssistant.fallback.title')}</p>
                <p className="mt-0.5 text-xs text-(--app-muted-foreground)">{t('publicAssistant.errors.bootstrapFailed')}</p>
                <ul className="mt-2 flex flex-wrap gap-1.5">
                    {FALLBACK_LINKS.map((link) => (
                        <li key={link.key}>
                            <Link href={safeRoute(link.route, link.path)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>
                                {t(`publicAssistant.fallback.${link.key}`)}
                            </Link>
                        </li>
                    ))}
                </ul>
            </nav>
        </div>
    );
}
