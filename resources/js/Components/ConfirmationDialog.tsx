import { useEffect, useRef, useState } from 'react';
import { Transition, Dialog, TransitionChild, DialogPanel } from '@headlessui/react';

export type ConfirmVariant = 'default' | 'warning' | 'danger' | 'success';

export interface ConfirmationDialogProps {
    open: boolean;
    onClose: () => void;
    onConfirm: (reason?: string) => void;
    title: string;
    description?: string;
    confirmLabel?: string;
    cancelLabel?: string;
    variant?: ConfirmVariant;
    loading?: boolean;
    requireReason?: boolean;
    requireTyped?: string | null;
    reasonLabel?: string;
    icon?: React.ReactNode;
}

// Default icons per variant
function DefaultIcon({ variant }: { variant: ConfirmVariant }) {
    const base = 'h-6 w-6';
    if (variant === 'danger') {
        return (
            <svg className={base} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true">
                <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008z" />
            </svg>
        );
    }
    if (variant === 'warning') {
        return (
            <svg className={base} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true">
                <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m9.303 3.376c.866 1.5-.217 3.374-1.948 3.374H4.645c-1.73 0-2.813-1.874-1.948-3.374L10.051 3.378c.866-1.5 3.032-1.5 3.898 0l7.354 12.748ZM12 15.75h.007v.008H12v-.008z" />
            </svg>
        );
    }
    if (variant === 'success') {
        return (
            <svg className={base} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true">
                <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" />
            </svg>
        );
    }
    // default (info/blue)
    return (
        <svg className={base} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true">
            <path strokeLinecap="round" strokeLinejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0zm-9-3.75h.008v.008H12V8.25z" />
        </svg>
    );
}

const iconWrapperCls: Record<ConfirmVariant, string> = {
    danger:  'rounded-full bg-red-100 p-2 text-red-600 dark:bg-red-900/30 dark:text-red-400',
    warning: 'rounded-full bg-amber-100 p-2 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400',
    success: 'rounded-full bg-green-100 p-2 text-green-600 dark:bg-green-900/30 dark:text-green-400',
    default: 'rounded-full bg-blue-100 p-2 text-[color:var(--color-primary)] dark:bg-blue-900/30 dark:text-[color:var(--color-primary)]',
};

const confirmBtnCls: Record<ConfirmVariant, string> = {
    danger:  'bg-red-600 text-white hover:bg-red-700 focus:ring-red-500 disabled:opacity-60',
    warning: 'bg-amber-500 text-slate-900 hover:bg-amber-600 focus:ring-amber-400 disabled:opacity-60',
    success: 'bg-green-600 text-white hover:bg-green-700 focus:ring-green-500 disabled:opacity-60',
    default: 'bg-[color:var(--color-primary)] text-white hover:bg-[color:var(--color-primary-hover)] focus:ring-[color:var(--color-primary)] disabled:opacity-60',
};

export default function ConfirmationDialog({
    open,
    onClose,
    onConfirm,
    title,
    description,
    confirmLabel = 'Confirm',
    cancelLabel = 'Cancel',
    variant = 'default',
    loading = false,
    requireReason = false,
    requireTyped = null,
    reasonLabel = 'Reason',
    icon,
}: ConfirmationDialogProps) {
    const [reason, setReason] = useState('');
    const [typed, setTyped] = useState('');
    const confirmRef = useRef<HTMLButtonElement>(null);
    const cancelRef = useRef<HTMLButtonElement>(null);

    // Reset fields on open
    useEffect(() => {
        if (open) {
            setReason('');
            setTyped('');
        }
    }, [open]);

    // Focus the cancel button on open (safer default for destructive dialogs)
    useEffect(() => {
        if (open) {
            const t = setTimeout(() => cancelRef.current?.focus(), 50);
            return () => clearTimeout(t);
        }
    }, [open]);

    const reasonOk = !requireReason || reason.trim().length > 0;
    const typedOk = !requireTyped || typed === requireTyped;
    const canConfirm = reasonOk && typedOk && !loading;

    function handleConfirm() {
        if (!canConfirm) return;
        onConfirm(requireReason ? reason : undefined);
    }

    // Escape closes (unless loading)
    function handleKeyDown(e: React.KeyboardEvent) {
        if (e.key === 'Escape' && !loading) {
            onClose();
        }
    }

    const role = variant === 'danger' ? 'alertdialog' : 'dialog';
    const titleId = 'confirm-dialog-title';
    const descId = 'confirm-dialog-desc';

    return (
        <Transition show={open} leave="duration-150">
            <Dialog
                as="div"
                role={role}
                className="fixed inset-0 z-50 flex items-center justify-center p-4"
                onClose={() => { if (!loading) onClose(); }}
                aria-labelledby={titleId}
                aria-describedby={description || requireReason ? descId : undefined}
                onKeyDown={handleKeyDown}
            >
                {/* Backdrop */}
                <TransitionChild
                    enter="ease-out duration-200"
                    enterFrom="opacity-0"
                    enterTo="opacity-100"
                    leave="ease-in duration-150"
                    leaveFrom="opacity-100"
                    leaveTo="opacity-0"
                >
                    <div className="absolute inset-0 bg-black/50 backdrop-blur-sm" aria-hidden="true" />
                </TransitionChild>

                {/* Panel */}
                <TransitionChild
                    enter="ease-out duration-200"
                    enterFrom="opacity-0 scale-95"
                    enterTo="opacity-100 scale-100"
                    leave="ease-in duration-150"
                    leaveFrom="opacity-100 scale-100"
                    leaveTo="opacity-0 scale-95"
                >
                    <DialogPanel className="relative w-full max-w-md rounded-panel bg-white shadow-2xl ring-1 ring-gray-200 dark:bg-slate-900 dark:ring-slate-700">
                        {/* Header */}
                        <div className="flex items-start gap-4 p-6">
                            <div className={iconWrapperCls[variant]} aria-hidden="true">
                                {icon ?? <DefaultIcon variant={variant} />}
                            </div>
                            <div className="min-w-0 flex-1">
                                <h3
                                    id={titleId}
                                    className="text-base font-semibold text-gray-900 dark:text-slate-100"
                                >
                                    {title}
                                </h3>
                                {description && (
                                    <p
                                        id={descId}
                                        className="mt-1 text-sm text-gray-500 dark:text-slate-400"
                                    >
                                        {description}
                                    </p>
                                )}
                            </div>
                            {!loading && (
                                <button
                                    type="button"
                                    onClick={onClose}
                                    className="ml-2 shrink-0 rounded-lg p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-slate-800 dark:hover:text-slate-300"
                                    aria-label={cancelLabel}
                                >
                                    <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18 18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            )}
                        </div>

                        {/* Body: typed confirmation */}
                        {requireTyped && (
                            <div className="px-6 pb-2">
                                <label className="mb-1 block text-xs font-medium text-gray-600 dark:text-slate-400">
                                    {`Type "${requireTyped}" to confirm`}
                                </label>
                                <input
                                    type="text"
                                    value={typed}
                                    onChange={(e) => setTyped(e.target.value)}
                                    className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[color:var(--color-primary)] dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:placeholder:text-slate-500"
                                    placeholder={requireTyped}
                                    autoComplete="off"
                                    spellCheck={false}
                                />
                            </div>
                        )}

                        {/* Body: reason textarea */}
                        {requireReason && (
                            <div className="px-6 pb-2">
                                <label className="mb-1 block text-xs font-medium text-gray-600 dark:text-slate-400">
                                    {reasonLabel}
                                </label>
                                <textarea
                                    value={reason}
                                    onChange={(e) => setReason(e.target.value)}
                                    rows={3}
                                    className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[color:var(--color-primary)] dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:placeholder:text-slate-500"
                                    placeholder={reasonLabel}
                                />
                            </div>
                        )}

                        {/* Footer */}
                        <div className="flex justify-end gap-2 rounded-b-panel border-t border-gray-100 bg-gray-50 px-6 py-4 dark:border-slate-800 dark:bg-slate-950">
                            <button
                                ref={cancelRef}
                                type="button"
                                onClick={onClose}
                                disabled={loading}
                                className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-60 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
                            >
                                {cancelLabel}
                            </button>
                            <button
                                ref={confirmRef}
                                type="button"
                                onClick={handleConfirm}
                                disabled={!canConfirm}
                                className={`inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium focus:outline-none focus:ring-2 focus:ring-offset-2 ${confirmBtnCls[variant]}`}
                            >
                                {loading && (
                                    <svg className="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                                    </svg>
                                )}
                                {confirmLabel}
                            </button>
                        </div>
                    </DialogPanel>
                </TransitionChild>
            </Dialog>
        </Transition>
    );
}
