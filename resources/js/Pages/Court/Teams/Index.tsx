import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import {
    buttonClassName, cx,
    tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName,
} from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';
import { formatNumber } from '@/utils/displayFormat';
import type { TeamRow, TeamsIndexProps } from '@/court/types/access';

/** The teams: each with its parent, members, leader and description; a team with members cannot be deleted. */
export default function TeamsIndex({ teams }: TeamsIndexProps) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const [busy, setBusy] = useState<number | null>(null);
    const title = t('cm.teams.page_header.index');

    const remove = async (team: TeamRow) => {
        const { confirmed } = await confirm({
            title: t('cm.teams.buttons.delete'),
            description: t('cm.teams.confirmations.delete'),
            confirmLabel: t('cm.teams.buttons.delete'),
            cancelLabel: t('common.cancel'),
            variant: 'danger',
        });
        if (confirmed) router.delete(route('court.teams.destroy', team.id), { preserveScroll: true, onStart: () => setBusy(team.id), onFinish: () => setBusy(null) });
    };

    return (
        <AuthenticatedLayout header={(
            <PageHeader title={title} description={t('cm.teams.descriptions.current')}
                actions={<Link href={route('court.teams.create')} className={buttonClassName({})}>{t('cm.teams.buttons.add_new')}</Link>} />
        )}>
            <Head title={t('cm.teams.title')} />

            <div className={tableContainerClassName}>
                <div className="overflow-x-auto">
                    <table className={cx(tableClassName, 'min-w-full text-left')}>
                        <thead className={tableHeadClassName}>
                            <tr>
                                {['team', 'members', 'leader', 'description', 'actions'].map((key) => (
                                    <th key={key} className={cx(tableHeaderCellClassName, 'whitespace-nowrap text-left')}>{t(`cm.teams.labels.${key}`)}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className={tableBodyClassName}>
                            {teams.map((team) => (
                                <tr key={team.id} className={cx(tableRowClassName, busy === team.id && 'opacity-60')}>
                                    <td className={cx(tableCellClassName, 'align-top')}>
                                        <div className="font-semibold text-(--app-foreground)">{team.name}</div>
                                        <div className="text-xs text-(--app-muted-foreground)">
                                            {team.parent_name ? t('cm.teams.labels.reports_to').replace(':team', team.parent_name) : t('cm.teams.labels.top_level')}
                                        </div>
                                    </td>
                                    <td className={cx(tableCellClassName, 'align-top')}>
                                        <div className="font-semibold tabular-nums text-(--app-foreground)">{formatNumber(team.members_count)}</div>
                                        <div className="text-xs text-(--app-muted-foreground)">{t('cm.teams.labels.member_count')}</div>
                                    </td>
                                    <td className={cx(tableCellClassName, 'align-top')}>
                                        <div className="text-(--app-foreground)">{team.leader_name ?? t('cm.teams.meta.unassigned')}</div>
                                        <div className="text-xs text-(--app-muted-foreground)">{team.leader_email ?? t('cm.teams.meta.unknown_email')}</div>
                                    </td>
                                    <td className={cx(tableCellClassName, 'max-w-md align-top text-(--app-muted-foreground)')}>{team.description || t('cm.teams.descriptions.description_missing')}</td>
                                    <td className={cx(tableCellClassName, 'align-top')}>
                                        <div className="flex flex-wrap gap-1.5">
                                            <Link href={route('court.teams.show', team.id)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('cm.teams.buttons.view')}</Link>
                                            <Link href={route('court.teams.edit', team.id)} className={buttonClassName({ size: 'sm' })}>{t('cm.teams.buttons.edit')}</Link>
                                            <button type="button" disabled={team.members_count > 0 || busy === team.id} onClick={() => void remove(team)}
                                                className={buttonClassName({ variant: 'destructiveOutline', size: 'sm' })}>
                                                {t('cm.teams.buttons.delete')}
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {teams.length === 0 && <EmptyState title={t('cm.teams.empty')} />}
            </div>
        </AuthenticatedLayout>
    );
}
