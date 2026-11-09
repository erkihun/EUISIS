import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Card, CardDescription, CardTitle, buttonClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import { formatNumber } from '@/utils/displayFormat';
import { fill } from '@/court/i18n/useAccessForms';
import type { TeamsShowProps } from '@/court/types/access';

/** One team: where it sits, who leads it, what it is for, and its members. */
export default function TeamsShow({ team }: TeamsShowProps) {
    const { t } = useLocale();

    return (
        <AuthenticatedLayout header={(
            <PageHeader title={t('cm.teams.page_header.show')}
                actions={<Link href={route('court.teams.index')} className={buttonClassName({ variant: 'outline' })}>{t('cm.teams.buttons.back_to_list')}</Link>} />
        )}>
            <Head title={t('cm.teams.headings.overview')} />

            <div className="space-y-4">
                <Card>
                    <CardTitle className="text-lg">{team.name}</CardTitle>
                    <CardDescription>{team.parent_name ? fill(t('cm.teams.labels.reports_to'), { team: team.parent_name }) : t('cm.teams.labels.top_level')}</CardDescription>
                    <p className="mt-3 text-sm text-(--app-muted-foreground)">
                        {t('cm.teams.labels.leader_prefix')}{' '}
                        <span className="font-semibold text-(--app-foreground)">{team.leader_name ?? t('cm.teams.meta.unassigned')}</span>
                    </p>
                    <p className="mt-4 text-sm text-(--app-foreground)">{team.description ?? t('cm.teams.descriptions.description_missing')}</p>
                </Card>

                <Card>
                    <div className="mb-4 flex items-start justify-between gap-3">
                        <div>
                            <h3 className="text-sm font-semibold uppercase tracking-wide text-(--app-foreground)">{t('cm.teams.headings.members')}</h3>
                            <p className="text-xs text-(--app-muted-foreground)">{formatNumber(team.members.length)} {t('cm.teams.labels.assigned')}</p>
                        </div>
                        <Link href={route('court.teams.edit', team.id)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('cm.teams.buttons.manage_members')}</Link>
                    </div>
                    {team.members.length === 0
                        ? <p className="text-sm text-(--app-muted-foreground)">{t('cm.teams.descriptions.members_empty')}</p>
                        : (
                            <ul className="divide-y divide-(--app-border) text-sm">
                                {team.members.map((member) => (
                                    <li key={member.id} className="flex items-center justify-between py-2">
                                        <div>
                                            <div className="font-semibold text-(--app-foreground)">{member.name}</div>
                                            <div className="text-xs text-(--app-muted-foreground)">{member.email}</div>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
