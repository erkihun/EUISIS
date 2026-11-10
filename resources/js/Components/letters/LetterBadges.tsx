import { badgeClassName } from '@/Components/ui';
import { priorityTone, statusTone, useLetters } from '@/Components/letters/helpers';
import type { JSX } from 'react';

type Kind = 'status' | 'registrationStatus' | 'routingStatus' | 'templateStatus' | 'direction' | 'classification' | 'dispatchMode';

/** One badge style for every status the module shows; the label comes from the dictionary. */
export function StatusBadge({ kind, value, className }: { kind: Kind; value: string; className?: string }): JSX.Element {
    const { lt } = useLetters();
    const tone = kind === 'direction' ? (value === 'incoming' ? 'info' : value === 'outgoing' ? 'success' : 'neutral')
        : kind === 'classification' ? (value === 'secret' ? 'danger' : value === 'confidential' ? 'warning' : 'neutral')
        : kind === 'dispatchMode' ? 'neutral'
        : statusTone(value);

    return <span className={badgeClassName(tone, className)}>{lt(`${kind}.${value}`)}</span>;
}

export function PriorityBadge({ value, className }: { value: string; className?: string }): JSX.Element | null {
    const { lt } = useLetters();
    if (value === 'normal') return null;

    return <span className={badgeClassName(priorityTone(value), className)}>{lt(`priority.${value}`)}</span>;
}
