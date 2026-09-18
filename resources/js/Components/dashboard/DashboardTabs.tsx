import { useEffect, useState } from 'react';

export type DashboardTab = {
    id: string;
    label: string;
};

const STORAGE_KEY = 'euisis-dashboard-tab';

interface Props {
    tabs: DashboardTab[];
    activeId: string;
    onChange: (id: string) => void;
    /** Names the tablist for screen readers. */
    label: string;
}

/**
 * Picks which area of the dashboard is on screen.
 *
 * The page previously stacked every permitted section — workforce, structure,
 * positions, cards, verification, services, NFC, integrations, transfers — one
 * after another. A Super Admin scrolled past twenty-two charts to reach the
 * audit feed, and nobody reaches the bottom of a page like that twice.
 *
 * Splitting them into tabs trades a little density per view for the ability to
 * arrive at the right area in one click. Only the active tab's charts mount,
 * so the initial render also stops paying for charts nobody is looking at.
 */
export default function DashboardTabs({ tabs, activeId, onChange, label }: Props) {
    if (tabs.length < 2) return null;

    return (
        <div
            role="tablist"
            aria-label={label}
            className="flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-slate-800"
        >
            {tabs.map((tab) => {
                const active = tab.id === activeId;

                return (
                    <button
                        key={tab.id}
                        type="button"
                        role="tab"
                        id={`dashboard-tab-${tab.id}`}
                        aria-selected={active}
                        aria-controls={`dashboard-panel-${tab.id}`}
                        onClick={() => onChange(tab.id)}
                        className={[
                            'relative shrink-0 whitespace-nowrap px-3 py-2 text-sm transition-colors',
                            'focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[color:var(--color-primary)]',
                            active
                                ? 'font-semibold text-[color:var(--color-primary)]'
                                : 'font-medium text-gray-500 hover:text-gray-900 dark:text-slate-400 dark:hover:text-slate-100',
                        ].join(' ')}
                    >
                        {tab.label}
                        {/* Sits on the container's own border line. */}
                        {active && (
                            <span
                                aria-hidden="true"
                                className="absolute inset-x-0 -bottom-px h-0.5 bg-[color:var(--color-primary)]"
                            />
                        )}
                    </button>
                );
            })}
        </div>
    );
}

/**
 * Remembers the tab across visits.
 *
 * An operator works in one area for a shift; sending them back to "Workforce"
 * on every navigation is a small tax paid many times a day. Falls back to the
 * first available tab whenever the stored one is no longer permitted, so a
 * permission change can never strand the page on an empty panel.
 */
export function useDashboardTab(available: DashboardTab[]): [string, (id: string) => void] {
    const firstId = available[0]?.id ?? '';

    /* The caller rebuilds `available` on every render, so depending on the
       array itself would re-run the effect each time. The id list is the part
       that actually changes. */
    const availableIds = available.map((tab) => tab.id).join(',');

    const [activeId, setActiveId] = useState<string>(() => {
        try {
            const stored = localStorage.getItem(STORAGE_KEY);
            if (stored && available.some((tab) => tab.id === stored)) return stored;
        } catch {
            /* Private mode or blocked storage — the default is fine. */
        }
        return firstId;
    });

    /* Re-validate when the permitted set changes under us. */
    useEffect(() => {
        const ids = availableIds ? availableIds.split(',') : [];
        if (ids.length > 0 && !ids.includes(activeId)) {
            setActiveId(ids[0]);
        }
    }, [availableIds, activeId]);

    const change = (id: string) => {
        setActiveId(id);
        try {
            localStorage.setItem(STORAGE_KEY, id);
        } catch {
            /* Not being able to remember the tab is not worth failing over. */
        }
    };

    return [activeId, change];
}
