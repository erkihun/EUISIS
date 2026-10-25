import { useEffect, useState } from 'react';

// Hash routes keep the frontend a plain static bundle: any web server can host it.

function current(): string {
    return window.location.hash.slice(1) || '/';
}

export function useRoute(): string {
    const [route, setRoute] = useState(current);

    useEffect(() => {
        const onChange = () => setRoute(current());
        window.addEventListener('hashchange', onChange);
        return () => window.removeEventListener('hashchange', onChange);
    }, []);

    return route;
}

export function navigate(path: string): void {
    window.location.hash = path;
}

/** "/employees/:id" against "/employees/abc" gives { id: "abc" }; null when it does not match. */
export function match(pattern: string, route: string): Record<string, string> | null {
    const want = pattern.split('/');
    const have = route.split('?')[0].split('/');
    if (want.length !== have.length) return null;

    const params: Record<string, string> = {};
    for (let i = 0; i < want.length; i++) {
        if (want[i].startsWith(':')) params[want[i].slice(1)] = decodeURIComponent(have[i]);
        else if (want[i] !== have[i]) return null;
    }

    return params;
}
