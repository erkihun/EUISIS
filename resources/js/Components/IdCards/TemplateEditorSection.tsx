import { useState, type ReactNode } from 'react';

type Props = {
    title: string;
    /** One line explaining what the section controls. */
    description?: string;
    /** Closed by default for advanced sections, so the form opens short. */
    defaultOpen?: boolean;
    /** Rendered on the header row — e.g. a count of configured items. */
    badge?: ReactNode;
    children: ReactNode;
};

/**
 * A collapsible block of the template editor. The editor has far more controls
 * than fit on one screen, so grouping them keeps the form scannable and lets an
 * admin open only the part they are changing.
 */
export default function TemplateEditorSection({
    title,
    description,
    defaultOpen = true,
    badge,
    children,
}: Props) {
    const [open, setOpen] = useState(defaultOpen);

    /*
     * Flush, not a card. These sit inside the editor panel, so giving each one
     * its own border and background produced a card inside a card inside the
     * page — three nested frames around one group of fields. A rule between
     * sections separates them just as well.
     */
    return (
        <section className="border-b border-gray-100 last:border-b-0 dark:border-slate-800">
            <button
                type="button"
                onClick={() => setOpen((value) => !value)}
                aria-expanded={open}
                className="flex w-full items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-gray-50 dark:hover:bg-slate-900/60"
            >
                <svg
                    viewBox="0 0 20 20"
                    aria-hidden="true"
                    className={`h-4 w-4 shrink-0 text-gray-400 transition-transform ${open ? 'rotate-90' : ''}`}
                >
                    <path d="M7 4l6 6-6 6" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
                </svg>
                <span className="min-w-0 flex-1">
                    <span className="block text-sm font-semibold text-gray-900 dark:text-slate-100">{title}</span>
                    {description && (
                        <span className="mt-0.5 block text-xs text-gray-500 dark:text-slate-400">{description}</span>
                    )}
                </span>
                {badge}
            </button>
            {open && <div className="space-y-4 border-t border-gray-100 px-4 py-4 dark:border-slate-800">{children}</div>}
        </section>
    );
}
