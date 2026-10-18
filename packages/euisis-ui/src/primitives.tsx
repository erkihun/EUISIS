import {
    forwardRef,
    type ButtonHTMLAttributes,
    type ComponentPropsWithoutRef,
    type ElementType,
    type HTMLAttributes,
    type ReactElement,
    type ReactNode,
} from 'react';

export function cx(...classes: Array<string | false | null | undefined>): string {
    return classes.filter(Boolean).join(' ');
}

export type ButtonVariant = 'default' | 'primary' | 'secondary' | 'outline' | 'ghost' | 'destructive' | 'destructiveOutline' | 'link';
export type ButtonSize = 'xs' | 'sm' | 'default' | 'md' | 'lg' | 'icon';

const buttonVariants: Record<ButtonVariant, string> = {
    // --color-on-primary: readable text on the theme's primary (dark text on a bright dark-mode variant)
    default: 'bg-primary text-(--color-on-primary,#fff) hover:bg-primary-hover',
    primary: 'bg-primary text-(--color-on-primary,#fff) hover:bg-primary-hover',
    secondary: 'bg-(--app-surface-muted) text-(--app-foreground) hover:brightness-95',
    outline: 'border border-(--app-border-strong) bg-(--app-surface) text-(--app-foreground) hover:bg-(--app-surface-muted)',
    ghost: 'text-(--app-muted-foreground) hover:bg-(--app-surface-muted) hover:text-(--app-foreground)',
    destructive: 'bg-red-700 text-white hover:bg-red-800 dark:bg-red-700 dark:hover:bg-red-600',
    // A harmful action that is not the page's main one (Remove, Revoke next to Save).
    destructiveOutline: 'border border-red-300 bg-(--app-surface) text-red-700 hover:bg-red-50 dark:border-red-900/60 dark:text-red-400 dark:hover:bg-red-950/30',
    link: 'h-auto p-0 text-primary underline-offset-4 hover:underline',
};

const buttonSizes: Record<ButtonSize, string> = {
    xs: 'h-6 gap-1 px-2 text-xs',
    sm: 'h-(--control-h-sm) gap-1.5 px-3 text-sm',
    default: 'h-(--control-h-md) gap-2 px-3.5 text-sm',
    md: 'h-(--control-h-md) gap-2 px-3.5 text-sm',
    lg: 'h-(--control-h-lg) gap-2 px-5 text-sm',
    icon: 'h-(--control-h-md) w-(--control-h-md) p-0',
};

export function buttonClassName({ variant = 'default', size = 'default', className = '' }: {
    variant?: ButtonVariant;
    size?: ButtonSize;
    className?: string;
} = {}): string {
    return cx(
        'inline-flex shrink-0 items-center justify-center rounded-control font-medium transition-colors',
        'focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2',
        'disabled:pointer-events-none disabled:opacity-60 aria-disabled:pointer-events-none aria-disabled:opacity-60',
        buttonVariants[variant],
        buttonSizes[size],
        className,
    );
}

export type ActionTone = 'primary' | 'neutral' | 'danger' | 'success';

const actionTones: Record<ActionTone, string> = {
    primary: 'text-primary hover:text-primary-hover',
    neutral: 'text-(--app-muted-foreground) hover:text-(--app-foreground)',
    danger: 'text-red-700 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300',
    success: 'text-emerald-700 hover:text-emerald-800 dark:text-emerald-400 dark:hover:text-emerald-300',
};

/**
 * An inline text action — Edit, View, Restore in a table row or beside a
 * heading. Underlines on hover; `danger` for removals, `success` for restores.
 * Add layout (`ml-auto`, `inline-flex gap-1`) and typography (`font-mono`)
 * through `className`, never another colour.
 */
export function actionLinkClassName({ tone = 'primary', size = 'xs', className = '' }: {
    tone?: ActionTone;
    size?: 'xs' | 'sm';
    className?: string;
} = {}): string {
    return cx(
        'font-medium underline-offset-4 transition-colors hover:underline',
        'focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2',
        'disabled:pointer-events-none disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50',
        size === 'xs' ? 'text-xs' : 'text-sm',
        actionTones[tone],
        className,
    );
}

type PolymorphicRef<T extends ElementType> = ComponentPropsWithoutRef<T>['ref'];
export type ButtonProps<T extends ElementType = 'button'> = {
    as?: T;
    variant?: ButtonVariant;
    size?: ButtonSize;
    loading?: boolean;
    icon?: ReactNode;
    iconPosition?: 'left' | 'right';
    children?: ReactNode;
    className?: string;
} & Omit<ComponentPropsWithoutRef<T>, 'as' | 'children' | 'className' | 'size'>;

function ButtonInner<T extends ElementType = 'button'>(
    { as, variant = 'default', size = 'default', loading = false, icon, iconPosition = 'left', children, className, ...props }: ButtonProps<T>,
    ref: PolymorphicRef<T>,
) {
    const Component: ElementType = as ?? 'button';
    const buttonProps: { type?: 'button'; disabled?: boolean; 'aria-disabled'?: boolean } = Component === 'button'
        ? { type: 'button', disabled: loading || Boolean((props as ButtonHTMLAttributes<HTMLButtonElement>).disabled) }
        : { 'aria-disabled': loading || undefined };

    return (
        <Component ref={ref} className={buttonClassName({ variant, size, className })} {...buttonProps} {...props}>
            {loading && <span className="h-4 w-4 animate-spin rounded-full border-2 border-current border-r-transparent" aria-hidden="true" />}
            {!loading && icon && iconPosition === 'left' && <span className="shrink-0" aria-hidden="true">{icon}</span>}
            {children && <span>{children}</span>}
            {!loading && icon && iconPosition === 'right' && <span className="shrink-0" aria-hidden="true">{icon}</span>}
        </Component>
    );
}

export const Button = forwardRef(ButtonInner) as <T extends ElementType = 'button'>(
    props: ButtonProps<T> & { ref?: PolymorphicRef<T> },
) => ReactElement | null;

// A caller's padding (p-0, p-3, ...) replaces the default. With both classes present the generated CSS
// order, not the class order, decides, and the arbitrary-value default always won.
const EXPLICIT_PADDING = /(?:^|\s)!?p-\S/;

export function Card({ className, children, ...props }: HTMLAttributes<HTMLDivElement>) {
    return <div className={cx('min-w-0 rounded-card border border-(--app-border) bg-(--app-surface)', !EXPLICIT_PADDING.test(className ?? '') && 'p-(--card-padding)', className)} {...props}>{children}</div>;
}

export function CardHeader({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
    return <div className={cx('mb-4 flex items-start justify-between gap-3', className)} {...props} />;
}

export function CardTitle({ className, ...props }: HTMLAttributes<HTMLHeadingElement>) {
    return <h2 className={cx('text-base font-semibold text-(--app-foreground)', className)} {...props} />;
}

export function CardDescription({ className, ...props }: HTMLAttributes<HTMLParagraphElement>) {
    return <p className={cx('mt-1 text-sm text-(--app-muted-foreground)', className)} {...props} />;
}

export function CardFooter({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
    return <div className={cx('mt-5 flex items-center justify-end gap-2 border-t border-(--app-border) pt-4', className)} {...props} />;
}

export function Skeleton({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
    return <div aria-hidden="true" className={cx('animate-pulse rounded-control bg-(--app-surface-muted)', className)} {...props} />;
}
