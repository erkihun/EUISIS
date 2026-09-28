import { Card, cx } from '@euisis/ui';
import type { PropsWithChildren, ReactNode } from 'react';

type Props = PropsWithChildren<{
    title: string;
    description?: string;
    actions?: ReactNode;
    footer?: ReactNode;
    className?: string;
}>;

/** A titled group of settings rows. */
export default function SettingsCard({ title, description, actions, footer, className, children }: Props) {
    return (
        <Card className={cx('overflow-hidden p-0', className)}>
            <div className="flex flex-col gap-3 border-b border-[color:var(--app-border)] px-5 py-3.5 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0">
                    <h3 className="text-sm font-semibold text-[color:var(--app-foreground)]">{title}</h3>
                    {description && <p className="mt-0.5 max-w-2xl text-xs leading-5 text-[color:var(--app-muted-foreground)]">{description}</p>}
                </div>
                {actions && <div className="shrink-0">{actions}</div>}
            </div>

            <div className="divide-y divide-[color:var(--app-border)]">{children}</div>

            {footer && <div className="border-t border-[color:var(--app-border)] bg-[color:var(--app-surface-muted)] px-5 py-3">{footer}</div>}
        </Card>
    );
}
