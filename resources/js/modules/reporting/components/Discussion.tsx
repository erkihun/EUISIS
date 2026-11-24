import { cx } from '@/Components/ui';
import { badge, labelCls, mutedCls, primaryBtn, textareaCls, useReporting } from '@/modules/reporting/lib/helpers';
import type { CommentRow, TemplateSection } from '@/modules/reporting/types';
import { useForm } from '@inertiajs/react';
import type { FormEvent, JSX } from 'react';

interface Props {
    obligationId: string;
    comments: CommentRow[];
    sections?: TemplateSection[];
    canComment: boolean;
    /** Reviewers of the receiving side may write notes the reporting side never sees. */
    canInternal: boolean;
}

/**
 * Comments on a report. Feedback is visible to the reporting institution;
 * internal notes are for the receiving side's reviewers only and are labelled
 * so nobody mistakes one for the other. Structured reports can be commented
 * section by section; an uploaded report takes general comments.
 */
export default function Discussion({ obligationId, comments, sections = [], canComment, canInternal }: Props): JSX.Element {
    const { rt, moment, am } = useReporting();
    const form = useForm({ body: '', section_key: '', visibility: 'submitter' as 'submitter' | 'internal' });
    const title = (key: string | null): string | null => {
        const section = sections.find((s) => s.key === key);

        return section ? (am ? section.title_am : section.title_en) : null;
    };
    const send = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, section_key: data.section_key || null }));
        form.post(route('reporting.obligations.comments', obligationId), { preserveScroll: true, onSuccess: () => form.reset('body') });
    };

    return (
        <div className="space-y-3">
            {comments.length === 0 ? <p className={mutedCls}>{rt('discussion.none')}</p> : (
                <ul className="space-y-3">
                    {comments.map((comment) => (
                        <li key={comment.id} className={cx('rounded-control border p-3 text-sm', comment.visibility === 'internal' ? 'border-dashed border-amber-400 bg-amber-50/60 dark:bg-amber-950/20' : 'border-(--app-border)')}>
                            <p className="flex flex-wrap items-center gap-2 text-xs text-(--app-muted-foreground)">
                                <span className="font-medium text-(--app-foreground)">{comment.author_name}</span>
                                <span>{moment(comment.created_at)}</span>
                                {comment.visibility === 'internal' && <span className={badge('warning')}>{rt('discussion.internal')}</span>}
                                {title(comment.section_key) && <span className={badge('neutral')}>{title(comment.section_key)}</span>}
                            </p>
                            <p className="mt-1 whitespace-pre-line">{comment.body}</p>
                        </li>
                    ))}
                </ul>
            )}

            {canComment && (
                <form onSubmit={send} className="space-y-2 border-t border-(--app-border) pt-3">
                    <label htmlFor="dc-body" className={labelCls}>{rt('discussion.add')}</label>
                    <textarea id="dc-body" className={textareaCls} rows={3} maxLength={5000} required value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} />
                    {form.errors.body && <p className="text-xs text-red-700 dark:text-red-400" role="alert">{form.errors.body}</p>}
                    <div className="flex flex-wrap items-end gap-3">
                        {sections.length > 0 && (
                            <div>
                                <label htmlFor="dc-section" className={labelCls}>{rt('discussion.section')}</label>
                                <select id="dc-section" className="rounded-control border border-(--app-border) bg-(--app-surface) px-2 py-1.5 text-sm" value={form.data.section_key} onChange={(e) => form.setData('section_key', e.target.value)}>
                                    <option value="">{rt('discussion.general')}</option>
                                    {sections.map((s) => <option key={s.key} value={s.key}>{am ? s.title_am : s.title_en}</option>)}
                                </select>
                            </div>
                        )}
                        {canInternal && (
                            <fieldset>
                                <legend className={labelCls}>{rt('discussion.visibility')}</legend>
                                <div className="flex gap-3 text-sm">
                                    <label className="flex items-center gap-1.5"><input type="radio" name="visibility" checked={form.data.visibility === 'submitter'} onChange={() => form.setData('visibility', 'submitter')} />{rt('discussion.toInstitution')}</label>
                                    <label className="flex items-center gap-1.5"><input type="radio" name="visibility" checked={form.data.visibility === 'internal'} onChange={() => form.setData('visibility', 'internal')} />{rt('discussion.internalOnly')}</label>
                                </div>
                            </fieldset>
                        )}
                        <button type="submit" className={primaryBtn} disabled={form.processing || form.data.body.trim() === ''}>{rt('discussion.send')}</button>
                    </div>
                </form>
            )}
        </div>
    );
}
