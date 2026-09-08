type Props = {
    steps: string[];
    current: number;
    /** Editing an existing template lets an admin jump straight to a step. */
    onSelect?: (index: number) => void;
    /** Steps already visited, shown as complete. */
    furthest: number;
};

/**
 * Progress header for the template wizard. Creating a template walks the steps
 * in order; editing one lets an admin jump straight to the part they came for.
 */
export default function TemplateWizardSteps({ steps, current, onSelect, furthest }: Props) {
    return (
        <ol className="flex flex-wrap items-center gap-x-2 gap-y-1" aria-label="Progress">
            {steps.map((step, index) => {
                const done = index < furthest;
                const active = index === current;
                const reachable = onSelect !== undefined && index <= furthest;

                return (
                    <li key={step} className="flex items-center gap-2">
                        <button
                            type="button"
                            disabled={!reachable}
                            aria-current={active ? 'step' : undefined}
                            onClick={() => reachable && onSelect?.(index)}
                            className={[
                                'flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium transition-colors',
                                active
                                    ? 'bg-blue-600 text-white'
                                    : done
                                      ? 'bg-blue-50 text-blue-700 dark:bg-blue-950/50 dark:text-blue-300'
                                      : 'text-gray-500 dark:text-slate-400',
                                reachable && !active ? 'hover:bg-gray-100 dark:hover:bg-slate-800' : '',
                                reachable ? 'cursor-pointer' : 'cursor-default',
                            ].join(' ')}
                        >
                            <span
                                className={[
                                    'flex h-4 w-4 items-center justify-center rounded-full text-[10px]',
                                    active
                                        ? 'bg-white/25'
                                        : done
                                          ? 'bg-blue-600 text-white'
                                          : 'border border-current',
                                ].join(' ')}
                            >
                                {done ? '✓' : index + 1}
                            </span>
                            {step}
                        </button>
                        {index < steps.length - 1 && (
                            <span aria-hidden="true" className="h-px w-4 bg-gray-300 dark:bg-slate-700" />
                        )}
                    </li>
                );
            })}
        </ol>
    );
}
