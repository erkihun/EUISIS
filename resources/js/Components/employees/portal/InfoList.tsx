import type { ReactNode } from 'react';
import { PrintedChip } from './ui';

export type InfoRow = { key: string; label: string; value: ReactNode; printed?: boolean };

/**
 * Read-only fields as a two-column definition list. Authoritative data is
 * shown as text, never inside disabled inputs, so nothing suggests it can be
 * edited here.
 */
export default function InfoList({ rows, printedLabel }: { rows: InfoRow[]; printedLabel: string }) {
    return (
        <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2">
            {rows.map((row) => (
                <div key={row.key} className="min-w-0">
                    <dt className="flex flex-wrap items-center gap-1.5 text-xs text-(--app-muted-foreground)">
                        {row.label}
                        {row.printed && <PrintedChip label={printedLabel} />}
                    </dt>
                    <dd className="mt-0.5 wrap-break-word text-sm font-medium text-(--app-foreground)">{row.value || '—'}</dd>
                </div>
            ))}
        </dl>
    );
}
