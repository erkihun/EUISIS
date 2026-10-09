import axios from 'axios';
import { useState, type JSX } from 'react';

type Props = { endpoint: string; label: string; onSuccess?: (result: { accuracy_meters: string | null; validation_status: string; distance_from_destination_meters: string | null }) => void };
type State = 'idle' | 'acquiring' | 'denied' | 'unavailable' | 'timeout' | 'submitting' | 'success' | 'error';

/** One explicit, high-accuracy capture; it never starts background tracking. */
export default function PreciseLocationCapture({ endpoint, label, onSuccess }: Props): JSX.Element {
    const [state, setState] = useState<State>('idle');
    const [message, setMessage] = useState<string | null>(null);
    const capture = () => {
        if (!navigator.geolocation) { setState('unavailable'); setMessage('Precise GPS is unavailable in this browser.'); return; }
        setState('acquiring'); setMessage('Acquiring precise GPS location…');
        navigator.geolocation.getCurrentPosition(async (position) => {
            try {
                setState('submitting'); setMessage(`GPS accuracy: ${Math.round(position.coords.accuracy)} m. Confirming…`);
                const response = await axios.post(endpoint, { latitude: position.coords.latitude, longitude: position.coords.longitude, accuracy_meters: position.coords.accuracy, altitude_meters: position.coords.altitude, heading_degrees: position.coords.heading, speed_mps: position.coords.speed, idempotency_key: crypto.randomUUID() });
                setState('success'); setMessage('Precise location confirmed.'); onSuccess?.(response.data.event);
            } catch { setState('error'); setMessage('Location verification failed. Retry when connectivity is available.'); }
        }, (error) => {
            const next: State = error.code === error.PERMISSION_DENIED ? 'denied' : error.code === error.TIMEOUT ? 'timeout' : 'unavailable';
            setState(next); setMessage(next === 'denied' ? 'Precise location permission is required to confirm this Field Work location.' : next === 'timeout' ? 'GPS timed out. Move to an open area and retry.' : 'GPS position is unavailable. Retry when a precise location is available.');
        }, { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
    };
    return <div className="space-y-1"><button type="button" disabled={state === 'acquiring' || state === 'submitting'} onClick={capture} className="text-[color:var(--color-primary)] hover:underline disabled:opacity-60">{state === 'acquiring' || state === 'submitting' ? 'Acquiring location…' : label}</button>{message && <p className={state === 'success' ? 'text-xs text-emerald-700' : 'text-xs text-gray-500'} role="status">{message}</p>}</div>;
}
