import { Head, Link, useForm } from '@inertiajs/react';
import { useMemo, type FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import RichTextEditor from '@/Components/forms/RichTextEditor';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { Button, Card, FieldError, FormField, Input, Select, buttonClassName } from '@/Components/ui';
import { isRichTextEmpty } from '@/richtext/policy';
import type { DecisionFormProps } from '@/court/types/decisions';
import { useDecisionText } from './useDecisionText';

interface JudgeSeat {
    admin_user_id: number | null;
}

interface FormData {
    case_id: string;
    case_file_number: string;
    decision_date: string;
    decision_content: string;
    /** The three seats in storage order: 0 first, 1 middle (the signed-in judge), 2 third. */
    judges: JudgeSeat[];
}

/** The seats in the order the page shows them: the session judge first, then the first and third seats. */
const DISPLAY_ORDER = [1, 0, 2] as const;
const SESSION_SEAT = 1;

/**
 * One form for a new and an existing decision: the decision date, the case
 * (closed cases only on create) with the parties and file number filled from
 * it, the three-judge panel with the signed-in judge in the middle seat, and
 * the rich-text decision. Posts to decisions.store / decisions.update.
 */
export default function DecisionForm({ decision, cases, judgeUsers, currentUser, defaults }: DecisionFormProps) {
    const { t, text, fill } = useDecisionText();
    const editing = decision !== null;
    const title = editing ? t('cm.decisions.edit.title') : t('cm.decisions.create.title');
    const seats = (editing ? decision.judges : defaults.judges).map((id): JudgeSeat => ({ admin_user_id: id }));
    while (seats.length < 3) seats.push({ admin_user_id: null });

    const form = useForm<FormData>({
        case_id: editing ? (decision.case_id ? String(decision.case_id) : '') : (defaults.case_id ? String(defaults.case_id) : ''),
        case_file_number: decision?.case_file_number ?? '',
        decision_date: decision?.decision_date ?? defaults.decision_date,
        decision_content: decision?.decision_content ?? '',
        judges: seats.slice(0, 3),
    });
    const errors = form.errors as Record<string, string | undefined>;

    const selectedCase = useMemo(() => cases.find((option) => String(option.id) === form.data.case_id) ?? null, [cases, form.data.case_id]);
    // The parties come from the chosen case; the stored names stand until another case is chosen.
    const applicant = selectedCase ? selectedCase.applicant : (decision?.applicant_full_name ?? '');
    const respondent = selectedCase ? selectedCase.respondent : (decision?.respondent_full_name ?? '');

    const chooseCase = (value: string) => {
        const option = cases.find((candidate) => String(candidate.id) === value) ?? null;
        form.setData((data) => ({ ...data, case_id: value, case_file_number: option ? option.case_number : data.case_file_number }));
    };

    const setSeat = (index: number, value: string) => {
        form.setData('judges', form.data.judges.map((seat, position) => (position === index ? { admin_user_id: value === '' ? null : Number(value) } : seat)));
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (isRichTextEmpty(form.data.decision_content)) {
            form.setError('decision_content', t('cm.decisions.fields.content_required'));
            return;
        }
        form.transform((data) => ({ ...data, case_id: data.case_id === '' ? null : Number(data.case_id) }));
        if (editing) form.patch(route('court.decisions.update', decision.id));
        else form.post(route('court.decisions.store'));
    };

    const truncate = (value: string | null, limit: number): string => (value && value.length > limit ? `${value.slice(0, limit).trimEnd()}...` : (value ?? ''));

    return (
        <AuthenticatedLayout header={(
            <PageHeader
                title={title}
                description={editing ? fill(t('cm.decisions.edit.subtitle'), { name: decision.name ?? '' }) : t('cm.decisions.create.subtitle')}
                backHref={route('court.decisions.index')}
            />
        )}>
            <Head title={title} />

            <form onSubmit={submit} className="mx-auto max-w-7xl">
                <Card className="space-y-6">
                    <div className="flex justify-end">
                        <FormField id="decision-date" label={t('cm.decisions.fields.decision_date')} required error={errors.decision_date} className="w-full md:w-64">
                            {({ id }) => <LocalizedDatePicker id={id} value={form.data.decision_date} onChange={(value) => form.setData('decision_date', value)} required />}
                        </FormField>
                    </div>

                    <div className="grid gap-4 md:grid-cols-2">
                        <FormField id="decision-case" label={t('cm.decisions.fields.case')} required error={errors.case_id}>
                            {({ id, describedBy, invalid }) => (
                                <Select id={id} value={form.data.case_id} onChange={(event) => chooseCase(event.target.value)} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full">
                                    <option value="">{t('cm.decisions.fields.select_case')}</option>
                                    {cases.map((option) => (
                                        <option key={option.id} value={option.id}>{option.case_number} - {truncate(option.title, 60)}</option>
                                    ))}
                                </Select>
                            )}
                        </FormField>
                        <FormField id="decision-case-file-number" label={t('cm.decisions.fields.case_file_number')} error={errors.case_file_number}>
                            {({ id }) => (
                                <Input id={id} value={form.data.case_file_number} onChange={(event) => form.setData('case_file_number', event.target.value)}
                                    placeholder={t('cm.decisions.fields.case_file_number_placeholder')} className="w-full" />
                            )}
                        </FormField>
                    </div>

                    <div className="grid gap-4 md:grid-cols-2">
                        <FormField id="decision-applicant" label={t('cm.decisions.fields.applicant_label')} description={!selectedCase && !applicant ? text('no_case_selected') : undefined}>
                            {({ id }) => <Input id={id} value={applicant} readOnly className="w-full bg-(--app-surface-muted)" />}
                        </FormField>
                        <FormField id="decision-respondent" label={t('cm.decisions.fields.respondent_label')}>
                            {({ id }) => <Input id={id} value={respondent} readOnly className="w-full bg-(--app-surface-muted)" />}
                        </FormField>
                    </div>

                    <section className="space-y-3 rounded-control border border-(--app-border) p-4">
                        <div className="flex items-center justify-between gap-3">
                            <h2 className="text-base font-semibold text-(--app-foreground)">{t('cm.decisions.judges.heading')}</h2>
                            <span className="text-xs uppercase tracking-wide text-(--app-muted-foreground)">{t('cm.decisions.judges.order_hint')}</span>
                        </div>
                        <FieldError>{errors.judges}</FieldError>
                        <div className="grid gap-3 md:grid-cols-3">
                            {DISPLAY_ORDER.map((seatIndex, displayIndex) => {
                                const label = fill(t('cm.decisions.judges.judge'), { number: String(displayIndex + 1) });
                                const seat = form.data.judges[seatIndex];
                                const error = errors[`judges.${seatIndex}.admin_user_id`];
                                if (seatIndex === SESSION_SEAT) {
                                    return (
                                        <FormField key={seatIndex} id={`decision-judge-${seatIndex}`} label={label} error={error}>
                                            {({ id }) => (
                                                <Input id={id} value={currentUser.name ?? t('cm.decisions.judges.current_user')} readOnly className="w-full bg-(--app-surface-muted)" />
                                            )}
                                        </FormField>
                                    );
                                }
                                return (
                                    <FormField key={seatIndex} id={`decision-judge-${seatIndex}`} label={label} error={error}>
                                        {({ id, describedBy, invalid }) => (
                                            <Select id={id} value={seat?.admin_user_id ?? ''} onChange={(event) => setSeat(seatIndex, event.target.value)} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full">
                                                <option value="">{t('cm.decisions.judges.select_judge')}</option>
                                                {judgeUsers.map((judge) => <option key={judge.id} value={judge.id}>{judge.name}</option>)}
                                                {seatIndex === 2 && judgeUsers.length === 0 && <option value="" disabled>{t('cm.decisions.judges.no_teammates')}</option>}
                                            </Select>
                                        )}
                                    </FormField>
                                );
                            })}
                        </div>
                    </section>

                    <RichTextEditor
                        label={t('cm.decisions.fields.final_decision')}
                        value={form.data.decision_content}
                        onChange={(html) => { form.setData('decision_content', html); if (errors.decision_content) form.clearErrors('decision_content'); }}
                        error={errors.decision_content}
                        preset="document"
                        minHeight={400}
                        required
                    />

                    <div className="sticky bottom-0 flex items-center justify-end gap-2 border-t border-(--app-border) bg-(--app-surface) py-3">
                        <Link href={route('court.decisions.index')} className={buttonClassName({ variant: 'outline' })}>{editing ? t('cm.decisions.edit.cancel') : t('cm.decisions.create.cancel')}</Link>
                        <Button type="submit" variant="primary" loading={form.processing}>{editing ? t('cm.decisions.edit.save') : t('cm.decisions.create.save')}</Button>
                    </div>
                </Card>
            </form>
        </AuthenticatedLayout>
    );
}
