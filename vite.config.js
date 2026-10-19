import path from 'node:path';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

/*
 * Keep the development server private by default.  When testing on a phone,
 * set VITE_DEV_SERVER_HOST to this machine's LAN address (for example,
 * 192.168.8.2).  Vite then listens on the LAN, but advertises the real LAN
 * address to Laravel and the HMR client rather than the unusable 0.0.0.0.
 */
const devServerHost = process.env.VITE_DEV_SERVER_HOST ?? '127.0.0.1';
const devServerPort = Number(process.env.VITE_DEV_SERVER_PORT ?? 5173);
const isLanDevServer = !['127.0.0.1', '::1', 'localhost'].includes(devServerHost);
const devServerUrlHost = devServerHost.includes(':') ? `[${devServerHost}]` : devServerHost;

export default defineConfig({
    server: {
        // Binding to all interfaces is needed only when an explicit LAN host
        // was configured. The browser still receives that one explicit host.
        host: isLanDevServer ? '0.0.0.0' : devServerHost,
        port: devServerPort,
        strictPort: true,
        origin: isLanDevServer ? `http://${devServerUrlHost}:${devServerPort}` : undefined,
        hmr: {
            host: devServerHost,
            port: devServerPort,
            clientPort: devServerPort,
        },
    },
    resolve: {
        alias: {
            // Ziggy's route() helper ships with the Composer package; no npm copy to keep in sync.
            'ziggy-js': path.resolve('vendor/tightenco/ziggy'),
        },
    },
    plugins: [
        // Tailwind 4's own Vite plugin (faster than the PostCSS route; no postcss.config.js).
        tailwindcss(),
        laravel({
            input: 'resources/js/app.tsx',
            refresh: true,
        }),
        react(),
    ],
    build: {
        chunkSizeWarningLimit: 600,
        rollupOptions: {
            output: {
                manualChunks(id) {
                    const file = id.replace(/\\/g, '/');

                    // The generated route list changes only when routes do; its own
                    // chunk keeps it cached across application releases.
                    if (file.endsWith('/resources/js/ziggy.js')) {
                        return 'ziggy';
                    }

                    // The rich-text editor library, fetched only where an editor is
                    // shown (RichTextEditor lazy-loads it). The toolbar skins stay
                    // out: each theme's skin is its own small chunk.
                    if ((file.includes('/node_modules/tinymce/') || file.includes('/node_modules/@tinymce/')) && !file.includes('/skins/')) {
                        return 'rich-text-editor';
                    }

                    // Force all React context providers into one shared chunk.
                    // Without this, Vite may inline a provider into whichever
                    // layout chunk first imports it, then duplicate createContext()
                    // in every page chunk that also imports from it — causing the
                    // "must be used within <Provider>" runtime error.
                    if (id.includes('ConfirmProvider') || id.includes('ToastProvider')) {
                        return 'providers';
                    }
                },
            },
        },
    },
});
