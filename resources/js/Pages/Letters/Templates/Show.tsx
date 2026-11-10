import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import ConfirmationDialog from '@/Components/ConfirmationDialog';
import PageHeader from '@/Components/PageHeader';
import RichTextViewer from '@/Components/forms/RichTextViewer';
import ActionErrors from '@/Components/letters/ActionErrors';
import { StatusBadge } from '@/Components/letters/LetterBadges';
import { dangerBtn, inputCls, labelCls, mutedCls, panelCls, primaryBtn, secondaryBtn, sectionTitleCls, useLetters } from '@/Components/letters/helpers';
import { cx } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import type { TemplateFull } from '@/types/letters';
import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent, type JSX } from 'react';

interface Props {
    template: TemplateFull;
    can: { update: boolean; publish: boolean; archive: boolean };
}

/** One template: its draft text, publishing it as the next version, and the versions letters were composed from. */
export default function TemplateShow({ template, can }: Props): JSX.Element {
    const { lt, locale, pick } = useLetters();
    const [note, setNote] = useState('');
    const [archiving, setArchiving] = useState(false);
    const [busy, setBusy] = useState(false);
    const [open, setOpen] = useState<number | null>(template.versions?.[0]?.version_no ?? null);
    const title = `${template.code} · ${locale === 'am' && template.title_am ? template.title_am : template.title_en}`;

    function publish(event: FormEvent) {
        event.preventDefault();
        setBusy(true);
        router.post(route('letters.templates.publish', template.id), { change_note: note }, { preserveScroll: true, onFinish: () => { setBusy(false); setNote(''); } });
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={template.platform ? lt('templates.platform') : pick(template.owner)} backHref={route('letters.templates.index')}
            actions={(
                <div className="flex flex-wrap gap-2">
                    {can.update && <Link className={secondaryBtn} href={route('letters.templates.edit', template.id)}>{lt('common.edit')}</Link>}
                    {can.archive && <button type="button" className={dangerBtn} onClick={() => setArchiving(true)}>{lt('templates.archive')}</button>}
                </div>
            )} />}>
            <Head title={title} />
            <ActionErrors />
            <div className="grid gap-5 lg:grid-cols-3">
                <div className="space-y-5 lg:col-span-2">
                    <section className={cx(panelCls, 'p-4')}>
                        <div className="mb-2 flex flex-wrap items-center gap-2">
                            <h2 className={sectionTitleCls}>{lt('templates.draft')}</h2>
                            <StatusBadge kind="templateStatus" value={template.status} />
                            <span className={mutedCls}>{lt(`templateCategory.${template.category}`)} · {lt(`language.${template.language}`)}</span>
                        </div>
                        {template.draft_subject || template.draft_body ? (
                            <>
                                <p className="text-sm"><span className={labelCls}>{lt('templates.subject')}</span>{template.draft_subject ?? '—'}</p>
                                <div className="mt-3">
                                    <p className={labelCls}>{lt('templates.body')}</p>
                                    <RichTextViewer html={template.draft_body} lang={template.language} className="text-sm leading-6" empty={<p className={mutedCls}>—</p>} />
                                </div>
                            </>
                        ) : <p className={mutedCls}>{lt('templates.noDraft')}</p>}
                        {template.draft_placeholders.length > 0 && (
                            <div className="mt-3">
                                <p className={labelCls}>{lt('templates.placeholders')}</p>
                                <ul className="flex flex-wrap gap-2 text-xs">
                                    {template.draft_placeholders.map((p) => <li key={p.key} className="rounded-control border border-(--app-border) px-2 py-0.5"><span className="font-mono">{`{{ ${p.key} }}`}</span> · {locale === 'am' && p.label_am ? p.label_am : p.label_en}{p.required && ' *'}</li>)}
                                </ul>
                            </div>
                        )}
                        <p className={cx(mutedCls, 'mt-3')}>{lt('templates.updatedBy', { name: template.updated_by ?? '—' })} <LocalizedDateDisplay value={template.updated_at} withTime /></p>
                        {can.publish && (
                            <form onSubmit={publish} className="mt-3 flex flex-wrap items-end gap-2 border-t border-(--app-border) pt-3" aria-label={lt('templates.publish')}>
                                <div className="grow">
                                    <label htmlFor="tpl-note" className={labelCls}>{lt('templates.publishNote')} <span className={mutedCls}>({lt('common.optional')})</span></label>
                                    <input id="tpl-note" className={inputCls} value={note} onChange={(e) => setNote(e.target.value)} maxLength={500} />
                                </div>
                                <button type="submit" className={primaryBtn} disabled={busy}>{lt('templates.publish')}</button>
                                <p className={cx(mutedCls, 'w-full')}>{lt('templates.publishHelp')}</p>
                            </form>
                        )}
                    </section>
                </div>

                <aside className={cx(panelCls, 'p-4')}>
                    <h2 className={cx(sectionTitleCls, 'mb-2')}>{lt('templates.versions')}</h2>
                    {(template.versions ?? []).length === 0 ? <p className={mutedCls}>{lt('templates.noVersions')}</p> : (
                        <ol className="space-y-2 text-sm">
                            {(template.versions ?? []).map((v) => (
                                <li key={v.id} className="rounded-control border border-(--app-border) p-3">
                                    <button type="button" className="flex w-full items-center justify-between text-start font-medium" onClick={() => setOpen(open === v.version_no ? null : v.version_no)}>
                                        <span>v{v.version_no}{v.version_no === template.current_version_no && ` · ${lt('templates.currentVersion')}`}</span>
                                        <span className="text-xs text-(--app-muted-foreground)"><LocalizedDateDisplay value={v.published_at} /></span>
                                    </button>
                                    <p className="text-xs text-(--app-muted-foreground)">{lt('templates.publishedBy', { name: v.published_by ?? '—' })}{v.change_note && ` · ${v.change_note}`}</p>
                                    {open === v.version_no && (
                                        <div className="mt-2 border-t border-(--app-border) pt-2">
                                            <p><span className={labelCls}>{lt('templates.subject')}</span>{v.subject}</p>
                                            <RichTextViewer html={v.body} lang={template.language} className="mt-2 text-sm leading-6" />
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ol>
                    )}
                </aside>
            </div>

            <ConfirmationDialog open={archiving} onClose={() => setArchiving(false)} loading={busy} variant="danger" title={lt('templates.archive')} description={lt('templates.confirmArchive')} confirmLabel={lt('templates.archive')}
                onConfirm={() => { setBusy(true); router.post(route('letters.templates.archive', template.id), {}, { preserveScroll: true, onFinish: () => { setBusy(false); setArchiving(false); } }); }} />
        </AuthenticatedLayout>
    );
}
