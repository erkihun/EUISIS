import { ReactNode } from 'react';
import { TrendingUpIcon as TrendingUp, TrendingDownIcon as TrendingDown, MinusIcon as Minus } from '@/Components/Icons';

type Variant = 'primary' | 'success' | 'warning' | 'danger' | 'accent' | 'neutral';
type Trend = 'up' | 'down' | 'neutral';

interface AppMetricCardProps {
    label: string;
    value: string | number;
    detail?: ReactNode;
    icon?: ReactNode;
    variant?: Variant;
    trend?: Trend;
    trendLabel?: string;
    loading?: boolean;
}

const variantStyles: Record<Variant, { icon: string; value: string }> = {
    primary:  { icon: 'bg-blue-100 text-primary dark:bg-blue-900/30 dark:text-primary', value: 'text-primary dark:text-primary' },
    success:  { icon: 'bg-green-100 text-green-600 dark:bg-green-900/30 dark:text-green-400', value: 'text-green-700 dark:text-green-400' },
    warning:  { icon: 'bg-amber-100 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400', value: 'text-amber-700 dark:text-amber-400' },
    danger:   { icon: 'bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400', value: 'text-red-600 dark:text-red-400' },
    accent:   { icon: 'bg-orange-100 text-orange-600 dark:bg-orange-900/30 dark:text-orange-400', value: 'text-orange-600 dark:text-orange-400' },
    neutral:  { icon: 'bg-(--app-surface-muted) text-(--app-muted-foreground)', value: 'text-(--app-foreground)' },
};

const trendStyles: Record<Trend, { cls: string; Icon: typeof TrendingUp }> = {
    up:      { cls: 'text-green-600 dark:text-green-400',  Icon: TrendingUp },
    down:    { cls: 'text-red-600 dark:text-red-400',      Icon: TrendingDown },
    neutral: { cls: 'text-(--app-muted-foreground)',   Icon: Minus },
};

export default function AppMetricCard({
    label,
    value,
    detail,
    icon,
    variant = 'primary',
    trend,
    trendLabel,
    loading = false,
}: AppMetricCardProps) {
    const styles = variantStyles[variant];

    if (loading) {
        return (
            <div className="overflow-hidden rounded-card border border-(--app-border) bg-(--app-surface) p-5">
                <div className="animate-pulse space-y-3">
                    <div className="h-3 w-24 rounded-sm bg-(--app-surface-muted)" />
                    <div className="h-7 w-16 rounded-sm bg-(--app-surface-muted)" />
                </div>
            </div>
        );
    }

    return (
        <div className="overflow-hidden rounded-card border border-(--app-border) bg-(--app-surface) p-5 transition-shadow hover:shadow-md">
            <div className="flex items-start justify-between gap-3">
                <p className="text-sm font-medium text-(--app-muted-foreground)">{label}</p>
                {icon && (
                    <div className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${styles.icon}`}>
                        {icon}
                    </div>
                )}
            </div>

            <p className={`mt-2 text-2xl font-bold tabular-nums ${styles.value}`}>
                {value}
            </p>

            {(trend || detail) && (
                <div className="mt-2 flex items-center gap-2">
                    {trend && (() => {
                        const TrendIcon = trendStyles[trend].Icon;
                        return (
                            <span className={`inline-flex items-center gap-1 text-xs font-medium ${trendStyles[trend].cls}`}>
                                <TrendIcon className="h-3 w-3" aria-hidden="true" />
                                {trendLabel}
                            </span>
                        );
                    })()}
                    {detail && (
                        <span className="text-xs text-(--app-muted-foreground)">{detail}</span>
                    )}
                </div>
            )}
        </div>
    );
}
