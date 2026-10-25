import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import StatusBadge from '@/Components/StatusBadge';
import { useLocale } from '@/hooks/useLocale';
import { buttonClassName } from '@/Components/ui';

type WorkflowStep = 'submit' | 'verify' | 'return' | 'approve';

/**
 * The verified-employee stage of a record, as the server describes it: who did
 * each step and when, the checklist, and only the actions this reader may take.
 * The server decides every step again (permissions, separation of duties).
 */
export type EmployeeWorkflow = {
    status: string;
    verification_status: string | null;
    steps: Array<{ key: 'registered' | 'submitted' | 'verified' | 'approved'; by: string | null; at: string | null }>;
    notes: string | null;
    checklist: Array<{ key: string; required: boolean; checked: boolean }>;
    can: Record<WorkflowStep, boolean>;
    urls: Record<WorkflowStep, string>;
};

export default function EmployeeWorkflowCard({ workflow }: { workflow: EmployeeWorkflow }) {
    const { t } = useLocale();
    const [returning, setReturning] = useState(false);
    const form = useForm<{ checks: Record<string, boolean>; notes: string; reason: string }>({
        checks: Object.fromEntries(workflow.checklist.map((check) => [check.key, check.checked])),
        notes: '',
        reason: '',
    });
    const errors = form.errors as Record<string, string | undefined>;
    const anyAction = workflow.can.submit || workflow.can.verify || workflow.can.return || workflow.can.approve;

    const post = (step: WorkflowStep) => form.post(workflow.urls[step], {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('notes', 'reason');
            setReturning(false);
        },
    });

    return (
        <section className="rounded-panel border border-(--app-border) bg-(--app-surface) p-5" aria-labelledby="employee-workflow-title">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 id="employee-workflow-title" className="text-sm font-semibold text-(--app-foreground)">{t('employees.workflow.title')}</h3>
                    <p className="mt-1 text-xs text-(--app-muted-foreground)">{t('employees.workflow.description')}</p>
                </div>
                {workflow.verification_status && (
                    <StatusBadge status={workflow.verification_status} label={t(`employees.workflow.states.${workflow.verification_status}`)} />
                )}
            </div>

            <ol className="mt-4 grid gap-3 sm:grid-cols-4">
                {workflow.steps.map((step) => (
                    <li key={step.key} className="rounded-card border border-(--app-border) px-3 py-2.5">
                        <p className="text-xs font-medium text-(--app-muted-foreground)">{t(`employees.workflow.steps.${step.key}`)}</p>
                        {step.at ? (
                            <>
                                <p className="mt-1 text-sm font-medium text-(--app-foreground)">{step.by ?? '—'}</p>
                                <p className="text-xs text-(--app-muted-foreground)"><LocalizedDateDisplay value={step.at} withTime /></p>
                            </>
                        ) : (
                            <p className="mt-1 text-sm text-(--app-muted-foreground)">{t('employees.workflow.notYet')}</p>
                        )}
                    </li>
                ))}
            </ol>

            {workflow.notes && (
                <p className="mt-4 rounded-card bg-(--app-surface-muted) px-3 py-2 text-sm text-(--app-foreground)">
                    <span className="font-medium">{t('employees.workflow.notes')}: </span>{workflow.notes}
                </p>
            )}

            {(workflow.can.verify || workflow.status === 'verified' || workflow.status === 'active') && workflow.checklist.length > 0 && (
                <fieldset className="mt-4" disabled={!workflow.can.verify || form.processing}>
                    <legend className="text-xs font-medium text-(--app-muted-foreground)">{t('employees.workflow.checklist')}</legend>
                    <div className="mt-2 grid gap-2 sm:grid-cols-2">
                        {workflow.checklist.map((check) => (
                            <label key={check.key} className="flex items-center gap-2 text-sm text-(--app-foreground)">
                                <input
                                    type="checkbox"
                                    className="h-4 w-4 rounded-sm border-(--app-border-strong) text-primary focus:ring-primary"
                                    checked={form.data.checks[check.key] ?? false}
                                    onChange={(event) => form.setData('checks', { ...form.data.checks, [check.key]: event.target.checked })}
                                />
                                {t(`employees.workflow.checks.${check.key}`)}
                                {check.required && <span className="text-xs text-(--app-muted-foreground)">({t('employees.workflow.required')})</span>}
                            </label>
                        ))}
                    </div>
                    {errors.checks && <p className="mt-2 text-xs text-red-600 dark:text-red-400" role="alert">{errors.checks}</p>}
                </fieldset>
            )}

            {anyAction && (
                <div className="mt-4 space-y-3">
                    {(workflow.can.submit || workflow.can.verify || workflow.can.approve) && (
                        <label className="block text-sm">
                            <span className="text-xs font-medium text-(--app-muted-foreground)">{t('employees.workflow.notesOptional')}</span>
                            <textarea
                                className="mt-1 w-full rounded-control border border-(--app-border-strong) bg-(--app-surface) px-3 py-2 text-sm"
                                rows={2}
                                maxLength={1000}
                                value={form.data.notes}
                                onChange={(event) => form.setData('notes', event.target.value)}
                            />
                        </label>
                    )}
                    {returning && (
                        <label className="block text-sm">
                            <span className="text-xs font-medium text-(--app-muted-foreground)">{t('employees.workflow.reason')}</span>
                            <textarea
                                className="mt-1 w-full rounded-control border border-(--app-border-strong) bg-(--app-surface) px-3 py-2 text-sm"
                                rows={2}
                                maxLength={1000}
                                value={form.data.reason}
                                onChange={(event) => form.setData('reason', event.target.value)}
                                aria-describedby="employee-workflow-reason-help"
                            />
                            <span id="employee-workflow-reason-help" className="text-xs text-(--app-muted-foreground)">{t('employees.workflow.reasonHelp')}</span>
                        </label>
                    )}
                    {(errors.employee || errors.reason) && (
                        <p className="text-sm text-red-600 dark:text-red-400" role="alert">{errors.employee ?? errors.reason}</p>
                    )}
                    <div className="flex flex-wrap gap-2">
                        {workflow.can.submit && (
                            <button type="button" className={buttonClassName({ variant: 'primary', size: 'sm' })} disabled={form.processing} onClick={() => post('submit')}>
                                {t('employees.workflow.submit')}
                            </button>
                        )}
                        {workflow.can.verify && (
                            <button type="button" className={buttonClassName({ variant: 'primary', size: 'sm' })} disabled={form.processing} onClick={() => post('verify')}>
                                {t('employees.workflow.verify')}
                            </button>
                        )}
                        {workflow.can.approve && (
                            <button type="button" className={buttonClassName({ variant: 'primary', size: 'sm' })} disabled={form.processing} onClick={() => post('approve')}>
                                {t('employees.workflow.approve')}
                            </button>
                        )}
                        {workflow.can.return && (
                            <button
                                type="button"
                                className={buttonClassName({ variant: 'outline', size: 'sm' })}
                                disabled={form.processing}
                                onClick={() => (returning ? post('return') : setReturning(true))}
                            >
                                {t('employees.workflow.return')}
                            </button>
                        )}
                    </div>
                </div>
            )}
        </section>
    );
}
