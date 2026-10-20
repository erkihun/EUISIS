import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Alert, Button, Card, FormField, Input, Select } from '@euisis/ui';
import { Head, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';
import { SUBMISSION_TYPES, type SubmissionType } from '../Categories/Form';
import type { QuestionnaireStatus } from './Index';

type QuestionType = 'rating' | 'single_choice' | 'multiple_choice' | 'short_text' | 'long_text' | 'yes_no';
type Section = 'experience' | 'details';

type OptionRow = { code: string; label_en: string; label_am: string };

type QuestionRow = {
    code: string;
    section: Section;
    question_type: QuestionType;
    label_en: string;
    label_am: string;
    help_en: string;
    help_am: string;
    is_required: boolean;
    submission_types: SubmissionType[];
    options: OptionRow[];
};

type Props = {
    questionSet: {
        id: string;
        version_no: number;
        status: QuestionnaireStatus;
        name_en: string;
        name_am: string;
        published_at: string | null;
        questions: QuestionRow[];
    };
    editable: boolean;
};

const QUESTION_TYPES: QuestionType[] = ['rating', 'single_choice', 'multiple_choice', 'short_text', 'long_text', 'yes_no'];
const SECTIONS: Section[] = ['experience', 'details'];
const BADGE: Record<QuestionnaireStatus, string> = { draft: 'draft', published: 'active', retired: 'inactive' };

const hasOptions = (type: QuestionType) => type === 'single_choice' || type === 'multiple_choice';

const blankQuestion = (): QuestionRow => ({
    code: '', section: 'experience', question_type: 'rating', label_en: '', label_am: '', help_en: '', help_am: '',
    is_required: false, submission_types: ['feedback'], options: [],
});

/**
 * One version of the questionnaire. A draft is edited and saved whole; a
 * published or withdrawn version is shown read-only, because submissions
 * answered exactly its wording.
 */
export default function QuestionnaireEdit({ questionSet, editable }: Props) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const form = useForm({
        name_en: questionSet.name_en,
        name_am: questionSet.name_am,
        questions: questionSet.questions,
    });
    const errors = form.errors as Record<string, string | undefined>;
    const questions = form.data.questions;

    const update = (index: number, patch: Partial<QuestionRow>) => {
        form.setData('questions', questions.map((question, position) => (position === index ? { ...question, ...patch } : question)));
    };
    const move = (index: number, offset: number) => {
        const target = index + offset;
        if (target < 0 || target >= questions.length) return;
        const next = [...questions];
        [next[index], next[target]] = [next[target], next[index]];
        form.setData('questions', next);
    };
    const updateOption = (index: number, optionIndex: number, patch: Partial<OptionRow>) => {
        update(index, { options: questions[index].options.map((option, position) => (position === optionIndex ? { ...option, ...patch } : option)) });
    };

    function save(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            questions: data.questions.map((question) => ({ ...question, options: hasOptions(question.question_type) ? question.options : [] })),
        }));
        form.put(route('service-feedback.questionnaire.update', questionSet.id), { preserveScroll: true });
    }

    async function publish() {
        const result = await confirm({ title: t('serviceFeedback.questionnairePublishConfirm'), variant: 'warning' });
        if (result.confirmed) router.post(route('service-feedback.questionnaire.publish', questionSet.id), {}, { preserveScroll: true });
    }

    const title = t('serviceFeedback.questionnaireVersionN').replace('{n}', String(questionSet.version_no));

    return (
        <AuthenticatedLayout
            contentWidth="form"
            header={<PageHeader title={`${t('serviceFeedback.questionnaireTitle')} · ${title}`} backHref={route('service-feedback.questionnaire.index')} />}
        >
            <Head title={`${t('serviceFeedback.questionnaireTitle')} · ${title}`} />

            <form onSubmit={save} className="space-y-5">
                <div className="flex flex-wrap items-center gap-3">
                    <StatusBadge status={BADGE[questionSet.status]} label={t(`serviceFeedback.questionnaireStatus_${questionSet.status}`)} />
                </div>
                {!editable && <Alert tone="info">{t('serviceFeedback.questionnaireReadOnly')}</Alert>}
                {editable && <Alert tone="warning">{t('serviceFeedback.questionnaireTemplateNote')}</Alert>}
                {errors.questionnaire && <Alert tone="danger">{errors.questionnaire}</Alert>}

                <Card>
                    <fieldset disabled={!editable} className="grid gap-4 p-6 sm:grid-cols-2">
                        <FormField id="questionnaire-name-en" label={`${t('serviceFeedback.questionnaireName')} (EN)`} error={errors.name_en} required>
                            {({ id, describedBy, invalid }) => <Input id={id} aria-describedby={describedBy} aria-invalid={invalid} value={form.data.name_en} maxLength={255} onChange={(e) => form.setData('name_en', e.target.value)} />}
                        </FormField>
                        <FormField id="questionnaire-name-am" label={`${t('serviceFeedback.questionnaireName')} (አማ)`} error={errors.name_am}>
                            {({ id, describedBy, invalid }) => <Input id={id} aria-describedby={describedBy} aria-invalid={invalid} value={form.data.name_am} maxLength={255} onChange={(e) => form.setData('name_am', e.target.value)} />}
                        </FormField>
                    </fieldset>
                </Card>

                {questions.length === 0 && <Card><p className="p-6 text-sm text-(--app-muted-foreground)">{t('serviceFeedback.noQuestions')}</p></Card>}

                {questions.map((question, index) => {
                    const prefix = `questions.${index}`;
                    const field = (name: string) => errors[`${prefix}.${name}`];

                    return (
                        <Card key={index}>
                            <fieldset disabled={!editable} className="space-y-4 p-6">
                                <legend className="sr-only">{question.label_en || question.code || `#${index + 1}`}</legend>
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <p className="font-semibold text-(--app-foreground)">#{index + 1} {question.code && <span className="font-mono text-xs text-(--app-muted-foreground)">{question.code}</span>}</p>
                                    {editable && (
                                        <div className="flex gap-2">
                                            <Button type="button" variant="outline" size="sm" onClick={() => move(index, -1)} disabled={index === 0}>{t('serviceFeedback.moveUp')}</Button>
                                            <Button type="button" variant="outline" size="sm" onClick={() => move(index, 1)} disabled={index === questions.length - 1}>{t('serviceFeedback.moveDown')}</Button>
                                            <Button type="button" variant="destructive" size="sm" onClick={() => form.setData('questions', questions.filter((_, position) => position !== index))}>{t('serviceFeedback.removeQuestion')}</Button>
                                        </div>
                                    )}
                                </div>
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <FormField id={`q-${index}-code`} label={t('serviceFeedback.questionCode')} description={t('serviceFeedback.questionCodeHint')} error={field('code')} required>
                                        {({ id, describedBy, invalid }) => <Input id={id} aria-describedby={describedBy} aria-invalid={invalid} value={question.code} maxLength={40} onChange={(e) => update(index, { code: e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, '_') })} />}
                                    </FormField>
                                    <FormField id={`q-${index}-type`} label={t('serviceFeedback.questionType')} error={field('question_type')} required>
                                        {({ id, describedBy, invalid }) => (
                                            <Select id={id} aria-describedby={describedBy} aria-invalid={invalid} value={question.question_type} onChange={(e) => update(index, { question_type: e.target.value as QuestionType })}>
                                                {QUESTION_TYPES.map((type) => <option key={type} value={type}>{t(`serviceFeedback.questionType_${type}`)}</option>)}
                                            </Select>
                                        )}
                                    </FormField>
                                    <FormField id={`q-${index}-section`} label={t('serviceFeedback.questionSection')} error={field('section')} required>
                                        {({ id, describedBy, invalid }) => (
                                            <Select id={id} aria-describedby={describedBy} aria-invalid={invalid} value={question.section} onChange={(e) => update(index, { section: e.target.value as Section })}>
                                                {SECTIONS.map((section) => <option key={section} value={section}>{t(`serviceFeedback.questionSection_${section}`)}</option>)}
                                            </Select>
                                        )}
                                    </FormField>
                                </div>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <FormField id={`q-${index}-label-en`} label={t('serviceFeedback.questionLabelEn')} error={field('label_en')} required>
                                        {({ id, describedBy, invalid }) => <Input id={id} aria-describedby={describedBy} aria-invalid={invalid} value={question.label_en} maxLength={300} onChange={(e) => update(index, { label_en: e.target.value })} />}
                                    </FormField>
                                    <FormField id={`q-${index}-label-am`} label={t('serviceFeedback.questionLabelAm')} error={field('label_am')}>
                                        {({ id, describedBy, invalid }) => <Input id={id} lang="am" aria-describedby={describedBy} aria-invalid={invalid} value={question.label_am} maxLength={300} onChange={(e) => update(index, { label_am: e.target.value })} />}
                                    </FormField>
                                    <FormField id={`q-${index}-help-en`} label={t('serviceFeedback.questionHelpEn')} error={field('help_en')}>
                                        {({ id, describedBy, invalid }) => <Input id={id} aria-describedby={describedBy} aria-invalid={invalid} value={question.help_en} maxLength={500} onChange={(e) => update(index, { help_en: e.target.value })} />}
                                    </FormField>
                                    <FormField id={`q-${index}-help-am`} label={t('serviceFeedback.questionHelpAm')} error={field('help_am')}>
                                        {({ id, describedBy, invalid }) => <Input id={id} lang="am" aria-describedby={describedBy} aria-invalid={invalid} value={question.help_am} maxLength={500} onChange={(e) => update(index, { help_am: e.target.value })} />}
                                    </FormField>
                                </div>
                                <div className="flex flex-wrap gap-6">
                                    <fieldset className="space-y-1.5">
                                        <legend className="text-sm font-medium text-(--app-foreground)">{t('serviceFeedback.appliesToTypes')}</legend>
                                        {SUBMISSION_TYPES.map((item) => (
                                            <label key={item.value} className="flex items-center gap-2 text-sm">
                                                <input
                                                    type="checkbox"
                                                    checked={question.submission_types.includes(item.value)}
                                                    onChange={() => update(index, {
                                                        submission_types: question.submission_types.includes(item.value)
                                                            ? question.submission_types.filter((type) => type !== item.value)
                                                            : [...question.submission_types, item.value],
                                                    })}
                                                    className="h-4 w-4 rounded-sm border-(--app-border-strong)"
                                                />
                                                {t(`serviceFeedback.${item.key}`)}
                                            </label>
                                        ))}
                                        {field('submission_types') && <p role="alert" className="text-sm text-red-600">{field('submission_types')}</p>}
                                    </fieldset>
                                    <label className="flex items-center gap-2 self-start text-sm">
                                        <input type="checkbox" checked={question.is_required} onChange={(e) => update(index, { is_required: e.target.checked })} className="h-4 w-4 rounded-sm border-(--app-border-strong)" />
                                        {t('serviceFeedback.questionRequired')}
                                    </label>
                                </div>

                                {hasOptions(question.question_type) && (
                                    <div className="space-y-3 rounded-lg border border-(--app-border) p-4">
                                        <p className="text-sm font-medium text-(--app-foreground)">{t('serviceFeedback.questionOptions')}</p>
                                        {question.options.map((option, optionIndex) => (
                                            <div key={optionIndex} className="grid items-end gap-3 sm:grid-cols-[1fr_2fr_2fr_auto]">
                                                <FormField id={`q-${index}-o-${optionIndex}-code`} label={t('serviceFeedback.questionCode')} error={field(`options.${optionIndex}.code`)} required>
                                                    {({ id, describedBy, invalid }) => <Input id={id} aria-describedby={describedBy} aria-invalid={invalid} value={option.code} maxLength={40} onChange={(e) => updateOption(index, optionIndex, { code: e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, '_') })} />}
                                                </FormField>
                                                <FormField id={`q-${index}-o-${optionIndex}-en`} label={t('serviceFeedback.optionLabelEn')} error={field(`options.${optionIndex}.label_en`)} required>
                                                    {({ id, describedBy, invalid }) => <Input id={id} aria-describedby={describedBy} aria-invalid={invalid} value={option.label_en} maxLength={200} onChange={(e) => updateOption(index, optionIndex, { label_en: e.target.value })} />}
                                                </FormField>
                                                <FormField id={`q-${index}-o-${optionIndex}-am`} label={t('serviceFeedback.optionLabelAm')} error={field(`options.${optionIndex}.label_am`)}>
                                                    {({ id, describedBy, invalid }) => <Input id={id} lang="am" aria-describedby={describedBy} aria-invalid={invalid} value={option.label_am} maxLength={200} onChange={(e) => updateOption(index, optionIndex, { label_am: e.target.value })} />}
                                                </FormField>
                                                {editable && (
                                                    <Button type="button" variant="outline" size="sm" onClick={() => update(index, { options: question.options.filter((_, position) => position !== optionIndex) })}>{t('serviceFeedback.removeOption')}</Button>
                                                )}
                                            </div>
                                        ))}
                                        {editable && (
                                            <Button type="button" variant="outline" size="sm" onClick={() => update(index, { options: [...question.options, { code: '', label_en: '', label_am: '' }] })}>{t('serviceFeedback.addOption')}</Button>
                                        )}
                                    </div>
                                )}
                            </fieldset>
                        </Card>
                    );
                })}

                {editable && (
                    <div className="flex flex-wrap justify-between gap-2">
                        <Button type="button" variant="outline" onClick={() => form.setData('questions', [...questions, blankQuestion()])}>{t('serviceFeedback.addQuestion')}</Button>
                        <div className="flex gap-2">
                            <Button type="submit" variant="outline" loading={form.processing}>{t('serviceFeedback.saveDraft')}</Button>
                            <Button type="button" variant="primary" onClick={publish} disabled={form.isDirty || form.processing}>{t('serviceFeedback.questionnairePublish')}</Button>
                        </div>
                    </div>
                )}
            </form>
        </AuthenticatedLayout>
    );
}
