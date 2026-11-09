import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Button, Card, FormField, Input, Select, Textarea, buttonClassName } from '@/Components/ui';
import type { DecisionEditReviewProps } from '@/court/types/decisions';
import { useDecisionText } from './useDecisionText';

const NOTE_OUTCOMES = ['reject', 'improve'];

/** The reviewer's own review: the outcome, and the note a difference or an improvement requires. Posts to decisions.reviews.update (PATCH). */
export default function DecisionEditReview({ decision, review }: DecisionEditReviewProps) {
    const { t, fill } = useDecisionText();
    const title = t('cm.decisions.reviews.edit_title');
    const form = useForm<{ outcome: string; review_note: string }>({ outcome: review.outcome, review_note: review.review_note });
    const needsNote = NOTE_OUTCOMES.includes(form.data.outcome);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.patch(route('court.decisions.reviews.update', [decision.id, review.id]));
    };

    return (
        <AuthenticatedLayout header={(
            <PageHeader
                title={title}
                description={fill(t('cm.decisions.reviews.edit_subtitle'), { case: decision.case_number ?? '—', reviewer: review.reviewer_name ?? '—' })}
                backHref={route('court.decisions.show', decision.id)}
            />
        )}>
            <Head title={title} />

            <form onSubmit={submit} className="mx-auto max-w-3xl">
                <Card className="space-y-4">
                    <div className="grid gap-4 md:grid-cols-2">
                        <FormField id="review-outcome" label={t('cm.decisions.reviews.outcome')} error={form.errors.outcome}>
                            {({ id }) => (
                                <Select id={id} value={form.data.outcome} onChange={(event) => form.setData('outcome', event.target.value)} className="w-full">
                                    <option value="approve">{t('cm.decisions.reviews.approve')}</option>
                                    <option value="reject">{t('cm.decisions.reviews.reject')}</option>
                                    <option value="improve">{t('cm.decisions.reviews.improve')}</option>
                                </Select>
                            )}
                        </FormField>
                        <FormField id="review-case-number" label={t('cm.decisions.reviews.case_number')}>
                            {({ id }) => <Input id={id} value={decision.case_number ?? ''} readOnly className="w-full bg-(--app-surface-muted)" />}
                        </FormField>
                    </div>

                    {needsNote && (
                        <FormField id="review-note" label={t('cm.decisions.reviews.review_note')} required error={form.errors.review_note}>
                            {({ id }) => (
                                <Textarea id={id} rows={4} required value={form.data.review_note} onChange={(event) => form.setData('review_note', event.target.value)}
                                    placeholder={form.data.outcome === 'improve' ? t('cm.decisions.reviews.note_placeholder_improve') : t('cm.decisions.reviews.note_placeholder_reject')} className="w-full" />
                            )}
                        </FormField>
                    )}

                    <div className="flex items-center justify-end gap-2 pt-2">
                        <Link href={route('court.decisions.show', decision.id)} className={buttonClassName({ variant: 'outline' })}>{t('cm.decisions.reviews.cancel')}</Link>
                        <Button type="submit" variant="primary" loading={form.processing}>{t('cm.decisions.reviews.update_review')}</Button>
                    </div>
                </Card>
            </form>
        </AuthenticatedLayout>
    );
}
