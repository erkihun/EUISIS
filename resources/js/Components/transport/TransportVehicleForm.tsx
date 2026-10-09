import { useForm } from '@inertiajs/react';

export default function TransportVehicleForm({ vehicle, routes = [] }: { vehicle?: any; routes?: any[] }) {
    const editing = Boolean(vehicle?.id);
    const form = useForm({
        vehicle_code: vehicle?.vehicle_code ?? '',
        plate_number: vehicle?.plate_number ?? '',
        vehicle_type: vehicle?.vehicle_type ?? 'bus',
        capacity: vehicle?.capacity ?? '',
        status: vehicle?.status ?? 'active',
        assigned_route_id: vehicle?.assigned_route_id ?? '',
    });
    function submit(event: React.FormEvent) {
        event.preventDefault();
        editing ? form.patch(route('provider.portal.transport.vehicles.update', vehicle.id)) : form.post(route('provider.portal.transport.vehicles.store'));
    }
    return (
        <form onSubmit={submit} className="grid gap-4 rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2">
            <input value={form.data.vehicle_code} onChange={(e) => form.setData('vehicle_code', e.target.value)} placeholder="vehicle code" className="rounded-md border-slate-300 dark:border-slate-700 dark:bg-slate-950" />
            <input value={form.data.plate_number} onChange={(e) => form.setData('plate_number', e.target.value)} placeholder="plate number" className="rounded-md border-slate-300 dark:border-slate-700 dark:bg-slate-950" />
            <input value={form.data.capacity} onChange={(e) => form.setData('capacity', e.target.value)} placeholder="capacity" className="rounded-md border-slate-300 dark:border-slate-700 dark:bg-slate-950" />
            <select value={form.data.assigned_route_id} onChange={(e) => form.setData('assigned_route_id', e.target.value)} className="rounded-md border-slate-300 dark:border-slate-700 dark:bg-slate-950">
                <option value="">route</option>{routes.map((item) => <option key={item.id} value={item.id}>{item.name_en}</option>)}
            </select>
            <button className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white">{editing ? 'Update' : 'Create'}</button>
        </form>
    );
}
