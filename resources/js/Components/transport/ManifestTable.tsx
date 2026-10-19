import { EmptyState, TableCard, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@euisis/ui';
import type { ReactNode } from 'react';
import { useTransportLabels } from './labels';
import { ActionDialogButton, Kpi, Pill } from './TransportShared';
import type { Manifest, ManifestRow } from '@/types/transport';

/**
 * Who boarded a trip. The staff tier shows the organization; the provider
 * tier shows name and number only. Refused and voided scans stay listed as
 * evidence. Voiding needs its own permission and a reason.
 */
export default function ManifestTable({ manifest, staffTier, voidUrl, actions }: {
    manifest: Manifest;
    staffTier: boolean;
    voidUrl?: (row: ManifestRow) => string;
    actions?: ReactNode;
}) {
    const { t, label, locale } = useTransportLabels();
    const time = (value: string | null) => (value ? new Date(value).toLocaleTimeString(locale === 'am' ? 'am-ET' : 'en-GB', { hour: '2-digit', minute: '2-digit' }) : '—');
    const stopName = (row: ManifestRow) => (row.stop ? (locale === 'am' ? row.stop.name_am || row.stop.name_en : row.stop.name_en || row.stop.name_am) : '—');

    return (
        <div className="space-y-4">
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                <Kpi label={t('transport.manifestBoarded')} value={manifest.totals.accepted} />
                <Kpi label={t('transport.expectedRiders')} value={manifest.totals.expected_riders || '—'} />
                <Kpi label={t('transport.manifestRefused')} value={manifest.totals.rejected} />
                <Kpi label={t('transport.manifestVoided')} value={manifest.totals.voided} />
                <Kpi label={t('transport.manifestManual')} value={manifest.totals.manual} tone={manifest.totals.manual > 0 ? 'warning' : undefined} />
            </div>
            <TableCard title={t('transport.manifest')} actions={actions}>
                {manifest.rows.length === 0 ? <EmptyState title={t('transport.noBoardings')} /> : (
                    <div className="relative overflow-x-auto">
                        <table className={tableClassName}>
                            <thead className={tableHeadClassName}>
                                <tr>
                                    <th className={tableHeaderCellClassName}>#</th>
                                    <th className={tableHeaderCellClassName}>{t('transport.employee')}</th>
                                    {staffTier && <th className={tableHeaderCellClassName}>{t('transport.organization')}</th>}
                                    <th className={tableHeaderCellClassName}>{t('transport.boardedAt')}</th>
                                    <th className={tableHeaderCellClassName}>{t('transport.stop')}</th>
                                    <th className={tableHeaderCellClassName}>{t('transport.method')}</th>
                                    <th className={tableHeaderCellClassName}>{t('transport.status')}</th>
                                    {voidUrl && <th className={tableHeaderCellClassName}><span className="sr-only">{t('transport.voidBoarding')}</span></th>}
                                </tr>
                            </thead>
                            <tbody className={tableBodyClassName}>
                                {manifest.rows.map((row, index) => (
                                    <tr key={row.id} className={`${tableRowClassName} ${row.status === 'voided' ? 'opacity-60' : ''}`}>
                                        <td className={`${tableCellClassName} tabular-nums text-(--app-muted-foreground)`}>{index + 1}</td>
                                        <td className={tableCellClassName}>
                                            <div className="font-medium">{row.full_name ?? '—'}</div>
                                            <div className="text-xs text-(--app-muted-foreground)">{row.employee_number}</div>
                                        </td>
                                        {staffTier && <td className={tableCellClassName}>{locale === 'am' && row.organization_am ? row.organization_am : row.organization ?? '—'}</td>}
                                        <td className={`${tableCellClassName} tabular-nums`}>{time(row.boarded_at)}{row.sync_state && row.sync_state !== 'online' ? <div className="text-xs text-(--app-muted-foreground)">{label('syncStates', row.sync_state)}</div> : null}</td>
                                        <td className={tableCellClassName}>{stopName(row)}</td>
                                        <td className={tableCellClassName}>{label('methods', row.verification_method)}{row.manual_reason ? <div className="text-xs text-(--app-muted-foreground)">{row.manual_reason}</div> : null}</td>
                                        <td className={tableCellClassName}>
                                            <Pill value={row.status} />
                                            {row.status !== 'accepted' && row.result_code && <div className="mt-1 text-xs text-(--app-muted-foreground)">{label('reasons', row.result_code)}</div>}
                                        </td>
                                        {voidUrl && (
                                            <td className={`${tableCellClassName} text-right`}>
                                                {row.status === 'accepted' && (
                                                    <ActionDialogButton label={t('transport.voidBoarding')} title={t('transport.voidBoarding')} description={t('transport.voidBoardingHelp')} destructive size="xs" url={voidUrl(row)}
                                                        fields={[{ name: 'reason', label: t('transport.reason'), type: 'textarea', required: true }]} />
                                                )}
                                            </td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </TableCard>
        </div>
    );
}
