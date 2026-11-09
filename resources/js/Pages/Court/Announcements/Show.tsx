import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import RichTextViewer from '@/Components/forms/RichTextViewer';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Card, badgeClassName, buttonClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';

interface Props {
    announcement: { id: number; title: string; content: string; status: string; created_at: string | null };
}

export default function AnnouncementShow({ announcement }: Props) {
    const { t } = useLocale();

    return (
        <AuthenticatedLayout header={(
            <PageHeader title={t('cm.announcements.show_heading')} backHref={route('court.announcements.index')}
                actions={<Link href={route('court.announcements.edit', announcement.id)} className={buttonClassName({})}>{t('cm.announcements.action_edit')}</Link>} />
        )}>
            <Head title={announcement.title} />
            <Card className="max-w-3xl space-y-4">
                <div>
                    <h2 className="text-xl font-bold text-(--app-foreground)">{announcement.title}</h2>
                    <p className="mt-1 flex items-center gap-2 text-sm text-(--app-muted-foreground)">
                        <LocalizedDateDisplay value={announcement.created_at} withTime />
                        <span className={badgeClassName(announcement.status === 'active' ? 'success' : 'neutral')}>{t(`cm.announcements.status_${announcement.status}`)}</span>
                    </p>
                </div>
                <RichTextViewer html={announcement.content} />
                <Link href={route('court.announcements.index')} className={buttonClassName({ variant: 'outline' })}>{t('cm.announcements.back_to_list')}</Link>
            </Card>
        </AuthenticatedLayout>
    );
}
