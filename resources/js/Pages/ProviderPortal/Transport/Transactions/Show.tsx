import { Link } from '@inertiajs/react';
import { Card, StatusBadge, buttonClassName } from '@euisis/ui';
import TransportProviderLayout from '@/Layouts/TransportProviderLayout';
import { useTransportLabels } from '@/Components/transport/labels';
import type { TransportTransaction } from '@/types/transport';

export default function Show({ transaction }: { transaction: TransportTransaction }) {
    const { t, name, label, tone, locale } = useTransportLabels();
    const rows: [string, string][] = [
        [t('transport.scannedAt'), transaction.scanned_at ? new Date(transaction.scanned_at).toLocaleString(locale === 'am' ? 'am-ET' : 'en-GB') : '—'],
        [t('transport.employee'), transaction.employee ? `${transaction.employee.full_name} (${transaction.employee.employee_number})` : '—'],
        [t('transport.organization'), (locale === 'am' && transaction.employee?.organization_am) || transaction.employee?.organization || '—'],
        [t('transport.route'), transaction.route ? `${transaction.route.route_code} — ${name(transaction.route)}` : '—'],
        [t('transport.trip'), transaction.trip?.trip_number ?? '—'],
        [t('transport.result'), label('reasons', transaction.result_code)],
    ];

    return (
        <TransportProviderLayout title={t('transport.transactionsAdmin')}>
            <div className="space-y-4">
                <Link href={route('provider.portal.transport.transactions.index')} className={buttonClassName({ variant: 'ghost', size: 'sm' })}>← {t('transport.back')}</Link>
                <Card>
                    <div className="mb-4"><StatusBadge tone={tone(transaction.status)}>{label('statuses', transaction.status)}</StatusBadge></div>
                    <dl className="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                        {rows.map(([term, value]) => (
                            <div key={term} className="min-w-0">
                                <dt className="text-[color:var(--app-muted-foreground)]">{term}</dt>
                                <dd className="mt-0.5 break-words">{value}</dd>
                            </div>
                        ))}
                    </dl>
                </Card>
            </div>
        </TransportProviderLayout>
    );
}
