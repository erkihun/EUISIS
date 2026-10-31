import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import RichTextViewer from '@/Components/forms/RichTextViewer';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Alert, Button, Card, FilterField, FilterPanel, ListPagination, Select, buttonClassName, cx } from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { useInspectionPagesText } from '@/court/i18n/useInspectionPagesText';
import type { BenchNoteRow, BenchNotesIndexProps } from '@/court/types/benchNotes';
import { JudgePanel, JudgeSignature, timeOfDay } from './benchNoteUi';

/** The bench notes, newest first, one card per note with its panel, content, author and the first judge's signature; filtered by case. */
export default function BenchNotesIndex({ notes, cases, filters, can }: BenchNotesIndexProps) {
    const { t, text } = useInspectionPagesText();
    const [caseId, setCaseId] = useState(filters.case_id);
    const title = t('cm.bench.page_header.index');
    const createHref = route('court.bench-notes.create', filters.case_id ? { case_id: filters.case_id } : {});

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        router.get(route('court.bench-notes.index'), caseId ? { case_id: caseId } : {}, { preserveState: true });
    };

    return (
        <AuthenticatedLayout header={(
            <PageHeader title={title} description={t('cm.bench.descriptions.index')} actions={(
                <div className="flex flex-wrap items-center gap-2">
                    {can.create && <Link href={createHref} className={buttonClassName({})}>{t('cm.bench.buttons.new_note')}</Link>}
                    <Link href={route('court.cases.index')} className={buttonClassName({ variant: 'outline' })}>{t('cm.bench.labels.cases')}</Link>
                </div>
            )} />
        )}>
            <Head title={t('cm.bench.title')} />

            {!can.view ? <Alert tone="warning">{text('forbidden')}</Alert> : (
                <div className="space-y-4">
                    <FilterPanel onSubmit={submit} onReset={() => router.get(route('court.bench-notes.index'))} hasActiveFilters={Boolean(filters.case_id)} label={title}
                        submitLabel={t('cm.bench.buttons.apply')} resetLabel={t('cm.bench.buttons.clear')}>
                        <FilterField label={t('cm.bench.labels.filter_by_case')} htmlFor="filter-case" wide>
                            <Select id="filter-case" value={caseId} onChange={(event) => setCaseId(event.target.value)}>
                                <option value="">{t('cm.bench.options.all_cases')}</option>
                                {cases.map((item) => <option key={item.id} value={String(item.id)}>{item.case_number} — {item.title}</option>)}
                            </Select>
                        </FilterField>
                    </FilterPanel>

                    {notes.data.length === 0 ? (
                        <Card>
                            <EmptyState title={t('cm.bench.empty.title')} description={filters.case_id ? t('cm.bench.empty.description_case') : t('cm.bench.empty.description_general')}
                                action={can.create ? <Link href={createHref} className={buttonClassName({})}>{t('cm.bench.buttons.create_new')}</Link> : undefined} />
                        </Card>
                    ) : (
                        <div className="mx-auto w-full max-w-6xl space-y-6">
                            {notes.data.map((note) => <NoteCard key={note.id} note={note} can={can} />)}
                            <ListPagination meta={notes} />
                        </div>
                    )}
                </div>
            )}
        </AuthenticatedLayout>
    );
}

/** One note as the Blade list showed it: case badge, title, panel, meta tiles, content, signature and actions. */
function NoteCard({ note, can }: { note: BenchNoteRow; can: BenchNotesIndexProps['can'] }) {
    const { t, text, locale } = useInspectionPagesText();
    const { confirm } = useConfirm();
    const [deleting, setDeleting] = useState(false);

    const destroy = async () => {
        const result = await confirm({ title: t('cm.bench.confirmations.delete'), description: note.title ?? undefined, variant: 'danger', confirmLabel: t('cm.bench.buttons.delete'), cancelLabel: t('cm.bench.buttons.cancel') });
        if (!result.confirmed) return;
        router.delete(route('court.bench-notes.destroy', note.id), { preserveScroll: true, onStart: () => setDeleting(true), onFinish: () => setDeleting(false) });
    };

    const tile = (label: string, value: React.ReactNode) => (
        <div>
            <div className="text-[11px] font-semibold uppercase tracking-wider text-(--app-muted-foreground)">{label}</div>
            <div className="text-sm font-medium text-(--app-foreground)">{value}</div>
        </div>
    );

    return (
        <Card className="overflow-hidden p-0">
            <div className="border-b border-(--app-border) bg-(--app-surface-muted) p-5">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0 flex-1">
                        <span className="inline-flex items-center rounded-control bg-primary/10 px-2.5 py-0.5 font-mono text-xs font-semibold text-primary">{note.case_number ?? t('cm.bench.meta.na')}</span>
                        <h2 className="mt-3 text-xl font-bold text-(--app-foreground)">{note.title ?? t('cm.bench.labels.note_editor')}</h2>
                        {note.case_title && (
                            <p className="mt-1 text-sm text-(--app-muted-foreground)"><span className="font-medium text-primary">{t('cm.bench.labels.case_prefix')}</span> {note.case_title}</p>
                        )}
                    </div>
                    <span className="rounded-control border border-(--app-border) bg-(--app-surface) px-2.5 py-1 text-xs text-(--app-muted-foreground)"><LocalizedDateDisplay value={note.created_at} /></span>
                </div>
            </div>

            <div className="space-y-6 p-5">
                <JudgePanel judges={note.judges} />

                <div className="rounded-control border border-(--app-border) bg-(--app-surface-muted) p-4">
                    <div className="grid gap-3 sm:grid-cols-4">
                        {tile(t('cm.bench.labels.author'), note.author_name ?? t('cm.bench.meta.unknown'))}
                        {tile(t('cm.bench.labels.created_date'), <LocalizedDateDisplay value={note.created_at} />)}
                        {tile(t('cm.bench.labels.created_time'), timeOfDay(note.created_at, locale))}
                        {tile(t('cm.bench.labels.last_updated'), note.updated_at ? <LocalizedDateDisplay value={note.updated_at} withTime /> : '-')}
                    </div>
                </div>

                <div>
                    <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-(--app-muted-foreground)">{text('note_content')}</h3>
                    <div className="rounded-control border border-(--app-border) bg-(--app-surface-muted) p-4 text-sm leading-relaxed">
                        <RichTextViewer html={note.note} empty={<p className="italic text-(--app-muted-foreground)">{t('cm.bench.helpers.empty_content')}</p>} />
                    </div>
                </div>
            </div>

            <div className={cx('space-y-4 border-t border-(--app-border) bg-(--app-surface-muted) p-5')}>
                <JudgeSignature judge={note.judges[0]} />
                <div className="flex flex-wrap justify-end gap-2">
                    <Link href={route('court.bench-notes.show', note.id)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{text('view')}</Link>
                    {can.update && note.mine && <Link href={route('court.bench-notes.edit', note.id)} className={buttonClassName({ variant: 'secondary', size: 'sm' })}>{t('cm.bench.buttons.edit')}</Link>}
                    {can.delete && note.mine && <Button type="button" variant="destructiveOutline" size="sm" onClick={destroy} disabled={deleting}>{t('cm.bench.buttons.delete')}</Button>}
                </div>
            </div>
        </Card>
    );
}
