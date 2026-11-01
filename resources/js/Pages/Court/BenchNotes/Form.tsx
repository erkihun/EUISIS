import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import RichTextEditor from '@/Components/forms/RichTextEditor';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { Alert, Button, Card, FormDescription, FormField, Input, Select, buttonClassName } from '@/Components/ui';
import { useCalendarSystem } from '@/lib/calendar/calendarSystem';
import { formatDateDisplay } from '@/lib/calendar/dateFormat';
import { useInspectionPagesText } from '@/court/i18n/useInspectionPagesText';
import type { BenchNoteFormProps } from '@/court/types/benchNotes';

type Seat = { admin_user_id: string };

/**
 * One form for a new and an existing bench note: case, title, date, the panel
 * of three judges (the first seat is the writer) and the rich-text note.
 */
export default function BenchNoteForm({ note, cases, judgeUsers, thirdJudgeUsers, noTeammates, currentUser, prefill }: BenchNoteFormProps) {
    const { t, text, fill, locale } = useInspectionPagesText();
    const system = useCalendarSystem();
    const editing = note !== null;
    const seatOf = (index: number): Seat => {
        const stored = note?.judges[index]?.id;
        if (stored) return { admin_user_id: String(stored) };
        return { admin_user_id: index === 0 && currentUser ? String(currentUser.id) : '' };
    };
    const form = useForm<{ case_id: string; title: string; note_date: string; note: string; judges: [Seat, Seat, Seat] }>({
        case_id: note ? String(note.case_id) : prefill.case_id,
        title: note?.title ?? '',
        note_date: note?.note_date ?? '',
        note: note?.note ?? '',
        judges: [seatOf(0), seatOf(1), seatOf(2)],
    });
    const title = editing ? t('cm.bench.headings.edit') : t('cm.bench.headings.create');
    const listHref = route('court.bench-notes.index', note ? { case_id: note.case_id } : prefill.case_id ? { case_id: prefill.case_id } : {});
    const firstJudgeName = judgeUsers.find((judge) => String(judge.id) === form.data.judges[0].admin_user_id)?.name
        ?? note?.judges[0]?.name ?? currentUser?.name ?? text('current_judge');

    const setSeat = (index: number, value: string) => {
        const judges = [...form.data.judges] as [Seat, Seat, Seat];
        judges[index] = { admin_user_id: value };
        form.setData('judges', judges);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        // Empty seats and an empty date post as null, as the Blade form's empty fields did.
        form.transform((data) => ({
            ...data,
            note_date: data.note_date === '' ? null : data.note_date,
            judges: data.judges.map((seat) => ({ admin_user_id: seat.admin_user_id === '' ? null : Number(seat.admin_user_id) })),
        }));
        if (editing) form.patch(route('court.bench-notes.update', note.id));
        else form.post(route('court.bench-notes.store'));
    };

    const seatError = (index: number): string | undefined => (form.errors as Record<string, string | undefined>)[`judges.${index}.admin_user_id`];

    return (
        <AuthenticatedLayout header={(
            <PageHeader title={title} description={editing ? t('cm.bench.descriptions.edit') : t('cm.bench.descriptions.create')} backHref={listHref}
                actions={<Link href={listHref} className={buttonClassName({ variant: 'outline' })}>{editing ? t('cm.bench.buttons.back_list') : t('cm.bench.buttons.back')}</Link>} />
        )}>
            <Head title={t('cm.bench.title')} />

            <form onSubmit={submit} className="mx-auto w-full max-w-5xl space-y-4">
                <Card className="space-y-6">
                    <div>
                        <h2 className="text-lg font-semibold text-(--app-foreground)">{editing ? fill(t('cm.bench.headings.edit_note'), { title: note.title ?? '' }) : t('cm.bench.headings.new')}</h2>
                        <p className="mt-1 text-sm text-(--app-muted-foreground)">{editing ? t('cm.bench.headings.edit_intro') : t('cm.bench.headings.create_intro')}</p>
                    </div>

                    {editing && (
                        <dl className="grid gap-3 rounded-control border border-(--app-border) bg-(--app-surface-muted) p-4 text-sm sm:grid-cols-3">
                            <div><dt className="text-xs text-(--app-muted-foreground)">{t('cm.bench.labels.author')}</dt><dd className="font-medium text-(--app-foreground)">{note.author_name ?? t('cm.bench.meta.unknown')}</dd></div>
                            <div><dt className="text-xs text-(--app-muted-foreground)">{t('cm.bench.labels.created')}</dt><dd className="font-medium text-(--app-foreground)"><LocalizedDateDisplay value={note.created_at} withTime /></dd></div>
                            {note.updated_at && (
                                <div><dt className="text-xs text-(--app-muted-foreground)">{t('cm.bench.labels.last_updated')}</dt><dd className="font-medium text-(--app-foreground)"><LocalizedDateDisplay value={note.updated_at} withTime /></dd></div>
                            )}
                        </dl>
                    )}

                    <section className="space-y-4">
                        <div className="flex flex-wrap items-start justify-between gap-4 border-b border-(--app-border) pb-2">
                            <h3 className="text-base font-semibold text-(--app-foreground)">{t('cm.bench.sections.basic_info')}</h3>
                            <div className="w-56">
                                <FormField label={t('cm.bench.labels.note_date')} description={t('cm.bench.helpers.note_date')} error={form.errors.note_date}>
                                    {({ id }) => <LocalizedDatePicker id={id} value={form.data.note_date} onChange={(value) => form.setData('note_date', value)} />}
                                </FormField>
                            </div>
                        </div>
                        <div className="grid gap-4 md:grid-cols-2">
                            <FormField label={t('cm.bench.labels.case')} required description={t('cm.bench.helpers.select_case')} error={form.errors.case_id}>
                                {({ id }) => (
                                    <Select id={id} value={form.data.case_id} onChange={(event) => form.setData('case_id', event.target.value)} required>
                                        <option value="">{t('cm.bench.placeholders.select_case')}</option>
                                        {cases.map((item) => <option key={item.id} value={String(item.id)}>{item.case_number} — {item.title}</option>)}
                                    </Select>
                                )}
                            </FormField>
                            <FormField label={t('cm.bench.labels.title')} required description={t('cm.bench.helpers.title')} error={form.errors.title}>
                                {({ id }) => <Input id={id} value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} maxLength={255} placeholder={t('cm.bench.placeholders.title')} required />}
                            </FormField>
                        </div>
                    </section>

                    <section className="space-y-4">
                        <h3 className="border-b border-(--app-border) pb-2 text-base font-semibold text-(--app-foreground)">{t('cm.bench.sections.judges')}</h3>
                        <div className="grid gap-4 md:grid-cols-3">
                            <FormField label={text('judge_n', { n: 1 })} error={seatError(0)}>
                                {({ id }) => <Input id={id} value={firstJudgeName} readOnly className="bg-(--app-surface-muted)" />}
                            </FormField>
                            {[1, 2].map((index) => {
                                const options = index === 2 ? thirdJudgeUsers : judgeUsers;
                                return (
                                    <FormField key={index} label={text('judge_n', { n: index + 1 })} error={seatError(index)}>
                                        {({ id }) => (
                                            <>
                                                <Select id={id} value={form.data.judges[index].admin_user_id} onChange={(event) => setSeat(index, event.target.value)}>
                                                    <option value="">{t('cm.bench.placeholders.select_judge')}</option>
                                                    {options.map((judge) => <option key={judge.id} value={String(judge.id)}>{judge.name}</option>)}
                                                </Select>
                                                {index === 2 && noTeammates && <FormDescription>{t('cm.bench.helpers.no_teammates')}</FormDescription>}
                                            </>
                                        )}
                                    </FormField>
                                );
                            })}
                        </div>
                    </section>

                    <section className="space-y-4">
                        <h3 className="border-b border-(--app-border) pb-2 text-base font-semibold text-(--app-foreground)">{t('cm.bench.sections.note_content')}</h3>
                        <RichTextEditor label={t('cm.bench.labels.note_editor')} value={form.data.note} onChange={(html) => form.setData('note', html)} error={form.errors.note}
                            helperText={t('cm.bench.helpers.note_editor')} minHeight={editing ? 400 : 600} required />
                    </section>

                    <div className="flex flex-wrap justify-end gap-2 border-t border-(--app-border) pt-4">
                        <Link href={listHref} className={buttonClassName({ variant: 'outline' })}>{t('cm.bench.buttons.cancel')}</Link>
                        <Button type="submit" disabled={form.processing}>{editing ? t('cm.bench.buttons.update') : t('cm.bench.buttons.save')}</Button>
                    </div>
                </Card>

                {editing && (
                    <Alert tone="warning" title={t('cm.bench.headings.editing_note')}>
                        {t('cm.bench.descriptions.editing_notice')}{' '}
                        {fill(t('cm.bench.descriptions.created_meta'), {
                            author: note.author_name ?? t('cm.bench.meta.unknown'),
                            date: note.created_at ? formatDateDisplay(note.created_at, system, locale) : t('cm.bench.meta.na'),
                        })}
                    </Alert>
                )}
            </form>
        </AuthenticatedLayout>
    );
}
