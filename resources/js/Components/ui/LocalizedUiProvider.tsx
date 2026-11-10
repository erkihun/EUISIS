import { UiProvider } from '@euisis/ui';
import { router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useActionIconRules } from '@/Components/ui/actionIcons';
import { useEffect, type ReactNode } from 'react';

/**
 * After a submit comes back with validation errors, move focus to the first
 * field marked invalid (or, failing that, the first inline error), so keyboard
 * and screen-reader users land on the problem instead of staying on the Save
 * button. One listener for every form: the kit marks fields `aria-invalid`
 * and errors `role="alert"`, so no page needs its own code.
 */
function useFocusFirstError(): void {
    useEffect(() => router.on('error', () => {
        // Let React commit the errors before looking for them.
        window.setTimeout(() => {
            const active = document.activeElement;
            if (active instanceof HTMLElement && active.getAttribute('aria-invalid') === 'true') return;
            // A field marked invalid; otherwise the control that shares a wrapper with the first inline error
            // (FormField renders label, control and error in one block); otherwise the error text itself.
            const marked = document.querySelector<HTMLElement>('[aria-invalid="true"]');
            const alert = marked ? null : document.querySelector<HTMLElement>('[role="alert"]');
            const sibling = alert?.parentElement?.querySelector<HTMLElement>('input:not([type="hidden"]), select, textarea, [role="combobox"], [contenteditable="true"]') ?? null;
            const field = marked ?? sibling;
            const target = field ?? alert;
            if (!target) return;
            if (!field && !target.hasAttribute('tabindex')) target.setAttribute('tabindex', '-1');
            const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches
                || document.documentElement.dataset.animations === 'false';
            target.scrollIntoView({ block: 'center', behavior: reduceMotion ? 'auto' : 'smooth' });
            target.focus({ preventScroll: true });
        }, 50);
    }), []);
}

export default function LocalizedUiProvider({ children }: { children: ReactNode }) {
    const { t } = useLocale();
    useFocusFirstError();
    const actionIcons = useActionIconRules(t);
    return (
        <UiProvider actionIcons={actionIcons} messages={{
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
            columns: t('common.tableColumns'),
            showAllColumns: t('common.tableShowAllColumns'),
            density: t('common.tableDensity'),
            compact: t('common.tableDensityCompact'),
            comfortable: t('common.tableDensityComfortable'),
            stickyHeader: t('common.tableStickyHeader'),
            resetTable: t('common.tableReset'),
            sortedOnPage: t('common.tableSortedOnPage'),
            sortBy: t('common.tableSortBy'),
            tableTools: t('common.tableTools'),
        }}>
            {children}
        </UiProvider>
    );
}
