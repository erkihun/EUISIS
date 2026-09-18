type Props = {
    steps: string[];
    current: number;
    /** Editing an existing template lets an admin jump straight to a step. */
    onSelect?: (index: number) => void;
    /** Steps already visited, shown as complete. */
    furthest: number;
};

/**
 * Progress header for the template wizard.
 *
 * Previously a wrapping row of pills joined by short connector rules. Six steps
 * never fit the editor column, so it wrapped to two lines and left connectors
 * dangling at the end of each — the strip read as broken rather than as a
 * sequence.
 *
 * It is now a single row that scrolls horizontally when it must, with the rule
 * drawn once behind the whole strip instead of between each pair. Numbers
 * always line up, and nothing dangles.
 */
export default function TemplateWizardSteps({ steps, current, onSelect, furthest }: Props) {
    return (
        <ol
            aria-label="Progress"
            className="flex items-center gap-1 overflow-x-auto pb-0.5 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
        >
            {steps.map((step, index) => {
                const done = index < furthest;
                const active = index === current;
                const reachable = onSelect !== undefined && index <= furthest;

                return (
                    <li key={step} className="flex shrink-0 items-center">
                        <button
                            type="button"
                            disabled={!reachable}
                            aria-current={active ? 'step' : undefined}
                            onClick={() => reachable && onSelect?.(index)}
                            className={[
                                'flex items-center gap-1.5 whitespace-nowrap rounded-control px-2.5 py-1.5 text-xs font-medium transition-colors',
                                active
                                    ? 'bg-[color:var(--color-primary)] text-white'
                                    : done
                                      ? 'text-[color:var(--color-primary)] hover:bg-gray-100 dark:hover:bg-slate-800'
                                      : 'text-gray-500 dark:text-slate-400',
                                reachable && !active ? 'cursor-pointer hover:bg-gray-100 dark:hover:bg-slate-800' : '',
                                !reachable ? 'cursor-default' : '',
                            ].join(' ')}
                        >
                            <span
                                aria-hidden="true"
                                className={[
                                    'flex h-4 w-4 shrink-0 items-center justify-center rounded-full text-[10px] font-semibold',
                                    active
                                        ? 'bg-white/25'
                                        : done
                                          ? 'bg-[color:var(--color-primary)] text-white'
                                          : 'border border-current',
                                ].join(' ')}
                            >
                                {done ? '✓' : index + 1}
                            </span>
                            {step}
                        </button>

                        {/* Separator, never after the last step. */}
                        {index < steps.length - 1 && (
                            <span
                                aria-hidden="true"
                                className="mx-0.5 h-px w-3 shrink-0 bg-gray-200 dark:bg-slate-700"
                            />
                        )}
                    </li>
                );
            })}
        </ol>
    );
}
