import PageHeader from '@/Components/PageHeader';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useLocale } from '@/hooks/useLocale';
import { Head } from '@inertiajs/react';
import AnnouncementForm, { emptyRow, type EligibilityRule, type Organization, type PositionOption, type PositionRow } from './Form';

type AnnouncementPosition = {
    organization_id: string;
    position_id: string;
    grade_level: string | null;
    salary_min: string | null;
    salary_max: string | null;
    vacancy_count: number;
};

type Announcement = {
    id: string;
    opening_date: string;
    closing_date: string;
    eligibility_rules: unknown[] | null;
    required_documents: string[] | null;
    positions: AnnouncementPosition[];
};

type Props = { announcement: Announcement; organizations: Organization[]; positions: PositionOption[]; positionsTruncated?: boolean; settingsDocuments?: string[] };

/** Rules saved by older versions may be malformed; only well-formed ones are edited. */
function supportedRules(rules: unknown[] | null): EligibilityRule[] {
    return (rules ?? []).filter((rule): rule is EligibilityRule => typeof rule === 'object' && rule !== null && 'type' in rule && 'operator' in rule && 'value' in rule);
}

const toRow = (position: AnnouncementPosition): PositionRow => ({
    organization_id: position.organization_id ?? '',
    position_id: position.position_id ?? '',
    grade_level: position.grade_level ?? '',
    salary_min: position.salary_min ?? '',
    salary_max: position.salary_max ?? '',
    vacancy_count: position.vacancy_count ?? 1,
});

export default function TransferAnnouncementEdit({ announcement, organizations, positions, positionsTruncated = false, settingsDocuments = [] }: Props) {
    const { t } = useLocale();

    return (
        <AuthenticatedLayout
            contentWidth="form"
            header={<PageHeader backHref={route('transfer-announcements.show', announcement.id)} title={t('transfers.editAnnouncement')} />}
        >
            <Head title={t('transfers.editAnnouncement')} />
            <AnnouncementForm
                initial={{
                    positions: announcement.positions?.length ? announcement.positions.map(toRow) : [emptyRow()],
                    eligibility_rules: supportedRules(announcement.eligibility_rules),
                    required_documents: announcement.required_documents ?? [],
                    opening_date: announcement.opening_date ?? '',
                    closing_date: announcement.closing_date ?? '',
                }}
                organizations={organizations}
                positions={positions}
                positionsTruncated={positionsTruncated}
                settingsDocuments={settingsDocuments}
                submit={{ method: 'patch', url: route('transfer-announcements.update', announcement.id) }}
                cancelHref={route('transfer-announcements.show', announcement.id)}
            />
        </AuthenticatedLayout>
    );
}
