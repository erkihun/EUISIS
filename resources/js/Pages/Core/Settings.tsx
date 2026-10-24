import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { SettingsIcon } from '@/Components/NavIcons';
import { buttonClassName } from '@/Components/ui';
import { Card } from '@euisis/ui';
import { useLocale } from '@/hooks/useLocale';

interface Props {
    sections: { key: string; href: string }[];
}

/** Core › Settings: the settings Core owns, each opening its existing screen. */
export default function CoreSettings({ sections }: Props) {
    const { t } = useLocale();

    return (
        <AuthenticatedLayout header={<PageHeader title={t('core.settings.title')} description={t('core.settings.description')} />}>
            <Head title={t('core.settings.title')} />

            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                {sections.map((section) => (
                    <Card key={section.key} className="flex flex-col gap-3">
                        <div className="flex items-start gap-3">
                            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-primary ring-1 ring-(--app-border)">
                                <SettingsIcon className="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div className="min-w-0">
                                <h2 className="text-base font-semibold text-(--app-foreground)">{t(`core.settings.sections.${section.key}.title`)}</h2>
                                <p className="text-sm text-(--app-muted-foreground)">{t(`core.settings.sections.${section.key}.description`)}</p>
                            </div>
                        </div>
                        <div className="mt-auto">
                            <Link href={section.href} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('core.settings.open')}</Link>
                        </div>
                    </Card>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}
