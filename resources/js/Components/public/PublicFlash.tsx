/**
 * The shared `flash` prop is always an object — every key null when nothing
 * was flashed — so a page must test for a message, not for the prop. Rendering
 * on the prop alone painted an empty red bar on every visit.
 */
export type SharedFlash = {
    message?: string | null;
    type?: string | null;
    success?: string | null;
    error?: string | null;
    warning?: string | null;
    info?: string | null;
} | null | undefined;

const TONE_CLASS: Record<'success' | 'error' | 'warning' | 'info', string> = {
    success: 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300',
    error: 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300',
    warning: 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200',
    info: 'border-primary/20 bg-primary/5 text-gray-800 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200',
};

function toneOf(flash: NonNullable<SharedFlash>): keyof typeof TONE_CLASS {
    if (flash.type === 'success' || flash.type === 'error' || flash.type === 'warning' || flash.type === 'info') return flash.type;
    if (flash.error) return 'error';
    if (flash.success) return 'success';
    if (flash.warning) return 'warning';
    return 'info';
}

export default function PublicFlash({ flash, className = '' }: { flash: SharedFlash; className?: string }) {
    const message = flash?.message ?? flash?.error ?? flash?.success ?? flash?.warning ?? flash?.info ?? null;

    if (!flash || !message) return null;

    const tone = toneOf(flash);

    return (
        <div
            role={tone === 'error' ? 'alert' : 'status'}
            className={`rounded-card border px-4 py-3 text-sm font-medium ${TONE_CLASS[tone]} ${className}`}
        >
            {message}
        </div>
    );
}
