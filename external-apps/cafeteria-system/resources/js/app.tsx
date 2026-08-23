import '../css/app.css';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';

createInertiaApp({
    title: (title) => (title ? `${title} · Cafeteria System` : 'Cafeteria System'),
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.tsx', { eager: true });
        const page = pages[`./Pages/${name}.tsx`];

        if (!page) {
            throw new Error(`Inertia page not found: ${name}`);
        }

        return page as never;
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
});
