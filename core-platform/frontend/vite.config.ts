import path from 'node:path';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

/*
 * The EUISIS screens (copied by scripts/sync-from-euisis.mjs), built into the
 * Core backend's public folder. In development the backend reads
 * ../backend/public/hot and loads the pages from this server.
 */
const devServerHost = process.env.VITE_DEV_SERVER_HOST ?? '127.0.0.1';
const devServerPort = Number(process.env.VITE_DEV_SERVER_PORT ?? 5174);

export default defineConfig({
    server: {
        host: devServerHost,
        port: devServerPort,
        strictPort: true,
        hmr: { host: devServerHost, port: devServerPort, clientPort: devServerPort },
    },
    resolve: {
        alias: {
            // Ziggy's route() helper ships with the backend's Composer package, as in EUISIS.
            'ziggy-js': path.resolve('../backend/vendor/tightenco/ziggy'),
        },
        dedupe: ['react', 'react-dom', '@inertiajs/react', '@inertiajs/core'],
    },
    plugins: [
        tailwindcss(),
        laravel({
            input: 'resources/js/app.tsx',
            publicDirectory: '../backend/public',
            refresh: ['../backend/resources/views/**', '../backend/routes/**'],
        }),
        react(),
    ],
    build: {
        chunkSizeWarningLimit: 600,
        rollupOptions: {
            output: {
                manualChunks(id) {
                    const file = id.replace(/\\/g, '/');
                    if (file.endsWith('/resources/js/ziggy.js')) {
                        return 'ziggy';
                    }
                    // One copy of the React context providers (see the EUISIS vite.config.js).
                    if (id.includes('ConfirmProvider') || id.includes('ToastProvider')) {
                        return 'providers';
                    }
                },
            },
        },
    },
});
