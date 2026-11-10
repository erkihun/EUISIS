import type { HTMLAttributes, ReactNode, TdHTMLAttributes, ThHTMLAttributes } from 'react';
import { cx } from './primitives';

/*
 * The one table style for admin lists. Pages either use the components below
 * or, where markup must stay a plain <table>, the class names they are built
 * from — never their own padding, colours or borders.
 */
export const tableContainerClassName = 'overflow-hidden rounded-card border border-(--app-border) bg-(--app-surface)';
export const tableClassName = 'w-full text-left text-sm text-(--app-foreground)';
export const tableHeadClassName = 'border-b border-(--app-border) bg-(--app-surface-muted)';
// No alignment and no cell colour: callers add text-left/right and any colour.
export const tableHeaderCellClassName = 'px-4 py-3 text-xs font-medium text-(--app-muted-foreground)';
export const tableBodyClassName = 'divide-y divide-(--app-border)';
export const tableRowClassName = 'transition-colors hover:bg-(--app-surface-muted)';
export const tableCellClassName = 'px-4 py-3';
/**
 * Long text in a cell (an institution's full name, a position title) held to
 * a few lines so rows keep a readable height; callers put the full text in
 * `title`. Two lines by default, three at most. The clamp sets its own display
 * (`-webkit-box`), so never add `block` or `flex` beside it.
 */
export function tableCellClampClassName(lines: 2 | 3 = 2): string {
    return lines === 3 ? 'line-clamp-3 max-w-xs' : 'line-clamp-2 max-w-xs';
}

type Align = 'left' | 'center' | 'right';
const alignClass: Record<Align, string> = { left: 'text-left', center: 'text-center', right: 'text-right' };

/** Horizontal scroll for wide tables; put it inside a TableCard or Card. */
export function Table({ className, children, ...props }: HTMLAttributes<HTMLTableElement>) {
    return (
        <div className="overflow-x-auto">
            <table className={cx(tableClassName, className)} {...props}>{children}</table>
        </div>
    );
}

export function THead({ className, ...props }: HTMLAttributes<HTMLTableSectionElement>) {
    return <thead className={cx(tableHeadClassName, className)} {...props} />;
}

export function TBody({ className, ...props }: HTMLAttributes<HTMLTableSectionElement>) {
    return <tbody className={cx(tableBodyClassName, className)} {...props} />;
}

export function Tr({ className, ...props }: HTMLAttributes<HTMLTableRowElement>) {
    return <tr className={cx(tableRowClassName, className)} {...props} />;
}

export function Th({ align = 'left', className, ...props }: ThHTMLAttributes<HTMLTableCellElement> & { align?: Align }) {
    return <th scope="col" className={cx(tableHeaderCellClassName, alignClass[align], className)} {...props} />;
}

export function Td({ align = 'left', numeric = false, muted = false, clamp, title, className, children, ...props }: TdHTMLAttributes<HTMLTableCellElement> & {
    align?: Align;
    /** Tabular figures, right-aligned unless `align` says otherwise. */
    numeric?: boolean;
    muted?: boolean;
    /** Limit text to this many lines (2 or 3); the full text goes in `title` (defaults to the text itself when it is a string). */
    clamp?: 2 | 3;
}) {
    const clampTitle = clamp && title === undefined && typeof children === 'string' ? children : title;
    return (
        <td
            className={cx(
                tableCellClassName,
                alignClass[numeric && align === 'left' ? 'right' : align],
                numeric && 'tabular-nums',
                muted && 'text-(--app-muted-foreground)',
                className,
            )}
            title={clampTitle}
            {...props}
        >
            {clamp ? <span className={tableCellClampClassName(clamp)}>{children}</span> : children}
        </td>
    );
}

/** A list's frame: optional title, description and actions above, pagination below. */
export function TableCard({ title, description, actions, footer, className, children }: {
    title?: ReactNode;
    description?: ReactNode;
    actions?: ReactNode;
    footer?: ReactNode;
    className?: string;
    children: ReactNode;
}) {
    return (
        <section className={cx(tableContainerClassName, className)}>
            {(title || description || actions) && (
                <header className="flex flex-wrap items-start justify-between gap-3 border-b border-(--app-border) px-4 py-3">
                    <div className="min-w-0">
                        {title && <h2 className="text-sm font-semibold text-(--app-foreground)">{title}</h2>}
                        {description && <p className="mt-0.5 text-xs text-(--app-muted-foreground)">{description}</p>}
                    </div>
                    {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
                </header>
            )}
            {children}
            {footer && <footer className="border-t border-(--app-border) px-4 py-3">{footer}</footer>}
        </section>
    );
}
