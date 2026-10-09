interface Props {
    title?: string;
    compact?: boolean;
}

/**
 * Stands in for a chart that has no data in the selected range.
 *
 * Kept deliberately small. It used to be a 280px dashed box — a chart-sized
 * hole saying "no data", so a dashboard on a quiet week or a young deployment
 * was mostly empty rectangles. The message is one short line; it gets one
 * short line's worth of space, and the cards around it close up.
 */
export default function EmptyDashboardState({ title, compact = false }: Props) {
    return (
        <div
            className={`flex items-center justify-center text-center ${
                compact ? 'py-6' : 'py-10'
            }`}
        >
            <p className="text-sm text-gray-400 dark:text-slate-500">{title}</p>
        </div>
    );
}
