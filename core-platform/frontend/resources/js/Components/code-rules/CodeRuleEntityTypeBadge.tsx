import { useLocale } from '@/hooks/useLocale';

import { badgeClassName } from '@/Components/ui';
export default function CodeRuleEntityTypeBadge({ entityType }: { entityType: string }) {
    const { t } = useLocale();

    return (
        <span className={badgeClassName('info')}>
            {t(`codeRules.entityTypes.${entityType}`)}
        </span>
    );
}
