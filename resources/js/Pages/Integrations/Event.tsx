import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { useLocale } from '@/hooks/useLocale';
import { Card } from '@euisis/ui';

interface Props {
    event: Record<string, unknown> & { event_type: string; event_id: string; status: string; feed_position: number | null; error: string | null };
}

/** One event's minimised payload, as recorded. Opening this page is audited. */
export default function IntegrationsEvent({ event }: Props) {
    const { t } = useLocale();

    return (
        <AuthenticatedLayout header={<PageHeader title={t('integrations.payloadTitle')} description={t('integrations.payloadHint')} backHref={route('integrations.index')} />}>
            <Head title={t('integrations.payloadTitle')} />

            <Card>
                <dl className="mb-4 grid gap-4 sm:grid-cols-3">
                    <div>
                        <dt className="text-xs text-(--app-muted-foreground)">{t('integrations.event')}</dt>
                        <dd className="mt-1 font-mono text-sm text-(--app-foreground)">{event.event_type}</dd>
                    </div>
                    <div>
                        <dt className="text-xs text-(--app-muted-foreground)">{t('integrations.eventStatus')}</dt>
                        <dd className="mt-1 text-sm text-(--app-foreground)">{event.status}</dd>
                    </div>
                    <div>
                        <dt className="text-xs text-(--app-muted-foreground)">{t('integrations.feedPositionLabel')}</dt>
                        <dd className="mt-1 text-sm tabular-nums text-(--app-foreground)">{event.feed_position ?? '—'}</dd>
                    </div>
                    {event.error && (
                        <div className="sm:col-span-3">
                            <dt className="text-xs text-(--app-muted-foreground)">{t('integrations.eventError')}</dt>
                            <dd className="mt-1 font-mono text-xs text-red-600">{event.error}</dd>
                        </div>
                    )}
                </dl>
                <pre className="max-h-[32rem] overflow-auto rounded-control bg-(--app-surface-muted) p-4 font-mono text-xs text-(--app-foreground)">{JSON.stringify(event, null, 2)}</pre>
            </Card>
        </AuthenticatedLayout>
    );
}
