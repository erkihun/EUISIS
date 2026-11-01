import { useMemo, useState, type CSSProperties } from 'react';
import { Head } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { buttonClassName } from '@/Components/ui';

type Profile = {
    name: string;
    mode: 'card' | 'sheet';
    paper: 'a4' | 'letter' | null;
    sides: 'both' | 'front' | 'back';
    duplex_order: 'interleaved' | 'grouped';
    offset_x_mm: number;
    offset_y_mm: number;
    scale_percent: number;
    margin_mm: number;
    gap_mm: number;
    cut_guides: boolean;
    mirror_backs: boolean;
};
type Card = { id: string; card_number: string; front: string; back: string };
type Props = {
    batch: { id: string; batch_number: string };
    profile: Profile;
    card: { width_mm: number; height_mm: number };
    grid: { columns: number; rows: number; per_page: number; page_w: number; page_h: number };
    cards: Card[];
    range: { from: number; to: number };
};

type Face = { key: string; src: string; side: 'front' | 'back' };
/** One printed page: card faces in grid order; null leaves a slot empty. */
type Page = Array<Face | null>;

const fill = (text: string, params: Record<string, string | number>) =>
    Object.entries(params).reduce((out, [key, value]) => out.replaceAll(`:${key}`, String(value)), text);

const chunk = <T,>(list: T[], size: number): T[][] =>
    Array.from({ length: Math.ceil(list.length / size) }, (_, index) => list.slice(index * size, index * size + size));

/**
 * The pages to print, in the order the printer needs them.
 *
 * A card printer takes one face per page: front, back, front, back… for a
 * duplex printer, or every front then every back when the stack is flipped
 * by hand. A sheet printer gets a page of fronts and then the matching page of
 * backs; with long-edge duplex each back row is mirrored so every back lands
 * behind its own front.
 */
function pagesFor(profile: Profile, cards: Card[], perPage: number, columns: number): Page[] {
    const face = (card: Card, side: 'front' | 'back'): Face => ({ key: `${card.id}-${side}`, src: card[side], side });
    const sides: Array<'front' | 'back'> = profile.sides === 'both' ? ['front', 'back'] : [profile.sides];

    if (profile.mode === 'card') {
        const faces = profile.sides === 'both' && profile.duplex_order === 'interleaved'
            ? cards.flatMap((card) => sides.map((side) => face(card, side)))
            : sides.flatMap((side) => cards.map((card) => face(card, side)));
        return faces.map((one) => [one]);
    }

    const backPage = (group: Card[]): Page => {
        const slots: Page = Array.from({ length: perPage }, () => null);
        group.forEach((card, index) => {
            const row = Math.floor(index / columns);
            const column = index % columns;
            const target = profile.mirror_backs ? row * columns + (columns - 1 - column) : index;
            slots[target] = face(card, 'back');
        });
        return slots;
    };
    const frontPage = (group: Card[]): Page => group.map((card) => face(card, 'front'));
    const groups = chunk(cards, perPage);

    if (profile.sides === 'front') return groups.map(frontPage);
    if (profile.sides === 'back') return groups.map(backPage);
    return profile.duplex_order === 'interleaved'
        ? groups.flatMap((group) => [frontPage(group), backPage(group)])
        : [...groups.map(frontPage), ...groups.map(backPage)];
}

export default function Sheet({ batch, profile, card, grid, cards, range }: Props) {
    const { t } = useLocale();
    const label = (key: string) => t(`cardPrinting.sheet.${key}`);
    const pages = useMemo(() => pagesFor(profile, cards, grid.per_page, grid.columns), [profile, cards, grid]);
    const totalImages = pages.reduce((sum, page) => sum + page.filter(Boolean).length, 0);
    const [loaded, setLoaded] = useState(0);
    const [failed, setFailed] = useState(0);

    const scale = profile.scale_percent / 100;
    const cardW = card.width_mm * scale;
    const cardH = card.height_mm * scale;
    const sheet = profile.mode === 'sheet';
    const pageW = sheet ? grid.page_w : card.width_mm;
    const pageH = sheet ? grid.page_h : card.height_mm;
    // The grid is centred on the sheet, so a mirrored back page lines up with
    // its front whatever the margin.
    const gridW = grid.columns * cardW + (grid.columns - 1) * profile.gap_mm;
    const gridH = grid.rows * cardH + (grid.rows - 1) * profile.gap_mm;
    const left0 = sheet ? (pageW - gridW) / 2 : 0;
    const top0 = sheet ? (pageH - gridH) / 2 : 0;

    const slotStyle = (index: number): CSSProperties => ({
        position: 'absolute',
        left: `${left0 + (index % grid.columns) * (cardW + profile.gap_mm) + profile.offset_x_mm}mm`,
        top: `${top0 + Math.floor(index / grid.columns) * (cardH + profile.gap_mm) + profile.offset_y_mm}mm`,
        width: `${cardW}mm`,
        height: `${cardH}mm`,
    });

    const allLoaded = totalImages > 0 && loaded + failed >= totalImages;

    return (
        <>
            <Head title={`${batch.batch_number} — ${range.from}–${range.to}`} />
            {/* Exact physical size: the browser must not add margins or scale. */}
            <style>{`
                @page { size: ${pageW}mm ${pageH}mm; margin: 0; }
                @media print {
                    html, body { margin: 0 !important; padding: 0 !important; background: #fff !important; }
                    .sheet-toolbar { display: none !important; }
                    .sheet-page { margin: 0 !important; box-shadow: none !important; break-after: page; page-break-after: always; }
                    .sheet-page:last-child { break-after: auto; page-break-after: auto; }
                    .sheet-pages { padding: 0 !important; gap: 0 !important; background: #fff !important; }
                }
            `}</style>

            <div className="sheet-toolbar sticky top-0 z-10 flex flex-wrap items-center gap-3 border-b border-(--app-border) bg-(--app-surface) px-4 py-3">
                <div className="text-sm">
                    <p className="font-semibold">{batch.batch_number} · {range.from}–{range.to}{profile.name ? ` · ${profile.name}` : ''}</p>
                    <p role="status" className="text-xs text-(--app-muted-foreground)">
                        {cards.length === 0
                            ? label('empty')
                            : allLoaded
                                ? fill(label('ready'), { count: cards.length })
                                : fill(label('loading'), { done: loaded + failed, total: totalImages })}
                    </p>
                    {failed > 0 && <p role="alert" className="text-xs text-red-600">{fill(label('failed'), { count: failed })}</p>}
                </div>
                <div className="ms-auto flex gap-2">
                    <button
                        type="button"
                        className={buttonClassName({ variant: 'primary' })}
                        disabled={!allLoaded || failed > 0}
                        onClick={() => window.print()}
                    >
                        {label('print')}
                    </button>
                    <button type="button" className={buttonClassName({ variant: 'outline' })} onClick={() => window.close()}>
                        {label('close')}
                    </button>
                </div>
            </div>

            <div className="sheet-pages flex flex-col items-center gap-6 bg-(--app-surface-muted) p-6">
                {pages.map((page, pageIndex) => (
                    <div
                        key={pageIndex}
                        className="sheet-page relative overflow-hidden bg-white shadow-md"
                        style={{ width: `${pageW}mm`, height: `${pageH}mm` }}
                    >
                        {page.map((face, slot) => face && (
                            <div key={face.key} style={slotStyle(slot)}>
                                <img
                                    src={face.src}
                                    alt=""
                                    data-print-face={face.side}
                                    className="block h-full w-full"
                                    onLoad={() => setLoaded((count) => count + 1)}
                                    onError={() => setFailed((count) => count + 1)}
                                />
                                {sheet && profile.cut_guides && (
                                    <div aria-hidden className="pointer-events-none absolute inset-0" style={{ outline: '0.1mm solid #9ca3af' }} />
                                )}
                            </div>
                        ))}
                    </div>
                ))}
            </div>
        </>
    );
}
