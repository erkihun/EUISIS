import { Toaster } from 'sonner';
import { useEffect, useRef, useState } from 'react';
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

type DocumentTheme = 'light' | 'dark';

function readDocumentTheme(): DocumentTheme {
    return typeof document !== 'undefined' && document.documentElement.classList.contains('dark') ? 'dark' : 'light';
}

/**
 * Sonner's own `theme="system"` only follows the operating system, but the
 * app's theme switch (lib/theme.ts) toggles the `dark` class on <html>, so a
 * toast has to follow that class or it renders light on a dark page.
 */
function useDocumentTheme(): DocumentTheme {
    const [theme, setTheme] = useState<DocumentTheme>(readDocumentTheme);

    useEffect(() => {
        const update = () => setTheme(readDocumentTheme());
        update();

        const observer = new MutationObserver(update);
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

        return () => observer.disconnect();
    }, []);

    return theme;
}

/**
 * The one toaster, mounted once per layout.
 *
 * - Renders Sonner's <Toaster> (top-center, rich colours, close button).
 * - Listens for Inertia flash messages and dispatches them via toast, unless
 *   `flashToasts` is off for a page that shows its flash inline instead.
 * - On a phone (Sonner switches below 600px) toasts span the screen width and
 *   keep clear of a notch; they can be swiped away.
 */
export default function AppToaster({ flashToasts = true }: { flashToasts?: boolean }) {
    const page = usePage();
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const flash = (flashToasts ? (page.props as any).flash : undefined) as FlashProps | undefined;
    const lastFlash = useRef<FlashProps | undefined>(undefined);
    const theme = useDocumentTheme();

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
            theme={theme}
            position="top-center"
            richColors
            closeButton
            duration={5000}
            mobileOffset={{ top: 'max(12px, env(safe-area-inset-top))', left: 12, right: 12 }}
            toastOptions={{
                classNames: {
                    toast: 'rounded-card shadow-lg text-sm leading-snug',
                },
            }}
        />
    );
}
