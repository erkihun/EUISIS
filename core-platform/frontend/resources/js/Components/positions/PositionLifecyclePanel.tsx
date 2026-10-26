import { useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import StatusBadge from '@/Components/StatusBadge';
import { useLocale } from '@/hooks/useLocale';
import { localizedName } from '@/utils/localizedName';
import { AppDialog, Button, FormField, Textarea } from '@euisis/ui';

type Master = { id: string; code: string; name_en: string; name_am: string | null } | null;

export type PositionLifecycle = {
    status: string;
    reason: string | null;
    created_by: string | null;
    approved_by: string | null;
    approved_at: string | null;
    masters: Record<'grade' | 'level' | 'occupation' | 'profession' | 'employment_type' | 'category', Master>;
    occupancy: { seats: number; holders: number; vacant: number };
    steps: Array<{ step: string; needs_reason: boolean }>;
    step_url: string;
    history: Array<{ id: number; change_type: string; changes: Record<string, { from: unknown; to: unknown }>; reason: string | null; effective_from: string | null; actor: string | null; at: string | null }>;
};

const STATUS_TONE: Record<string, string> = { draft: 'pending', approved: 'active', suspended: 'warning', abolished: 'inactive', archived: 'inactive' };

/**
 * The Core position status panel (ADR 0010): the lifecycle status, the job
 * masters the position uses, its seats against its holders, the steps this
 * reader may take, and the position's history.
 */
export default function PositionLifecyclePanel({ lifecycle }: { lifecycle: PositionLifecycle }) {
    const { t, locale } = useLocale();
    const label = (key: string) => t(`core.positionLifecycle.${key}`);
    const [step, setStep] = useState<{ step: string; needs_reason: boolean } | null>(null);
    const form = useForm({ step: '', reason: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(lifecycle.step_url, { preserveScroll: true, onSuccess: () => setStep(null) });
    };

    return (
        <section className="mt-6 rounded-panel border border-(--app-border) bg-(--app-surface)">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-(--app-border) px-5 py-4">
                <div className="flex items-center gap-3">
                    <h2 className="font-semibold text-(--app-foreground)">{label('title')}</h2>
                    <StatusBadge status={STATUS_TONE[lifecycle.status] ?? 'info'} label={label(`statuses.${lifecycle.status}`)} />
                </div>
                <div className="flex flex-wrap gap-2">
                    {lifecycle.steps.map((option) => (
                        <Button key={option.step} size="sm" variant={option.step === 'approve' || option.step === 'reinstate' ? 'primary' : 'outline'}
                            onClick={() => { form.setData({ step: option.step, reason: '' }); form.clearErrors(); setStep(option); }}>
                            {label(`steps.${option.step}`)}
                        </Button>
                    ))}
                </div>
            </div>

            <div className="grid gap-4 px-5 py-4 text-sm md:grid-cols-3">
                {(Object.keys(lifecycle.masters) as Array<keyof PositionLifecycle['masters']>).map((key) => {
                    const value = lifecycle.masters[key];
                    return (
                        <div key={key}>
                            <div className="text-xs text-(--app-muted-foreground)">{label(`masters.${key}`)}</div>
                            <div className="mt-1">{value ? <><span className="font-mono text-xs">{value.code}</span> {localizedName(value.name_en, value.name_am, locale)}</> : '—'}</div>
                        </div>
                    );
                })}
                <div><div className="text-xs text-(--app-muted-foreground)">{label('seats')}</div><div className="mt-1 tabular-nums">{lifecycle.occupancy.seats}</div></div>
                <div><div className="text-xs text-(--app-muted-foreground)">{label('holders')}</div><div className="mt-1 tabular-nums">{lifecycle.occupancy.holders}</div></div>
                <div><div className="text-xs text-(--app-muted-foreground)">{label('vacant')}</div><div className="mt-1 tabular-nums">{lifecycle.occupancy.vacant}</div></div>
                <div><div className="text-xs text-(--app-muted-foreground)">{label('createdBy')}</div><div className="mt-1">{lifecycle.created_by ?? '—'}</div></div>
                <div><div className="text-xs text-(--app-muted-foreground)">{label('approvedBy')}</div><div className="mt-1">{lifecycle.approved_by ?? '—'} {lifecycle.approved_at && <LocalizedDateDisplay value={lifecycle.approved_at} withTime />}</div></div>
                {lifecycle.reason && <div><div className="text-xs text-(--app-muted-foreground)">{label('reason')}</div><div className="mt-1 whitespace-pre-wrap">{lifecycle.reason}</div></div>}
            </div>

            <div className="border-t border-(--app-border) px-5 py-4">
                <h3 className="text-sm font-semibold text-(--app-foreground)">{label('history')}</h3>
                {lifecycle.history.length === 0 ? <p className="mt-2 text-sm text-(--app-muted-foreground)">{label('noHistory')}</p> : (
                    <ol className="mt-2 space-y-2 text-sm">
                        {lifecycle.history.map((entry) => (
                            <li key={entry.id} className="border-l-2 border-(--app-border) pl-3">
                                <span className="font-medium">{label(`changeTypes.${entry.change_type}`)}</span>
                                <span className="text-(--app-muted-foreground)"> · {label('by')} {entry.actor ?? '—'} · <LocalizedDateDisplay value={entry.at} withTime /></span>
                                <span className="block text-xs text-(--app-muted-foreground)">
                                    {Object.entries(entry.changes).map(([field, change]) => `${field}: ${String(change.from ?? '—')} → ${String(change.to ?? '—')}`).join('; ')}
                                </span>
                                {entry.reason && <span className="block text-xs">{entry.reason}</span>}
                            </li>
                        ))}
                    </ol>
                )}
            </div>

            <AppDialog
                open={step !== null}
                onClose={() => setStep(null)}
                title={step ? label(`steps.${step.step}`) : label('stepTitle')}
                description={label('stepDescription')}
                footer={(
                    <>
                        <Button variant="outline" onClick={() => setStep(null)} disabled={form.processing}>{label('cancel')}</Button>
                        <Button variant="primary" type="submit" form="position-lifecycle-form" loading={form.processing}>{label('confirm')}</Button>
                    </>
                )}
            >
                <form id="position-lifecycle-form" onSubmit={submit} className="space-y-4">
                    {(form.errors as Record<string, string | undefined>).step && <p className="text-sm text-red-600">{(form.errors as Record<string, string | undefined>).step}</p>}
                    <FormField label={label('reasonField')} required={step?.needs_reason} description={label('reasonHelp')} error={form.errors.reason}>
                        {({ id, describedBy }) => <Textarea id={id} aria-describedby={describedBy} rows={3} maxLength={2000} value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} />}
                    </FormField>
                </form>
            </AppDialog>
        </section>
    );
}
