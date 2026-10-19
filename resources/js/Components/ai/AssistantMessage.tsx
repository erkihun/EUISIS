import { useEffect, useState } from 'react';
import { Button, cx } from '@/Components/ui';
import { CopyIcon, CheckReadIcon, LikeIcon, DislikeIcon } from '@/Components/NavIcons';
import { useLocale } from '@/hooks/useLocale';
import type { AssistantFeedback, AssistantMessage as Message, TextBlock } from '@/types/assistant';
import AssistantBlocks, { type BlockContext } from './AssistantBlocks';
import AssistantTextRenderer from './AssistantTextRenderer';
import { visibleText } from './useAssistant';

interface Props {
    message: Message;
    context: BlockContext;
    onFeedback: (messageId: string, value: AssistantFeedback) => void;
}

async function copyText(text: string): Promise<boolean> {
    try {
        if (navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(text);
            return true;
        }
    } catch {
        // fall through to the legacy path
    }
    try {
        const area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        const ok = document.execCommand('copy');
        document.body.removeChild(area);
        return ok;
    } catch {
        return false;
    }
}

export default function AssistantMessage({ message, context, onFeedback }: Props) {
    const { t } = useLocale();
    const [copied, setCopied] = useState(false);

    useEffect(() => {
        if (!copied) return;
        const timer = window.setTimeout(() => setCopied(false), 2000);
        return () => window.clearTimeout(timer);
    }, [copied]);

    if (message.role === 'user') {
        return (
            <div className="flex justify-end">
                <div className="max-w-[85%] whitespace-pre-wrap break-words rounded-card bg-primary px-3 py-2 text-sm text-(--color-on-primary,#fff)">
                    {message.text}
                </div>
            </div>
        );
    }

    const textBlocks = message.blocks.filter((block): block is TextBlock => block.type === 'text' && typeof (block as TextBlock).text === 'string');
    const aiGenerated = message.meta?.ai === true;
    const copyable = visibleText(message);

    return (
        <div className="flex justify-start">
            <div className="max-w-[92%] space-y-2 rounded-card border border-(--app-border) bg-(--app-surface-muted) px-3 py-2 text-sm text-(--app-foreground)">
                {textBlocks.length > 0
                    ? textBlocks.map((block, index) => <AssistantTextRenderer key={index} text={block.text} />)
                    : message.text && <AssistantTextRenderer text={message.text} />}
                <AssistantBlocks blocks={message.blocks} context={context} />
                {(copyable !== '' || aiGenerated) && (
                    <div className="flex flex-wrap items-center gap-1 pt-1">
                        {copyable !== '' && (
                            <Button
                                variant="ghost"
                                size="xs"
                                icon={copied ? <CheckReadIcon className="h-3.5 w-3.5" /> : <CopyIcon className="h-3.5 w-3.5" />}
                                onClick={() => { void copyText(copyable).then((ok) => setCopied(ok)); }}
                                aria-live="polite"
                            >
                                {copied ? t('assistant.copied') : t('assistant.copy')}
                            </Button>
                        )}
                        {aiGenerated && (
                            <span className="ms-auto inline-flex items-center gap-1" role="group" aria-label={t('assistant.feedback.label')}>
                                <Button
                                    variant="ghost"
                                    size="xs"
                                    aria-pressed={message.feedback === 'helpful'}
                                    className={cx(message.feedback === 'helpful' && 'text-primary')}
                                    icon={<LikeIcon className="h-3.5 w-3.5" />}
                                    onClick={() => onFeedback(message.id, 'helpful')}
                                    disabled={message.feedback !== null}
                                >
                                    {t('assistant.feedback.helpful')}
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="xs"
                                    aria-pressed={message.feedback === 'not_helpful'}
                                    className={cx(message.feedback === 'not_helpful' && 'text-primary')}
                                    icon={<DislikeIcon className="h-3.5 w-3.5" />}
                                    onClick={() => onFeedback(message.id, 'not_helpful')}
                                    disabled={message.feedback !== null}
                                >
                                    {t('assistant.feedback.notHelpful')}
                                </Button>
                            </span>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}
