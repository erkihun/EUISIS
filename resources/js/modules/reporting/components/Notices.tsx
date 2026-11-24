import { cx } from '@/Components/ui';
import { useReporting } from '@/modules/reporting/lib/helpers';
import { usePage } from '@inertiajs/react';
import type { JSX, ReactNode } from 'react';

const tones = {
    danger: 'border-red-300 bg-red-50 text-red-900 dark:border-red-800 dark:bg-red-950/40 dark:text-red-100',
    warning: 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100',
    info: 'border-sky-300 bg-sky-50 text-sky-900 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-100',
};

export function Notice({ tone = 'info', children, role = 'status' }: { tone?: keyof typeof tones; children: ReactNode; role?: 'status' | 'alert' }): JSX.Element {
    return <div role={role} className={cx('rounded-control border px-3 py-2 text-sm', tones[tone])}>{children}</div>;
}

/**
 * What the server refused: a workflow rule, a file, or a field. Shown at the
 * top of the page so a refusal is never silent. Field errors named in
 * `except` are shown beside their fields instead.
 */
export function ActionErrors({ except = [] }: { except?: string[] }): JSX.Element | null {
    const errors = (usePage().props.errors ?? {}) as Record<string, string>;
    const messages = Object.entries(errors).filter(([key]) => !except.includes(key)).map(([, message]) => message);
    if (messages.length === 0) return null;

    return (
        <div className="mb-4">
            <Notice tone="danger" role="alert">
                {messages.length === 1 ? messages[0] : <ul className="list-disc space-y-1 ps-5">{messages.map((message) => <li key={message}>{message}</li>)}</ul>}
            </Notice>
        </div>
    );
}

/** The maintenance switch is on: the pages stay readable, nothing can be saved. */
export function PausedNotice({ paused }: { paused: boolean }): JSX.Element | null {
    const { rt } = useReporting();
    if (!paused) return null;

    return <div className="mb-4"><Notice tone="warning">{rt('common.paused')}</Notice></div>;
}
