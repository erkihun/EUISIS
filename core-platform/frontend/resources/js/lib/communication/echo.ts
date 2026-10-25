/**
 * Laravel Echo over Reverb, created on demand by the Communication pages (the
 * socket client is not part of every page's bundle). Channel authorization
 * goes through axios so it carries the session and the XSRF cookie, and it
 * runs behind the same middleware as the pages (bootstrap/app.php).
 *
 * Nothing is created when the Reverb key is not configured; the pages then
 * keep polling over HTTP.
 */
import axios from 'axios';

type AuthCallback = (error: Error | null, data: { auth: string; channel_data?: string } | null) => void;

export async function createEcho(): Promise<boolean> {
    const env = import.meta.env as Record<string, string | undefined>;
    const key = env.VITE_REVERB_APP_KEY;
    if (!key) return false;

    const [{ default: Echo }, { default: Pusher }] = await Promise.all([import('laravel-echo'), import('pusher-js')]);
    (window as unknown as { Pusher: unknown }).Pusher = Pusher;

    const scheme = env.VITE_REVERB_SCHEME ?? 'https';
    const port = Number(env.VITE_REVERB_PORT ?? (scheme === 'https' ? 443 : 80));

    (window as unknown as { Echo: unknown }).Echo = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: env.VITE_REVERB_HOST ?? window.location.hostname,
        wsPort: port,
        wssPort: port,
        forceTLS: scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        authorizer: (channel: { name: string }) => ({
            authorize: (socketId: string, callback: AuthCallback) => {
                axios.post('/broadcasting/auth', { socket_id: socketId, channel_name: channel.name })
                    .then((response) => callback(null, response.data))
                    .catch((error: Error) => callback(error, null));
            },
        }),
    });

    return true;
}
