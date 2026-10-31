import { ChangeEvent, ClipboardEvent, KeyboardEvent, useEffect, useRef } from 'react';

interface Props {
    value: string;
    onChange: (value: string) => void;
    /** Fired when the last digit lands. */
    onComplete?: (value: string) => void;
    disabled?: boolean;
    label: string;
    describedBy?: string;
    invalid?: boolean;
    autoFocus?: boolean;
    length?: number;
}

/**
 * Six-box numeric code entry, copied from the EUISIS Components/public/OtpInput
 * (court-ms keeps it here; that file is not part of its sync). The boxes act
 * as one control: paste fills them all, backspace walks back, arrows move.
 */
export default function CourtOtpInput({ value, onChange, onComplete, disabled = false, label, describedBy, invalid = false, autoFocus = false, length = 6 }: Props) {
    const inputsRef = useRef<Array<HTMLInputElement | null>>([]);
    const digits = Array.from({ length }, (_, index) => value[index] ?? '');

    useEffect(() => {
        if (value.length === length) onComplete?.(value);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [value]);

    useEffect(() => {
        if (autoFocus) inputsRef.current[0]?.focus();
    }, [autoFocus]);

    function focusBox(index: number) {
        inputsRef.current[Math.min(Math.max(index, 0), length - 1)]?.focus();
    }

    function setDigit(index: number, digit: string) {
        const next = digits.slice();
        next[index] = digit;
        onChange(next.join('').replace(/\D/g, '').slice(0, length));
    }

    function handleChange(index: number, event: ChangeEvent<HTMLInputElement>) {
        const typed = event.target.value.replace(/\D/g, '');

        if (typed === '') {
            setDigit(index, '');
            return;
        }

        if (typed.length > 1) {
            const merged = (value.slice(0, index) + typed).replace(/\D/g, '').slice(0, length);
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

    function handlePaste(event: ClipboardEvent<HTMLInputElement>) {
        event.preventDefault();
        const pasted = event.clipboardData.getData('text').replace(/\D/g, '').slice(0, length);
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
                    ref={(element) => { inputsRef.current[index] = element; }}
                    value={digit}
                    onChange={(event) => handleChange(index, event)}
                    onKeyDown={(event) => handleKeyDown(index, event)}
                    onPaste={handlePaste}
                    onFocus={(event) => event.target.select()}
                    disabled={disabled}
                    inputMode="numeric"
                    autoComplete="one-time-code"
                    pattern="\d*"
                    maxLength={1}
                    aria-label={`${label} ${index + 1}`}
                    aria-invalid={invalid || undefined}
                    className={`h-14 w-full min-w-0 rounded-card border bg-white text-center text-xl font-semibold text-slate-900 focus:border-primary focus:outline-hidden focus-visible:ring-2 focus-visible:ring-primary disabled:bg-gray-100 disabled:text-slate-400 dark:bg-slate-950 dark:text-slate-100 sm:h-16 sm:text-2xl ${invalid ? 'border-red-400 dark:border-red-700' : 'border-gray-300 dark:border-slate-700'}`}
                />
            ))}
        </div>
    );
}
