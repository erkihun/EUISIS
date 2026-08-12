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
export default function FormSection({ title, description, aside, grid = true, columns = 2, children }: Props) {
    return (
        <section className="border-t border-gray-100 pt-6 first:border-t-0 first:pt-0 dark:border-slate-800">
            <div className="mb-4 flex items-start justify-between gap-3">
                <div>
                    <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">{title}</h2>
                    {description && (
                        <p className="mt-1 max-w-2xl text-xs text-gray-500 dark:text-slate-400">{description}</p>
                    )}
                </div>
                {aside && <div className="shrink-0">{aside}</div>}
            </div>
            <div className={grid ? gridCols[columns] : 'space-y-4'}>{children}</div>
        </section>
    );
}
