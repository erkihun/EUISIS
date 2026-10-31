import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Alert, Button, Card, FormField, Input, Select } from '@/Components/ui';
import type { CaseDetailCase } from '@/court/types/caseDetail';
import { useCaseText } from './useCaseText';
import { CheckIcon, StatusIcon } from './icons';

interface Props {
    kase: CaseDetailCase;
    statuses: string[];
}

/**
 * The status change panel (people with cases.edit). A closed case shows the
 * lock notice instead of the form: the server refuses the change too
 * (ClosedCaseStatusLockTest).
 */
export default function StatusPanel({ kase, statuses }: Props) {
    const { t, statusLabel } = useCaseText();
    const initial = kase.review_status === 'awaiting_review' ? 'pending' : kase.status;
    const form = useForm<{ status: string; note: string }>({ status: statuses.includes(initial) ? initial : (statuses[0] ?? 'pending'), note: '' });

    if (kase.status === 'closed') {
        return (
            <Alert tone="success" className="print:hidden" title={t('cm.cases.status.closed_locked_title')}>
                {t('cm.cases.status.closed_locked')}
            </Alert>
        );
    }

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.patch(route('court.cases.status.update', kase.id), { preserveScroll: true, onSuccess: () => form.setData('note', '') });
    };

    return (
        <Card className="print:hidden">
            <h3 className="mb-4 flex items-center gap-2 text-base font-semibold text-(--app-foreground)">
                <StatusIcon className="h-5 w-5 text-primary" /> {t('cm.cases.status.change_case_status')}
            </h3>
            <form onSubmit={submit} className="grid items-end gap-4 md:grid-cols-3">
                <FormField id="case-status" label={t('cm.cases.status.new_status')} error={form.errors.status}>
                    {({ id, describedBy, invalid }) => (
                        <Select id={id} value={form.data.status} onChange={(event) => form.setData('status', event.target.value)} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full">
                            {statuses.map((status) => <option key={status} value={status}>{statusLabel(status)}</option>)}
                        </Select>
                    )}
                </FormField>
                <FormField id="case-status-note" label={t('cm.cases.status.note_to_timeline')} error={form.errors.note}>
                    {({ id, describedBy, invalid }) => (
                        <Input id={id} value={form.data.note} onChange={(event) => form.setData('note', event.target.value)} placeholder={t('cm.cases.status.add_note_placeholder')}
                            aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full" />
                    )}
                </FormField>
                <div>
                    <Button type="submit" variant="primary" loading={form.processing} icon={<CheckIcon className="h-4 w-4" />}>{t('cm.cases.status.update_status')}</Button>
                </div>
            </form>
        </Card>
    );
}
