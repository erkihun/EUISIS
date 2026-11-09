import { usePage } from '@inertiajs/react';
import type { JSX } from 'react';

/**
 * Why a page action was refused (lock, certify, accept, verify…). These
 * errors belong to no form field, so without this they would be silent.
 */
const ACTION_KEYS = ['period', 'submission', 'certification', 'verification', 'deadline', 'section', 'attest'] as const;

export default function ActionErrors(): JSX.Element | null {
    const errors = usePage().props.errors as Record<string, string>;
    const messages = ACTION_KEYS.map((key) => errors[key]).filter((message): message is string => Boolean(message));
    if (messages.length === 0) return null;

    return (
        <div role="alert" className="mb-4 rounded-panel border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200">
            {messages.map((message) => <p key={message}>{message}</p>)}
        </div>
    );
}
