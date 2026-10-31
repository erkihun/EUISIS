import { useForm } from '@inertiajs/react';
import { useEffect, type FormEvent } from 'react';
import { AppDialog } from '@euisis/ui';
import { Button, FormField, Textarea } from '@/Components/ui';
import type { ReviewDecision } from './HeaderCard';
import { useCaseText } from './useCaseText';

interface Props {
    caseId: number;
    decision: Exclude<ReviewDecision, 'accept'> | null;
    initialNote: string;
    onClose: () => void;
}

/**
 * The "return for correction" / "reject case" dialog: the reason note is
 * required, and the decision goes to cases.review.update (PATCH).
 */
export default function ReviewDialog({ caseId, decision, initialNote, onClose }: Props) {
    const { t } = useCaseText();
    const form = useForm<{ decision: string; note: string }>({ decision: decision ?? 'return', note: initialNote });

    useEffect(() => {
        if (decision) {
            form.setData({ decision, note: initialNote });
            form.clearErrors();
        }
        // The form object is recreated on each render; the dialog only re-seeds when it opens.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [decision, initialNote]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.patch(route('court.cases.review.update', caseId), { preserveScroll: true, onSuccess: () => onClose() });
    };

    return (
        <AppDialog
            open={decision !== null}
            onClose={onClose}
            title={decision === 'reject' ? t('cm.cases.show.reject_case') : t('cm.cases.show.return_for_correction')}
            footer={(
                <>
                    <Button variant="outline" onClick={onClose} disabled={form.processing}>{t('cm.cases.general.cancel')}</Button>
                    <Button variant="primary" form="case-review-form" type="submit" loading={form.processing}>{t('cm.cases.show.submit')}</Button>
                </>
            )}
        >
            <form id="case-review-form" onSubmit={submit} className="space-y-4">
                <FormField id="review-note" label={t('cm.cases.show.reason_note')} required error={form.errors.note}>
                    {({ id, describedBy, invalid }) => (
                        <Textarea id={id} rows={3} required value={form.data.note} onChange={(event) => form.setData('note', event.target.value)}
                            aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full" autoFocus />
                    )}
                </FormField>
            </form>
        </AppDialog>
    );
}
