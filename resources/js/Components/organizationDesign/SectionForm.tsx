import RichTextEditor from '@/Components/forms/RichTextEditor';
import { Button, FieldError } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { useDesignText } from './ui';

/*
 * A custom job-description section (PUT), written in both languages. Edited
 * in place in the section's card rather than in a modal: the formatting
 * editor's link dialog cannot take focus inside a modal's focus trap, and a
 * document section is easier to write at page width.
 */
export default function SectionForm({ jobDescriptionId, sectionKey, title, contentEn, contentAm, onClose }: {
    jobDescriptionId: string; sectionKey: string; title: string; contentEn: string | null; contentAm: string | null; onClose: () => void;
}) {
    const { tt } = useDesignText();
    const form = useForm({ content_en: contentEn ?? '', content_am: contentAm ?? '' });
    const errors = form.errors as Record<string, string | undefined>;

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.transform((data) => ({ content_en: data.content_en || null, content_am: data.content_am || null }));
        form.put(route('organization-design.job-descriptions.sections.update', { jobDescription: jobDescriptionId, sectionKey }), { preserveScroll: true, onSuccess: onClose });
    }

    return (
        <form onSubmit={submit} aria-label={`${tt('positionDesign.jd.editSection')} · ${title}`}>
            <div className="grid gap-5">
                <RichTextEditor id="section-en" label={tt('positionDesign.jd.contentEn')} value={form.data.content_en} onChange={(html) => form.setData('content_en', html)} error={errors.content_en} preset="document" minHeight={180} maxLength={20000} locale="en" />
                <RichTextEditor id="section-am" label={tt('positionDesign.jd.contentAm')} value={form.data.content_am} onChange={(html) => form.setData('content_am', html)} error={errors.content_am} preset="document" minHeight={180} maxLength={20000} locale="am" />
            </div>
            <FieldError>{errors.section ?? errors.status}</FieldError>
            <div className="mt-5 flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={onClose} disabled={form.processing}>{tt('actions.cancel')}</Button>
                <Button type="submit" variant="primary" loading={form.processing}>{tt('actions.save')}</Button>
            </div>
        </form>
    );
}
