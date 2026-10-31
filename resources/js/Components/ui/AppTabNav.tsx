import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { cx } from '@euisis/ui';

export interface AppTabNavItem {
    key: string;
    label: ReactNode;
    href: string;
    /** Query data sent with the link (the tab's name), for a page whose tabs the server answers. */
    data?: Record<string, string | number | boolean>;
    /** A figure after the label: how many records the tab holds. */
    count?: number;
    icon?: ReactNode;
}

/**
 * The link tab strip under a page heading: one row of sub-pages or
 * server-side tabs, as API Management draws its sections. Every tab is a
 * link, so the back button and a bookmark land on the same tab; the active
 * one is the item whose key is `current`.
 */
export default function AppTabNav({ items, current, label, preserveScroll = true, className }: {
    items: AppTabNavItem[];
    current: string;
    /** Accessible name of the strip, e.g. "Import stages". */
    label: string;
    preserveScroll?: boolean;
    className?: string;
}) {
    return (
        <nav aria-label={label} className={cx('flex gap-1 overflow-x-auto border-b border-(--app-border)', className)}>
            {items.map((item) => {
                const active = item.key === current;
                return (
                    <Link
                        key={item.key}
                        href={item.href}
                        data={item.data}
                        preserveScroll={preserveScroll}
                        aria-current={active ? 'page' : undefined}
                        className={cx(
                            '-mb-px inline-flex items-center gap-2 whitespace-nowrap border-b-2 px-3 py-2 text-sm transition-colors',
                            active ? 'border-primary font-medium text-primary' : 'border-transparent text-(--app-muted-foreground) hover:text-(--app-foreground)',
                        )}
                    >
                        {item.icon && <span className="shrink-0" aria-hidden="true">{item.icon}</span>}
                        {item.label}
                        {item.count !== undefined && <span className="text-xs tabular-nums text-(--app-muted-foreground)">{item.count}</span>}
                    </Link>
                );
            })}
        </nav>
    );
}
