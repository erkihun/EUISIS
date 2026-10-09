import { forwardRef, useImperativeHandle, useRef } from 'react';

export type CodeFormatInputHandle = {
    insertAtCursor: (text: string) => void;
};

type Props = {
    value: string;
    onChange: (value: string) => void;
    className?: string;
    id?: string;
    placeholder?: string;
};

/**
 * A controlled text input for format strings.
 * Exposes `insertAtCursor(text)` via a ref handle for inserting tokens at the
 * current cursor position.
 */
const CodeFormatInput = forwardRef<CodeFormatInputHandle, Props>(function CodeFormatInput(
    { value, onChange, className, id, placeholder },
    ref,
) {
    const inputRef = useRef<HTMLInputElement>(null);

    useImperativeHandle(ref, () => ({
        insertAtCursor(text: string) {
            const el = inputRef.current;

            if (!el) {
                onChange(value + text);

                return;
            }

            const start = el.selectionStart ?? value.length;
            const end = el.selectionEnd ?? value.length;
            const newValue = value.slice(0, start) + text + value.slice(end);

            onChange(newValue);

            // Restore cursor position after React re-render
            requestAnimationFrame(() => {
                const newCursor = start + text.length;
                el.setSelectionRange(newCursor, newCursor);
                el.focus();
            });
        },
    }));

    return (
        <input
            ref={inputRef}
            id={id}
            type="text"
            value={value}
            onChange={(e) => onChange(e.target.value)}
            className={className}
            placeholder={placeholder}
            spellCheck={false}
            autoComplete="off"
        />
    );
});

export default CodeFormatInput;
