import { useEffect, useRef, useState } from 'react';

export type SaveState = 'saved' | 'unsaved' | 'saving';

/**
 * Saves a section a moment after the user stops typing, and reports
 * saved / unsaved / saving for the screen (aria-live). `save` returns a
 * promise that settles when the request finishes.
 */
export function useAutosave(dirty: boolean, enabled: boolean, save: () => Promise<void>, delay = 1500): SaveState {
    const [state, setState] = useState<SaveState>('saved');
    const latest = useRef(save);
    latest.current = save;

    useEffect(() => {
        if (!dirty) {
            setState((s) => (s === 'saving' ? s : 'saved'));
            return;
        }
        setState('unsaved');
        if (!enabled) return;
        const timer = window.setTimeout(() => {
            setState('saving');
            latest.current().finally(() => setState('saved'));
        }, delay);
        return () => window.clearTimeout(timer);
    }, [dirty, enabled, delay]);

    return state;
}
