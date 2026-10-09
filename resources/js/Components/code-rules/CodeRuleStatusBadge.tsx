import { useLocale } from '@/hooks/useLocale';

export default function CodeRuleStatusBadge({ isActive }: { isActive: boolean }) {
    const { t } = useLocale();

    return (
        <span
            className={[
                'inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium',
                isActive
                    ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300'
                    : 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
            ].join(' ')}
        >
            {isActive ? t('codeRules.statusActive') : t('codeRules.statusInactive')}
        </span>
    );
}
