import EmptyState from '@/Components/EmptyState';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';
import { badgeClassName, buttonClassName, cx, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
import type { DelegationRow } from './types';

/** The delegations table shared by the administration page and My Team (ADR 0025). */
export default function DelegationTable({ delegations, revokeRoute, canRevoke, linkEmployees = true }: {
    delegations: DelegationRow[];
    revokeRoute: (id: string) => string;
    canRevoke: (row: DelegationRow) => boolean;
    linkEmployees?: boolean;
}) {
    const { t, locale } = useLocale();
    const { confirm } = useConfirm();
    const name = (en: string | null, am: string | null): string => (locale === 'am' ? (am ?? en ?? '') : (en ?? ''));

    async function revoke(id: string) {
        const { confirmed, reason } = await confirm({
            title: t('reportingLines.revokeTitle'),
            description: t('reportingLines.revokeDescription'),
            confirmLabel: t('reportingLines.revoke'),
            cancelLabel: t('confirmations.cancel'),
            variant: 'danger',
            requireReason: true,
            reasonLabel: t('reportingLines.revokeReasonLabel'),
        });
        if (confirmed) router.post(revokeRoute(id), { reason: reason ?? '' }, { preserveScroll: true });
    }

    const person = (id: string, label: string) => (linkEmployees
        ? <Link href={route('reporting-lines.employees.show', id)} className="text-primary hover:underline">{label}</Link>
        : <span>{label}</span>);

    if (delegations.length === 0) {
        return <EmptyState title={t('reportingLines.noDelegations')} />;
    }

    return (
        <div className="overflow-x-auto">
            <table className={cx(tableClassName, 'min-w-full text-left')}>
                <thead className={tableHeadClassName}>
                    <tr>
                        {[t('reportingLines.delegator'), t('reportingLines.delegate'), t('reportingLines.actions'), t('reportingLines.period'), t('reportingLines.reason'), t('common.status'), ''].map((h, i) => (
                            <th key={i} className={cx(tableHeaderCellClassName, 'text-left')}>{h}</th>
                        ))}
                    </tr>
                </thead>
                <tbody className={tableBodyClassName}>
                    {delegations.map((d) => (
                        <tr key={d.id} className={tableRowClassName}>
                            <td className={cx(tableCellClassName, 'font-medium')}>{person(d.delegator_employee_id, name(d.delegator_name, d.delegator_name_am))}<span className="ml-2 text-xs font-normal text-(--app-muted-foreground)">{d.delegator_number}</span></td>
                            <td className={tableCellClassName}>{person(d.delegate_employee_id, name(d.delegate_name, d.delegate_name_am))}<span className="ml-2 text-xs text-(--app-muted-foreground)">{d.delegate_number}</span></td>
                            <td className={cx(tableCellClassName, 'text-xs')}>{d.action_labels.join(', ')}</td>
                            <td className={tableCellClassName}>{d.starts_on && <LocalizedDateDisplay value={d.starts_on} />} – {d.ends_on ? <LocalizedDateDisplay value={d.ends_on} /> : t('reportingLines.openEnded')}</td>
                            <td className={cx(tableCellClassName, 'max-w-xs text-xs text-(--app-muted-foreground)')}>
                                {d.reason}
                                {d.status === 'revoked' && <div className="mt-1">{t('reportingLines.revokedBy')}: {d.revoked_by} · {d.revocation_reason}</div>}
                            </td>
                            <td className={tableCellClassName}><span className={badgeClassName(d.in_force ? 'success' : d.status === 'revoked' ? 'danger' : 'neutral')}>{d.in_force ? t('reportingLines.inForce') : d.status === 'active' ? t('reportingLines.notInForce') : d.status_label}</span></td>
                            <td className={tableCellClassName}>
                                {canRevoke(d) && d.status === 'active' && (
                                    <button type="button" onClick={() => revoke(d.id)} className={buttonClassName({ variant: 'destructiveOutline', size: 'sm' })}>{t('reportingLines.revoke')}</button>
                                )}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
