import { EmptyState as UiEmptyState } from '@euisis/ui';
import { useLocale } from '@/hooks/useLocale';
import type { ReactNode } from 'react';

interface Props {
    title?: string;
    description?: string;
    action?: ReactNode;
    icon?: ReactNode;
    className?: string;
}

export default function EmptyState({ title, ...props }: Props) {
    const { t } = useLocale();
    return <UiEmptyState title={title ?? t('common.noResults')} {...props} />;
}
