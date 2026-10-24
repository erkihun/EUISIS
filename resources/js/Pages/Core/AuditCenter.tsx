import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { EyeScanIcon } from '@/Components/NavIcons';
import { buttonClassName } from '@/Components/ui';
import { Card } from '@euisis/ui';
import { useLocale } from '@/hooks/useLocale';
import { formatNumber } from '@/utils/displayFormat';

interface AuditView {
    key: string;
    count: number;
    href: string;
}

interface Props {
    views: AuditView[];
    windowDays: number;
}

/** Core › Audit Center: ready-made views of the audit trail, counted inside the viewer's scope. */
export default function CoreAuditCenter({ views, windowDays }: Props) {
    const { t } = useLocale();

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('core.audit.title')}
                    description={t('core.audit.description').replace(':days', String(windowDays))}
                    actions={<Link href={route('audit-logs.index')} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('core.audit.openLog')}</Link>}
                />
            }
        >
            <Head title={t('core.audit.title')} />

            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                {views.map((view) => (
                    <Card key={view.key} className="flex flex-col gap-3">
                        <div className="flex items-start gap-3">
                            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-primary ring-1 ring-(--app-border)">
                                <EyeScanIcon className="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div className="min-w-0">
                                <h2 className="text-base font-semibold text-(--app-foreground)">{t(`core.audit.views.${view.key}.title`)}</h2>
                                <p className="text-sm text-(--app-muted-foreground)">{t(`core.audit.views.${view.key}.description`)}</p>
                            </div>
                        </div>
                        <div className="mt-auto flex items-center justify-between gap-2">
                            <span className="text-2xl font-semibold tabular-nums text-(--app-foreground)">{formatNumber(view.count)}</span>
                            <Link href={view.href} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('core.audit.open')}</Link>
                        </div>
                    </Card>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}
