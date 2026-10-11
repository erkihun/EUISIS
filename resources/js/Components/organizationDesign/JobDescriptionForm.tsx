import Modal from '@/Components/Modal';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { Button, FieldError, FormField, Select } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { useDesignText } from './ui';

export type ProfileOption = { id: string; version_no: number; status: string; effective_from: string | null };

/** A job description draft (POST for a position) or a new version of a published one (POST versions). */
export default function JobDescriptionForm({ show, onClose, title, url, profiles }: { show: boolean; onClose: () => void; title: string; url: string; profiles?: ProfileOption[] }) {
    const { tt } = useDesignText();
    const form = useForm({ effective_from: '', effective_to: '', position_profile_id: '' });
    const errors = form.errors as Record<string, string | undefined>;

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.transform((data) => ({ effective_from: data.effective_from || null, effective_to: data.effective_to || null, ...(profiles ? { position_profile_id: data.position_profile_id || null } : {}) }));
        form.post(url, { preserveScroll: true, onSuccess: () => { form.reset(); onClose(); } });
    }

    return (
        <Modal show={show} onClose={onClose} maxWidth="lg">
            <form onSubmit={submit} className="p-6">
                <h2 className="text-base font-semibold text-[color:var(--app-foreground)]">{title}</h2>
                <p className="mt-1 text-sm text-[color:var(--app-muted-foreground)]">{tt('positionDesign.draftJdHint')}</p>
                <div className="mt-5 grid gap-4 sm:grid-cols-2">
                    <FormField id="jd-from" label={tt('fields.effectiveFrom')} required error={errors.effective_from}>{({ id }) => <LocalizedDatePicker id={id} className="w-full" value={form.data.effective_from} onChange={(iso) => form.setData('effective_from', iso)} />}</FormField>
                    <FormField id="jd-to" label={tt('fields.effectiveTo')} error={errors.effective_to}>{({ id }) => <LocalizedDatePicker id={id} className="w-full" value={form.data.effective_to} min={form.data.effective_from || undefined} onChange={(iso) => form.setData('effective_to', iso)} />}</FormField>
                    {profiles && (
                        <FormField id="jd-profile" label={tt('positionDesign.profileVersion')} error={errors.position_profile_id} className="sm:col-span-2">
                            {({ id }) => <Select id={id} value={form.data.position_profile_id} onChange={(e) => form.setData('position_profile_id', e.target.value)}><option value="">{tt('positionDesign.latestPublished')}</option>{profiles.map((p) => <option key={p.id} value={p.id}>v{p.version_no} · {tt(`status.${p.status}`)} · {p.effective_from ?? ''}</option>)}</Select>}
                        </FormField>
                    )}
                </div>
                <FieldError>{errors.status}</FieldError>
                <div className="mt-6 flex justify-end gap-2">
                    <Button type="button" variant="outline" onClick={onClose}>{tt('actions.cancel')}</Button>
                    <Button type="submit" variant="primary" loading={form.processing}>{tt('actions.save')}</Button>
                </div>
            </form>
        </Modal>
    );
}
