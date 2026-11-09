import { useEffect, useState } from 'react';
import type { Theme } from '@/lib/theme';

function current(): Theme {
    return typeof document !== 'undefined' && document.documentElement.classList.contains('dark') ? 'dark' : 'light';
}

/**
 * The theme the page is drawn in right now (the `dark` class on <html>, set by
 * lib/theme). For widgets that cannot follow CSS variables and must be told,
 * such as the rich-text editor's toolbar skin.
 */
export function useDocumentTheme(): Theme {
    const [theme, setTheme] = useState<Theme>(current);

    useEffect(() => {
        const observer = new MutationObserver(() => setTheme(current()));
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

        return () => observer.disconnect();
    }, []);

    return theme;
}
