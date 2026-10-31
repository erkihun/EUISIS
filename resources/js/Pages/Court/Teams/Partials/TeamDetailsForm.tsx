import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button, FormField, Input, Select, Textarea } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import type { TeamFormTeam, TeamOption, TeamUserOption } from '@/court/types/access';

interface TeamDetailsData {
    name: string;
    parent_id: string;
    team_leader_id: string;
    description: string;
}

type Props =
    | { mode: 'create'; teams: TeamOption[]; users: TeamUserOption[]; team?: undefined }
    | { mode: 'edit'; teams: TeamOption[]; users: TeamUserOption[]; team: TeamFormTeam };

/**
 * A team's details — name, parent, leader, description — posted to teams.store or
 * teams.update. The leader is picked among the people free to lead (in no other team).
 */
export default function TeamDetailsForm(props: Props) {
    const { t } = useLocale();
    const { mode, teams, users } = props;
    const team = mode === 'edit' ? props.team : null;

    const form = useForm<TeamDetailsData>({
        name: team?.name ?? '',
        parent_id: team?.parent_id ? String(team.parent_id) : '',
        team_leader_id: team?.team_leader_id ? String(team.team_leader_id) : '',
        description: team?.description ?? '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            name: data.name,
            parent_id: data.parent_id === '' ? null : Number(data.parent_id),
            team_leader_id: data.team_leader_id === '' ? null : Number(data.team_leader_id),
            description: data.description === '' ? null : data.description,
        }));
        if (mode === 'create') form.post(route('court.teams.store'));
        else form.patch(route('court.teams.update', team!.id));
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <FormField id="team-name" label={t('cm.teams.labels.name')} required error={form.errors.name}>
                {({ id, describedBy, invalid }) => (
                    <Input id={id} value={form.data.name} required aria-describedby={describedBy} aria-invalid={invalid || undefined} onChange={(event) => form.setData('name', event.target.value)} />
                )}
            </FormField>

            <FormField id="team-parent_id" label={t('cm.teams.labels.parent')} error={form.errors.parent_id}>
                {({ id, describedBy, invalid }) => (
                    <Select id={id} value={form.data.parent_id} aria-describedby={describedBy} aria-invalid={invalid || undefined} onChange={(event) => form.setData('parent_id', event.target.value)}>
                        <option value="">{t('cm.teams.labels.none')}</option>
                        {teams.map((option) => <option key={option.id} value={option.id}>{option.name}</option>)}
                    </Select>
                )}
            </FormField>

            {mode === 'edit' && (
                <FormField id="team-description" label={t('cm.teams.labels.description')} error={form.errors.description}>
                    {({ id, describedBy, invalid }) => (
                        <Textarea id={id} rows={4} value={form.data.description} aria-describedby={describedBy} aria-invalid={invalid || undefined} onChange={(event) => form.setData('description', event.target.value)} />
                    )}
                </FormField>
            )}

            <FormField id="team-team_leader_id" label={t('cm.teams.labels.team_leader')} error={form.errors.team_leader_id} description={t('cm.teams.descriptions.leader_optional')}>
                {({ id, describedBy, invalid }) => (
                    <Select id={id} value={form.data.team_leader_id} aria-describedby={describedBy} aria-invalid={invalid || undefined} onChange={(event) => form.setData('team_leader_id', event.target.value)}>
                        <option value="">{t('cm.teams.labels.none')}</option>
                        {users.map((user) => <option key={user.id} value={user.id}>{user.name} ({user.email})</option>)}
                    </Select>
                )}
            </FormField>

            {mode === 'create' && (
                <FormField id="team-description" label={t('cm.teams.labels.description')} error={form.errors.description}>
                    {({ id, describedBy, invalid }) => (
                        <Textarea id={id} rows={4} value={form.data.description} aria-describedby={describedBy} aria-invalid={invalid || undefined} onChange={(event) => form.setData('description', event.target.value)} />
                    )}
                </FormField>
            )}

            <Button type="submit" variant="primary" loading={form.processing} className="w-full">
                {mode === 'create' ? t('cm.teams.buttons.create') : t('cm.teams.buttons.save_changes')}
            </Button>
        </form>
    );
}
