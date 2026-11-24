import Modal from '@/Components/Modal';
import { cx } from '@/Components/ui';
import { dangerBtn, labelCls, mutedCls, primaryBtn, secondaryBtn, textareaCls, useReporting } from '@/modules/reporting/lib/helpers';
import { useEffect, useId, useState, type FormEvent, type JSX, type ReactNode } from 'react';

interface Props {
    open: boolean;
    title: string;
    description?: string;
    confirmLabel: string;
    /** A reason is always recorded with these decisions; `optional` is for an approval's remark. */
    reasonLabel?: string;
    optional?: boolean;
    destructive?: boolean;
    busy?: boolean;
    children?: ReactNode;
    onClose: () => void;
    onConfirm: (reason: string) => void;
}

/**
 * A decision that is recorded with its reason: returning or rejecting a
 * report, granting an extension, exempting, reopening, reassigning. The
 * confirm button stays disabled until a reason is written, because the server
 * refuses the decision without one.
 */
export default function ReasonDialog({ open, title, description, confirmLabel, reasonLabel, optional = false, destructive = false, busy = false, children, onClose, onConfirm }: Props): JSX.Element {
    const { rt } = useReporting();
    const id = useId();
    const [reason, setReason] = useState('');

    useEffect(() => {
        if (open) setReason('');
    }, [open]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (optional || reason.trim() !== '') onConfirm(reason.trim());
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            <form onSubmit={submit} className="space-y-4 p-5" aria-labelledby={`${id}-title`}>
                <div>
                    <h2 id={`${id}-title`} className="text-lg font-semibold">{title}</h2>
                    {description && <p className={cx(mutedCls, 'mt-1')}>{description}</p>}
                </div>
                {children}
                <div>
                    <label htmlFor={`${id}-reason`} className={labelCls}>
                        {reasonLabel ?? rt('common.reason')} {optional && <span>({rt('common.optional')})</span>}
                    </label>
                    <textarea id={`${id}-reason`} className={textareaCls} rows={4} maxLength={2000} value={reason} required={!optional} autoFocus onChange={(e) => setReason(e.target.value)} />
                    {!optional && <p className={cx(mutedCls, 'mt-1 text-xs')}>{rt('common.reasonRecorded')}</p>}
                </div>
                <div className="flex flex-wrap justify-end gap-2">
                    <button type="button" className={secondaryBtn} onClick={onClose} disabled={busy}>{rt('common.cancel')}</button>
                    <button type="submit" className={destructive ? dangerBtn : primaryBtn} disabled={busy || (!optional && reason.trim() === '')}>{confirmLabel}</button>
                </div>
            </form>
        </Modal>
    );
}
