import PortalPage from '@/Components/employees/portal/PortalPage';
import SupervisorSummary from '@/Components/ReportingLines/SupervisorSummary';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import type { ActingAppointmentRow, EmployeeRow, SupervisorResolution } from '@/Components/ReportingLines/types';
import { Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { localizedName } from '@/utils/localizedName';
import { badgeClassName, buttonClassName, cx } from '@/Components/ui';

/** My Portal: the signed-in employee's own supervisor and the chain above them (ADR 0025). */
export default function EmployeeReportingLine({ employee, supervisor, chain, acting_as, can }: {
    employee: EmployeeRow;
    supervisor: SupervisorResolution;
    chain: SupervisorResolution[];
    acting_as: ActingAppointmentRow[];
    can: { view_team: boolean };
}) {
    const { t, locale } = useLocale();

    return (
        <PortalPage title={t('reportingLines.myReportingLine')} description={`${localizedName(employee.position_title_en ?? '', employee.position_title_am, locale)}${employee.unit_name_en ? ` · ${localizedName(employee.unit_name_en, employee.unit_name_am, locale)}` : ''}`} actions={can.view_team ? <Link href={route('employee.my-team')} className={buttonClassName({ variant: 'secondary', size: 'sm' })}>{t('reportingLines.myTeam')}</Link> : undefined}>
            <section className="space-y-3">
                <h3 className="text-sm font-semibold text-(--app-foreground)">{t('reportingLines.supervisor')}</h3>
                <SupervisorSummary resolution={supervisor} />
                <p className="text-xs text-(--app-muted-foreground)">{t('reportingLines.lineHint')}</p>
            </section>

            <section className="space-y-3">
                <h3 className="text-sm font-semibold text-(--app-foreground)">{t('reportingLines.chain')}</h3>
                <ol className="space-y-2">
                    {chain.map((step, i) => (
                        <li key={`${step.employee_id}-${i}`} className="flex gap-3">
                            <span className="mt-3 w-6 shrink-0 text-right text-xs tabular-nums text-(--app-muted-foreground)">{i + 1}</span>
                            <div className="min-w-0 flex-1"><SupervisorSummary resolution={step} compact /></div>
                        </li>
                    ))}
                </ol>
            </section>

            {acting_as.length > 0 && (
                <section className="rounded-panel border border-(--app-border) bg-(--app-surface) p-5">
                    <h3 className="mb-3 text-sm font-semibold text-(--app-foreground)">{t('reportingLines.actingAs')}</h3>
                    <ul className="space-y-2 text-sm">
                        {acting_as.map((a) => (
                            <li key={a.id} className="flex flex-wrap items-center justify-between gap-2">
                                <span>{localizedName(a.position_title_en ?? '', a.position_title_am, locale)}{a.unit_name_en ? ` · ${localizedName(a.unit_name_en, a.unit_name_am, locale)}` : ''}</span>
                                <span className="text-xs text-(--app-muted-foreground)">
                                    {a.starts_on && <LocalizedDateDisplay value={a.starts_on} />} – {a.ends_on ? <LocalizedDateDisplay value={a.ends_on} /> : t('reportingLines.openEnded')}
                                    <span className={cx(badgeClassName('info'), 'ml-2')}>{t('reportingLines.inForce')}</span>
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </PortalPage>
    );
}
