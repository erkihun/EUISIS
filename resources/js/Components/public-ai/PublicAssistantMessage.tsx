import AssistantTextRenderer from '@/Components/ai/AssistantTextRenderer';
import type { PublicAssistantMessage as Message, PublicTextBlock } from '@/types/publicAssistant';
import PublicAssistantBlocks from './PublicAssistantBlocks';

/** One turn: the visitor's text on the right, the reply (strict text + blocks) on the left. */
export default function PublicAssistantMessage({ message }: { message: Message }) {
    if (message.role === 'user') {
        return (
            <div className="flex justify-end">
                <div className="max-w-[85%] whitespace-pre-wrap break-words rounded-card bg-primary px-3 py-2 text-sm text-(--color-on-primary,#fff)">
                    {message.text}
                </div>
            </div>
        );
    }

    const textBlocks = message.blocks.filter((block): block is PublicTextBlock => block.type === 'text' && typeof (block as PublicTextBlock).text === 'string');

    return (
        <div className="flex justify-start">
            <div className="max-w-[92%] space-y-2 rounded-card border border-(--app-border) bg-(--app-surface-muted) px-3 py-2 text-sm text-(--app-foreground)">
                {textBlocks.length > 0
                    ? textBlocks.map((block, index) => <AssistantTextRenderer key={index} text={block.text} />)
                    : message.text && <AssistantTextRenderer text={message.text} />}
                <PublicAssistantBlocks blocks={message.blocks} messageId={message.id} />
            </div>
        </div>
    );
}
