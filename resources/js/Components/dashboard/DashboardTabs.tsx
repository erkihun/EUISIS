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
    /** Prefix of the tab and panel element ids; pages other than the dashboard pass their own. */
    idPrefix?: string;
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
export default function DashboardTabs({ tabs, activeId, onChange, label, idPrefix = 'dashboard' }: Props) {
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
            className="sticky top-14 z-10 flex max-w-full gap-1 overflow-x-auto border-b border-(--app-border) bg-(--app-surface-muted) pt-1"
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
                        id={`${idPrefix}-tab-${tab.id}`}
                        aria-selected={active}
                        aria-controls={`${idPrefix}-panel-${tab.id}`}
                        tabIndex={active ? 0 : -1}
                        onClick={() => onChange(tab.id)}
                        onKeyDown={(event) => handleKeyDown(event, index)}
                        className={[
                            'relative min-h-11 shrink-0 whitespace-nowrap px-3 py-2 text-sm transition-colors',
                            'focus:outline-hidden focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary',
                            active
                                ? 'font-semibold text-primary dark:text-indigo-300'
                                : 'font-medium text-(--app-muted-foreground) hover:text-(--app-foreground)',
                        ].join(' ')}
                    >
                        {tab.label}
                        {/* Sits on the container's own border line. */}
                        {active && (
                            <span
                                aria-hidden="true"
                                className="absolute inset-x-0 -bottom-px h-0.5 bg-primary"
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
export function useDashboardTab(available: DashboardTab[], storageKey: string = STORAGE_KEY): [string, (id: string) => void] {
    const firstId = available[0]?.id ?? '';

    /* The caller rebuilds `available` on every render, so depending on the
       array itself would re-run the effect each time. The id list is the part
       that actually changes. */
    const availableIds = available.map((tab) => tab.id).join(',');

    const [activeId, setActiveId] = useState<string>(() => {
        try {
            const stored = localStorage.getItem(storageKey);
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
            localStorage.setItem(storageKey, id);
        } catch {
            /* Not being able to remember the tab is not worth failing over. */
        }
    };

    return [activeId, change];
}
