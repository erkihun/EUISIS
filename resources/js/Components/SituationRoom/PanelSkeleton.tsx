/** Placeholder cards in the shape of a category while its figures load. */
export default function PanelSkeleton({ label }: { label: string }) {
    return (
        <div role="status" aria-label={label} className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
            <span className="sr-only">{label}</span>
            {[3, 1, 1, 1, 2, 1].map((span, i) => (
                <div key={i} className={`${span === 3 ? 'md:col-span-2 lg:col-span-3 h-28' : span === 2 ? 'md:col-span-2 h-72' : 'h-72'} animate-pulse rounded-card bg-[color:var(--app-surface-muted)]`} />
            ))}
        </div>
    );
}
