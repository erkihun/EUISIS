import { ReactNode } from 'react';

type Tone = 'primary' | 'success' | 'warning' | 'neutral';

interface Props {
    label: string;
    value: string | number;
    icon?: ReactNode;
    tone?: Tone;
    hint?: ReactNode;
}

const toneChip: Record<Tone, string> = {
    primary: 'bg-blue-50 text-[color:var(--color-primary)] dark:bg-blue-900/30 dark:text-blue-300',
    success: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-300',
    warning: 'bg-amber-50 text-amber-600 dark:bg-amber-900/30 dark:text-amber-300',
    neutral: 'bg-[color:var(--app-surface-muted)] text-[color:var(--app-muted-foreground)]',
};

/**
 * Clean, gradient-free KPI tile for government-enterprise summary rows.
 * The value stays neutral; tone accents only the small icon chip.
 */
export default function StatCard({ label, value, icon, tone = 'neutral', hint }: Props) {
    return (
        <div className="rounded-card border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5">
            <div className="flex items-start justify-between gap-3">
                <p className="truncate text-sm font-medium text-[color:var(--app-muted-foreground)]">{label}</p>
                {icon && (
                    <div className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ${toneChip[tone]}`}>
                        {icon}
                    </div>
                )}
            </div>
            <p className="mt-2 text-2xl font-semibold tabular-nums text-[color:var(--app-foreground)]">
                {value}
            </p>
            {hint && (
                <div className="mt-1 text-xs text-[color:var(--app-muted-foreground)]">{hint}</div>
            )}
        </div>
    );
}
