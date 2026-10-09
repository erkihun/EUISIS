import type { JSX } from 'react';

/**
 * Read-only star display for admin screens.
 *
 * Deliberately not interactive — the public form owns its own input control.
 * Rendering the numeric value alongside the stars keeps the rating legible to
 * screen readers and in dense tables where five glyphs are hard to count.
 */
export default function RatingStars({
    rating,
    size = 'sm',
    showValue = false,
}: {
    rating: number;
    size?: 'sm' | 'md';
    showValue?: boolean;
}): JSX.Element {
    const dimension = size === 'md' ? 'h-5 w-5' : 'h-4 w-4';

    return (
        <span className="inline-flex items-center gap-1" title={`${rating} / 5`}>
            <span className="inline-flex" aria-hidden="true">
                {[1, 2, 3, 4, 5].map((star) => (
                    <svg
                        key={star}
                        viewBox="0 0 24 24"
                        fill="currentColor"
                        className={`${dimension} ${
                            star <= rating ? 'text-amber-400' : 'text-gray-200 dark:text-slate-700'
                        }`}
                    >
                        <path d="M12 17.27 18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z" />
                    </svg>
                ))}
            </span>
            <span className="sr-only">{rating} out of 5</span>
            {showValue && (
                <span className="text-sm font-medium text-gray-700 dark:text-slate-300">{rating.toFixed(1)}</span>
            )}
        </span>
    );
}
