import { useForm } from '@inertiajs/react';

export default function TransportRouteForm({ routeData }: { routeData?: any }) {
    const editing = Boolean(routeData?.id);
    const form = useForm({
        route_code: routeData?.route_code ?? '',
        name_en: routeData?.name_en ?? '',
        name_am: routeData?.name_am ?? '',
        origin_en: routeData?.origin_en ?? '',
        destination_en: routeData?.destination_en ?? '',
        assigned_scope_type: routeData?.assigned_scope_type ?? 'self',
        is_active: routeData?.is_active ?? true,
    });

    function submit(event: React.FormEvent) {
        event.preventDefault();
        editing ? form.patch(route('provider.portal.transport.routes.update', routeData.id)) : form.post(route('provider.portal.transport.routes.store'));
    }

    return (
        <form onSubmit={submit} className="grid gap-4 rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2">
            {['route_code', 'name_en', 'name_am', 'origin_en', 'destination_en'].map((key) => (
                <input key={key} value={(form.data as any)[key]} onChange={(event) => form.setData(key as any, event.target.value)} className="rounded-md border-slate-300 dark:border-slate-700 dark:bg-slate-950" placeholder={key.replaceAll('_', ' ')} />
            ))}
            <select value={form.data.assigned_scope_type} onChange={(event) => form.setData('assigned_scope_type', event.target.value)} className="rounded-md border-slate-300 dark:border-slate-700 dark:bg-slate-950">
                <option value="self">self</option><option value="subtree">subtree</option><option value="citywide">citywide</option>
            </select>
            <button className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white">{editing ? 'Update' : 'Create'}</button>
        </form>
    );
}
