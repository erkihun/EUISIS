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
                <span className="font-medium text-[color:var(--app-foreground)]">{label}</span>
                <span className="text-[color:var(--app-muted-foreground)]">{value} / {total}</span>
            </div>
            <div className="h-2 rounded-full bg-[color:var(--app-surface-muted)]">
                <div
                    className={`h-2 rounded-full ${barTone[tone]}`}
                    style={{ width: `${percent}%` }}
                    aria-hidden="true"
                />
            </div>
        </div>
    );
}
