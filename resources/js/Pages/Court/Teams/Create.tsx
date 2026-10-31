import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Card, CardDescription, CardTitle } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import type { TeamsCreateProps } from '@/court/types/access';
import TeamDetailsForm from './Partials/TeamDetailsForm';

/** A new team: its name, where it sits in the hierarchy, who leads it, and a description. */
export default function TeamsCreate({ teams, users }: TeamsCreateProps) {
    const { t } = useLocale();

    return (
        <AuthenticatedLayout contentWidth="form" header={<PageHeader title={t('cm.teams.page_header.create')} backHref={route('court.teams.index')} />}>
            <Head title={t('cm.teams.buttons.add_new')} />

            <Card>
                <CardTitle className="text-lg">{t('cm.teams.headings.details')}</CardTitle>
                <CardDescription>{t('cm.teams.descriptions.details')}</CardDescription>
                <div className="mt-6">
                    <TeamDetailsForm mode="create" teams={teams} users={users} />
                </div>
            </Card>
        </AuthenticatedLayout>
    );
}
