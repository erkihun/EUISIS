import { Component, lazy, Suspense, useState, type JSX, type ReactNode } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { MapPinned, XMarkIcon } from '@/Components/NavIcons';
import { secondaryBtn } from '@/Components/fieldWork/helpers';
import type { MapConfig } from '@/Components/fieldWork/LocationMap';

const LocationMap = lazy(() => import('@/Components/fieldWork/LocationMap'));

/** Used when a point is set and no radius has been given yet. */
const DEFAULT_RADIUS_M = 200;

type Values = { lat: string; lng: string; radius: string };

type Props = Values & {
    config: MapConfig;
    onChange: (values: Values) => void;
};

/** A map that failed (tiles blocked, offline, script error) leaves the typed fields working. */
class MapBoundary extends Component<{ fallback: ReactNode; children: ReactNode }, { failed: boolean }> {
    state = { failed: false };

    static getDerivedStateFromError(): { failed: boolean } {
        return { failed: true };
    }

    render(): ReactNode {
        return this.state.failed ? this.props.fallback : this.props.children;
    }
}

const round = (value: number) => String(Math.round(value * 1e6) / 1e6);

/**
 * Expected location for field work: pick the point on a map (click, drag the
 * pin, or the device's current position) and see the check-in radius as a
 * circle. The latitude, longitude and radius fields stay beside it for exact
 * values and keyboard use; both always show the same point.
 */
export default function LocationPicker({ lat, lng, radius, config, onChange }: Props): JSX.Element {
    const { t } = useLocale();
    const [locating, setLocating] = useState(false);
    const [notice, setNotice] = useState<string | null>(null);

    const latNum = Number(lat);
    const lngNum = Number(lng);
    const valid = lat.trim() !== '' && lng.trim() !== '' && Number.isFinite(latNum) && Number.isFinite(lngNum)
        && Math.abs(latNum) <= 90 && Math.abs(lngNum) <= 180;
    const radiusNum = Number(radius);
    const point = valid ? { lat: latNum, lng: lngNum } : null;

    const place = (nextLat: number, nextLng: number) => {
        const keepRadius = radius.trim() !== '' && Number(radius) > 0;
        setNotice(keepRadius ? null : t('fieldWork.fields.defaultRadiusNote'));
        onChange({
            lat: round(nextLat),
            lng: round(nextLng),
            radius: keepRadius ? radius : String(Math.min(DEFAULT_RADIUS_M, config.maxRadius)),
        });
    };

    const locate = () => {
        if (!('geolocation' in navigator)) {
            setNotice(t('fieldWork.fields.locationDenied'));
            return;
        }
        setLocating(true);
        setNotice(null);
        navigator.geolocation.getCurrentPosition(
            (position) => {
                setLocating(false);
                place(position.coords.latitude, position.coords.longitude);
            },
            () => {
                setLocating(false);
                setNotice(t('fieldWork.fields.locationDenied'));
            },
            { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 },
        );
    };

    const fallback = <div className="flex h-72 w-full items-center justify-center rounded-lg border border-dashed border-(--app-border) p-4 text-center text-sm text-(--app-muted-foreground) sm:h-80">{t('fieldWork.fields.mapUnavailable')}</div>;

    return (
        <div className="space-y-2">
            <MapBoundary fallback={fallback}>
                <Suspense fallback={<div aria-busy="true" className="flex h-72 w-full animate-pulse items-center justify-center rounded-lg bg-(--app-surface-muted) text-sm text-(--app-muted-foreground) sm:h-80">{t('fieldWork.fields.mapLoading')}</div>}>
                    <LocationMap config={config} point={point} radius={valid && radiusNum > 0 ? radiusNum : null} label={t('fieldWork.fields.mapLabel')} onPick={place} />
                </Suspense>
            </MapBoundary>
            <div className="flex flex-wrap items-center gap-2">
                <button type="button" onClick={locate} disabled={locating} className={`${secondaryBtn} inline-flex items-center gap-1.5`}>
                    <MapPinned className="h-4 w-4" aria-hidden="true" />
                    {locating ? t('fieldWork.fields.locating') : t('fieldWork.fields.useMyLocation')}
                </button>
                {(lat || lng || radius) && (
                    <button type="button" onClick={() => { setNotice(null); onChange({ lat: '', lng: '', radius: '' }); }} className={`${secondaryBtn} inline-flex items-center gap-1.5`}>
                        <XMarkIcon className="h-4 w-4" aria-hidden="true" />
                        {t('fieldWork.fields.clearLocation')}
                    </button>
                )}
                <p className="text-xs text-(--app-muted-foreground)">{t('fieldWork.fields.mapHint')}</p>
            </div>
            {notice && <p role="status" className="text-xs text-amber-800 dark:text-amber-300">{notice}</p>}
        </div>
    );
}
