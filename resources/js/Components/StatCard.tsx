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
    primary: 'bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-300',
    success: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-300',
    warning: 'bg-amber-50 text-amber-600 dark:bg-amber-900/30 dark:text-amber-300',
    neutral: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
};

/**
 * Clean, gradient-free KPI tile for government-enterprise summary rows.
 * The value stays neutral; tone accents only the small icon chip.
 */
export default function StatCard({ label, value, icon, tone = 'neutral', hint }: Props) {
    return (
        <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div className="flex items-start justify-between gap-3">
                <p className="truncate text-sm font-medium text-gray-500 dark:text-slate-400">{label}</p>
                {icon && (
                    <div className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ${toneChip[tone]}`}>
                        {icon}
                    </div>
                )}
            </div>
            <p className="mt-2 text-2xl font-semibold tabular-nums text-gray-900 dark:text-slate-100">
                {value}
            </p>
            {hint && (
                <div className="mt-1 text-xs text-gray-400 dark:text-slate-500">{hint}</div>
            )}
        </div>
    );
}
