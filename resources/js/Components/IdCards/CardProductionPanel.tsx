import { Link, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import StatusBadge from '@/Components/StatusBadge';
import { useLocale } from '@/hooks/useLocale';
import { AppDialog, Button, FormField, Textarea } from '@euisis/ui';

export type CardProduction = {
    template: { code: string | null; version: number; status: string } | null;
    print_count: number;
    reprint_required: boolean;
    qc: { status: string; at: string | null; notes: string | null; by: string | null };
    credential: { status: string; kind: string; key_version: number; issued_at: string | null; last_verified_at: string | null; verification_count: number } | null;
    preview_url: string;
    urls: { quality: string | null; reprint: string | null; issue_credential: string | null; rotate_credential: string | null; revoke_credential: string | null };
};

type Step = { url: string; title: string; field: 'qc_notes' | 'reason'; data?: Record<string, string>; required: boolean };

const QC_TONE: Record<string, string> = { pending: 'pending', passed: 'active', failed: 'danger', not_recorded: 'info' };

/**
 * Core only (ADR 0010): how the card was produced — template version, prints,
 * quality check, credential — and the production steps this reader may take.
 */
export default function CardProductionPanel({ production }: { production: CardProduction }) {
    const { t } = useLocale();
    const label = (key: string) => t(`core.cardProduction.${key}`);
    const [step, setStep] = useState<Step | null>(null);
    const form = useForm<Record<string, string>>({});

    const open = (next: Step) => {
        form.setData({ ...(next.data ?? {}), [next.field]: '' });
        form.clearErrors();
        setStep(next);
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (step) form.post(step.url, { preserveScroll: true, onSuccess: () => setStep(null) });
    };
    const { urls } = production;

    return (
        <section className="mb-5 rounded-panel border border-(--app-border) bg-(--app-surface) p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <h2 className="font-semibold text-(--app-foreground)">{label('title')}</h2>
                <Link href={production.preview_url} className="text-sm text-primary hover:underline">{label('preview')}</Link>
            </div>
            <dl className="mt-4 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div><dt className="text-xs text-(--app-muted-foreground)">{label('template')}</dt><dd className="mt-1">{production.template ? `${production.template.code ?? '—'} · ${label('version')} ${production.template.version}` : '—'}</dd></div>
                <div><dt className="text-xs text-(--app-muted-foreground)">{label('prints')}</dt><dd className="mt-1 tabular-nums">{production.print_count}{production.reprint_required && <span className="ml-2"><StatusBadge status="warning" label={label('reprintRequired')} /></span>}</dd></div>
                <div>
                    <dt className="text-xs text-(--app-muted-foreground)">{label('qualityCheck')}</dt>
                    <dd className="mt-1"><StatusBadge status={QC_TONE[production.qc.status] ?? 'info'} label={label(`qcStatuses.${production.qc.status}`)} />
                        {production.qc.by && <span className="ml-1 text-xs text-(--app-muted-foreground)">{production.qc.by} · <LocalizedDateDisplay value={production.qc.at} withTime /></span>}
                        {production.qc.notes && <span className="block text-xs">{production.qc.notes}</span>}
                    </dd>
                </div>
                <div>
                    <dt className="text-xs text-(--app-muted-foreground)">{label('credential')}</dt>
                    <dd className="mt-1">{production.credential ? (
                        <>
                            <StatusBadge status={production.credential.status === 'active' ? 'active' : 'inactive'} label={label(`credentialStatuses.${production.credential.status}`)} />
                            <span className="block text-xs text-(--app-muted-foreground)">{label('keyVersion')} {production.credential.key_version} · {label('checks')} {production.credential.verification_count}</span>
                        </>
                    ) : '—'}</dd>
                </div>
            </dl>
            <div className="mt-4 flex flex-wrap gap-2">
                {urls.quality && <Button size="sm" variant="primary" onClick={() => open({ url: urls.quality as string, title: label('pass'), field: 'qc_notes', data: { result: 'passed' }, required: false })}>{label('pass')}</Button>}
                {urls.quality && <Button size="sm" variant="outline" onClick={() => open({ url: urls.quality as string, title: label('fail'), field: 'qc_notes', data: { result: 'failed' }, required: true })}>{label('fail')}</Button>}
                {urls.reprint && <Button size="sm" variant="outline" onClick={() => open({ url: urls.reprint as string, title: label('reprint'), field: 'reason', required: true })}>{label('reprint')}</Button>}
                {urls.issue_credential && <Button size="sm" variant="outline" onClick={() => open({ url: urls.issue_credential as string, title: label('issueCredential'), field: 'reason', required: false })}>{label('issueCredential')}</Button>}
                {urls.rotate_credential && <Button size="sm" variant="outline" onClick={() => open({ url: urls.rotate_credential as string, title: label('rotateCredential'), field: 'reason', required: true })}>{label('rotateCredential')}</Button>}
                {urls.revoke_credential && <Button size="sm" variant="outline" onClick={() => open({ url: urls.revoke_credential as string, title: label('revokeCredential'), field: 'reason', required: true })}>{label('revokeCredential')}</Button>}
            </div>

            <AppDialog open={step !== null} onClose={() => setStep(null)} title={step?.title ?? ''} description={label('stepDescription')}
                footer={<><Button variant="outline" onClick={() => setStep(null)} disabled={form.processing}>{label('cancel')}</Button><Button variant="primary" type="submit" form="card-production-form" loading={form.processing}>{label('confirm')}</Button></>}>
                <form id="card-production-form" onSubmit={submit} className="space-y-3">
                    {(form.errors as Record<string, string | undefined>).card && <p className="text-sm text-red-600">{(form.errors as Record<string, string | undefined>).card}</p>}
                    {step && (
                        <FormField label={label(step.field === 'qc_notes' ? 'notes' : 'reason')} required={step.required} error={form.errors[step.field]}>
                            {({ id }) => <Textarea id={id} rows={3} maxLength={1000} value={form.data[step.field] ?? ''} onChange={(event) => form.setData(step.field, event.target.value)} />}
                        </FormField>
                    )}
                </form>
            </AppDialog>
        </section>
    );
}
