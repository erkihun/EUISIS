import { Link } from '@inertiajs/react';

/**
 * Tone shows as a single hairline rail on the leading edge. Colour never
 * carries meaning on its own — the label and value always state it in text.
 */
const toneRail = {
    primary: 'bg-[color:var(--color-primary)]',
    success: 'bg-emerald-600',
    warning: 'bg-amber-500',
    critical: 'bg-red-600',
    neutral: 'bg-gray-300 dark:bg-slate-700',
} as const;

interface Props {
    title: string;
    value: string | number;
    /**
     * Accepted for call-site compatibility, deliberately not drawn.
     *
     * Every tile used to carry an icon chip in its top-right corner. With six
     * tiles in a row that is six glyphs competing with six numbers, and none of
     * them tells you anything the label doesn't — there is no icon that
     * distinguishes "Active Employees" from "Registered Employees". The number
     * is the content; it gets the space.
     */
    icon?: string;
    tone?: keyof typeof toneRail;
    trend?: number | null;
    trendDirection?: 'up' | 'down' | 'flat' | null;
    comparisonLabel?: string | null;
    /** Destination when the viewer may open the underlying list. */
    href?: string | null;
}

export default function KpiCard({
    title,
    value,
    tone = 'neutral',
    trend,
    trendDirection,
    comparisonLabel,
    href = null,
}: Props) {
    const rail = toneRail[tone] ?? toneRail.neutral;
    const hasTrend = trend !== null && trend !== undefined;

    const trendColor =
        trendDirection === 'up'
            ? 'text-emerald-700 dark:text-emerald-400'
            : trendDirection === 'down'
              ? 'text-red-700 dark:text-red-400'
              : 'text-gray-500 dark:text-slate-400';

    const body = (
        <>
            <span aria-hidden="true" className={`absolute inset-y-0 left-0 w-0.5 ${rail}`} />

            {/*
             * Value first, label under it. A dashboard is scanned for numbers;
             * leading with the caption makes the reader parse a sentence before
             * reaching the thing they came for.
             */}
            <p className="text-[26px] font-semibold leading-none tabular-nums tracking-tight text-gray-900 dark:text-slate-50">
                {value}
            </p>

            <p className="mt-1.5 truncate text-xs leading-snug text-gray-600 dark:text-slate-400">
                {title}
            </p>

            {hasTrend && (
                <p className={`mt-1 text-xs ${trendColor}`}>
                    <span className="font-medium tabular-nums">
                        {trend > 0 ? '+' : ''}
                        {trend}
                    </span>
                    {comparisonLabel && (
                        <span className="ml-1 text-gray-500 dark:text-slate-500">{comparisonLabel}</span>
                    )}
                </p>
            )}
        </>
    );

    const shell =
        'relative overflow-hidden rounded-card border border-gray-200 bg-white py-3 pl-4 pr-3 dark:border-slate-800 dark:bg-slate-900';

    if (!href) {
        return <div className={shell}>{body}</div>;
    }

    return (
        <Link
            href={href}
            className={`${shell} block transition-colors hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)] focus-visible:ring-offset-2 dark:hover:bg-slate-800/60 dark:focus-visible:ring-offset-slate-950`}
        >
            {body}
        </Link>
    );
}
