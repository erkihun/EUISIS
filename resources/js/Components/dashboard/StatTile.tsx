import { Link } from '@inertiajs/react';
import { useDisplayFormat } from '@/hooks/useDisplayFormat';

export type StatTone = 'neutral' | 'success' | 'warning' | 'critical';

const toneText: Record<StatTone, string> = {
    neutral: 'text-gray-900 dark:text-slate-100',
    success: 'text-emerald-700 dark:text-emerald-400',
    warning: 'text-[color:var(--color-accent)]',
    critical: 'text-red-700 dark:text-red-400',
};

export type Stat = {
    key: string;
    label: string;
    /**
     * `null` means the figure was not loaded or is unavailable. It renders as
     * an em dash, never as 0 — a real zero and a missing value are different
     * facts and an operator must be able to tell them apart.
     */
    value: number | string | null;
    tone?: StatTone;
    href?: string | null;
};

/**
 * Dense operational readout: a compact row of labelled figures.
 *
 * Used for the ID, NFC and integration panels where an operator wants many
 * exact numbers at a glance rather than a chart.
 */
export default function StatTileGroup({ stats, columns = 4 }: { stats: Stat[]; columns?: 3 | 4 }) {
    /* Thousands separator follows `localization.number_format`. */
    const { number } = useDisplayFormat();

    const gridCls =
        columns === 3
            ? 'grid grid-cols-2 sm:grid-cols-3'
            : 'grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4';

    /*
     * Cell borders rather than a `gap-px` grid over a tinted container: with a
     * count that does not fill the last row, the container's colour showed
     * through as a stray grey block where a tile should have been.
     * `overflow-hidden` clips the trailing edges.
     */
    return (
        <div className={`${gridCls} overflow-hidden rounded-card border border-gray-200 dark:border-slate-800`}>
            {stats.map((stat) => {
                const unavailable = stat.value === null;
                const display = unavailable
                    ? '—'
                    : typeof stat.value === 'number'
                      ? number(stat.value)
                      : stat.value;

                /*
                 * Zero of a bad thing is a good thing. Without this, a clean
                 * ID-card panel showed "Expired 0 / Lost 0 / Revoked 0" in
                 * three shades of red, which is exactly how a reader learns
                 * that the colours on this page mean nothing.
                 */
                const isEmptyBadThing =
                    (stat.tone === 'warning' || stat.tone === 'critical') && Number(stat.value) === 0;
                const tone: StatTone = isEmptyBadThing ? 'neutral' : (stat.tone ?? 'neutral');

                const content = (
                    <>
                        <dt className="min-h-8 whitespace-normal break-words text-xs leading-snug text-gray-600 dark:text-slate-400">
                            {stat.label}
                        </dt>
                        <dd
                            className={`mt-1 text-lg font-semibold tabular-nums ${
                                unavailable ? 'text-gray-400 dark:text-slate-600' : toneText[tone]
                            }`}
                        >
                            {display}
                        </dd>
                    </>
                );

                const cellCls =
                    'border-b border-r border-gray-200 bg-white px-3 py-2.5 dark:border-slate-800 dark:bg-slate-900';

                return stat.href && !unavailable ? (
                    <Link
                        key={stat.key}
                        href={stat.href}
                        className={`${cellCls} block transition-colors hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[color:var(--color-primary)] dark:hover:bg-slate-800`}
                    >
                        {content}
                    </Link>
                ) : (
                    <div key={stat.key} className={cellCls}>
                        {content}
                    </div>
                );
            })}
        </div>
    );
}
