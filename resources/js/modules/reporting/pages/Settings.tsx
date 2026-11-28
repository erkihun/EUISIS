import PageHeader from '@/Components/PageHeader';
import { cx } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ActionErrors, Notice } from '@/modules/reporting/components/Notices';
import { badge, errorCls, inputCls, labelCls, mutedCls, panelCls, primaryBtn, sectionTitleCls, useReporting } from '@/modules/reporting/lib/helpers';
import { Head, useForm } from '@inertiajs/react';
import type { FormEvent, JSX } from 'react';

interface ReminderRules {
    days_before: number[];
    on_due_date: boolean;
    when_overdue: boolean;
    escalate_after_working_days: number | null;
}

interface Props {
    /** Named report_setting, never `settings`: the layout shares the system settings under that name. */
    report_setting: { maintenance: boolean; max_upload_kb: number; reminder_rules: ReminderRules; document_font: string; email_notifications: boolean };
    health: {
        paused: boolean;
        healthy: boolean;
        tasks: { task: string; status: string | null; last_run_at: string | null; error: string | null; stale: boolean }[];
        outbox: { pending: number; failed: number; oldest_pending_at: string | null };
        artifacts: { queued: number; processing: number; failed: number };
        notifications: { failed: number };
    };
    capabilities: { pdf_preview: boolean; malware_scanner: boolean; metric_sources: { key: string; available: boolean }[] };
    can: { manage: boolean };
}

/**
 * The module's own settings and its operational health. The maintenance
 * switch pauses every write and all scheduled work while the pages stay
 * readable; queued work waits and resumes when the switch is turned off.
 */
export default function Settings({ report_setting, health, capabilities, can }: Props): JSX.Element {
    const { rt, moment } = useReporting();
    const form = useForm(report_setting);
    const rules = form.data.reminder_rules;
    const setRules = (patch: Partial<ReminderRules>) => form.setData('reminder_rules', { ...rules, ...patch });
    const save = (event: FormEvent) => {
        event.preventDefault();
        form.put(route('reporting.settings.update'), { preserveScroll: true });
    };
    const errors = form.errors as Record<string, string>;

    return (
        <AuthenticatedLayout header={<PageHeader title={rt('settings.title')} description={rt('settings.description')} />}>
            <Head title={rt('settings.title')} />
            <ActionErrors except={Object.keys(errors)} />
            <div className="space-y-5 pb-4">
                <section className={cx(panelCls, 'p-4')} aria-labelledby="st-health">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 id="st-health" className={sectionTitleCls}>{rt('settings.health')}</h2>
                        <span className={badge(health.paused ? 'warning' : health.healthy ? 'success' : 'danger')}>{rt(health.paused ? 'settings.statePaused' : health.healthy ? 'settings.stateHealthy' : 'settings.stateAttention')}</span>
                    </div>
                    <div className="mt-3 overflow-x-auto">
                        <table className="w-full min-w-[36rem] text-sm">
                            <caption className="sr-only">{rt('settings.scheduledWork')}</caption>
                            <thead>
                                <tr className="border-b border-(--app-border) text-xs text-(--app-muted-foreground)">
                                    <th scope="col" className="px-2 py-1.5 text-start font-medium">{rt('settings.task')}</th>
                                    <th scope="col" className="px-2 py-1.5 text-start font-medium">{rt('settings.lastRun')}</th>
                                    <th scope="col" className="px-2 py-1.5 text-start font-medium">{rt('settings.result')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-(--app-border)">
                                {health.tasks.map((task) => (
                                    <tr key={task.task}>
                                        <th scope="row" className="px-2 py-1.5 text-start font-normal">{rt(`settings.tasks.${task.task}`)}</th>
                                        <td className="px-2 py-1.5">{task.last_run_at ? moment(task.last_run_at) : rt('settings.never')}</td>
                                        <td className="px-2 py-1.5">
                                            {task.status && <span className={badge(task.status === 'completed' ? 'success' : task.status === 'paused' ? 'warning' : 'danger')}>{rt(`settings.runStatus.${task.status}`)}</span>}
                                            {task.stale && <span className={cx(badge('danger'), 'ms-1')}>{rt('settings.stale')}</span>}
                                            {task.error && <span className={cx(errorCls, 'block')}>{task.error}</span>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <dl className="mt-3 grid gap-3 border-t border-(--app-border) pt-3 text-sm sm:grid-cols-3">
                        <div><dt className={labelCls}>{rt('settings.outbox')}</dt><dd>{rt('settings.outboxCounts', { pending: health.outbox.pending, failed: health.outbox.failed })}</dd></div>
                        <div><dt className={labelCls}>{rt('settings.generatedFiles')}</dt><dd>{rt('settings.artifactCounts', { queued: health.artifacts.queued + health.artifacts.processing, failed: health.artifacts.failed })}</dd></div>
                        <div><dt className={labelCls}>{rt('settings.notices')}</dt><dd>{rt('settings.noticeCounts', { failed: health.notifications.failed })}</dd></div>
                    </dl>
                    <p className={cx(mutedCls, 'mt-2 text-xs')}>{rt('settings.healthHelp')}</p>
                </section>

                <section className={cx(panelCls, 'p-4')} aria-labelledby="st-cap">
                    <h2 id="st-cap" className={sectionTitleCls}>{rt('settings.capabilities')}</h2>
                    <ul className="mt-2 space-y-1.5 text-sm">
                        <li><span className={badge(capabilities.malware_scanner ? 'success' : 'warning')}>{rt(capabilities.malware_scanner ? 'settings.configured' : 'settings.notConfigured')}</span> {rt('settings.scanner')}</li>
                        <li><span className={badge(capabilities.pdf_preview ? 'success' : 'neutral')}>{rt(capabilities.pdf_preview ? 'settings.configured' : 'settings.notConfigured')}</span> {rt('settings.pdf')}</li>
                        {capabilities.metric_sources.map((source) => (
                            <li key={source.key}><span className={badge(source.available ? 'success' : 'neutral')}>{rt(source.available ? 'settings.available' : 'settings.unavailable')}</span> {rt('settings.metricSource', { name: source.key })}</li>
                        ))}
                    </ul>
                </section>

                <form onSubmit={save} className={cx(panelCls, 'space-y-4 p-4')} aria-labelledby="st-settings">
                    <h2 id="st-settings" className={sectionTitleCls}>{rt('settings.sectionSettings')}</h2>
                    {!can.manage && <Notice>{rt('settings.readOnly')}</Notice>}
                    <fieldset disabled={!can.manage} className="space-y-4">
                        <label className="flex items-start gap-3 rounded-control border border-(--app-border) p-3 text-sm">
                            <input type="checkbox" className="mt-1" checked={form.data.maintenance} onChange={(e) => form.setData('maintenance', e.target.checked)} />
                            <span><span className="font-medium">{rt('settings.maintenance')}</span><span className={cx(mutedCls, 'block text-xs')}>{rt('settings.maintenanceHelp')}</span></span>
                        </label>
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            <div>
                                <label htmlFor="st-upload" className={labelCls}>{rt('settings.maxUpload')}</label>
                                <input id="st-upload" type="number" min={512} max={102400} step={512} className={inputCls} value={form.data.max_upload_kb} onChange={(e) => form.setData('max_upload_kb', Number(e.target.value))} />
                                {errors.max_upload_kb && <p className={errorCls} role="alert">{errors.max_upload_kb}</p>}
                            </div>
                            <div>
                                <label htmlFor="st-font" className={labelCls}>{rt('settings.font')}</label>
                                <input id="st-font" type="text" className={inputCls} maxLength={80} value={form.data.document_font} onChange={(e) => form.setData('document_font', e.target.value)} aria-describedby="st-font-help" />
                                <p id="st-font-help" className={cx(mutedCls, 'mt-1 text-xs')}>{rt('settings.fontHelp')}</p>
                                {errors.document_font && <p className={errorCls} role="alert">{errors.document_font}</p>}
                            </div>
                            <label className="flex items-start gap-2 text-sm">
                                <input type="checkbox" className="mt-1" checked={form.data.email_notifications} onChange={(e) => form.setData('email_notifications', e.target.checked)} />
                                <span>{rt('settings.email')}<span className={cx(mutedCls, 'block text-xs')}>{rt('settings.emailHelp')}</span></span>
                            </label>
                        </div>
                        <fieldset className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <legend className="text-sm font-semibold">{rt('settings.reminders')}</legend>
                            <div>
                                <label htmlFor="st-days" className={labelCls}>{rt('settings.daysBefore')}</label>
                                <input id="st-days" type="text" inputMode="numeric" className={inputCls} value={rules.days_before.join(', ')} onChange={(e) => setRules({ days_before: e.target.value.split(/[^0-9]+/).filter(Boolean).map(Number).slice(0, 6) })} aria-describedby="st-days-help" />
                                <p id="st-days-help" className={cx(mutedCls, 'mt-1 text-xs')}>{rt('settings.daysBeforeHelp')}</p>
                            </div>
                            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={rules.on_due_date} onChange={(e) => setRules({ on_due_date: e.target.checked })} />{rt('settings.onDueDate')}</label>
                            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={rules.when_overdue} onChange={(e) => setRules({ when_overdue: e.target.checked })} />{rt('settings.whenOverdue')}</label>
                            <div>
                                <label htmlFor="st-esc" className={labelCls}>{rt('settings.escalateAfter')}</label>
                                <input id="st-esc" type="number" min={1} max={30} className={inputCls} value={rules.escalate_after_working_days ?? ''} onChange={(e) => setRules({ escalate_after_working_days: e.target.value === '' ? null : Number(e.target.value) })} />
                            </div>
                        </fieldset>
                        <button type="submit" className={primaryBtn} disabled={form.processing}>{rt('common.save')}</button>
                    </fieldset>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
