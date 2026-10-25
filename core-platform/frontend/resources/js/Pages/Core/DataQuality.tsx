import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Card } from '@euisis/ui';
import { useLocale } from '@/hooks/useLocale';

type Kind = 'position' | 'employee' | 'card';

interface Check {
    key: string;
    count: number;
    kind: Kind;
    records: { id: string; href: string }[];
}

interface Props {
    checks: Check[];
    sample: number;
}

/** Core › Data Quality: the Golden Path integrity checks with a link to each finding. Read-only. */
export default function CoreDataQuality({ checks, sample }: Props) {
    const { t } = useLocale();

    return (
        <AuthenticatedLayout header={<PageHeader title={t('core.dataQuality.title')} description={t('core.dataQuality.description')} />}>
            <Head title={t('core.dataQuality.title')} />

            <div className="grid gap-4 lg:grid-cols-2">
                {checks.map((check) => (
                    <Card key={check.key} className="flex flex-col gap-3">
                        <div className="flex items-start justify-between gap-3">
                            <h2 className="text-sm font-semibold text-(--app-foreground)">{t(`core.dataQuality.checks.${check.key}`)}</h2>
                            <span
                                className={`shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold tabular-nums ${check.count === 0
                                    ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'
                                    : 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'}`}
                            >
                                {check.count === 0 ? t('core.dataQuality.clean') : t('core.dataQuality.findings').replace(':count', String(check.count))}
                            </span>
                        </div>

                        {check.records.length > 0 && (
                            <>
                                <ul className="divide-y divide-(--app-border) text-sm">
                                    {check.records.map((record) => (
                                        <li key={record.id} className="flex items-center justify-between gap-3 py-1.5">
                                            <span className="min-w-0 truncate">
                                                <span className="text-(--app-muted-foreground)">{t(`core.dataQuality.kinds.${check.kind}`)} · </span>
                                                <span className="font-mono text-xs text-(--app-foreground)">{record.id}</span>
                                            </span>
                                            <Link href={record.href} className="shrink-0 text-xs font-medium text-primary hover:underline">
                                                {t('core.dataQuality.open')}
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                                {check.count > check.records.length && (
                                    <p className="text-xs text-(--app-muted-foreground)">{t('core.dataQuality.showing').replace(':count', String(sample))}</p>
                                )}
                            </>
                        )}
                    </Card>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}
