import { Link } from '@inertiajs/react';
import {
    ActivityIcon,
    AlertTriangle,
    ArrowLeftRightIcon,
    Building2,
    ClipboardListIcon,
    CreditCard,
    Layers,
    LayoutDashboard,
    MinusIcon,
    ShieldCheck,
    Store,
    TrendingDownIcon,
    TrendingUpIcon,
    Users,
} from '@/Components/Icons';

/*
 * `card` is the light-theme tint (card-tone-* in app.css); the dark theme keeps
 * its slate surface. Icon tiles are solid in light so they read on the tint.
 */
const toneStyles = {
    primary: {
        marker: 'bg-primary',
        card: 'card-tone-info',
        icon: 'bg-primary text-white dark:bg-primary/10 dark:text-primary',
    },
    success: {
        marker: 'bg-emerald-500',
        card: 'card-tone-success',
        icon: 'bg-emerald-700 text-white dark:bg-emerald-950/60 dark:text-emerald-400',
    },
    warning: {
        marker: 'bg-amber-500',
        card: 'card-tone-warning',
        icon: 'bg-amber-700 text-white dark:bg-amber-950/60 dark:text-amber-400',
    },
    critical: {
        marker: 'bg-red-500',
        card: 'card-tone-danger',
        icon: 'bg-red-700 text-white dark:bg-red-950/60 dark:text-red-400',
    },
    neutral: {
        marker: 'bg-slate-400 dark:bg-slate-600',
        card: 'card-tone-neutral',
        icon: 'bg-slate-600 text-white dark:bg-slate-800 dark:text-(--app-muted-foreground)',
    },
} as const;

type IconName =
    | 'users'
    | 'card'
    | 'building'
    | 'layers'
    | 'shield'
    | 'queue'
    | 'alert'
    | 'transfer'
    | 'coverage'
    | 'activity'
    | 'primary'
    | 'store';

const icons: Record<IconName, typeof Users> = {
    users: Users,
    card: CreditCard,
    building: Building2,
    layers: Layers,
    shield: ShieldCheck,
    queue: ClipboardListIcon,
    alert: AlertTriangle,
    transfer: ArrowLeftRightIcon,
    coverage: ActivityIcon,
    activity: ActivityIcon,
    primary: LayoutDashboard,
    store: Store,
};

interface Props {
    title: string;
    value: string | number;
    icon?: IconName;
    tone?: keyof typeof toneStyles;
    trend?: number | null;
    trendDirection?: 'up' | 'down' | 'flat' | null;
    comparisonLabel?: string | null;
    href?: string | null;
    featured?: boolean;
    /** Recent values of the same measure, oldest first; drawn as a small line when at least three exist. */
    spark?: number[] | null;
}

/** A 96×28 polyline over the series, normalised to its own range; a flat series draws a mid line. */
function sparkPoints(values: number[]): string {
    const width = 96;
    const height = 28;
    const min = Math.min(...values);
    const max = Math.max(...values);
    const span = max - min || 1;
    const step = values.length > 1 ? width / (values.length - 1) : width;

    return values.map((value, index) => `${(index * step).toFixed(1)},${(height - 2 - ((value - min) / span) * (height - 4)).toFixed(1)}`).join(' ');
}

export default function KpiCard({
    title,
    value,
    icon = 'primary',
    tone = 'neutral',
    trend,
    trendDirection,
    comparisonLabel,
    href = null,
    featured = false,
    spark = null,
}: Props) {
    const sparkline = spark && spark.length >= 3 ? sparkPoints(spark) : null;
    const style = toneStyles[tone] ?? toneStyles.neutral;
    const Icon = icons[icon] ?? LayoutDashboard;
    const hasTrend = trend !== null && trend !== undefined;
    const TrendIcon =
        trendDirection === 'up'
            ? TrendingUpIcon
            : trendDirection === 'down'
              ? TrendingDownIcon
              : MinusIcon;

    const trendChip =
        trendDirection === 'up'
            ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300'
            : trendDirection === 'down'
              ? 'bg-red-50 text-red-800 dark:bg-red-950/60 dark:text-red-300'
              : 'bg-(--app-surface-muted) text-(--app-muted-foreground)';

    const body = (
        <div className="relative z-1 flex h-full flex-col">
            <div className="flex items-start justify-between gap-4">
                <p
                    className="min-w-0 whitespace-normal wrap-break-word text-xs font-medium leading-relaxed text-(--app-muted-foreground) sm:text-sm"
                >
                    {title}
                </p>
                <span
                    className={`hidden h-7 w-7 shrink-0 place-items-center rounded-md sm:grid ${style.icon}`}
                    aria-hidden="true"
                >
                    <Icon
                        className="h-4 w-4"
                    />
                </span>
            </div>

            <div className="flex items-end justify-between gap-3 pt-3">
                <p className="wrap-break-word text-2xl font-semibold leading-tight tabular-nums tracking-tight text-gray-950 sm:text-3xl dark:text-white">
                    {value}
                </p>
                {sparkline && (
                    <svg width="96" height="28" viewBox="0 0 96 28" className="hidden shrink-0 text-primary sm:block dark:text-indigo-300" aria-hidden="true">
                        <polyline points={sparkline} fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
                    </svg>
                )}
            </div>

            {hasTrend && (
                <p className="mt-auto flex flex-wrap items-center gap-1.5 pt-3 text-xs">
                    <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-semibold tabular-nums ${trendChip}`}>
                        <TrendIcon className="h-3.5 w-3.5" aria-hidden="true" />
                        {trend > 0 ? '+' : ''}
                        {trend}
                    </span>
                    {comparisonLabel && <span className="text-(--app-muted-foreground)">{comparisonLabel}</span>}
                </p>
            )}

        </div>
    );

    const shell = [
        'relative h-full min-w-0 overflow-hidden rounded-panel border transition-colors',
        style.card,
        featured
            ? 'min-h-[112px] border-(--app-border) border-s-[3px] border-s-primary p-4 dark:border-s-indigo-400 '
            : 'min-h-[112px] border-(--app-border) p-4 ',
    ].join(' ');

    if (!href) {
        return <div className={shell}>{body}</div>;
    }

    return (
        <Link
            href={href}
            className={`${shell} group block hover:border-(--app-border-strong) focus:outline-hidden focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 dark:hover:bg-slate-800/70 dark:focus-visible:ring-offset-slate-950`}
        >
            {body}
        </Link>
    );
}
