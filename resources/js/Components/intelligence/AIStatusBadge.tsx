import { badgeClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';

/**
 * Says plainly whether AI explanations are on. The deterministic figures never
 * depend on it; this badge only tells the reader what kind of text to expect.
 */
export default function AIStatusBadge({ enabled, provider }: { enabled: boolean; provider: string }) {
    const { t } = useLocale();

    return (
        <span className={badgeClassName(enabled ? 'success' : 'neutral')} title={enabled ? provider : undefined}>
            {enabled ? t('intelligence.ai.on') : t('intelligence.ai.off')}
        </span>
    );
}
