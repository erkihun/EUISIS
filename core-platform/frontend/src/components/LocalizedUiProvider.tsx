import type { ReactNode } from 'react';
import { UiProvider } from '../ui';
import { useLocale } from '../i18n';

/** EUISIS Components/ui/LocalizedUiProvider.tsx: the UI kit's words in the viewer's language. */
export default function LocalizedUiProvider({ children }: { children: ReactNode }) {
    const { t } = useLocale();

    return (
        <UiProvider messages={{
            actions: t('common.actions'),
            cancel: t('common.cancel'),
            clear: t('common.clear'),
            confirm: t('common.confirm'),
            filter: t('common.filter'),
            filters: t('common.filters'),
            loading: t('common.loading'),
            noResults: t('common.noResults'),
            next: t('common.next'),
            page: t('common.page'),
            pagination: t('common.pagination'),
            previous: t('common.previous'),
            range: t('common.paginationRange'),
            remove: t('common.remove'),
            replace: t('common.replace'),
            required: t('common.requiredField'),
            reset: t('common.reset'),
            results: t('common.results'),
            search: t('common.search'),
        }}>
            {children}
        </UiProvider>
    );
}
