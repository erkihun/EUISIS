import { router } from '@inertiajs/react';
import AppActionMenu from '@/Components/ui/AppActionMenu';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';

type RuleRow = {
    id: string;
    is_active: boolean;
    can: { view: boolean; update: boolean; archive: boolean; restore: boolean };
};

/** The ⋮ menu on a code-rule row: view, edit, and archive or restore depending on the rule's state. */
export default function CodeRuleActionMenu({ rule }: { rule: RuleRow }) {
    const { t } = useLocale();
    const { confirm } = useConfirm();

    async function archive() {
        const { confirmed } = await confirm({
            title: t('confirmations.confirmDeleteTitle'),
            description: t('confirmations.thisRecordWillMoveToRecycleBin'),
            confirmLabel: t('confirmations.delete'),
            cancelLabel: t('confirmations.cancel'),
            variant: 'danger',
        });
        if (confirmed) router.post(route('code-rules.archive', rule.id));
    }

    async function restore() {
        const { confirmed } = await confirm({
            title: t('confirmations.confirmRestoreTitle'),
            description: t('confirmations.thisActionCannotBeUndone'),
            confirmLabel: t('confirmations.restore'),
            cancelLabel: t('confirmations.cancel'),
            variant: 'default',
        });
        if (confirmed) router.post(route('code-rules.restore', rule.id));
    }

    return (
        <AppActionMenu
            items={[
                { label: t('common.view'), href: route('code-rules.show', rule.id), show: rule.can.view },
                { label: t('common.edit'), href: route('code-rules.edit', rule.id), show: rule.can.update },
                { label: t('common.delete'), onClick: archive, variant: 'danger', show: rule.can.archive && rule.is_active },
                { label: t('common.restore'), onClick: restore, show: rule.can.restore && !rule.is_active },
            ]}
        />
    );
}
