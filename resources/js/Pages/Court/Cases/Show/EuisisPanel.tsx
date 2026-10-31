import { useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Alert, Button, Input, Select, badgeClassName, cx } from '@/Components/ui';
import type { EuisisPanelData } from '@/court/types/caseDetail';
import { useCaseText } from './useCaseText';
import { LinkIcon } from './icons';

interface Props {
    caseId: number;
    panel: EuisisPanelData;
}

type EuisisErrors = { euisis?: Record<string, string> };

/**
 * The EUISIS panel of the summary: where a referred case came from, the
 * respondent organization as EUISIS knows it, and the EUISIS employee record
 * of the applicant or complainant, with the two link forms
 * (cases.euisis.organization, cases.euisis.employee; error bag `euisis`).
 */
export default function EuisisPanel({ caseId, panel }: Props) {
    const { t, fill } = useCaseText();
    const bag = (usePage().props.errors as EuisisErrors | undefined)?.euisis ?? {};
    const organizationForm = useForm<{ organization_uuid: string }>({ organization_uuid: panel.selected_organization_uuid ?? '' });
    const employeeForm = useForm<{ employee_number: string }>({ employee_number: '' });

    const linkOrganization = (event: FormEvent) => {
        event.preventDefault();
        organizationForm.patch(route('court.cases.euisis.organization', caseId), { preserveScroll: true, errorBag: 'euisis' });
    };
    const linkEmployee = (event: FormEvent) => {
        event.preventDefault();
        employeeForm.post(route('court.cases.euisis.employee', caseId), { preserveScroll: true, errorBag: 'euisis', onSuccess: () => employeeForm.reset() });
    };

    const dash = (value: string | null | undefined) => (value && value.trim() !== '' ? value : '—');
    const messages = Object.values(bag);

    return (
        <section id="euisis-panel" className="space-y-4 rounded-control border border-teal-200 bg-teal-50/40 p-4 dark:border-teal-900 dark:bg-teal-950/20">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex items-center gap-2">
                    <span className="inline-flex h-7 w-7 items-center justify-center rounded-full bg-teal-100 text-teal-700 dark:bg-teal-900 dark:text-teal-200"><LinkIcon /></span>
                    <div className="text-xs font-semibold uppercase tracking-wide text-(--app-muted-foreground)">{t('cm.euisis.panel.title')}</div>
                </div>
                {panel.referral && <span className={badgeClassName('info')}>{t('cm.euisis.panel.referred_badge')}</span>}
            </div>

            {panel.notice && <Alert tone="info">{panel.notice}</Alert>}
            {messages.length > 0 && (
                <Alert tone="danger">
                    <ul className="space-y-0.5">{messages.map((message) => <li key={message}>{message}</li>)}</ul>
                </Alert>
            )}

            {panel.referral && (
                <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                    <div><dt className="text-xs text-(--app-muted-foreground)">{t('cm.euisis.panel.grievance_reference')}</dt><dd className="font-mono text-(--app-foreground)">{dash(panel.referral.grievance_reference)}</dd></div>
                    <div><dt className="text-xs text-(--app-muted-foreground)">{t('cm.euisis.panel.tribunal_case_number')}</dt><dd className="font-mono text-(--app-foreground)">{dash(panel.referral.tribunal_case_number)}</dd></div>
                    <div>
                        <dt className="text-xs text-(--app-muted-foreground)">{t('cm.euisis.panel.complainant')}</dt>
                        <dd className="text-(--app-foreground)">
                            {dash(panel.referral.complainant_name)}
                            {panel.referral.complainant_contact && <span className="block text-xs text-(--app-muted-foreground)">{panel.referral.complainant_contact}</span>}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-xs text-(--app-muted-foreground)">{t('cm.euisis.panel.referring_officer')}</dt>
                        <dd className="text-(--app-foreground)">
                            {dash(panel.referral.referring_officer)}
                            <span className="block text-xs text-(--app-muted-foreground)">{fill(t('cm.euisis.panel.received_at'), { date: panel.referral.received_at ?? '—' })}</span>
                        </dd>
                    </div>
                </dl>
            )}

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <div className="space-y-2 rounded-control border border-(--app-border) bg-(--app-surface) p-3">
                    <div className="text-xs font-semibold text-(--app-muted-foreground)">{t('cm.euisis.panel.respondent_organization')}</div>
                    {panel.organization ? (
                        <>
                            <p className="font-semibold text-(--app-foreground)">
                                {panel.organization.name}
                                {panel.organization.code && <span className="ml-1 font-mono text-xs text-(--app-muted-foreground)">{panel.organization.code}</span>}
                            </p>
                            {!panel.organization.active && (
                                <p className="text-xs font-semibold text-amber-700 dark:text-amber-300">{fill(t('cm.euisis.panel.organization_inactive'), { status: panel.organization.status ?? '' })}</p>
                            )}
                        </>
                    ) : <p className="text-sm text-(--app-muted-foreground)">{t('cm.euisis.panel.not_linked')}</p>}

                    {panel.can_link && panel.has_organizations && (
                        <form onSubmit={linkOrganization} className="flex flex-wrap items-end gap-2">
                            <Select value={organizationForm.data.organization_uuid} onChange={(event) => organizationForm.setData('organization_uuid', event.target.value)}
                                aria-label={t('cm.euisis.panel.respondent_organization')} className="min-w-0 flex-1">
                                <option value="">{t('cm.euisis.panel.no_organization')}</option>
                                {panel.organizations.map((option) => (
                                    <option key={option.uuid} value={option.uuid}>{option.name}{option.active ? '' : ` (${option.status ?? ''})`}</option>
                                ))}
                            </Select>
                            <Button type="submit" size="sm" variant="primary" loading={organizationForm.processing}>{t('cm.euisis.panel.link')}</Button>
                        </form>
                    )}
                    {panel.can_link && !panel.has_organizations && <p className="text-xs text-(--app-muted-foreground)">{t('cm.euisis.panel.no_cache')}</p>}
                </div>

                <div className="space-y-2 rounded-control border border-(--app-border) bg-(--app-surface) p-3">
                    <div className="text-xs font-semibold text-(--app-muted-foreground)">{panel.has_applicant ? t('cm.euisis.panel.applicant_employee') : t('cm.euisis.panel.complainant_employee')}</div>
                    {panel.employee ? (
                        <>
                            <p className="font-semibold text-(--app-foreground)">{panel.employee.name} <span className="font-mono text-xs text-(--app-muted-foreground)">{panel.employee.number}</span></p>
                            <p className="text-xs text-(--app-muted-foreground)">{panel.employee.position ?? t('cm.euisis.panel.position_unknown')} · {panel.employee.organization ?? '—'}</p>
                            {panel.employee.status && panel.employee.status !== 'active' && (
                                <p className="text-xs font-semibold text-amber-700 dark:text-amber-300">{fill(t('cm.euisis.panel.employee_status'), { status: panel.employee.status })}</p>
                            )}
                        </>
                    ) : <p className="text-sm text-(--app-muted-foreground)">{t(panel.employee_uuid ? 'cm.euisis.panel.employee_not_synced' : 'cm.euisis.panel.not_linked')}</p>}

                    {panel.can_link_employee && (
                        <form onSubmit={linkEmployee} className="flex flex-wrap items-end gap-2">
                            <Input value={employeeForm.data.employee_number} onChange={(event) => employeeForm.setData('employee_number', event.target.value)} maxLength={60} required
                                placeholder={t('cm.euisis.panel.employee_number')} aria-label={t('cm.euisis.panel.employee_number')} className={cx('min-w-0 flex-1')} />
                            <Button type="submit" size="sm" variant="primary" loading={employeeForm.processing}>{t('cm.euisis.panel.find_and_link')}</Button>
                        </form>
                    )}
                </div>
            </div>

            {panel.enabled && (
                <p className="text-xs text-(--app-muted-foreground)">
                    {fill(t('cm.euisis.panel.last_sync'), { time: panel.last_sync ?? t('cm.euisis.panel.never') })}
                    {panel.last_error && <span className="text-rose-700 dark:text-rose-300"> · {fill(t('cm.euisis.panel.last_error'), { error: panel.last_error })}</span>}
                </p>
            )}
        </section>
    );
}
