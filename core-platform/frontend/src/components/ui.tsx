import { useEffect, useRef, type ReactNode } from 'react';
import { useI18n } from '../i18n';

export const buttonClass = {
    primary: 'inline-flex items-center justify-center gap-2 rounded-md bg-brand px-4 py-2 text-sm font-medium text-white hover:bg-brand-strong disabled:opacity-60',
    secondary: 'inline-flex items-center justify-center gap-2 rounded-md border border-line bg-surface px-4 py-2 text-sm font-medium text-ink hover:bg-subtle disabled:opacity-60',
    danger: 'inline-flex items-center justify-center gap-2 rounded-md bg-danger px-4 py-2 text-sm font-medium text-white hover:opacity-90 disabled:opacity-60',
    small: 'rounded border border-line px-2 py-1 text-xs font-medium text-ink hover:bg-subtle',
};

/** Field look without a width, for controls that size themselves (filters). */
export const controlClass = 'rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink placeholder:text-muted focus:border-brand focus:ring-2 focus:ring-brand/20 focus:outline-none';

export const inputClass = `w-full ${controlClass}`;

const tone: Record<string, string> = {
    active: 'bg-ok-soft text-ok',
    issued: 'bg-info-soft text-info',
    suspended: 'bg-warn-soft text-warn',
    revoked: 'bg-danger-soft text-danger',
    closed: 'bg-subtle text-muted',
    inactive: 'bg-subtle text-muted',
};

export function StatusBadge({ value }: { value: string | null | undefined }) {
    const { status } = useI18n();
    const classes = tone[value ?? ''] ?? 'bg-subtle text-ink';

    return <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${classes}`}>{status(value)}</span>;
}

export function Field({ label, error, help, children }: { label: string; error?: string | null; help?: string; children: ReactNode }) {
    return (
        <label className="block space-y-1">
            <span className="text-sm font-medium text-ink">{label}</span>
            {children}
            {help && !error && <span className="block text-xs text-muted">{help}</span>}
            {error && <span className="block text-xs text-danger">{error}</span>}
        </label>
    );
}

export function Notice({ kind, children }: { kind: 'ok' | 'error'; children: ReactNode }) {
    const classes = kind === 'ok' ? 'border-ok/30 bg-ok-soft text-ok' : 'border-danger/30 bg-danger-soft text-danger';

    return (
        <div role={kind === 'error' ? 'alert' : 'status'} className={`rounded-md border px-4 py-3 text-sm ${classes}`}>
            {children}
        </div>
    );
}

export function Card({ title, actions, children }: { title?: string; actions?: ReactNode; children: ReactNode }) {
    return (
        <section className="rounded-lg border border-line bg-surface">
            {(title || actions) && (
                <header className="flex flex-wrap items-center justify-between gap-2 border-b border-line px-4 py-3">
                    {title && <h2 className="text-sm font-semibold text-ink">{title}</h2>}
                    {actions}
                </header>
            )}
            <div className="p-4">{children}</div>
        </section>
    );
}

export function Modal({ title, onClose, children }: { title: string; onClose: () => void; children: ReactNode }) {
    const dialog = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => event.key === 'Escape' && onClose();
        window.addEventListener('keydown', onKey);
        dialog.current?.querySelector<HTMLElement>('textarea, input, select, button')?.focus();
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
            <div ref={dialog} role="dialog" aria-modal="true" aria-label={title} className="w-full max-w-md rounded-lg bg-surface p-5 shadow-xl">
                <h2 className="mb-4 text-base font-semibold text-ink">{title}</h2>
                {children}
            </div>
        </div>
    );
}

export function Detail({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div>
            <dt className="text-xs text-muted">{label}</dt>
            <dd className="mt-0.5 text-sm text-ink">{children}</dd>
        </div>
    );
}
