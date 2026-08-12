import { Toaster } from 'sonner';
import { useEffect, useRef } from 'react';
import { usePage } from '@inertiajs/react';
import { toast } from '@/lib/toast';

interface FlashProps {
    success?: string | null;
    error?: string | null;
    warning?: string | null;
    info?: string | null;
    message?: string | null;
    type?: string | null;
}

/**
 * Drop-in replacement for the legacy ToastProvider.
 *
 * - Renders Sonner's <Toaster> (top-center, rich colours, close button).
 * - Listens for Inertia flash messages and dispatches them via toast.
 */
export default function AppToaster() {
    const page = usePage();
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const flash = (page.props as any).flash as FlashProps | undefined;
    const lastFlash = useRef<FlashProps | undefined>(undefined);

    // Keyed on the flash object itself, NOT the URL: Inertia hands out a fresh
    // props object on every server response, so `flash` is a new reference per
    // action even when it redirects back to the same page (back(), archive on
    // an index, a wizard step, …). Keying on the URL swallowed exactly those
    // messages. The ref guards against re-toasting the same response when the
    // component re-renders for unrelated reasons.
    useEffect(() => {
        if (!flash || flash === lastFlash.current) return;
        lastFlash.current = flash;

        if (flash.success)  toast.success(flash.success);
        if (flash.error)    toast.error(flash.error);
        if (flash.warning)  toast.warning(flash.warning);
        if (flash.info)     toast.info(flash.info);

        const hadKeys = Boolean(flash.success || flash.error || flash.warning || flash.info);
        if (!hadKeys && flash.message) {
            const type = flash.type;
            if (type === 'success')       toast.success(flash.message);
            else if (type === 'error')    toast.error(flash.message);
            else if (type === 'warning')  toast.warning(flash.message);
            else                          toast.info(flash.message);
        }
    }, [flash]);

    return (
        <Toaster
            position="top-center"
            richColors
            closeButton
            duration={5000}
            toastOptions={{
                classNames: {
                    toast: 'rounded-xl shadow-lg text-sm',
                },
            }}
        />
    );
}
