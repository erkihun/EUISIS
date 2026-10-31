import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import RichTextViewer from '@/Components/forms/RichTextViewer';
import { Card, buttonClassName } from '@/Components/ui';
import { useInspectionPagesText } from '@/court/i18n/useInspectionPagesText';
import type { BenchNoteShowProps } from '@/court/types/benchNotes';
import { JudgePanel, JudgeSignature } from './benchNoteUi';

/** One bench note as it reads on the bench: the panel, the content, the first judge's signature. */
export default function BenchNoteShow({ note, can }: BenchNoteShowProps) {
    const { t, text } = useInspectionPagesText();
    const listHref = route('court.bench-notes.index', { case_id: note.case_id });

    return (
        <AuthenticatedLayout header={(
            <PageHeader title={t('cm.bench.page_header.show')} description={[note.case_number, note.title].filter(Boolean).join(' — ')} backHref={listHref} actions={(
                <div className="flex flex-wrap items-center gap-2">
                    {can.update && note.mine && <Link href={route('court.bench-notes.edit', note.id)} className={buttonClassName({ variant: 'outline' })}>{t('cm.bench.buttons.edit')}</Link>}
                    <Link href={listHref} className={buttonClassName({ variant: 'outline' })}>{text('back')}</Link>
                </div>
            )} />
        )}>
            <Head title={note.title ?? t('cm.bench.title')} />

            <Card className="mx-auto w-full max-w-5xl space-y-10">
                <JudgePanel judges={note.judges} className="space-y-2 text-center text-sm text-(--app-foreground)" />

                <section className="space-y-4">
                    <h2 className="text-base font-semibold text-(--app-foreground)">{text('note_content')}</h2>
                    <div className="rounded-control border border-(--app-border) bg-(--app-surface-muted) p-5 text-sm leading-relaxed">
                        <RichTextViewer html={note.note} empty={<p className="italic text-(--app-muted-foreground)">{t('cm.bench.helpers.empty_content')}</p>} />
                    </div>
                </section>

                <JudgeSignature judge={note.judges[0]} />
            </Card>
        </AuthenticatedLayout>
    );
}
