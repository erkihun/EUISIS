import { useForm } from '@inertiajs/react';

export default function TransportTripForm({ trip, routes = [], vehicles = [], drivers = [] }: { trip?: any; routes?: any[]; vehicles?: any[]; drivers?: any[] }) {
    const editing = Boolean(trip?.id);
    const form = useForm({ transport_route_id: trip?.transport_route_id ?? '', transport_vehicle_id: trip?.transport_vehicle_id ?? '', transport_driver_id: trip?.transport_driver_id ?? '', trip_date: trip?.trip_date ?? '', departure_time: trip?.departure_time ?? '', status: trip?.status ?? 'scheduled' });
    function submit(event: React.FormEvent) {
        event.preventDefault();
        editing ? form.patch(route('provider.portal.transport.trips.update', trip.id)) : form.post(route('provider.portal.transport.trips.store'));
    }
    return (
        <form onSubmit={submit} className="grid gap-4 rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2">
            <select value={form.data.transport_route_id} onChange={(e) => form.setData('transport_route_id', e.target.value)} className="rounded-md border-slate-300 dark:border-slate-700 dark:bg-slate-950"><option value="">route</option>{routes.map((item) => <option key={item.id} value={item.id}>{item.name_en}</option>)}</select>
            <select value={form.data.transport_vehicle_id} onChange={(e) => form.setData('transport_vehicle_id', e.target.value)} className="rounded-md border-slate-300 dark:border-slate-700 dark:bg-slate-950"><option value="">vehicle</option>{vehicles.map((item) => <option key={item.id} value={item.id}>{item.plate_number}</option>)}</select>
            <select value={form.data.transport_driver_id} onChange={(e) => form.setData('transport_driver_id', e.target.value)} className="rounded-md border-slate-300 dark:border-slate-700 dark:bg-slate-950"><option value="">driver</option>{drivers.map((item) => <option key={item.id} value={item.id}>{item.full_name}</option>)}</select>
            <input type="date" value={form.data.trip_date} onChange={(e) => form.setData('trip_date', e.target.value)} className="rounded-md border-slate-300 dark:border-slate-700 dark:bg-slate-950" />
            <input type="time" value={form.data.departure_time} onChange={(e) => form.setData('departure_time', e.target.value)} className="rounded-md border-slate-300 dark:border-slate-700 dark:bg-slate-950" />
            <button className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white">{editing ? 'Update' : 'Create'}</button>
        </form>
    );
}
