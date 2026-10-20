import { forwardRef, type KeyboardEvent } from 'react';
import { Button, Textarea, cx } from '@/Components/ui';
import { SendIcon } from '@/Components/NavIcons';
import { useLocale } from '@/hooks/useLocale';

interface Props {
    value: string;
    onChange: (value: string) => void;
    onSend: () => void;
    disabled: boolean;
    maxChars: number;
    className?: string;
}

/** Enter sends, Shift+Enter inserts a newline; the counter appears near the limit. */
const PublicAssistantComposer = forwardRef<HTMLTextAreaElement, Props>(function PublicAssistantComposer(
    { value, onChange, onSend, disabled, maxChars, className },
    ref,
) {
    const { t } = useLocale();
    const remaining = maxChars - value.length;
    const nearLimit = maxChars > 0 && (remaining <= 100 || value.length >= maxChars * 0.8);
    const canSend = !disabled && value.trim() !== '' && remaining >= 0;

    const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if (event.key === 'Enter' && !event.shiftKey && !event.nativeEvent.isComposing) {
            event.preventDefault();
            if (canSend) onSend();
        }
    };

    return (
        <div className={cx('border-t border-(--app-border) bg-(--app-surface) px-3 pt-2', className)}>
            <div className="flex items-end gap-2">
                <Textarea
                    ref={ref}
                    rows={1}
                    value={value}
                    onChange={(event) => onChange(event.target.value.slice(0, maxChars > 0 ? maxChars : undefined))}
                    onKeyDown={onKeyDown}
                    disabled={disabled}
                    maxLength={maxChars > 0 ? maxChars : undefined}
                    placeholder={t('publicAssistant.composer.placeholder')}
                    aria-label={t('publicAssistant.composer.label')}
                    className="w-full resize-none text-base sm:text-sm"
                    style={{ minHeight: '2.75rem', maxHeight: '10rem' }}
                />
                <Button
                    variant="primary"
                    size="icon"
                    className="h-11 w-11 shrink-0"
                    onClick={onSend}
                    disabled={!canSend}
                    aria-label={t('publicAssistant.composer.send')}
                    icon={<SendIcon className="h-5 w-5" />}
                />
            </div>
            <div className="flex min-h-5 items-center justify-between gap-2 py-1 text-[11px] text-(--app-muted-foreground)">
                <span className="hidden sm:inline">{t('publicAssistant.composer.hint')}</span>
                {nearLimit && (
                    <span className={cx('ms-auto tabular-nums', remaining < 0 && 'text-red-600 dark:text-red-400')} aria-live="polite">
                        {value.length} / {maxChars}
                    </span>
                )}
            </div>
        </div>
    );
});

export default PublicAssistantComposer;
