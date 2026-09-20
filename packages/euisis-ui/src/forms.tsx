import {
    forwardRef,
    useId,
    type HTMLAttributes,
    type InputHTMLAttributes,
    type LabelHTMLAttributes,
    type ReactNode,
    type SelectHTMLAttributes,
    type TextareaHTMLAttributes,
} from 'react';
import { Button, cx } from './primitives';
import { useUiMessages } from './context';

export const controlClassName = cx(
    'h-[var(--control-h-md)] w-full rounded-[var(--radius-control)] border border-[color:var(--app-border-strong)]',
    'bg-[color:var(--app-surface)] px-3 text-sm text-[color:var(--app-foreground)] placeholder:text-[color:var(--app-muted-foreground)]',
    'focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)]/20',
    'disabled:cursor-not-allowed disabled:bg-[color:var(--app-surface-muted)] disabled:opacity-70',
    'aria-[invalid=true]:border-red-600 aria-[invalid=true]:ring-red-600/20',
);

export const Input = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement>>(function Input({ className, ...props }, ref) {
    return <input ref={ref} className={cx(controlClassName, className)} {...props} />;
});

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaHTMLAttributes<HTMLTextAreaElement>>(function Textarea({ className, ...props }, ref) {
    return <textarea ref={ref} className={cx(controlClassName, 'h-auto min-h-24 py-2', className)} {...props} />;
});

export const Select = forwardRef<HTMLSelectElement, SelectHTMLAttributes<HTMLSelectElement>>(function Select({ className, ...props }, ref) {
    return <select ref={ref} className={cx(controlClassName, 'pr-9', className)} {...props} />;
});

export function FormLabel({ required, className, children, ...props }: LabelHTMLAttributes<HTMLLabelElement> & { required?: boolean }) {
    return (
        <label className={cx('block text-sm font-medium text-[color:var(--app-foreground)]', className)} {...props}>
            {children}
            {required && <span className="ml-1 text-red-600" aria-hidden="true">*</span>}
            {required && <span className="sr-only"> required</span>}
        </label>
    );
}

export function FormDescription({ className, ...props }: HTMLAttributes<HTMLParagraphElement>) {
    return <p className={cx('text-xs text-[color:var(--app-muted-foreground)]', className)} {...props} />;
}

export function FieldError({ className, children, ...props }: HTMLAttributes<HTMLParagraphElement>) {
    if (!children) return null;
    return <p role="alert" className={cx('text-xs text-red-600 dark:text-red-400', className)} {...props}>{children}</p>;
}

export function FormField({ id, label, required, description, error, children, className }: {
    id?: string;
    label: ReactNode;
    required?: boolean;
    description?: ReactNode;
    error?: ReactNode;
    children: ReactNode | ((ids: { id: string; describedBy?: string; invalid: boolean }) => ReactNode);
    className?: string;
}) {
    const generatedId = useId();
    const fieldId = id ?? generatedId;
    const descriptionId = description ? `${fieldId}-description` : undefined;
    const errorId = error ? `${fieldId}-error` : undefined;
    const describedBy = [descriptionId, errorId].filter(Boolean).join(' ') || undefined;
    return (
        <div className={cx('space-y-1.5', className)}>
            <FormLabel htmlFor={fieldId} required={required}>{label}</FormLabel>
            {typeof children === 'function' ? children({ id: fieldId, describedBy, invalid: Boolean(error) }) : children}
            {description && <FormDescription id={descriptionId}>{description}</FormDescription>}
            <FieldError id={errorId}>{error}</FieldError>
        </div>
    );
}

export function SearchInput({ value, onChange, onClear, label, className, ...props }: Omit<InputHTMLAttributes<HTMLInputElement>, 'onChange'> & {
    value: string;
    onChange: (value: string) => void;
    onClear?: () => void;
    label?: string;
}) {
    const messages = useUiMessages();
    return (
        <div className={cx('relative min-w-0', className)}>
            <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[color:var(--app-muted-foreground)]" aria-hidden="true">⌕</span>
            <Input
                type="search"
                value={value}
                onChange={(event) => onChange(event.target.value)}
                aria-label={label ?? messages.search}
                className="pl-9 pr-9"
                {...props}
            />
            {value && (
                <button type="button" onClick={() => { onChange(''); onClear?.(); }} aria-label={messages.clear} className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-[color:var(--app-muted-foreground)] hover:bg-[color:var(--app-surface-muted)]">×</button>
            )}
        </div>
    );
}

export function FileUpload({ id, label, value, accept, maxSizeMb, disabled, error, progress, onChange, onRemove }: {
    id?: string;
    label: ReactNode;
    value?: File | null;
    accept?: string;
    maxSizeMb?: number;
    disabled?: boolean;
    error?: ReactNode;
    progress?: number;
    onChange: (file: File | null) => void;
    onRemove?: () => void;
}) {
    const messages = useUiMessages();
    const inputId = id ?? `file-${String(label).replace(/\s+/g, '-').toLowerCase()}`;
    return (
        <FormField id={inputId} label={label} error={error} description={maxSizeMb ? `${maxSizeMb} MB maximum` : undefined}>
            {({ id: fieldId, describedBy, invalid }) => (
                <div className="space-y-2">
                    <Input id={fieldId} type="file" accept={accept} disabled={disabled} aria-describedby={describedBy} aria-invalid={invalid} onChange={(event) => {
                        const file = event.target.files?.[0] ?? null;
                        if (file && maxSizeMb && file.size > maxSizeMb * 1024 * 1024) { event.target.value = ''; onChange(null); return; }
                        onChange(file);
                    }} className="cursor-pointer py-1.5 file:mr-3 file:border-0 file:bg-transparent file:text-sm file:font-medium" />
                    {value && <div className="flex min-w-0 items-center justify-between gap-2 text-sm"><span className="truncate">{value.name}</span>{onRemove && <Button variant="ghost" size="sm" onClick={() => { onRemove(); onChange(null); }}>{messages.remove}</Button>}</div>}
                    {progress !== undefined && <progress className="w-full accent-[color:var(--color-primary)]" max={100} value={progress} aria-label={messages.loading} />}
                </div>
            )}
        </FormField>
    );
}
