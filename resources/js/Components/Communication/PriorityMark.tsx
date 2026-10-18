import type { JSX } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { DangerCircleIcon, MegaphoneIcon } from '@/Components/NavIcons';
import type { AnnouncementPriority } from '@/types/communication';

const TONE: Record<AnnouncementPriority, string> = {
    NORMAL: 'text-(--app-muted-foreground)',
    IMPORTANT: 'text-primary',
    URGENT: 'text-amber-700 dark:text-amber-300',
    EMERGENCY: 'text-(--color-danger,#b42318)',
};

/** The announcement's priority as an icon in its tone, with the word for anything above normal. */
export default function PriorityMark({ priority, withLabel = true }: { priority: AnnouncementPriority; withLabel?: boolean }): JSX.Element {
    const { t } = useLocale();
    const Icon = priority === 'EMERGENCY' || priority === 'URGENT' ? DangerCircleIcon : MegaphoneIcon;

    return (
        <span className={`inline-flex items-center gap-1 text-xs font-medium ${TONE[priority]}`}>
            <Icon className="h-4 w-4 shrink-0" aria-hidden="true" />
            {withLabel && priority !== 'NORMAL' && <span>{t(`communication.announcements.priorities.${priority}`)}</span>}
            {(!withLabel || priority === 'NORMAL') && <span className="sr-only">{t(`communication.announcements.priorities.${priority}`)}</span>}
        </span>
    );
}
