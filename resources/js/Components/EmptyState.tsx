import { ReactNode } from 'react';

interface Props {
    title?: string;
    description?: string;
    action?: ReactNode;
    /**
     * Kept for call-site compatibility, but ignored.
     *
     * An empty table is not an event worth illustrating — the icon chip this
     * component used to render drew the eye to the one part of the page that
     * had nothing to say. The sentence carries it.
     */
    icon?: ReactNode;
}

/**
 * Shown in place of a table or list that has no rows.
 *
 * Deliberately plain: no dashed border, no icon medallion, no encouragement.
 * A government clerk who filters to an empty result needs to know the filter
 * returned nothing and how to proceed, not to be congratulated for arriving.
 */
export default function EmptyState({ title = 'No results found', description, action }: Props) {
    return (
        <div className="px-6 py-12 text-center">
            <p className="text-sm font-medium text-gray-900 dark:text-slate-100">{title}</p>
            {description && (
                <p className="mx-auto mt-1 max-w-md text-sm text-gray-500 dark:text-slate-400">
                    {description}
                </p>
            )}
            {action && <div className="mt-4 flex justify-center">{action}</div>}
        </div>
    );
}
