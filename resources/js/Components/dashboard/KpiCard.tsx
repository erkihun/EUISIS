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

const toneStyles = {
    primary: {
        marker: 'bg-[color:var(--color-primary)]',
        icon: 'bg-[color:var(--color-primary)]/10 text-[color:var(--color-primary)]',
    },
    success: {
        marker: 'bg-emerald-500',
        icon: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400',
    },
    warning: {
        marker: 'bg-amber-500',
        icon: 'bg-amber-50 text-amber-700 dark:bg-amber-950/60 dark:text-amber-400',
    },
    critical: {
        marker: 'bg-red-500',
        icon: 'bg-red-50 text-red-700 dark:bg-red-950/60 dark:text-red-400',
    },
    neutral: {
        marker: 'bg-slate-400 dark:bg-slate-600',
        icon: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
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
}: Props) {
    const style = toneStyles[tone] ?? toneStyles.neutral;
    const Icon = icons[icon] ?? LayoutDashboard;
    const hasTrend = trend !== null && trend !== undefined;
    const TrendIcon =
        trendDirection === 'up'
            ? TrendingUpIcon
            : trendDirection === 'down'
              ? TrendingDownIcon
              : MinusIcon;

    const trendColor =
        trendDirection === 'up'
            ? 'text-emerald-700 dark:text-emerald-400'
            : trendDirection === 'down'
              ? 'text-red-700 dark:text-red-400'
              : 'text-gray-500 dark:text-slate-400';

    const body = (
        <div className="relative z-[1] flex h-full flex-col">
            <div className="flex items-start justify-between gap-4">
                <p
                    className="min-w-0 whitespace-normal break-words text-xs font-medium leading-relaxed text-gray-600 dark:text-slate-300 sm:text-sm"
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

            <p
                className="mt-auto break-words pt-3 text-2xl font-semibold leading-tight tabular-nums tracking-tight text-gray-950 sm:text-3xl dark:text-white"
            >
                {value}
            </p>

            {hasTrend && (
                <p
                    className={`mt-3 flex items-center gap-1.5 text-xs ${trendColor}`}
                >
                    <TrendIcon className="h-3.5 w-3.5" aria-hidden="true" />
                    <span className="font-medium tabular-nums">
                        {trend > 0 ? '+' : ''}
                        {trend}
                    </span>
                    {comparisonLabel && (
                        <span
                            className="text-gray-500 dark:text-slate-500"
                        >
                            {comparisonLabel}
                        </span>
                    )}
                </p>
            )}

        </div>
    );

    const shell = [
        'relative h-full min-w-0 overflow-hidden rounded-xl border transition-colors',
        featured
            ? 'min-h-[112px] border-gray-200 border-s-[3px] border-s-[color:var(--color-primary)] bg-white p-4 dark:border-slate-700 dark:border-s-indigo-400 dark:bg-slate-900'
            : 'min-h-[112px] border-gray-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900',
    ].join(' ');

    if (!href) {
        return <div className={shell}>{body}</div>;
    }

    return (
        <Link
            href={href}
            className={`${shell} group block hover:border-gray-300 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)] focus-visible:ring-offset-2 dark:hover:border-slate-700 dark:hover:bg-slate-800/70 dark:focus-visible:ring-offset-slate-950`}
        >
            {body}
        </Link>
    );
}
