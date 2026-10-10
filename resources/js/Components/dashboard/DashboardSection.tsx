import type { ReactNode } from 'react';

interface Props {
    title: string;
    description?: string;
    actions?: ReactNode;
    children: ReactNode;
}

export default function DashboardSection({ title, description, actions, children }: Props) {
    return (
        <section className="space-y-4">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 className="text-lg font-semibold text-[color:var(--app-foreground)]">
                        {title}
                    </h2>
                    {description && (
                        <p className="mt-1 text-sm text-[color:var(--app-muted-foreground)]">
                            {description}
                        </p>
                    )}
                </div>
                {actions && <div className="flex items-center gap-2">{actions}</div>}
            </div>
            {children}
        </section>
    );
}
