import { Head } from '@inertiajs/react';
import type { JSX } from 'react';
import { StatusBadge } from '@euisis/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { InfoIcon } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';

/**
 * Court Cases — planned standalone module (docs/court-cases.md).
 *
 * States that the module is planned and nothing more: no counts, cases or
 * sample data until the module is designed.
 */
export default function CourtCasesIndex(): JSX.Element {
    const { t } = useLocale();

    return (
        <AuthenticatedLayout>
            <Head title={t('courtCases.title')} />
            <div className="min-w-0 space-y-4">
                <PageHeader
                    title={t('courtCases.title')}
                    description={t('courtCases.description')}
                    actions={<StatusBadge tone="neutral">{t('courtCases.planned')}</StatusBadge>}
                />
                <p className="flex items-start gap-2 rounded-[var(--radius-card)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)] px-4 py-3 text-sm text-[color:var(--app-muted-foreground)]">
                    <InfoIcon aria-hidden="true" className="mt-0.5 h-4 w-4 shrink-0" />
                    <span>{t('courtCases.notice')}</span>
                </p>
            </div>
        </AuthenticatedLayout>
    );
}
