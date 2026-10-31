import { useEffect, useRef, useState, type ClipboardEvent, type KeyboardEvent } from 'react';
import { cx } from '@/Components/ui';
import { useApplicantPagesText } from '@/court/i18n/useApplicantPagesText';

/**
 * A one-time code as one box per digit (the Blade x-ui.otp-input): typing
 * moves to the next box, Backspace to the previous one, a pasted code fills
 * them all. The joined digits are the value; the form sends it as one field.
 */
export default function OtpCodeInput({ id, value, onChange, length = 6, autoFocus = false, invalid = false, describedBy }: {
    id: string;
    value: string;
    onChange: (value: string) => void;
    length?: number;
    autoFocus?: boolean;
    invalid?: boolean;
    describedBy?: string;
}) {
    const { text } = useApplicantPagesText();
    const boxes = useRef<Array<HTMLInputElement | null>>([]);
    const split = (code: string) => Array.from({ length }, (_, index) => code[index] ?? '');
    // Kept per box, so clearing one digit leaves the others where they are.
    const [digits, setDigits] = useState<string[]>(() => split(value));

    useEffect(() => {
        if (value !== digits.join('')) setDigits(split(value));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [value]);

    useEffect(() => {
        if (autoFocus) boxes.current[0]?.focus();
    }, [autoFocus]);

    const update = (next: string[]) => {
        setDigits(next);
        onChange(next.join(''));
    };

    const setDigit = (index: number, digit: string) => {
        const next = [...digits];
        next[index] = digit;
        update(next);
    };

    const onInput = (index: number, raw: string) => {
        const digit = raw.replace(/\D/g, '').slice(-1);
        setDigit(index, digit);
        if (digit && index < length - 1) boxes.current[index + 1]?.focus();
    };

    const onKeyDown = (index: number, event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'Backspace' && !digits[index] && index > 0) {
            event.preventDefault();
            setDigit(index - 1, '');
            boxes.current[index - 1]?.focus();
        } else if (event.key === 'ArrowLeft' && index > 0) {
            event.preventDefault();
            boxes.current[index - 1]?.focus();
        } else if (event.key === 'ArrowRight' && index < length - 1) {
            event.preventDefault();
            boxes.current[index + 1]?.focus();
        }
    };

    const onPaste = (event: ClipboardEvent<HTMLInputElement>) => {
        const pasted = event.clipboardData.getData('text').replace(/\D/g, '').slice(0, length);
        if (!pasted) return;
        event.preventDefault();
        update(split(pasted));
        boxes.current[Math.min(pasted.length, length - 1)]?.focus();
    };

    return (
        <div className="flex items-center justify-center gap-2 sm:gap-3" role="group" aria-describedby={describedBy}>
            {digits.map((digit, index) => (
                <input
                    key={index}
                    ref={(element) => { boxes.current[index] = element; }}
                    id={index === 0 ? id : undefined}
                    type="text"
                    inputMode="numeric"
                    autoComplete={index === 0 ? 'one-time-code' : 'off'}
                    maxLength={1}
                    value={digit}
                    aria-label={text('otp_digit', { n: index + 1, total: length })}
                    aria-invalid={invalid || undefined}
                    onChange={(event) => onInput(index, event.target.value)}
                    onKeyDown={(event) => onKeyDown(index, event)}
                    onPaste={onPaste}
                    onFocus={(event) => event.target.select()}
                    className={cx(
                        'h-12 w-10 rounded-control border bg-(--app-surface) text-center font-mono text-xl font-semibold text-(--app-foreground) focus:outline-hidden focus:ring-2 focus:ring-primary sm:h-14 sm:w-12 sm:text-2xl',
                        invalid ? 'border-red-400 dark:border-red-500' : 'border-(--app-border-strong)',
                    )}
                />
            ))}
        </div>
    );
}
