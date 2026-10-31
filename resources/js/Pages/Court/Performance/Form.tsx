import { Head, Link, router, useForm } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useState, type FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { Alert, Button, Card, FieldError, FormField, Select, Textarea, badgeClassName, buttonClassName, cx } from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { usePerformanceText } from '@/court/i18n/usePerformanceText';
import { useCalendarSystem } from '@/lib/calendar/calendarSystem';
import { formatDateDisplay } from '@/lib/calendar/dateFormat';
import type { EvaluationPeriodType, PerformanceFormProps } from '@/court/types/performance';
import { PERIOD_TYPES, criterionTextClass } from './score';

interface ScoreInput {
    criterion_id: number;
    score: number;
    comment: string;
}

interface EvaluationFormData {
    evaluated_user_id: string;
    period_type: EvaluationPeriodType;
    period_start: string;
    period_end: string;
    notes: string;
    scores: ScoreInput[];
}

type Action = 'draft' | 'submitted';

/** A member's appeal outcomes over the period (AppealRecords::judgeRecord); score null when none. */
interface AppealRecord {
    total: number;
    confirmed: number;
    modified: number;
    reversed: number;
    score: number | null;
}

/**
 * One form for a new evaluation and for editing a draft or submitted one: the
 * member, the period and notes, then each active criterion scored 0-10 on a
 * slider with an optional comment. "Save as Draft" and "Submit for Review"
 * send the same fields with `action` draft or submitted; the server weights
 * the scores into the overall score. Editing also offers Delete.
 */
export default function PerformanceForm({ evaluation, users, criteria, scores, can }: PerformanceFormProps) {
    const { p, text, locale } = usePerformanceText();
    const { confirm } = useConfirm();
    const editing = evaluation !== null;
    const saved = new Map(scores.map((score) => [score.criterion_id, score]));
    const form = useForm<EvaluationFormData>({
        evaluated_user_id: evaluation ? String(evaluation.evaluated_user_id) : '',
        period_type: evaluation?.period_type ?? 'monthly',
        period_start: evaluation?.period_start ?? '',
        period_end: evaluation?.period_end ?? '',
        notes: evaluation?.notes ?? '',
        scores: criteria.map((criterion) => ({
            criterion_id: criterion.id,
            score: saved.get(criterion.id)?.score ?? 5,
            comment: saved.get(criterion.id)?.comment ?? '',
        })),
    });
    const errors = form.errors as Record<string, string | undefined>;
    const title = editing ? p('edit_evaluation') : p('new_evaluation');
    const back = editing ? route('court.performance-evaluations.show', evaluation.id) : route('court.performance-evaluations.index');
    const system = useCalendarSystem();
    const description = editing
        ? [evaluation.member_name, [evaluation.period_start, evaluation.period_end].map((date) => (date ? formatDateDisplay(date, system, locale) : p('not_available'))).join(' – ')].filter(Boolean).join(' · ')
        : p('subtitle');

    // The appeal outcomes this evaluation would count (EUISIS ADR 0013 #14), read for the chosen member and period.
    const [appealRecord, setAppealRecord] = useState<AppealRecord | null>(null);
    const hasAutomatic = criteria.some((criterion) => criterion.source === 'appeal_outcomes');
    const { evaluated_user_id: member, period_start: start, period_end: end } = form.data;
    useEffect(() => {
        if (!hasAutomatic || member === '' || start === '' || end === '') {
            setAppealRecord(null);
            return;
        }
        const controller = new AbortController();
        axios.get<{ data: AppealRecord }>(route('court.performance-evaluations.appeal-record'), { params: { user: member, start, end }, signal: controller.signal })
            .then((response) => setAppealRecord(response.data.data))
            .catch(() => undefined);
        return () => controller.abort();
    }, [hasAutomatic, member, start, end]);

    const setScore = (index: number, patch: Partial<ScoreInput>) => {
        form.setData('scores', form.data.scores.map((score, i) => (i === index ? { ...score, ...patch } : score)));
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const submitter = (event.nativeEvent as SubmitEvent).submitter as HTMLButtonElement | null;
        const action: Action = submitter?.value === 'submitted' ? 'submitted' : 'draft';
        form.transform((data) => ({ ...data, action }));
        if (editing) form.patch(route('court.performance-evaluations.update', evaluation.id));
        else form.post(route('court.performance-evaluations.store'));
    };

    const destroy = async () => {
        if (!editing) return;
        const { confirmed } = await confirm({
            title: p('actions.delete'),
            description: p('confirm.delete'),
            confirmLabel: p('actions.delete'),
            cancelLabel: p('actions.cancel'),
            variant: 'danger',
        });
        if (confirmed) router.delete(route('court.performance-evaluations.destroy', evaluation.id));
    };

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={description} backHref={back} />}>
            <Head title={editing ? p('edit_title') : p('new_title')} />

            <form onSubmit={submit} className="mx-auto max-w-3xl space-y-4">
                <Card className="space-y-4">
                    <h2 className="text-sm font-semibold text-(--app-foreground)">{p('details')}</h2>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <FormField label={p('fields.staff_member')} required error={errors.evaluated_user_id} className="sm:col-span-2">
                            {({ id, describedBy, invalid }) => (
                                <Select id={id} value={form.data.evaluated_user_id} onChange={(event) => form.setData('evaluated_user_id', event.target.value)} required
                                    aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full">
                                    <option value="">{p('placeholders.select_member')}</option>
                                    {users.map((user) => <option key={user.id} value={String(user.id)}>{user.name}</option>)}
                                </Select>
                            )}
                        </FormField>
                        <FormField label={p('fields.period_type')} required error={errors.period_type}>
                            {({ id, describedBy, invalid }) => (
                                <Select id={id} value={form.data.period_type} onChange={(event) => form.setData('period_type', event.target.value as EvaluationPeriodType)} required
                                    aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full">
                                    {PERIOD_TYPES.map((period) => <option key={period} value={period}>{p(`periods.${period}`)}</option>)}
                                </Select>
                            )}
                        </FormField>
                        <div className="hidden sm:block" aria-hidden="true" />
                        <FormField label={p('fields.period_start')} required error={errors.period_start}>
                            {({ id }) => <LocalizedDatePicker id={id} name="period_start" value={form.data.period_start} onChange={(value) => form.setData('period_start', value)} required />}
                        </FormField>
                        <FormField label={p('fields.period_end')} required error={errors.period_end}>
                            {({ id }) => (
                                <LocalizedDatePicker id={id} name="period_end" value={form.data.period_end} onChange={(value) => form.setData('period_end', value)}
                                    min={form.data.period_start || undefined} required />
                            )}
                        </FormField>
                        <FormField label={p('fields.general_notes')} error={errors.notes} className="sm:col-span-2">
                            {({ id, describedBy, invalid }) => (
                                <Textarea id={id} rows={3} value={form.data.notes} onChange={(event) => form.setData('notes', event.target.value)} maxLength={2000}
                                    placeholder={p('placeholders.general_notes')} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full" />
                            )}
                        </FormField>
                    </div>
                </Card>

                <Card className="space-y-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-sm font-semibold text-(--app-foreground)">{p('criteria_scores')}</h2>
                        <span className="text-xs text-(--app-muted-foreground)">{editing ? p('score_hint_short') : p('score_hint')}</span>
                    </div>
                    {(errors.scores || errors.action) && <Alert tone="danger">{errors.scores ?? errors.action}</Alert>}

                    {criteria.map((criterion, index) => {
                        const value = form.data.scores[index];
                        if (!value) return null;
                        const sliderId = `criterion-${criterion.id}-score`;
                        if (criterion.source === 'appeal_outcomes') {
                            return (
                                <div key={criterion.id} className="space-y-2 rounded-lg border border-dashed border-(--app-border) p-4">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <div className="flex flex-wrap items-center gap-2">
                                                {criterion.category_label && <span className={badgeClassName('neutral', 'uppercase')}>{criterion.category_label}</span>}
                                                <span className={badgeClassName('info')}>{p('appeal_outcomes.automatic')}</span>
                                                <span className="text-xs text-(--app-muted-foreground)">{p('fields.weight')}: {criterion.weight}%</span>
                                            </div>
                                            <p className="mt-1 text-sm font-semibold text-(--app-foreground)">{criterion.name}</p>
                                            <p className="mt-0.5 text-xs text-(--app-muted-foreground)">{p('appeal_outcomes.counting')}</p>
                                        </div>
                                        {appealRecord?.score !== null && appealRecord?.score !== undefined && (
                                            <div className="w-12 shrink-0 text-center" aria-hidden="true">
                                                <div className={cx('text-3xl font-extrabold tabular-nums', criterionTextClass(appealRecord.score))}>{appealRecord.score}</div>
                                                <div className="text-[10px] text-(--app-muted-foreground)">/10</div>
                                            </div>
                                        )}
                                    </div>
                                    <p role="status" className="text-sm text-(--app-foreground)">
                                        {appealRecord === null
                                            ? p('appeal_outcomes.pick')
                                            : appealRecord.score === null
                                                ? p('appeal_outcomes.none')
                                                : p('appeal_outcomes.result', { ...appealRecord, score: appealRecord.score })}
                                    </p>
                                </div>
                            );
                        }
                        return (
                            <div key={criterion.id} className="space-y-3 rounded-lg border border-(--app-border) bg-(--app-surface-muted) p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            {criterion.category_label && <span className={badgeClassName('neutral', 'uppercase')}>{criterion.category_label}</span>}
                                            <span className="text-xs text-(--app-muted-foreground)">{p('fields.weight')}: {criterion.weight}%</span>
                                        </div>
                                        <label htmlFor={sliderId} className="mt-1 block text-sm font-semibold text-(--app-foreground)">{criterion.name}</label>
                                        {criterion.description && <p className="mt-0.5 text-xs text-(--app-muted-foreground)">{criterion.description}</p>}
                                    </div>
                                    <div className="w-12 shrink-0 text-center" aria-hidden="true">
                                        <div className={cx('text-3xl font-extrabold tabular-nums', criterionTextClass(value.score))}>{value.score}</div>
                                        <div className="text-[10px] text-(--app-muted-foreground)">/10</div>
                                    </div>
                                </div>

                                <div>
                                    <input id={sliderId} type="range" min={0} max={10} step={1} value={value.score}
                                        onChange={(event) => setScore(index, { score: Number(event.target.value) })}
                                        aria-valuetext={text('score_value', { score: value.score })}
                                        className="w-full cursor-pointer accent-primary" />
                                    <div className="mt-1 flex justify-between text-[10px] text-(--app-muted-foreground)" aria-hidden="true">
                                        {Array.from({ length: 11 }, (_, n) => <span key={n}>{n}</span>)}
                                    </div>
                                    <FieldError>{errors[`scores.${index}.score`] ?? errors[`scores.${index}.criterion_id`]}</FieldError>
                                </div>

                                <div>
                                    <Textarea rows={1} value={value.comment} onChange={(event) => setScore(index, { comment: event.target.value })} maxLength={500}
                                        aria-label={`${criterion.name}: ${editing ? p('placeholders.comment') : p('placeholders.criterion_comment')}`}
                                        placeholder={editing ? p('placeholders.comment') : p('placeholders.criterion_comment')}
                                        className="min-h-9 w-full resize-none text-xs" />
                                    <FieldError>{errors[`scores.${index}.comment`]}</FieldError>
                                </div>
                            </div>
                        );
                    })}
                </Card>

                <Card className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex flex-wrap gap-2">
                        <Link href={back} className={buttonClassName({ variant: 'outline' })}>{p('actions.cancel')}</Link>
                        {editing && can.delete && (
                            <Button type="button" variant="destructiveOutline" onClick={() => void destroy()} disabled={form.processing}>{p('actions.delete')}</Button>
                        )}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button type="submit" name="action" value="draft" variant="outline" disabled={form.processing}>{p('actions.save_draft')}</Button>
                        <Button type="submit" name="action" value="submitted" variant="primary" disabled={form.processing}>{p('actions.submit_review')}</Button>
                    </div>
                </Card>
            </form>
        </AuthenticatedLayout>
    );
}
