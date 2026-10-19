import { useEffect, useSyncExternalStore } from 'react';

/**
 * A page may tell the assistant which record it shows, and nothing else
 * (docs/assistant/context-and-tools.md: "only when the page component
 * declares them"). The declaration lives in a module-level store so the
 * assistant, which is mounted by the layout, reads it without prop drilling
 * and survives the layout remounting between Inertia visits.
 */
export interface DeclaredPageRecord {
    type: string;
    id: string | number;
}

let current: DeclaredPageRecord | null = null;
const listeners = new Set<() => void>();

function set(next: DeclaredPageRecord | null): void {
    current = next;
    listeners.forEach((listener) => listener());
}

function subscribe(listener: () => void): () => void {
    listeners.add(listener);
    return () => listeners.delete(listener);
}

function snapshot(): DeclaredPageRecord | null {
    return current;
}

/** The record the current page declared, or null. */
export function useAssistantPageRecord(): DeclaredPageRecord | null {
    return useSyncExternalStore(subscribe, snapshot, snapshot);
}

/** Non-rendering: registers the record while the page is mounted. */
export default function AssistantPageContext({ type, id }: DeclaredPageRecord) {
    useEffect(() => {
        set({ type, id });
        return () => {
            if (current && current.type === type && String(current.id) === String(id)) set(null);
        };
    }, [type, id]);

    return null;
}
