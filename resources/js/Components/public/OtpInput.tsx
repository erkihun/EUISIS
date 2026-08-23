import { ChangeEvent, ClipboardEvent, KeyboardEvent, useEffect, useRef } from 'react';

interface Props {
    value: string;
    onChange: (value: string) => void;
    /** Fired when the sixth digit lands, so a phone user need not hunt for Verify. */
    onComplete?: (value: string) => void;
    disabled?: boolean;
    label: string;
    describedBy?: string;
}

const LENGTH = 6;

/**
 * Six-box numeric code entry.
 *
 * One box per digit rather than a single field: on a phone it gives a large
 * touch target per character and makes a mistyped digit obvious. The boxes
 * behave as one control — paste fills all six, backspace walks backwards, and
 * arrow keys move between them.
 */
export default function OtpInput({ value, onChange, onComplete, disabled = false, label, describedBy }: Props) {
    const inputsRef = useRef<Array<HTMLInputElement | null>>([]);

    const digits = Array.from({ length: LENGTH }, (_, index) => value[index] ?? '');

    // Announce completion once the last digit arrives.
    useEffect(() => {
        if (value.length === LENGTH) {
            onComplete?.(value);
        }
        // onComplete is intentionally excluded: re-running on every parent
        // render would fire the callback repeatedly for one completed code.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [value]);

    function focusBox(index: number) {
        inputsRef.current[Math.min(Math.max(index, 0), LENGTH - 1)]?.focus();
    }

    function setDigit(index: number, digit: string) {
        const next = digits.slice();
        next[index] = digit;

        onChange(next.join('').replace(/\D/g, '').slice(0, LENGTH));
    }

    function handleChange(index: number, event: ChangeEvent<HTMLInputElement>) {
        const typed = event.target.value.replace(/\D/g, '');

        if (typed === '') {
            setDigit(index, '');

            return;
        }

        // A phone keyboard or autofill can deliver several digits at once.
        if (typed.length > 1) {
            const merged = (value.slice(0, index) + typed).replace(/\D/g, '').slice(0, LENGTH);
            onChange(merged);
            focusBox(merged.length);

            return;
        }

        setDigit(index, typed);
        focusBox(index + 1);
    }

    function handleKeyDown(index: number, event: KeyboardEvent<HTMLInputElement>) {
        if (event.key === 'Backspace' && digits[index] === '' && index > 0) {
            event.preventDefault();
            setDigit(index - 1, '');
            focusBox(index - 1);

            return;
        }

        if (event.key === 'ArrowLeft') {
            event.preventDefault();
            focusBox(index - 1);
        }

        if (event.key === 'ArrowRight') {
            event.preventDefault();
            focusBox(index + 1);
        }
    }

    /** Accept a code pasted from an SMS or email into any box. */
    function handlePaste(event: ClipboardEvent<HTMLInputElement>) {
        event.preventDefault();

        const pasted = event.clipboardData.getData('text').replace(/\D/g, '').slice(0, LENGTH);

        if (pasted !== '') {
            onChange(pasted);
            focusBox(pasted.length);
        }
    }

    return (
        <div role="group" aria-label={label} aria-describedby={describedBy} className="flex justify-between gap-1.5 sm:gap-2">
            {digits.map((digit, index) => (
                <input
                    key={index}
                    ref={(element) => {
                        inputsRef.current[index] = element;
                    }}
                    value={digit}
                    onChange={(event) => handleChange(index, event)}
                    onKeyDown={(event) => handleKeyDown(index, event)}
                    onPaste={handlePaste}
                    onFocus={(event) => event.target.select()}
                    disabled={disabled}
                    // Numeric keypad on mobile; `one-time-code` lets iOS and
                    // Android offer the SMS code straight from the notification.
                    inputMode="numeric"
                    autoComplete="one-time-code"
                    pattern="\d*"
                    maxLength={1}
                    aria-label={`${label} ${index + 1}`}
                    className="h-14 w-full min-w-0 rounded-xl border border-gray-300 bg-white text-center text-xl font-semibold text-slate-900 focus:border-blue-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 disabled:bg-gray-100 disabled:text-slate-400 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 sm:h-16 sm:text-2xl"
                />
            ))}
        </div>
    );
}
