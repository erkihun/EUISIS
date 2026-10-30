import Modal from '@/Components/Modal';
import { useForm } from '@inertiajs/react';
import { useId, type FormEvent, type JSX } from 'react';
import { errorCls, inputCls, labelCls, primaryBtn, secondaryBtn, useSm } from './helpers';

/**
 * A keyboard-operable dialog that asks for one reason and posts it: reopen,
 * revoke, reject, record as not submitted. The field is focused on open and
 * the server's message is shown under it.
 */
export default function ReasonDialog({ show, title, label, url, minLength = 10, submitLabel, onClose, extra = {} }: {
    show: boolean;
    title: string;
    label: string;
    url: string;
    minLength?: number;
    submitLabel?: string;
    onClose: () => void;
    extra?: Record<string, string | number | boolean>;
}): JSX.Element {
    const { sm } = useSm();
    const id = useId();
    const form = useForm<{ reason: string }>({ reason: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({ ...data, ...extra }));
        form.post(url, { preserveScroll: true, onSuccess: () => { form.reset(); onClose(); } });
    }

    return (
        <Modal show={show} onClose={onClose} maxWidth="lg">
            <form onSubmit={submit} className="space-y-3 p-5" aria-labelledby={`${id}-title`}>
                <h2 id={`${id}-title`} className="text-base font-semibold text-(--app-foreground)">{title}</h2>
                <div>
                    <label htmlFor={`${id}-reason`} className={labelCls}>{label}</label>
                    <textarea
                        id={`${id}-reason`}
                        rows={4}
                        autoFocus
                        className={inputCls}
                        value={form.data.reason}
                        minLength={minLength}
                        required
                        aria-invalid={form.errors.reason ? true : undefined}
                        aria-describedby={form.errors.reason ? `${id}-error` : undefined}
                        onChange={(e) => form.setData('reason', e.target.value)}
                    />
                    {form.errors.reason && <p id={`${id}-error`} className={errorCls}>{form.errors.reason}</p>}
                    {Object.entries(form.errors).filter(([key]) => key !== 'reason').map(([key, message]) => (
                        <p key={key} className={errorCls}>{message}</p>
                    ))}
                </div>
                <div className="flex justify-end gap-2">
                    <button type="button" className={secondaryBtn} onClick={onClose}>{sm('common.cancel')}</button>
                    <button type="submit" className={primaryBtn} disabled={form.processing}>{submitLabel ?? sm('common.confirm')}</button>
                </div>
            </form>
        </Modal>
    );
}
