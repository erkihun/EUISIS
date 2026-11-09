import type { ReactNode } from 'react';
import { useLocale } from '@/hooks/useLocale';

/**
 * The platform's five-point satisfaction scale (1 = very dissatisfied …
 * 5 = very satisfied) as one accessible question: a fieldset whose legend is
 * the question, five NATIVE radio inputs (keyboard arrows, screen-reader
 * group semantics, `required`), each with its full text label for assistive
 * technology and a large numbered target for the thumb. The chosen answer is
 * also written out under the row, so nobody has to decode the colour or the
 * number. Never stars-only.
 */

const VALUES = [1, 2, 3, 4, 5] as const;

/** Selected-state colours, from dissatisfied (rose) through neutral (amber) to satisfied (emerald). */
const TONE: Record<number, string> = {
    // White text only on shades that keep at least 4.5:1 contrast.
    1: 'peer-checked:border-rose-800 peer-checked:bg-rose-800 peer-checked:text-white',
    2: 'peer-checked:border-rose-700 peer-checked:bg-rose-700 peer-checked:text-white',
    3: 'peer-checked:border-amber-400 peer-checked:bg-amber-400 peer-checked:text-slate-950',
    4: 'peer-checked:border-emerald-700 peer-checked:bg-emerald-700 peer-checked:text-white',
    5: 'peer-checked:border-emerald-800 peer-checked:bg-emerald-800 peer-checked:text-white',
};

export default function RatingScale({ name, legend, help, value, onChange, required = false, error, emphasis = false }: {
    /** The radio group name; also the base of the element ids. */
    name: string;
    legend: ReactNode;
    help?: string | null;
    /** '' when unanswered, otherwise '1'…'5'. */
    value: string;
    onChange: (value: string) => void;
    required?: boolean;
    error?: string;
    /** The overall-satisfaction question: a larger heading. */
    emphasis?: boolean;
}) {
    const { t } = useLocale();
    const id = name.replace(/[^a-zA-Z0-9_-]/g, '-');
    const describedBy = [help ? `${id}-help` : null, error ? `${id}-error` : null].filter(Boolean).join(' ') || undefined;

    return (
        <fieldset data-field aria-describedby={describedBy} aria-invalid={error ? true : undefined} className="min-w-0">
            <legend className={`${emphasis ? 'text-lg font-bold' : 'text-sm font-semibold'} text-(--app-foreground)`}>
                {legend}
            </legend>
            {help && <p id={`${id}-help`} className="mt-1 text-xs leading-relaxed text-(--app-muted-foreground)">{help}</p>}
            <div className="mt-3 grid grid-cols-5 gap-1.5">
                {VALUES.map((rating) => (
                    <label key={rating} className="relative block cursor-pointer">
                        <input
                            type="radio"
                            name={name}
                            value={rating}
                            checked={value === String(rating)}
                            onChange={() => onChange(String(rating))}
                            required={required}
                            aria-invalid={error ? true : undefined}
                            className="peer sr-only"
                        />
                        <span
                            aria-hidden="true"
                            className={`flex min-h-12 items-center justify-center rounded-xl border-2 border-(--app-border-strong) bg-(--app-surface) text-lg font-bold text-(--app-foreground) transition-colors hover:bg-(--app-surface-muted) peer-focus-visible:outline-solid peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-primary ${TONE[rating]}`}
                        >
                            {rating}
                        </span>
                        <span className="sr-only">{`${rating} — ${t(`serviceFeedback.rating${rating}`)}`}</span>
                    </label>
                ))}
            </div>
            <div className="mt-1.5 flex justify-between gap-2 text-xs text-(--app-muted-foreground)" aria-hidden="true">
                <span>{t('serviceFeedback.rating1')}</span>
                <span className="text-right">{t('serviceFeedback.rating5')}</span>
            </div>
            <p className="mt-1 min-h-5 text-center text-sm font-semibold text-(--app-foreground)" aria-hidden="true">
                {value !== '' ? t(`serviceFeedback.rating${value}`) : ''}
            </p>
            {error && <p id={`${id}-error`} role="alert" className="mt-1 text-sm text-red-600 dark:text-red-400">{error}</p>}
        </fieldset>
    );
}
