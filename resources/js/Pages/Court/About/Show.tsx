import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import RichTextViewer from '@/Components/forms/RichTextViewer';
import { Card, badgeClassName, buttonClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';

interface Props {
    page: { id: number; title: string; slug: string; body: string; is_published: boolean };
}

export default function AboutShow({ page }: Props) {
    const { t } = useLocale();

    return (
        <AuthenticatedLayout header={(
            <PageHeader title={t('cm.about.title')} backHref={route('court.about.index')} actions={(
                <>
                    <Link href={route('court.about.index')} className={buttonClassName({ variant: 'outline' })}>{t('cm.about.form_cancel')}</Link>
                    <Link href={route('court.about.edit', page.id)} className={buttonClassName({})}>{t('cm.about.action_edit')}</Link>
                </>
            )} />
        )}>
            <Head title={page.title} />
            <Card className="max-w-3xl space-y-4">
                <div>
                    <h2 className="text-xl font-bold text-(--app-foreground)">{page.title}</h2>
                    <p className="mt-1 flex items-center gap-2 text-sm text-(--app-muted-foreground)">
                        <span className="font-mono">/{page.slug}</span>
                        <span className={badgeClassName(page.is_published ? 'success' : 'neutral')}>{page.is_published ? t('cm.about.status_published') : t('cm.about.status_draft')}</span>
                    </p>
                </div>
                <RichTextViewer html={page.body} />
            </Card>
        </AuthenticatedLayout>
    );
}
