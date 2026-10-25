import type { ReactNode } from 'react';

type Variant = 'primary' | 'success' | 'warning' | 'danger' | 'accent' | 'neutral';

const variantStyles: Record<Variant, { icon: string; value: string }> = {
    primary: { icon: 'bg-blue-100 text-primary dark:bg-blue-900/30 dark:text-primary', value: 'text-primary dark:text-primary' },
    success: { icon: 'bg-green-100 text-green-600 dark:bg-green-900/30 dark:text-green-400', value: 'text-green-700 dark:text-green-400' },
    warning: { icon: 'bg-amber-100 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400', value: 'text-amber-700 dark:text-amber-400' },
    danger: { icon: 'bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400', value: 'text-red-600 dark:text-red-400' },
    accent: { icon: 'bg-orange-100 text-orange-600 dark:bg-orange-900/30 dark:text-orange-400', value: 'text-orange-600 dark:text-orange-400' },
    neutral: { icon: 'bg-gray-100 text-gray-600 dark:bg-slate-800 dark:text-slate-400', value: 'text-gray-800 dark:text-slate-100' },
};

/** EUISIS Components/ui/AppMetricCard.tsx (without the trend arrow, which the Core dashboard does not use). */
export default function AppMetricCard({ label, value, detail, icon, variant = 'primary', loading = false }: {
    label: string;
    value: string | number;
    detail?: ReactNode;
    icon?: ReactNode;
    variant?: Variant;
    loading?: boolean;
}) {
    const styles = variantStyles[variant];

    if (loading) {
        return (
            <div className="overflow-hidden rounded-card border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <div className="animate-pulse space-y-3">
                    <div className="h-3 w-24 rounded-sm bg-gray-200 dark:bg-slate-700" />
                    <div className="h-7 w-16 rounded-sm bg-gray-200 dark:bg-slate-700" />
                </div>
            </div>
        );
    }

    return (
        <div className="overflow-hidden rounded-card border border-gray-200 bg-white p-5 transition-shadow hover:shadow-md dark:border-slate-800 dark:bg-slate-900">
            <div className="flex items-start justify-between gap-3">
                <p className="text-sm font-medium text-gray-500 dark:text-slate-400">{label}</p>
                {icon && <div className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${styles.icon}`}>{icon}</div>}
            </div>
            <p className={`mt-2 text-2xl font-bold tabular-nums ${styles.value}`}>{value}</p>
            {detail && (
                <div className="mt-2 flex items-center gap-2">
                    <span className="text-xs text-gray-400 dark:text-slate-500">{detail}</span>
                </div>
            )}
        </div>
    );
}
