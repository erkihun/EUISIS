// The Leaflet map body of LocationPicker. This module is the only one that
// imports Leaflet; it is loaded on demand, so the library (and its CSS) never
// weighs on pages without a map.
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { useEffect, useRef, type JSX } from 'react';

export type MapConfig = {
    tileUrl: string;
    attribution: string;
    maxZoom: number;
    center: { lat: number; lng: number };
    zoom: number;
    maxRadius: number;
};

type Point = { lat: number; lng: number } | null;

type Props = {
    config: MapConfig;
    point: Point;
    radius: number | null;
    label: string;
    onPick: (lat: number, lng: number) => void;
};

/** A drawn pin (no image files to bundle), in the brand colour. */
const pin = L.divIcon({
    className: 'fw-map-pin',
    html: '<svg viewBox="0 0 24 32" width="28" height="36" aria-hidden="true"><path d="M12 0C5.4 0 0 5.2 0 11.7 0 20.5 12 32 12 32s12-11.5 12-20.3C24 5.2 18.6 0 12 0z" style="fill:var(--color-primary)"/><circle cx="12" cy="11.5" r="4.5" fill="#fff"/></svg>',
    iconSize: [28, 36],
    iconAnchor: [14, 36],
});

export default function LocationMap({ config, point, radius, label, onPick }: Props): JSX.Element {
    const box = useRef<HTMLDivElement>(null);
    const map = useRef<L.Map | null>(null);
    const marker = useRef<L.Marker | null>(null);
    const circle = useRef<L.Circle | null>(null);
    const pick = useRef(onPick);
    pick.current = onPick;

    // Create the map once.
    useEffect(() => {
        if (!box.current || map.current) return;
        const instance = L.map(box.current, {
            center: point ? [point.lat, point.lng] : [config.center.lat, config.center.lng],
            zoom: point ? 16 : config.zoom,
            maxZoom: config.maxZoom,
            attributionControl: true,
        });
        L.tileLayer(config.tileUrl, { maxZoom: config.maxZoom, attribution: config.attribution }).addTo(instance);
        instance.on('click', (event: L.LeafletMouseEvent) => pick.current(event.latlng.lat, event.latlng.lng));
        map.current = instance;

        // The form may change size (sections, fonts) after the map mounts.
        const observer = new ResizeObserver(() => instance.invalidateSize());
        observer.observe(box.current);

        return () => {
            observer.disconnect();
            instance.remove();
            map.current = null;
            marker.current = null;
            circle.current = null;
        };
    }, [config]);

    // Keep the pin and the circle in step with the form values.
    useEffect(() => {
        const instance = map.current;
        if (!instance) return;

        if (!point) {
            marker.current?.remove();
            circle.current?.remove();
            marker.current = null;
            circle.current = null;
            return;
        }

        const at = L.latLng(point.lat, point.lng);
        if (!marker.current) {
            marker.current = L.marker(at, { icon: pin, draggable: true, keyboard: true, title: label, alt: label }).addTo(instance);
            marker.current.on('dragend', () => {
                const moved = marker.current?.getLatLng();
                if (moved) pick.current(moved.lat, moved.lng);
            });
        } else {
            marker.current.setLatLng(at);
        }

        if (radius && radius > 0) {
            if (!circle.current) {
                // SVG attributes do not read CSS variables: resolve the brand colour now.
                const brand = getComputedStyle(document.documentElement).getPropertyValue('--color-primary').trim() || '#122170';
                circle.current = L.circle(at, { radius, color: brand, fillColor: brand, weight: 2, fillOpacity: 0.12, interactive: false }).addTo(instance);
            } else {
                circle.current.setLatLng(at).setRadius(radius);
            }
        } else {
            circle.current?.remove();
            circle.current = null;
        }

        // Close enough to see the check-in circle: zoom in on the first point, follow it afterwards.
        if (instance.getZoom() < 15 || !instance.getBounds().contains(at)) instance.setView(at, Math.max(instance.getZoom(), 16));
    }, [point?.lat, point?.lng, radius, label]);

    return <div ref={box} role="application" aria-label={label} className="h-72 w-full overflow-hidden rounded-lg border border-[color:var(--app-border)] sm:h-80" />;
}
