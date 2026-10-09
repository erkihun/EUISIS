import { useCallback, useEffect, useRef, useState } from 'react';

const STORAGE_PREFIX = 'hierarchy-tree:';
const MAX_IDS = 500;

function storageKey(treeKey: string): string {
    return `${STORAGE_PREFIX}${treeKey}:expandedNodes`;
}

function readFromStorage(treeKey: string): Set<string> | null {
    try {
        const raw = localStorage.getItem(storageKey(treeKey));

        if (!raw) {
            return null;
        }

        const parsed = JSON.parse(raw);

        if (!Array.isArray(parsed)) {
            return null;
        }

        return new Set(parsed.filter((value): value is string => typeof value === 'string'));
    } catch {
        return null;
    }
}

function writeToStorage(treeKey: string, ids: Set<string>): void {
    try {
        const values = Array.from(ids).slice(0, MAX_IDS);
        localStorage.setItem(storageKey(treeKey), JSON.stringify(values));
    } catch {
        // Ignore storage failures such as private mode or quota limits.
    }
}

export function useTreeExpandState(
    treeKey: string,
    defaultIds: Set<string>,
): {
    expandedIds: Set<string>;
    toggleNode: (id: string) => void;
    expandAll: (allIds: Set<string>) => void;
    collapseAll: () => void;
} {
    const [expandedIds, setExpandedIds] = useState<Set<string>>(() => {
        const saved = readFromStorage(treeKey);

        return saved ?? defaultIds;
    });

    const writeTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    const persist = useCallback((ids: Set<string>) => {
        if (writeTimer.current) {
            clearTimeout(writeTimer.current);
        }

        writeTimer.current = setTimeout(() => {
            writeToStorage(treeKey, ids);
        }, 300);
    }, [treeKey]);

    useEffect(() => () => {
        if (writeTimer.current) {
            clearTimeout(writeTimer.current);
        }
    }, []);

    const toggleNode = useCallback((id: string) => {
        setExpandedIds((previous) => {
            const next = new Set(previous);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            persist(next);

            return next;
        });
    }, [persist]);

    const expandAll = useCallback((allIds: Set<string>) => {
        setExpandedIds(() => {
            persist(allIds);

            return allIds;
        });
    }, [persist]);

    const collapseAll = useCallback(() => {
        setExpandedIds(() => {
            const empty = new Set<string>();
            persist(empty);

            return empty;
        });
    }, [persist]);

    return { expandedIds, toggleNode, expandAll, collapseAll };
}
