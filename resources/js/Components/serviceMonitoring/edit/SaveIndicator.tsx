import type { JSX } from 'react';
import { useSm } from '@/Components/serviceMonitoring/helpers';
import type { SaveState } from './useAutosave';

export default function SaveIndicator({ state }: { state: SaveState }): JSX.Element {
    const { sm } = useSm();
    const tone = state === 'saved' ? 'text-emerald-700 dark:text-emerald-300' : state === 'saving' ? 'text-(--app-muted-foreground)' : 'text-amber-700 dark:text-amber-300';

    return <p role="status" aria-live="polite" className={`text-xs ${tone}`}>{sm(`common.${state === 'saving' ? 'saving' : state === 'saved' ? 'saved' : 'unsaved'}`)}</p>;
}
