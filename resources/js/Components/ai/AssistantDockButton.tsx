import { usePage } from '@inertiajs/react';
import type { JSX } from 'react';
import { AssistantIcon } from '@/Components/NavIcons';
import { useLocale } from '@/hooks/useLocale';
import type { PageProps } from '@/types';
import { assistantDock, useAssistantDock } from './assistantDock';

/**
 * The assistant's trigger inside a page's own toolbar (see assistantDock.ts).
 * Sized like the toolbar's other icon buttons, 44px on touch screens.
 */
export default function AssistantDockButton(): JSX.Element | null {
    const { t } = useLocale();
    const enabled = usePage<PageProps>().props.assistant?.enabled === true;
    const { expanded } = useAssistantDock();
    if (!enabled) return null;

    return (
        <button
            ref={assistantDock.attach}
            type="button"
            onClick={assistantDock.toggle}
            aria-label={t('assistant.title')}
            title={t('assistant.title')}
            aria-haspopup="dialog"
            aria-expanded={expanded}
            className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-control transition-colors hover:bg-(--app-surface-muted) hover:text-(--app-foreground) focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-primary pointer-coarse:h-11 pointer-coarse:w-11 ${expanded ? 'bg-(--app-surface-muted) text-primary' : 'text-(--app-muted-foreground)'}`}
        >
            <AssistantIcon className="h-5 w-5" aria-hidden="true" />
        </button>
    );
}
