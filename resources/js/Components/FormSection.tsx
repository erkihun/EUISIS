import type { PropsWithChildren, ReactNode } from 'react';

type Props = PropsWithChildren<{
    title: string;
    description?: string;
    /** Optional trailing content in the section header (e.g. a badge). */
    aside?: ReactNode;
    /** When false, children are rendered without the default two-column grid. */
    grid?: boolean;
    /** Max columns at the widest breakpoint. Use 3 on full-width forms. */
    columns?: 2 | 3;
    /** Anchor for jump links to the section. */
    id?: string;
    /** Step number shown before the title on a numbered form. */
    step?: number;
}>;

const gridCols: Record<2 | 3, string> = {
    2: 'grid gap-4 md:grid-cols-2',
    3: 'grid gap-4 md:grid-cols-2 xl:grid-cols-3',
};

/**
 * A titled group of form fields. Used to break long organization forms into
 * clear sections (Basic Information, Classification, Hierarchy, …) without any
 * heavy UI library — just consistent spacing and a divider.
 */
export default function FormSection({ title, description, aside, grid = true, columns = 2, id, step, children }: Props) {
    return (
        <section id={id} className="scroll-mt-24 border-t border-(--app-border) pt-6 first:border-t-0 first:pt-0">
            <div className="mb-4 flex items-start justify-between gap-3">
                <div>
                    <h2 className="flex items-center gap-2 text-sm font-semibold text-(--app-foreground)">
                        {step !== undefined && (
                            <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary/10 text-[11px] font-semibold text-primary">{step}</span>
                        )}
                        {title}
                    </h2>
                    {description && (
                        <p className="mt-1 max-w-2xl text-xs text-(--app-muted-foreground)">{description}</p>
                    )}
                </div>
                {aside && <div className="shrink-0">{aside}</div>}
            </div>
            <div className={grid ? gridCols[columns] : 'space-y-4'}>{children}</div>
        </section>
    );
}
