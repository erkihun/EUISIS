import { useForm } from '@inertiajs/react';

export default function TransportDriverForm({ driver, vehicles = [] }: { driver?: any; vehicles?: any[] }) {
    const editing = Boolean(driver?.id);
    const form = useForm({ full_name: driver?.full_name ?? '', license_number: driver?.license_number ?? '', status: driver?.status ?? 'active', assigned_vehicle_id: driver?.assigned_vehicle_id ?? '' });
    function submit(event: React.FormEvent) {
        event.preventDefault();
        editing ? form.patch(route('provider.portal.transport.drivers.update', driver.id)) : form.post(route('provider.portal.transport.drivers.store'));
    }
    return (
        <form onSubmit={submit} className="grid gap-4 rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2">
            <input value={form.data.full_name} onChange={(e) => form.setData('full_name', e.target.value)} placeholder="driver name" className="rounded-md border-slate-300 dark:border-slate-700 dark:bg-slate-950" />
            <input value={form.data.license_number} onChange={(e) => form.setData('license_number', e.target.value)} placeholder="license number" className="rounded-md border-slate-300 dark:border-slate-700 dark:bg-slate-950" />
            <select value={form.data.assigned_vehicle_id} onChange={(e) => form.setData('assigned_vehicle_id', e.target.value)} className="rounded-md border-slate-300 dark:border-slate-700 dark:bg-slate-950">
                <option value="">vehicle</option>{vehicles.map((item) => <option key={item.id} value={item.id}>{item.plate_number}</option>)}
            </select>
            <button className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white">{editing ? 'Update' : 'Create'}</button>
        </form>
    );
}
