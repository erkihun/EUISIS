import Modal from '@/Components/Modal';
import { Button, FieldError, FormField, Textarea } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { useDesignText } from './ui';

/** A custom job-description section (PUT), written in both languages. */
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
        <Modal show onClose={onClose} maxWidth="2xl">
            <form onSubmit={submit} className="p-6">
                <h2 className="text-base font-semibold text-[color:var(--app-foreground)]">{tt('positionDesign.jd.editSection')} · {title}</h2>
                <div className="mt-5 grid gap-4">
                    <FormField id="section-en" label={tt('positionDesign.jd.contentEn')} error={errors.content_en}>{({ id }) => <Textarea id={id} rows={6} value={form.data.content_en} maxLength={20000} onChange={(e) => form.setData('content_en', e.target.value)} />}</FormField>
                    <FormField id="section-am" label={tt('positionDesign.jd.contentAm')} error={errors.content_am}>{({ id }) => <Textarea id={id} rows={6} value={form.data.content_am} maxLength={20000} onChange={(e) => form.setData('content_am', e.target.value)} />}</FormField>
                </div>
                <FieldError>{errors.section ?? errors.status}</FieldError>
                <div className="mt-6 flex justify-end gap-2">
                    <Button type="button" variant="outline" onClick={onClose}>{tt('actions.cancel')}</Button>
                    <Button type="submit" variant="primary" loading={form.processing}>{tt('actions.save')}</Button>
                </div>
            </form>
        </Modal>
    );
}
