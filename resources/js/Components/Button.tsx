import { forwardRef, type ButtonHTMLAttributes, type ReactNode } from 'react';

/*
 * Four roles, deliberately. `warning` and `success` were dropped: a button is
 * a thing you click, and "this action is a success" is not a meaningful state
 * to communicate before the fact — those two existed only to give pages more
 * colours to pick from, which is exactly what made the UI look scattered.
 *
 * Use:
 *   primary     — the one committing action on the page (Save, Create, Confirm)
 *   secondary   — supporting actions (Cancel, Back, Export)
 *   outline     — same weight as secondary, on tinted or busy backgrounds
 *   ghost       — in-table / in-toolbar actions where a border would add noise
 *   destructive — only for actions that delete, revoke or cannot be undone
 */
type Variant = 'primary' | 'secondary' | 'outline' | 'ghost' | 'destructive';
type Size = 'xs' | 'sm' | 'md' | 'lg';

interface Props extends ButtonHTMLAttributes<HTMLButtonElement> {
    variant?: Variant;
    size?: Size;
    loading?: boolean;
    icon?: ReactNode;
    iconPosition?: 'left' | 'right';
}

/*
 * Primary resolves through the brand token rather than a Tailwind blue, so an
 * administrator changing `appearance.primary_color` actually moves the
 * buttons — previously the setting existed but every button ignored it.
 */
const variantClasses: Record<Variant, string> = {
    primary:
        'bg-[color:var(--color-primary)] text-white hover:bg-[color:var(--color-primary-hover)] ' +
        'focus-visible:ring-[color:var(--color-primary)] disabled:opacity-50',
    secondary:
        'bg-gray-100 text-gray-800 hover:bg-gray-200 focus-visible:ring-gray-400 ' +
        'dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700',
    outline:
        'border border-gray-300 bg-white text-gray-800 hover:bg-gray-50 focus-visible:ring-gray-400 ' +
        'dark:border-slate-700 dark:bg-transparent dark:text-slate-100 dark:hover:bg-slate-800',
    ghost:
        'text-gray-600 hover:bg-gray-100 hover:text-gray-900 focus-visible:ring-gray-400 ' +
        'dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100',
    destructive:
        'bg-red-700 text-white hover:bg-red-800 focus-visible:ring-red-600 disabled:opacity-50 ' +
        'dark:bg-red-700 dark:hover:bg-red-600',
};

/* Heights match --control-h-* so buttons line up with inputs in a filter row. */
const sizeClasses: Record<Size, string> = {
    xs: 'h-6 px-2 text-xs gap-1',
    sm: 'h-[30px] px-3 text-sm gap-1.5',
    md: 'h-9 px-3.5 text-sm gap-2',
    lg: 'h-10 px-5 text-sm gap-2',
};

const Spinner = () => (
    <svg
        className="h-4 w-4 animate-spin"
        xmlns="http://www.w3.org/2000/svg"
        fill="none"
        viewBox="0 0 24 24"
        aria-hidden="true"
    >
        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
    </svg>
);

const Button = forwardRef<HTMLButtonElement, Props>(
    (
        {
            variant = 'primary',
            size = 'md',
            loading = false,
            icon,
            iconPosition = 'left',
            children,
            disabled,
            className = '',
            ...rest
        },
        ref,
    ) => {
        const isDisabled = disabled || loading;

        return (
            <button
                ref={ref}
                disabled={isDisabled}
                className={[
                    'inline-flex items-center justify-center rounded-control font-medium transition-colors',
                    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2',
                    'disabled:pointer-events-none disabled:opacity-60',
                    variantClasses[variant],
                    sizeClasses[size],
                    className,
                ].join(' ')}
                {...rest}
            >
                {loading && <Spinner />}
                {!loading && icon && iconPosition === 'left' && (
                    <span className="shrink-0" aria-hidden="true">{icon}</span>
                )}
                {children && <span>{children}</span>}
                {!loading && icon && iconPosition === 'right' && (
                    <span className="shrink-0" aria-hidden="true">{icon}</span>
                )}
            </button>
        );
    },
);

Button.displayName = 'Button';
export default Button;
