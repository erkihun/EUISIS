import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Button, Card, CardDescription, CardTitle, FieldError, buttonClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import { useAccessForms } from '@/court/i18n/useAccessForms';
import type { TeamsEditProps } from '@/court/types/access';
import TeamDetailsForm from './Partials/TeamDetailsForm';

/**
 * An existing team, in two forms side by side: its details (teams.update) and its
 * members (teams.users.update). Each person belongs to one team, so the people
 * offered are the current members and those in no team; the leader always stays a member.
 */
export default function TeamsEdit({ team, teams, users }: TeamsEditProps) {
    const { t } = useLocale();
    const access = useAccessForms();
    const members = useForm<{ users: number[] }>({ users: team.user_ids });

    const toggle = (id: number, checked: boolean) => {
        members.setData('users', checked ? [...members.data.users.filter((u) => u !== id), id] : members.data.users.filter((u) => u !== id));
    };

    const saveMembers = (event: FormEvent) => {
        event.preventDefault();
        members.patch(route('court.teams.users.update', team.id), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={(
            <PageHeader title={t('cm.teams.page_header.edit')} description={t('cm.teams.descriptions.edit_notice')}
                actions={<Link href={route('court.teams.index')} className={buttonClassName({ variant: 'outline' })}>{t('cm.teams.buttons.back_to_list')}</Link>} />
        )}>
            <Head title={t('cm.teams.page_header.edit')} />

            <div className="grid gap-6 lg:grid-cols-2">
                <Card>
                    <CardTitle className="text-lg">{t('cm.teams.headings.details')}</CardTitle>
                    <div className="mt-6">
                        <TeamDetailsForm mode="edit" team={team} teams={teams} users={users} />
                    </div>
                </Card>

                <Card>
                    <CardTitle className="text-lg">{t('cm.teams.headings.members')}</CardTitle>
                    <CardDescription>{t('cm.teams.descriptions.members_edit_notice')}</CardDescription>
                    <p className="mt-1 text-xs text-(--app-muted-foreground)">{t('cm.teams.descriptions.members_leader_notice')}</p>
                    <form onSubmit={saveMembers} className="mt-6 space-y-4">
                        <div className="max-h-60 overflow-y-auto rounded-control border border-(--app-border) p-2 text-sm">
                            {users.length === 0
                                ? <p className="px-2 py-1 text-(--app-muted-foreground)">{access('noUsersAvailable')}</p>
                                : (
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        {users.map((user) => (
                                            <label key={user.id} className="flex items-center gap-2 rounded-control px-2 py-1 text-(--app-foreground) hover:bg-(--app-surface-muted)">
                                                <input type="checkbox" className="h-4 w-4 rounded border-(--app-border) text-primary focus:ring-primary"
                                                    checked={members.data.users.includes(user.id)} onChange={(event) => toggle(user.id, event.target.checked)} />
                                                <span className="min-w-0 truncate">{user.name} ({user.email})</span>
                                            </label>
                                        ))}
                                    </div>
                                )}
                        </div>
                        <p className="text-xs text-(--app-muted-foreground)">{access('selectedCount', { count: members.data.users.length })}</p>
                        <FieldError>{members.errors.users}</FieldError>
                        <Button type="submit" variant="primary" loading={members.processing} className="w-full">{t('cm.teams.buttons.save_members')}</Button>
                    </form>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
