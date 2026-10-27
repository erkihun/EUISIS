import { createContext, useContext, useRef, useState } from 'react';
import ConfirmationDialog, { type ConfirmVariant } from './ConfirmationDialog';

export interface ConfirmOptions {
    title: string;
    description?: string;
    confirmLabel?: string;
    cancelLabel?: string;
    variant?: ConfirmVariant;
    requireReason?: boolean;
    requireTyped?: string | null;
    reasonLabel?: string;
    icon?: React.ReactNode;
}

export interface ConfirmResult {
    confirmed: boolean;
    reason?: string;
}

type ConfirmFn = (options: ConfirmOptions) => Promise<ConfirmResult>;

const ConfirmContext = createContext<ConfirmFn | null>(null);

export function useConfirmContext(): ConfirmFn {
    const ctx = useContext(ConfirmContext);
    if (!ctx) {
        throw new Error('useConfirmContext must be used within <ConfirmProvider>');
    }
    return ctx;
}

export function useConfirm(): { confirm: ConfirmFn } {
    const confirm = useConfirmContext();
    return { confirm };
}

interface DialogState extends ConfirmOptions {
    open: boolean;
    loading: boolean;
    resolve: ((result: ConfirmResult) => void) | null;
}

const INITIAL: DialogState = {
    open: false,
    loading: false,
    resolve: null,
    title: '',
};

export default function ConfirmProvider({ children }: { children: React.ReactNode }) {
    const [state, setState] = useState<DialogState>(INITIAL);
    // Keep a stable ref to resolve so we can call it after state update
    const resolveRef = useRef<((result: ConfirmResult) => void) | null>(null);

    const confirm: ConfirmFn = (options) => {
        return new Promise<ConfirmResult>((resolve) => {
            resolveRef.current = resolve;
            setState({
                ...options,
                open: true,
                loading: false,
                resolve,
            });
        });
    };

    function handleClose() {
        const resolve = resolveRef.current;
        resolveRef.current = null;
        setState(INITIAL);
        resolve?.({ confirmed: false });
    }

    function handleConfirm(reason?: string) {
        const resolve = resolveRef.current;
        resolveRef.current = null;
        setState(INITIAL);
        resolve?.({ confirmed: true, reason });
    }

    return (
        <ConfirmContext.Provider value={confirm}>
            {children}
            <ConfirmationDialog
                open={state.open}
                title={state.title}
                description={state.description}
                confirmLabel={state.confirmLabel}
                cancelLabel={state.cancelLabel}
                variant={state.variant}
                loading={state.loading}
                requireReason={state.requireReason}
                requireTyped={state.requireTyped}
                reasonLabel={state.reasonLabel}
                icon={state.icon}
                onClose={handleClose}
                onConfirm={handleConfirm}
            />
        </ConfirmContext.Provider>
    );
}
