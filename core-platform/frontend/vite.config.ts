import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { defineConfig, loadEnv } from 'vite';

// The Core frontend talks only to the Core API. In development /api is proxied
// to the Core backend (php artisan serve --port=8100); in production set
// VITE_API_BASE_URL, or serve both behind one host so /api resolves.
export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, '.', '');

    return {
        plugins: [react(), tailwindcss()],
        resolve: { dedupe: ['react', 'react-dom'] },
        // Pre-bundled together at start-up, so a dependency found later can never
        // bring a second copy of React into a running dev server.
        optimizeDeps: {
            include: ['react', 'react-dom', 'react-dom/client', 'react/jsx-runtime', '@headlessui/react', 'sonner', 'cmdk', '@tanstack/react-table'],
        },
        server: {
            port: 5174,
            strictPort: true,
            proxy: {
                '/api': { target: env.CORE_API_PROXY ?? 'http://127.0.0.1:8100', changeOrigin: true },
            },
        },
        preview: { port: 5174 },
    };
});
