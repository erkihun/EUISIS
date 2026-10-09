import { useLocale } from '@/hooks/useLocale';

export default function CodeRuleEntityTypeBadge({ entityType }: { entityType: string }) {
    const { t } = useLocale();

    return (
        <span className="inline-flex items-center rounded-full bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700 dark:bg-blue-900/20 dark:text-blue-300">
            {t(`codeRules.entityTypes.${entityType}`)}
        </span>
    );
}
