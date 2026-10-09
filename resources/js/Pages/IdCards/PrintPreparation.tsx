import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, useForm } from '@inertiajs/react';

export default function PrintPreparation({ card, snapshot, labels }: { card: { id: string; card_number: string }; snapshot: { id: string; orientation: string; width_mm: number; height_mm: number; confirmed: boolean }; labels: Record<string, string> }) {
    const form = useForm({ printed_successfully: false });
    return <AuthenticatedLayout header={<PageHeader title={card.card_number} />}><Head title={card.card_number} />
        <style>{`@media print { body * { visibility: hidden !important; } #frozen-card-print, #frozen-card-print * { visibility: visible !important; } #frozen-card-print { position: absolute; left: 0; top: 0; } .frozen-face { break-after: page; } }`}</style>
        <p className="mb-4">{labels.print_instructions}</p><button className="mb-4 rounded-lg bg-blue-700 px-4 py-2 text-white" onClick={() => window.print()}>{labels.print}</button>
        <div id="frozen-card-print" className="space-y-5">{['front', 'back'].map(side => <img className="frozen-face border" key={side} style={{ width: `${snapshot.width_mm}mm`, height: `${snapshot.height_mm}mm` }} src={route('id-cards.print-artifact', [card.id, snapshot.id, side])} alt={labels[side]} />)}</div>
        {!snapshot.confirmed && <form className="mt-5 space-y-4" onSubmit={e => { e.preventDefault(); form.post(route('id-cards.confirm-print', [card.id, snapshot.id])); }}><label className="flex items-center gap-2"><input type="checkbox" required checked={form.data.printed_successfully} onChange={e => form.setData('printed_successfully', e.target.checked)} />{labels.confirm_print}</label><div role="alert" className="text-red-700">{Object.values(form.errors).join(' ')}</div><button className="rounded-lg bg-blue-700 px-4 py-2 text-white disabled:opacity-50" disabled={form.processing || !form.data.printed_successfully}>{labels.confirm_print}</button></form>}
    </AuthenticatedLayout>;
}
