import { useSyncExternalStore } from 'react';

/*
 * Lets a page give the assistant's trigger a place of its own (AssistantDockButton)
 * instead of the floating bottom-right button. Full-height workspaces such as
 * Communication need that corner for their composer, its send button and the
 * "jump to latest" control. While a dock button is mounted the floating one
 * is not drawn; GlobalAIAssistant stays the only owner of the panel and its state.
 */

type Snapshot = { dock: HTMLButtonElement | null; expanded: boolean };

let snapshot: Snapshot = { dock: null, expanded: false };
let toggleHandler: () => void = () => undefined;
const listeners = new Set<() => void>();

function emit(next: Partial<Snapshot>): void {
    snapshot = { ...snapshot, ...next };
    listeners.forEach((listener) => listener());
}

function subscribe(listener: () => void): () => void {
    listeners.add(listener);
    return () => listeners.delete(listener);
}

export const assistantDock = {
    /** Ref callback of the dock button: registers it on mount, clears it on unmount. */
    attach(element: HTMLButtonElement | null): void {
        if (snapshot.dock !== element) emit({ dock: element });
    },
    /** GlobalAIAssistant reports the panel state and how to toggle it. */
    publish(expanded: boolean, toggle: () => void): void {
        toggleHandler = toggle;
        if (snapshot.expanded !== expanded) emit({ expanded });
    },
    toggle(): void {
        toggleHandler();
    },
    element(): HTMLButtonElement | null {
        return snapshot.dock;
    },
};

export function useAssistantDock(): Snapshot {
    return useSyncExternalStore(subscribe, () => snapshot, () => snapshot);
}
