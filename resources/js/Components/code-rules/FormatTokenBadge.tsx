type TokenVariant = 'default' | 'active' | 'unknown' | 'inactive';

const variantClasses: Record<TokenVariant, string> = {
    default:
        'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700 cursor-pointer',
    active:
        'bg-blue-100 text-blue-700 ring-1 ring-blue-400 hover:bg-blue-200 dark:bg-blue-900/40 dark:text-blue-300 dark:ring-[color:var(--color-primary)] dark:hover:bg-blue-900/60 cursor-pointer',
    unknown:
        'bg-red-100 text-red-700 ring-1 ring-red-400 dark:bg-red-900/30 dark:text-red-400 dark:ring-red-700',
    inactive:
        'bg-gray-100 text-gray-400 line-through cursor-not-allowed dark:bg-slate-800 dark:text-slate-600',
};

export default function FormatTokenBadge({
    token,
    onClick,
    variant = 'default',
    title,
}: {
    token: string;
    onClick?: () => void;
    variant?: TokenVariant;
    title?: string;
}) {
    const classes = [
        'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-mono font-medium transition-colors select-none',
        variantClasses[variant],
    ].join(' ');

    if (onClick && variant !== 'inactive') {
        return (
            <button type="button" className={classes} onClick={onClick} title={title}>
                {`{${token}}`}
            </button>
        );
    }

    return (
        <span className={classes} title={title}>
            {`{${token}}`}
        </span>
    );
}
