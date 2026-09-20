import { UiProvider } from '@euisis/ui';
import { useLocale } from '@/hooks/useLocale';
import type { ReactNode } from 'react';

export default function LocalizedUiProvider({ children }: { children: ReactNode }) {
    const { t } = useLocale();
    return (
        <UiProvider messages={{
            actions: t('common.actions'),
            cancel: t('common.cancel'),
            clear: t('common.clear'),
            confirm: t('common.confirm'),
            loading: t('common.loading'),
            noResults: t('common.noResults'),
            next: t('common.next'),
            previous: t('common.previous'),
            remove: t('common.remove'),
            replace: t('common.replace'),
            results: t('common.results'),
            search: t('common.search'),
        }}>
            {children}
        </UiProvider>
    );
}
