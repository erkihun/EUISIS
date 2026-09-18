interface Props {
    label: string;
    value: number;
    total: number;
    tone?: 'blue' | 'orange' | 'green';
}

const barTone = {
    blue: 'bg-[color:var(--color-primary)]',
    orange: 'bg-orange-500',
    green: 'bg-emerald-500',
} as const;

export default function ProgressMetric({ label, value, total, tone = 'blue' }: Props) {
    const percent = total > 0 ? Math.min(100, Math.round((value / total) * 100)) : 0;

    return (
        <div className="space-y-2">
            <div className="flex items-center justify-between gap-3 text-sm">
                <span className="font-medium text-gray-700 dark:text-slate-200">{label}</span>
                <span className="text-gray-500 dark:text-slate-400">{value} / {total}</span>
            </div>
            <div className="h-2 rounded-full bg-gray-100 dark:bg-slate-800">
                <div
                    className={`h-2 rounded-full ${barTone[tone]}`}
                    style={{ width: `${percent}%` }}
                    aria-hidden="true"
                />
            </div>
        </div>
    );
}
