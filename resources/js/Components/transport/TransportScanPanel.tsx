import { FormEvent, useState } from 'react';
import axios from 'axios';
import TransportScanResultCard from './TransportScanResultCard';

export default function TransportScanPanel({
    providers = [],
    routes = [],
    trips = [],
    scanRouteName = 'provider.portal.transport.scan.store',
}: {
    providers?: any[];
    routes?: any[];
    trips?: any[];
    scanRouteName?: string;
}) {
    const [providerId, setProviderId] = useState(providers[0]?.id ?? '');
    const [qrToken, setQrToken] = useState('');
    const [routeId, setRouteId] = useState('');
    const [tripId, setTripId] = useState('');
    const [result, setResult] = useState<any>(null);
    const [busy, setBusy] = useState(false);

    async function submit(event: FormEvent) {
        event.preventDefault();
        setBusy(true);
        setResult(null);

        try {
            const response = await axios.post(route(scanRouteName), {
                provider_id: providerId || null,
                qr_token: qrToken,
                scan_nonce: crypto.randomUUID(),
                transport_route_id: routeId || null,
                transport_trip_id: tripId || null,
            });
            setResult(response.data);
            setQrToken('');
        } catch (error: any) {
            setResult(error.response?.data ?? { accepted: false, result_code: 'scan_failed' });
        } finally {
            setBusy(false);
        }
    }

    return (
        <div className="grid gap-4 lg:grid-cols-[1fr_360px]">
            <form onSubmit={submit} className="space-y-4 rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                {providers.length > 0 && (
                    <select value={providerId} onChange={(event) => setProviderId(event.target.value)} className="w-full rounded-md border-slate-300 dark:border-slate-700 dark:bg-slate-950" required>
                        <option value="">Transport Provider</option>
                        {providers.map((item) => <option key={item.id} value={item.id}>{item.provider_code ? `${item.provider_code} - ${item.name_en}` : item.name_en}</option>)}
                    </select>
                )}
                <textarea value={qrToken} onChange={(event) => setQrToken(event.target.value)} rows={5} className="w-full rounded-md border-slate-300 dark:border-slate-700 dark:bg-slate-950" placeholder="QR reference" required />
                <div className="grid gap-3 sm:grid-cols-2">
                    <select value={routeId} onChange={(event) => setRouteId(event.target.value)} className="rounded-md border-slate-300 dark:border-slate-700 dark:bg-slate-950">
                        <option value="">Route</option>
                        {routes.filter((item) => !providerId || !item.provider_id || item.provider_id === providerId).map((item) => <option key={item.id} value={item.id}>{item.name_en ?? item.name ?? item.route_code}</option>)}
                    </select>
                    <select value={tripId} onChange={(event) => setTripId(event.target.value)} className="rounded-md border-slate-300 dark:border-slate-700 dark:bg-slate-950">
                        <option value="">Trip</option>
                        {trips.filter((item) => !providerId || !item.provider_id || item.provider_id === providerId).map((item) => <option key={item.id} value={item.id}>{item.trip_number}</option>)}
                    </select>
                </div>
                <button disabled={busy} className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">{busy ? 'Scanning' : 'Scan ID'}</button>
            </form>
            <TransportScanResultCard result={result} />
        </div>
    );
}
