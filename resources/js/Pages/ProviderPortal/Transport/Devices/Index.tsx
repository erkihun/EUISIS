import { EmptyState, TableCard, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@euisis/ui';
import TransportProviderLayout from '@/Layouts/TransportProviderLayout';
import { useTransportLabels } from '@/Components/transport/labels';
import { ActionDialogButton, DateText, Pill } from '@/Components/transport/TransportShared';
import type { TransportDevice } from '@/types/transport';

/** The provider's scanner devices. Registration is the city's; a lost device is revoked here at once. */
export default function Index({ devices, inactivityMinutes }: { devices: TransportDevice[]; inactivityMinutes: number }) {
    const { t } = useTransportLabels();
    const stale = (d: TransportDevice) => d.status === 'active' && (!d.last_seen_at || Date.now() - new Date(d.last_seen_at).getTime() > inactivityMinutes * 60000);

    return (
        <TransportProviderLayout title={t('transport.devices')}>
            <TableCard title={t('transport.devices')} description={t('transport.devicesPortalIntro')}>
                {devices.length === 0 ? <EmptyState title={t('transport.noRecords')} /> : (
                    <div className="relative overflow-x-auto">
                        <table className={tableClassName}>
                            <thead className={tableHeadClassName}><tr><th className={tableHeaderCellClassName}>{t('transport.deviceCode')}</th><th className={tableHeaderCellClassName}>{t('transport.boundVehicle')}</th><th className={tableHeaderCellClassName}>{t('transport.lastSeen')}</th><th className={tableHeaderCellClassName}>{t('transport.status')}</th><th className={tableHeaderCellClassName} /></tr></thead>
                            <tbody className={tableBodyClassName}>
                                {devices.map((d) => (
                                    <tr key={d.id} className={tableRowClassName}>
                                        <td className={tableCellClassName}><div className="font-medium">{d.device_code}</div><div className="text-xs text-(--app-muted-foreground)">{d.name}{d.platform ? ` · ${d.platform}` : ''}{d.software_version ? ` ${d.software_version}` : ''}</div></td>
                                        <td className={tableCellClassName}>{d.vehicle?.plate_number ?? t('transport.anyVehicleOfProvider')}</td>
                                        <td className={`${tableCellClassName} ${stale(d) ? 'text-amber-600' : ''}`}>{d.last_seen_at ? <DateText value={d.last_seen_at} withTime /> : t('transport.neverSeen')}</td>
                                        <td className={tableCellClassName}><Pill value={d.status} />{d.revocation_reason ? <div className="text-xs text-(--app-muted-foreground)">{d.revocation_reason}</div> : null}</td>
                                        <td className={`${tableCellClassName} text-right`}>{d.status !== 'revoked' && <ActionDialogButton label={t('transport.reportLost')} title={t('transport.reportLost')} description={t('transport.reportLostHelp')} destructive size="xs" url={route('provider.portal.transport.devices.revoke', d.id)} fields={[{ name: 'reason', label: t('transport.reason'), required: true }]} />}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </TableCard>
        </TransportProviderLayout>
    );
}
