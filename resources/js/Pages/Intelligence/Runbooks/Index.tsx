import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { useSituationLabels } from '@/Components/SituationRoom/useSituationLabels';
import { Alert, Card, badgeClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import type { PageProps } from '@/types';
import type { SrLabels } from '@/types/situationRoom';
import type { Runbook } from '@/types/intelligenceSystem';

type Props = { labels: SrLabels; runbooks: Runbook[]; execution_enabled: boolean };

/** Read-only runbooks (brief §49). No step runs from here. */
export default function RunbooksIndex({ runbooks, execution_enabled }: PageProps<Props>) {
    const { t } = useLocale();
    const { sr } = useSituationLabels();
    const title = t('intelligence.areas.runbooks.title');

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={t('intelligence.areas.runbooks.description')} backHref={route('intelligence.index')} />}>
            <Head title={`${title} · ${t('intelligence.title')}`} />

            <div className="space-y-5">
                {!execution_enabled && <Alert tone="info">{t('intelligence.runbooks.readOnly')}</Alert>}
                <ul className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                    {runbooks.map((book) => (
                        <li key={book.key}>
                            <Link href={book.href ?? '#'} className="group block h-full focus-visible:outline-hidden">
                                <Card className="flex h-full flex-col gap-2 transition-colors group-hover:border-primary group-focus-visible:border-primary">
                                    <div className="flex items-start justify-between gap-2">
                                        <h2 className="font-semibold text-(--app-foreground)">{sr(book.title)}</h2>
                                        {(book.active_incidents ?? 0) > 0 && <span className={badgeClassName('danger')}>{book.active_incidents} {t('intelligence.runbooks.activeIncidents')}</span>}
                                    </div>
                                    <p className="text-sm text-(--app-muted-foreground)">{sr(book.summary)}</p>
                                    <div className="mt-auto flex flex-wrap gap-1.5 pt-1">
                                        {book.components.map((c) => <span key={c} className={badgeClassName('neutral')}>{sr(`intelligence.components.${c}`)}</span>)}
                                        <span className="ml-auto text-xs text-(--app-muted-foreground)">{book.steps.length} {t('intelligence.runbooks.steps')}</span>
                                    </div>
                                </Card>
                            </Link>
                        </li>
                    ))}
                </ul>
            </div>
        </AuthenticatedLayout>
    );
}
