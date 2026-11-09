import { Button } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import type { PublicAssistantQuickAction } from '@/types/publicAssistant';

interface Props {
    actions: PublicAssistantQuickAction[];
    /** Whether the chips are shown; after the first message they fold behind the "Quick help" toggle. */
    show: boolean;
    hasMessages: boolean;
    disabled: boolean;
    onToggle: () => void;
    onSelect: (key: string, label: string) => void;
}

/** The page's quick-help chips from the bootstrap; a tap sends the action with its label as the message. */
export default function PublicAssistantQuickActions({ actions, show, hasMessages, disabled, onToggle, onSelect }: Props) {
    const { t } = useLocale();
    if (actions.length === 0) return null;

    return (
        <div className="shrink-0 border-t border-(--app-border) bg-(--app-surface) px-3 py-2">
            {hasMessages && (
                <Button variant="ghost" size="xs" onClick={onToggle} aria-expanded={show} className="mb-1">
                    {t('publicAssistant.quickHelp')}
                </Button>
            )}
            {show && (
                <div className="flex flex-wrap gap-1.5" role="group" aria-label={t('publicAssistant.quickHelpLabel')}>
                    {actions.map((action) => (
                        <Button
                            key={action.key}
                            variant="outline"
                            size="sm"
                            className="min-h-9 rounded-full"
                            disabled={disabled}
                            onClick={() => onSelect(action.key, action.label)}
                        >
                            {action.label}
                        </Button>
                    ))}
                </div>
            )}
        </div>
    );
}
