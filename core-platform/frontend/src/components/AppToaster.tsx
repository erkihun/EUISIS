import { useEffect, useState } from 'react';
import { Toaster } from 'sonner';

type DocumentTheme = 'light' | 'dark';

function readDocumentTheme(): DocumentTheme {
    return document.documentElement.classList.contains('dark') ? 'dark' : 'light';
}

/** EUISIS Components/ui/AppToaster.tsx: one Sonner toaster that follows the `dark` class on <html>. */
export default function AppToaster() {
    const [theme, setTheme] = useState<DocumentTheme>(readDocumentTheme);

    useEffect(() => {
        const observer = new MutationObserver(() => setTheme(readDocumentTheme()));
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
        return () => observer.disconnect();
    }, []);

    return (
        <Toaster
            theme={theme}
            position="top-center"
            richColors
            closeButton
            duration={5000}
            mobileOffset={{ top: 'max(12px, env(safe-area-inset-top))', left: 12, right: 12 }}
            toastOptions={{ classNames: { toast: 'rounded-card shadow-lg text-sm leading-snug' } }}
        />
    );
}
