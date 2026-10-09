import TransportProviderLayout from '@/Layouts/TransportProviderLayout';
import { Link } from '@inertiajs/react';

export default function Index({ routes = [] }: { routes: any[] }) {
    return (
        <TransportProviderLayout title="Transport Routes">
            <div className="mb-4"><Link href={route('provider.portal.transport.routes.create')} className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white">New Route</Link></div>
            <div className="rounded-lg border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">{routes.map((item) => <Link key={item.id} href={route('provider.portal.transport.routes.edit', item.id)} className="block border-b border-slate-100 px-4 py-3 text-sm last:border-0 dark:border-slate-800">{item.route_code} - {item.name_en}</Link>)}</div>
        </TransportProviderLayout>
    );
}
