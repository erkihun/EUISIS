import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Alert, Button, Card, FormField, Select, buttonClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import type { AssignPageProps } from '@/court/types/caseDetail';

/**
 * The case assignment form: a team leader routes the case to one of their
 * members; an admin hands it to a team leader. The current assignee can be
 * removed with the unassign box. Posts to cases.assign.update (PATCH).
 */
export default function CaseAssign({ case: kase, assignmentMode, canAssignLeaders, leaderTeam, teams }: AssignPageProps) {
    const { t } = useLocale();
    const title = t('cm.cases.assign.assign_case');
    const form = useForm<{ assigned_user_id: string; unassign: boolean }>({ assigned_user_id: kase.assigned_user_id ? String(kase.assigned_user_id) : '', unassign: false });
    const leaderMode = assignmentMode === 'leader' && leaderTeam !== null;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ assigned_user_id: data.assigned_user_id === '' ? null : Number(data.assigned_user_id), unassign: data.unassign ? 1 : 0 }));
        form.patch(route('court.cases.assign.update', kase.id));
    };

    const statusLabel = (() => {
        const translated = t(`cm.cases.status.${kase.status}`);
        return translated.startsWith('cm.') ? kase.status : translated;
    })();

    return (
        <AuthenticatedLayout header={<PageHeader title={title} backHref={route('court.cases.show', kase.id)} />}>
            <Head title={`${title} · ${kase.case_number}`} />

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <Card className="lg:col-span-1">
                    <h3 className="mb-3 text-sm font-medium text-(--app-muted-foreground)">{t('cm.cases.assign.summary_title')}</h3>
                    <dl className="space-y-2 text-sm">
                        <div className="flex flex-wrap gap-x-2"><dt className="text-(--app-muted-foreground)">{t('cm.cases.assign.case_number')}</dt><dd className="font-mono text-(--app-foreground)">{kase.case_number}</dd></div>
                        <div className="flex flex-wrap gap-x-2"><dt className="text-(--app-muted-foreground)">{t('cm.cases.assign.title')}</dt><dd className="text-(--app-foreground)">{kase.title ?? '—'}</dd></div>
                        <div className="flex flex-wrap gap-x-2"><dt className="text-(--app-muted-foreground)">{t('cm.cases.assign.status')}</dt><dd className="text-(--app-foreground)">{statusLabel}</dd></div>
                        <div className="flex flex-wrap gap-x-2">
                            <dt className="text-(--app-muted-foreground)">{t('cm.cases.assign.current_assignee')}</dt>
                            <dd className="text-(--app-foreground)">
                                {kase.assignee_name
                                    ? <>{kase.assignee_name} {kase.assignee_email && <span className="text-xs text-(--app-muted-foreground)">({kase.assignee_email})</span>}</>
                                    : <span className="text-(--app-muted-foreground)">{t('cm.cases.assign.unassigned')}</span>}
                            </dd>
                        </div>
                        {kase.assigned_at && (
                            <div className="flex flex-wrap gap-x-2"><dt className="text-(--app-muted-foreground)">{t('cm.cases.assign.assigned_at')}</dt><dd className="text-(--app-foreground)"><LocalizedDateDisplay value={kase.assigned_at} withTime /></dd></div>
                        )}
                    </dl>
                    <div className="mt-4">
                        <Link href={route('court.cases.index')} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('cm.cases.assign.back')}</Link>
                    </div>
                </Card>

                <Card className="lg:col-span-2">
                    <form onSubmit={submit} className="space-y-4">
                        {!canAssignLeaders && <Alert tone="warning">{t('cm.cases.assign.permission_warning')}</Alert>}
                        {leaderMode && leaderTeam
                            ? <Alert tone="info">{t('cm.cases.assign.leader_context').replace(':team', leaderTeam.name)}</Alert>
                            : <Alert tone="neutral">{t('cm.cases.assign.admin_context')}</Alert>}

                        <FormField id="assigned_user_id" label={t('cm.cases.assign.assign_to')} error={form.errors.assigned_user_id}>
                            {({ id, describedBy, invalid }) => (
                                <Select id={id} value={form.data.assigned_user_id} onChange={(event) => form.setData('assigned_user_id', event.target.value)} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full">
                                    <option value="">{t('cm.cases.assign.select_user')}</option>
                                    {leaderMode && leaderTeam ? (
                                        <optgroup label={t('cm.cases.assign.team_members')}>
                                            {leaderTeam.members.map((member) => <option key={member.id} value={member.id}>{member.name} - {member.email}</option>)}
                                        </optgroup>
                                    ) : teams.map((team) => (
                                        <optgroup key={team.id} label={t('cm.cases.assign.team_leader_label').replace(':team', team.name)}>
                                            <option value={team.leader.id}>{team.leader.name} - {team.leader.email}</option>
                                        </optgroup>
                                    ))}
                                </Select>
                            )}
                        </FormField>

                        {kase.assigned_user_id !== null && (
                            <label className="flex items-center gap-2 text-sm text-(--app-foreground)">
                                <input type="checkbox" className="rounded border-(--app-border) text-primary focus:ring-primary" checked={form.data.unassign} onChange={(event) => form.setData('unassign', event.target.checked)} />
                                {t('cm.cases.assign.unassign')}
                            </label>
                        )}

                        <div className="flex items-center gap-2 pt-2">
                            <Button type="submit" variant="primary" loading={form.processing}>{t('cm.cases.general.save')}</Button>
                            <Link href={route('court.cases.index')} className={buttonClassName({ variant: 'outline' })}>{t('cm.cases.general.cancel')}</Link>
                        </div>
                    </form>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
