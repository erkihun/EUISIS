import { usePage } from '@inertiajs/react';
import { Alert } from '@/Components/ui';
import type { JSX } from 'react';

/**
 * Validation errors of the action buttons (submit, dispatch, route…), which post
 * without a form of their own, so the page shows them in one place.
 */
export default function ActionErrors({ only, except }: { only?: string[]; except?: string[] }): JSX.Element | null {
    const errors = (usePage().props.errors ?? {}) as Record<string, string>;
    // `except` lists the fields a page already shows beside their inputs; everything else must surface here, or a refusal is silent.
    const entries = Object.entries(errors).filter(([key]) => (!only || only.includes(key)) && !except?.includes(key));
    if (entries.length === 0) return null;

    return (
        <Alert tone="danger" className="mb-4">
            <ul className="list-disc ps-5">
                {entries.map(([key, message]) => <li key={key}>{message}</li>)}
            </ul>
        </Alert>
    );
}
