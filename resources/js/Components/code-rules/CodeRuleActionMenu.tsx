import { Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';

type RuleRow = {
    id: string;
    is_active: boolean;
    can: { view: boolean; update: boolean; archive: boolean; restore: boolean };
};

export default function CodeRuleActionMenu({ rule }: { rule: RuleRow }) {
    const { t } = useLocale();
    const { confirm } = useConfirm();

    return (
        <div className="flex justify-end gap-3 text-xs font-medium">
            {rule.can.view && (
                <Link href={route('code-rules.show', rule.id)} className="text-[color:var(--color-primary)] hover:text-[color:var(--color-primary-hover)] dark:text-[color:var(--color-primary)]">
                    {t('codeRules.actions.view')}
                </Link>
            )}
            {rule.can.update && (
                <Link href={route('code-rules.edit', rule.id)} className="text-[color:var(--color-primary)] hover:text-[color:var(--color-primary-hover)] dark:text-[color:var(--color-primary)]">
                    {t('common.edit')}
                </Link>
            )}
            {rule.can.archive && rule.is_active && (
                <button
                    type="button"
                    onClick={async () => {
                        const { confirmed } = await confirm({
                            title: t('confirmations.confirmDeleteTitle'),
                            description: t('confirmations.thisRecordWillMoveToRecycleBin'),
                            confirmLabel: t('confirmations.delete'),
                            cancelLabel: t('confirmations.cancel'),
                            variant: 'danger',
                        });
                        if (confirmed) router.post(route('code-rules.archive', rule.id));
                    }}
                    className="text-red-600 hover:text-red-800"
                >
                    {t('codeRules.actions.archive')}
                </button>
            )}
            {rule.can.restore && !rule.is_active && (
                <button
                    type="button"
                    onClick={async () => {
                        const { confirmed } = await confirm({
                            title: t('confirmations.confirmRestoreTitle'),
                            description: t('confirmations.thisActionCannotBeUndone'),
                            confirmLabel: t('confirmations.restore'),
                            cancelLabel: t('confirmations.cancel'),
                            variant: 'default',
                        });
                        if (confirmed) router.post(route('code-rules.restore', rule.id));
                    }}
                    className="text-green-600 hover:text-green-800"
                >
                    {t('codeRules.actions.restore')}
                </button>
            )}
        </div>
    );
}
