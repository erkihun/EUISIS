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

export type ButtonVariant = 'default' | 'primary' | 'secondary' | 'outline' | 'ghost' | 'destructive' | 'link';
export type ButtonSize = 'xs' | 'sm' | 'default' | 'md' | 'lg' | 'icon';

const buttonVariants: Record<ButtonVariant, string> = {
    default: 'bg-[color:var(--color-primary)] text-white hover:bg-[color:var(--color-primary-hover)]',
    primary: 'bg-[color:var(--color-primary)] text-white hover:bg-[color:var(--color-primary-hover)]',
    secondary: 'bg-[color:var(--app-surface-muted)] text-[color:var(--app-foreground)] hover:brightness-95',
    outline: 'border border-[color:var(--app-border-strong)] bg-[color:var(--app-surface)] text-[color:var(--app-foreground)] hover:bg-[color:var(--app-surface-muted)]',
    ghost: 'text-[color:var(--app-muted-foreground)] hover:bg-[color:var(--app-surface-muted)] hover:text-[color:var(--app-foreground)]',
    destructive: 'bg-red-700 text-white hover:bg-red-800 dark:bg-red-700 dark:hover:bg-red-600',
    link: 'h-auto p-0 text-[color:var(--color-primary)] underline-offset-4 hover:underline',
};

const buttonSizes: Record<ButtonSize, string> = {
    xs: 'h-6 gap-1 px-2 text-xs',
    sm: 'h-[var(--control-h-sm)] gap-1.5 px-3 text-sm',
    default: 'h-[var(--control-h-md)] gap-2 px-3.5 text-sm',
    md: 'h-[var(--control-h-md)] gap-2 px-3.5 text-sm',
    lg: 'h-[var(--control-h-lg)] gap-2 px-5 text-sm',
    icon: 'h-[var(--control-h-md)] w-[var(--control-h-md)] p-0',
};

export function buttonClassName({ variant = 'default', size = 'default', className = '' }: {
    variant?: ButtonVariant;
    size?: ButtonSize;
    className?: string;
} = {}): string {
    return cx(
        'inline-flex shrink-0 items-center justify-center rounded-[var(--radius-control)] font-medium transition-colors',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)] focus-visible:ring-offset-2',
        'disabled:pointer-events-none disabled:opacity-60 aria-disabled:pointer-events-none aria-disabled:opacity-60',
        buttonVariants[variant],
        buttonSizes[size],
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

export function Card({ className, children, ...props }: HTMLAttributes<HTMLDivElement>) {
    return <div className={cx('rounded-[var(--radius-card)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-[var(--card-padding)]', className)} {...props}>{children}</div>;
}

export function CardHeader({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
    return <div className={cx('mb-4 flex items-start justify-between gap-3', className)} {...props} />;
}

export function CardTitle({ className, ...props }: HTMLAttributes<HTMLHeadingElement>) {
    return <h2 className={cx('text-base font-semibold text-[color:var(--app-foreground)]', className)} {...props} />;
}

export function CardDescription({ className, ...props }: HTMLAttributes<HTMLParagraphElement>) {
    return <p className={cx('mt-1 text-sm text-[color:var(--app-muted-foreground)]', className)} {...props} />;
}

export function CardFooter({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
    return <div className={cx('mt-5 flex items-center justify-end gap-2 border-t border-[color:var(--app-border)] pt-4', className)} {...props} />;
}

export function Skeleton({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
    return <div aria-hidden="true" className={cx('animate-pulse rounded-[var(--radius-control)] bg-[color:var(--app-surface-muted)]', className)} {...props} />;
}
