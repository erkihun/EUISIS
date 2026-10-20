import PageHeader from '@/Components/PageHeader';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useLocale } from '@/hooks/useLocale';
import { Head } from '@inertiajs/react';
import AnnouncementForm, { emptyRow, type Organization, type PositionOption } from './Form';

type Props = { organizations: Organization[]; positions: PositionOption[]; positionsTruncated?: boolean; settingsDocuments?: string[] };

export default function TransferAnnouncementCreate({ organizations, positions, positionsTruncated = false, settingsDocuments = [] }: Props) {
    const { t } = useLocale();

    return (
        <AuthenticatedLayout
            contentWidth="form"
            header={<PageHeader backHref={route('transfer-announcements.index')} title={t('transfers.createAnnouncement')} description={t('transfers.createAnnouncementHint')} />}
        >
            <Head title={t('transfers.createAnnouncement')} />
            <AnnouncementForm
                initial={{ positions: [emptyRow()], eligibility_rules: [], required_documents: [], opening_date: '', closing_date: '' }}
                organizations={organizations}
                positions={positions}
                positionsTruncated={positionsTruncated}
                settingsDocuments={settingsDocuments}
                submit={{ method: 'post', url: route('transfer-announcements.store') }}
                cancelHref={route('transfer-announcements.index')}
            />
        </AuthenticatedLayout>
    );
}
