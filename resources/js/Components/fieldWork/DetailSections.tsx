import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { useLocale } from '@/hooks/useLocale';
import type { JSX, ReactNode } from 'react';
import { FieldWorkStatusBadge, FlagBadge, LocationBadge } from './Badges';
import { destinationName, employeeName, named, panelCls } from './helpers';
import type { FieldWorkDetail } from './types';

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className={`${panelCls} p-4`}>
            <h2 className="mb-2 text-sm font-semibold text-gray-900 dark:text-slate-100">{title}</h2>
            {children}
        </section>
    );
}

function Meta({ rows }: { rows: [string, ReactNode][] }) {
    return (
        <dl className="grid gap-x-4 gap-y-1.5 text-sm sm:grid-cols-[12rem_1fr]">
            {rows.map(([label, value]) => (
                <div key={label} className="contents">
                    <dt className="text-gray-500 dark:text-slate-400">{label}</dt>
                    <dd className="whitespace-pre-line break-words text-gray-900 dark:text-slate-100">{value ?? '—'}</dd>
                </div>
            ))}
        </dl>
    );
}

/**
 * The read-only body of a field work request, shared by My Portal and the
 * management detail page. GPS rows show the verification status; coordinates
 * render only when the server sent them (precise-location permission).
 */
export default function DetailSections({ fieldWork }: { fieldWork: FieldWorkDetail }): JSX.Element {
    const { t, locale } = useLocale();
    const d = fieldWork.destination_details;

    const summary: [string, ReactNode][] = [
        [t('fieldWork.show.requester'), `${employeeName(fieldWork.requester, locale)} (${fieldWork.requester?.employee_number ?? ''})`],
        [t('fieldWork.show.placement'), [fieldWork.organization, fieldWork.organization_unit, fieldWork.position].map((p) => named(p ?? undefined, locale)).filter(Boolean).join(' · ') || '—'],
        [t('fieldWork.fields.type'), named(fieldWork.type ?? undefined, locale) || '—'],
        [t('fieldWork.fields.purpose'), fieldWork.purpose],
    ];
    if (fieldWork.activity_description) summary.push([t('fieldWork.fields.activity'), fieldWork.activity_description]);
    summary.push(
        [t('fieldWork.columns.schedule'), <span key="s">{t(`fieldWork.scheduleTypes.${fieldWork.schedule_type}`)} · <LocalizedDateDisplay value={fieldWork.starts_at} withTime /> → <LocalizedDateDisplay value={fieldWork.expected_return_at} withTime /></span>],
        [t('fieldWork.show.supervisor'), fieldWork.supervisor?.name ?? '—'],
    );

    const destination: [string, ReactNode][] = [
        [t('fieldWork.fields.destinationType'), t(`fieldWork.destinationTypes.${fieldWork.destination_type}`)],
        [t('fieldWork.columns.destination'), destinationName(fieldWork, locale)],
    ];
    if (d.organization_unit) destination.push([t('fieldWork.fields.destinationUnit'), named(d.organization_unit, locale)]);
    if (d.address) destination.push([t('fieldWork.fields.address'), d.address]);
    if (d.contact_person) destination.push([t('fieldWork.fields.contactPerson'), d.contact_person]);
    if (d.contact_phone) destination.push([t('fieldWork.fields.contactPhone'), d.contact_phone]);
    if (d.expected_point) destination.push([t('fieldWork.fields.expectedPoint'), `${d.expected_point.latitude}, ${d.expected_point.longitude} (± ${d.expected_point.radius_m} m)`]);

    return (
        <>
            <div className="flex flex-wrap items-center gap-2">
                <span className="text-base font-semibold text-gray-900 dark:text-slate-100">{fieldWork.reference_number}</span>
                <FieldWorkStatusBadge status={fieldWork.status} />
                {fieldWork.monitoring_flag && <FlagBadge flag={fieldWork.monitoring_flag} />}
                {fieldWork.status === 'pending_supervisor_approval' && fieldWork.supervisor_resolution === 'supervisor_not_resolved' && <FlagBadge flag="supervisor_not_resolved" />}
            </div>

            {fieldWork.status === 'pending_supervisor_approval' && fieldWork.supervisor_resolution === 'supervisor_not_resolved' && (
                <p role="status" className="rounded-panel border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200">{t('fieldWork.show.supervisorNotResolved')}</p>
            )}

            <Section title={t('fieldWork.form.sectionWork')}><Meta rows={summary} /></Section>
            <Section title={t('fieldWork.form.sectionDestination')}><Meta rows={destination} /></Section>

            {fieldWork.decision && (
                <Section title={t('fieldWork.show.decision')}>
                    <Meta rows={[
                        [t('fieldWork.columns.status'), <FieldWorkStatusBadge key="st" status={fieldWork.status} />],
                        [t('fieldWork.show.supervisor'), fieldWork.decision.by ?? '—'],
                        [t('fieldWork.show.decidedAt'), <LocalizedDateDisplay key="at" value={fieldWork.decision.at} withTime />],
                        ...(fieldWork.decision.reason ? [[t('fieldWork.fields.reason'), fieldWork.decision.reason] as [string, ReactNode]] : []),
                    ]} />
                </Section>
            )}

            <Section title={t('fieldWork.show.participants')}>
                {fieldWork.location_visibility === 'precise' && <p className="mb-2 text-xs text-amber-800 dark:text-amber-300">{t('fieldWork.gps.precise')}</p>}
                <ul className="divide-y divide-gray-100 dark:divide-slate-800">
                    {fieldWork.participants.map((p) => (
                        <li key={p.id} className="py-2 text-sm">
                            <p className="font-medium text-gray-900 dark:text-slate-100">
                                {employeeName(p.employee, locale)} <span className="text-xs font-normal text-gray-500 dark:text-slate-400">· {p.employee?.employee_number} · {t(`fieldWork.roles.${p.role}`)}</span>
                            </p>
                            <p className="text-xs text-gray-600 dark:text-slate-400">
                                {p.checked_in_at ? <>{t('fieldWork.show.checkedIn')} <LocalizedDateDisplay value={p.checked_in_at} withTime /></> : t('fieldWork.show.notCheckedIn')}
                                {' · '}
                                {p.checked_out_at ? <>{t('fieldWork.show.checkedOut')} <LocalizedDateDisplay value={p.checked_out_at} withTime /></> : t('fieldWork.show.notCheckedOut')}
                            </p>
                            {p.events.length > 0 && (
                                <ul className="mt-1 space-y-1">
                                    {p.events.map((e) => (
                                        <li key={e.id} className="flex flex-wrap items-center gap-2 text-xs text-gray-700 dark:text-slate-300">
                                            <span className="font-medium">{t(`fieldWork.eventTypes.${e.event_type}`)}</span>
                                            <LocationBadge status={e.validation_status} />
                                            <LocalizedDateDisplay value={e.captured_at} withTime />
                                            {e.latitude !== undefined && (
                                                <span className="tabular-nums text-gray-500 dark:text-slate-400">
                                                    {t('fieldWork.gps.coordinates')}: {e.latitude}, {e.longitude}
                                                    {e.accuracy_m != null && ` · ${t('fieldWork.gps.accuracy')} ±${Math.round(e.accuracy_m)} m`}
                                                    {e.distance_m != null && ` · ${t('fieldWork.gps.distance')} ${Math.round(e.distance_m)} m`}
                                                </span>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </li>
                    ))}
                </ul>
            </Section>

            {fieldWork.completion && (
                <Section title={t('fieldWork.show.completion')}>
                    <Meta rows={[
                        [t('fieldWork.show.actualStart'), <LocalizedDateDisplay key="as" value={fieldWork.completion.actual_start_at} withTime />],
                        [t('fieldWork.show.actualReturn'), <LocalizedDateDisplay key="ar" value={fieldWork.completion.actual_return_at} withTime />],
                        [t('fieldWork.fields.completionNote'), fieldWork.completion.note],
                        ...(fieldWork.completion.outcome ? [[t('fieldWork.fields.outcome'), fieldWork.completion.outcome] as [string, ReactNode]] : []),
                        ...(fieldWork.completion.follow_up_required ? [[t('fieldWork.show.followUp'), fieldWork.completion.follow_up_note ?? '—'] as [string, ReactNode]] : []),
                    ]} />
                </Section>
            )}

            <Section title={t('fieldWork.show.history')}>
                <ol className="space-y-2 border-s border-gray-200 ps-4 dark:border-slate-700">
                    {fieldWork.history.map((entry, index) => (
                        <li key={`${entry.action}-${index}`} className="text-sm">
                            <p className="text-gray-900 dark:text-slate-100">
                                <span className="font-medium">{t(`fieldWork.historyActions.${entry.action}`)}</span>
                                {entry.actor && <span className="text-gray-600 dark:text-slate-400"> · {entry.actor}</span>}
                                <span className="text-xs text-gray-500 dark:text-slate-400"> · <LocalizedDateDisplay value={entry.at} withTime /></span>
                            </p>
                            {entry.comment && <p className="whitespace-pre-line text-gray-700 dark:text-slate-300">{entry.comment}</p>}
                        </li>
                    ))}
                </ol>
            </Section>
        </>
    );
}
