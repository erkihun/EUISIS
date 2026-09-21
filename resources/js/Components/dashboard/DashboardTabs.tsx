import { type KeyboardEvent, useEffect, useRef, useState } from 'react';

export type DashboardTab = {
    id: string;
    label: string;
};

const STORAGE_KEY = 'euisis-dashboard-view-v2';

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
    const tabRefs = useRef<Array<HTMLButtonElement | null>>([]);

    if (tabs.length < 2) return null;

    const selectTab = (index: number) => {
        const nextIndex = (index + tabs.length) % tabs.length;
        const nextTab = tabs[nextIndex];

        onChange(nextTab.id);
        tabRefs.current[nextIndex]?.focus();
        tabRefs.current[nextIndex]?.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' });
    };

    const handleKeyDown = (event: KeyboardEvent<HTMLButtonElement>, index: number) => {
        const targetIndex =
            event.key === 'ArrowRight'
                ? index + 1
                : event.key === 'ArrowLeft'
                  ? index - 1
                  : event.key === 'Home'
                    ? 0
                    : event.key === 'End'
                      ? tabs.length - 1
                      : null;

        if (targetIndex === null) return;

        event.preventDefault();
        selectTab(targetIndex);
    };

    return (
        <div
            role="tablist"
            aria-label={label}
            aria-orientation="horizontal"
            className="sticky top-14 z-10 flex max-w-full gap-1 overflow-x-auto border-b border-gray-200 bg-gray-50 pt-1 dark:border-slate-800 dark:bg-slate-950"
        >
            {tabs.map((tab, index) => {
                const active = tab.id === activeId;

                return (
                    <button
                        key={tab.id}
                        ref={(element) => {
                            tabRefs.current[index] = element;
                        }}
                        type="button"
                        role="tab"
                        id={`dashboard-tab-${tab.id}`}
                        aria-selected={active}
                        aria-controls={`dashboard-panel-${tab.id}`}
                        tabIndex={active ? 0 : -1}
                        onClick={() => onChange(tab.id)}
                        onKeyDown={(event) => handleKeyDown(event, index)}
                        className={[
                            'relative min-h-11 shrink-0 whitespace-nowrap px-3 py-2 text-sm transition-colors',
                            'focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[color:var(--color-primary)]',
                            active
                                ? 'font-semibold text-[color:var(--color-primary)] dark:text-indigo-300'
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
