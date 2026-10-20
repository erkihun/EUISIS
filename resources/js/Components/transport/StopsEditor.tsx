import { useForm } from '@inertiajs/react';
import { Button, Card, EmptyState, FormField, Input, StatusBadge, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@euisis/ui';
import { useState, type FormEvent } from 'react';
import { useTransportLabels } from './labels';
import { ActionDialogButton } from './TransportShared';
import type { TransportRouteStop } from '@/types/transport';

/**
 * The ordered stops of a route, edited in place on the route page (admin
 * and portal). Coordinates are optional; a stop that is removed is soft
 * deleted so assignments that named it keep their history.
 */
export default function StopsEditor({ stops, storeUrl, updateUrl, destroyUrl, canEdit }: {
    stops: TransportRouteStop[];
    storeUrl: string;
    updateUrl: (stop: TransportRouteStop) => string;
    destroyUrl: (stop: TransportRouteStop) => string;
    canEdit: boolean;
}) {
    const { t, name } = useTransportLabels();
    const [editing, setEditing] = useState<TransportRouteStop | null>(null);
    const [adding, setAdding] = useState(false);

    return (
        <Card className="space-y-4">
            <div className="flex items-center justify-between gap-3">
                <div>
                    <h2 className="text-sm font-semibold">{t('transport.stops')}</h2>
                    <p className="text-sm text-(--app-muted-foreground)">{t('transport.stopsHelp')}</p>
                </div>
                {canEdit && !adding && !editing && <Button type="button" variant="outline" size="sm" onClick={() => setAdding(true)}>{t('transport.addStop')}</Button>}
            </div>

            {(adding || editing) && (
                <StopForm key={editing?.id ?? 'new'} stop={editing ?? undefined} nextSequence={(stops.at(-1)?.sequence ?? 0) + 1} url={editing ? updateUrl(editing) : storeUrl} method={editing ? 'patch' : 'post'} onDone={() => { setAdding(false); setEditing(null); }} />
            )}

            {stops.length === 0 ? <EmptyState title={t('transport.noStops')} /> : (
                <div className="relative overflow-x-auto">
                    <table className={tableClassName}>
                        <thead className={tableHeadClassName}>
                            <tr>
                                <th className={tableHeaderCellClassName}>#</th>
                                <th className={tableHeaderCellClassName}>{t('transport.stopCode')}</th>
                                <th className={tableHeaderCellClassName}>{t('transport.stop')}</th>
                                <th className={tableHeaderCellClassName}>{t('transport.pickupDropoff')}</th>
                                <th className={tableHeaderCellClassName}>{t('transport.coordinates')}</th>
                                <th className={tableHeaderCellClassName}>{t('transport.status')}</th>
                                {canEdit && <th className={tableHeaderCellClassName}><span className="sr-only">{t('transport.edit')}</span></th>}
                            </tr>
                        </thead>
                        <tbody className={tableBodyClassName}>
                            {stops.map((stop) => (
                                <tr key={stop.id} className={tableRowClassName}>
                                    <td className={`${tableCellClassName} tabular-nums`}>{stop.sequence}</td>
                                    <td className={`${tableCellClassName} font-mono text-xs`}>{stop.stop_code}</td>
                                    <td className={tableCellClassName}>{name(stop)}</td>
                                    <td className={tableCellClassName}>{stop.pickup_allowed ? t('transport.pickup') : '—'} / {stop.dropoff_allowed ? t('transport.dropoff') : '—'}</td>
                                    <td className={`${tableCellClassName} tabular-nums text-xs`}>{stop.latitude != null && stop.longitude != null ? `${Number(stop.latitude).toFixed(5)}, ${Number(stop.longitude).toFixed(5)}` : '—'}</td>
                                    <td className={tableCellClassName}><StatusBadge tone={stop.is_active ? 'success' : 'neutral'}>{stop.is_active ? t('transport.active') : t('transport.inactive')}</StatusBadge></td>
                                    {canEdit && (
                                        <td className={`${tableCellClassName} text-right whitespace-nowrap`}>
                                            <Button type="button" variant="ghost" size="xs" onClick={() => { setAdding(false); setEditing(stop); }}>{t('transport.edit')}</Button>
                                            <ActionDialogButton label={t('transport.delete')} title={t('transport.removeStop')} description={t('transport.removeStopHelp')} destructive size="xs" method="delete" url={destroyUrl(stop)} />
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Card>
    );
}

function StopForm({ stop, nextSequence, url, method, onDone }: { stop?: TransportRouteStop; nextSequence: number; url: string; method: 'post' | 'patch'; onDone: () => void }) {
    const { t } = useTransportLabels();
    const form = useForm({
        stop_code: stop?.stop_code ?? '',
        name_en: stop?.name_en ?? '',
        name_am: stop?.name_am ?? '',
        latitude: stop?.latitude == null ? '' : String(stop.latitude),
        longitude: stop?.longitude == null ? '' : String(stop.longitude),
        sequence: String(stop?.sequence ?? nextSequence),
        pickup_allowed: stop?.pickup_allowed ?? true,
        dropoff_allowed: stop?.dropoff_allowed ?? true,
        is_active: stop?.is_active ?? true,
        effective_from: stop?.effective_from ?? '',
        effective_to: stop?.effective_to ?? '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((d) => ({ ...d, latitude: d.latitude || null, longitude: d.longitude || null, effective_from: d.effective_from || null, effective_to: d.effective_to || null }));
        form.submit(method, url, { preserveScroll: true, onSuccess: () => { form.reset(); onDone(); } });
    }

    const text = (key: 'stop_code' | 'name_en' | 'name_am' | 'latitude' | 'longitude' | 'sequence' | 'effective_from' | 'effective_to', label: string, type = 'text', required = false) => (
        <FormField label={label} required={required} error={form.errors[key]}>
            {({ id, describedBy, invalid }) => <Input id={id} type={type} value={form.data[key]} onChange={(e) => form.setData(key, e.target.value)} aria-describedby={describedBy} aria-invalid={invalid} />}
        </FormField>
    );
    const check = (key: 'pickup_allowed' | 'dropoff_allowed' | 'is_active', label: string) => (
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="h-4 w-4" checked={form.data[key]} onChange={(e) => form.setData(key, e.target.checked)} />{label}</label>
    );

    return (
        <form onSubmit={submit} noValidate className="space-y-4 rounded-card border border-(--app-border) bg-(--app-surface-muted) p-4">
            <div className="grid gap-3 sm:grid-cols-3">
                {text('sequence', t('transport.sequence'), 'number', true)}
                {text('stop_code', t('transport.stopCode'), 'text', true)}
                {text('name_en', t('transport.nameEn'), 'text', true)}
                {text('name_am', t('transport.nameAm'))}
                {text('latitude', t('transport.latitude'), 'number')}
                {text('longitude', t('transport.longitude'), 'number')}
                {text('effective_from', t('transport.validFrom'), 'date')}
                {text('effective_to', t('transport.validUntil'), 'date')}
            </div>
            <div className="flex flex-wrap gap-4">{check('pickup_allowed', t('transport.pickup'))}{check('dropoff_allowed', t('transport.dropoff'))}{check('is_active', t('transport.active'))}</div>
            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" size="sm" onClick={onDone}>{t('transport.cancel')}</Button>
                <Button type="submit" variant="primary" size="sm" disabled={form.processing}>{t('transport.save')}</Button>
            </div>
        </form>
    );
}
