import { FieldError, cx } from '@euisis/ui';
import type { ReactNode } from 'react';

type Props = {
    /** Id of the control the label points at; omit for grouped controls and pass labelId instead. */
    htmlFor?: string;
    /** Id given to the label text, for controls labelled with aria-labelledby. */
    labelId?: string;
    label: string;
    description?: string | null;
    required?: boolean;
    /** A status beside the label, such as whether a secret or file is configured. */
    badge?: ReactNode;
    error?: string;
    errorId?: string;
    /** Label and control on one line, the control at the end: used for switches. */
    inline?: boolean;
    /** Tighter spacing, for grids of switches. */
    compact?: boolean;
    children: ReactNode;
};

/** One setting: its label and help on the left, its control on the right (stacked on phones). */
export default function FieldRow({ htmlFor, labelId, label, description, required, badge, error, errorId, inline = false, compact = false, children }: Props) {
    const heading = (
        <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                {htmlFor
                    ? <label id={labelId} htmlFor={htmlFor} className="text-sm font-medium text-[color:var(--app-foreground)]">{label}</label>
                    : <span id={labelId} className="text-sm font-medium text-[color:var(--app-foreground)]">{label}</span>}
                {required && <span aria-hidden="true" className="text-sm text-red-600 dark:text-red-400">*</span>}
                {badge}
            </div>
            {description && <p className="mt-1 text-xs leading-5 text-[color:var(--app-muted-foreground)]">{description}</p>}
        </div>
    );

    if (inline) {
        return (
            <div className={cx('flex items-start justify-between gap-4 px-5', compact ? 'py-3' : 'py-4')}>
                <div className="min-w-0">
                    {heading}
                    {error && <FieldError id={errorId}>{error}</FieldError>}
                </div>
                <div className="shrink-0 pt-0.5">{children}</div>
            </div>
        );
    }

    return (
        <div className={cx('grid gap-2 px-5 py-4 md:grid-cols-[minmax(0,2fr)_minmax(0,3fr)] md:gap-6')}>
            {heading}
            <div className="min-w-0 space-y-1.5">
                {children}
                {error && <FieldError id={errorId}>{error}</FieldError>}
            </div>
        </div>
    );
}

/** Accessible on/off switch in the settings style. */
export function Switch({ id, checked, disabled, onChange, labelledBy, describedBy }: {
    id?: string;
    checked: boolean;
    disabled?: boolean;
    onChange: (checked: boolean) => void;
    labelledBy?: string;
    describedBy?: string;
}) {
    return (
        <button
            id={id}
            type="button"
            role="switch"
            aria-checked={checked}
            aria-labelledby={labelledBy}
            aria-describedby={describedBy}
            disabled={disabled}
            onClick={() => onChange(!checked)}
            className={cx(
                'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)] focus-visible:ring-offset-2 focus-visible:ring-offset-[color:var(--app-surface)]',
                'disabled:cursor-not-allowed disabled:opacity-60',
                checked ? 'bg-[color:var(--color-primary)]' : 'bg-[color:var(--app-border-strong)]',
            )}
        >
            <span aria-hidden="true" className={cx('inline-block h-5 w-5 rounded-full bg-white shadow-sm transition-transform motion-reduce:transition-none', checked ? 'translate-x-5' : 'translate-x-0.5')} />
        </button>
    );
}

/** A set of on/off choices shown as chips, for list settings with known options. */
export function ChoiceChips({ options, value, disabled, labelledBy, onChange }: {
    options: { value: string; label: string }[];
    value: string[];
    disabled?: boolean;
    labelledBy?: string;
    onChange: (value: string[]) => void;
}) {
    return (
        <div role="group" aria-labelledby={labelledBy} className="flex flex-wrap gap-2">
            {options.map((option) => {
                const selected = value.includes(option.value);
                return (
                    <button
                        key={option.value}
                        type="button"
                        aria-pressed={selected}
                        disabled={disabled}
                        onClick={() => onChange(selected ? value.filter((entry) => entry !== option.value) : [...value, option.value])}
                        className={cx(
                            'inline-flex h-8 items-center gap-1.5 rounded-full border px-3 text-sm transition-colors',
                            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)] disabled:cursor-not-allowed disabled:opacity-60',
                            selected
                                ? 'border-[color:var(--color-primary)] bg-[color:var(--color-primary)]/10 font-medium text-[color:var(--app-foreground)]'
                                : 'border-[color:var(--app-border-strong)] text-[color:var(--app-muted-foreground)] hover:text-[color:var(--app-foreground)]',
                        )}
                    >
                        {selected && (
                            <svg aria-hidden="true" viewBox="0 0 24 24" className="h-3.5 w-3.5 text-[color:var(--color-primary)]" fill="none" stroke="currentColor" strokeWidth={3} strokeLinecap="round" strokeLinejoin="round"><path d="m5 12 5 5L20 7" /></svg>
                        )}
                        {option.label}
                    </button>
                );
            })}
        </div>
    );
}
