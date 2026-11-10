import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import PageHeader from '@/Components/PageHeader';
import ActionErrors from '@/Components/letters/ActionErrors';
import { errorCls, inputCls, labelCls, panelCls, primaryBtn, secondaryBtn, textareaCls, useLetters } from '@/Components/letters/helpers';
import { AlignCenterIcon, AlignLeftIcon, AlignRightIcon, BoldIcon, ChevronDown, ChevronUp, EyeIcon, GalleryIcon, ImageAddIcon, PlusIcon, TextLineIcon, TrashIcon, UnderlineIcon } from '@/Components/NavIcons';
import { cx } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import type { LayoutCell, LayoutEditorOptions, LayoutItem, LayoutPage, LayoutRow, LayoutSection, LetterLayoutData, OrganizationOption } from '@/types/letters';
import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState, type CSSProperties, type FormEvent, type JSX, type MouseEvent, type ReactNode } from 'react';

interface Props {
    organizations: OrganizationOption[];
    platform: boolean;
    organization: (OrganizationOption & { id: string }) | null;
    layout: LetterLayoutData;
    previews: Record<string, string>;
    options: LayoutEditorOptions;
}

type Align = LayoutCell['align'];
type Position = 'left' | 'center' | 'right';
type AreaKey = 'header' | 'below' | 'footer';
type Logo = { image: string; width: number } | null;
type Rule = { on: boolean; style: LayoutRow['border_style']; color: string };
type NewImage = { key: string; name: string; file: File; url: string };
type SegmentOption = [string, string] | [string, string, ReactNode];

/** What prints in one position of an area: an optional logo, then text lines. */
type Block = { logo: Logo; lines: LayoutItem[]; align: Align; /** Share of the row as stored; unset for a position that is new, which then gets a sensible one. */ width?: number };
/** An area of the page as people describe it: what is on the left, in the centre and on the right. */
type Area = Record<Position, Block>;

/**
 * The letterhead in everyday terms: the header, the strip under its dividing
 * line and the footer, each with a left, a centre and a right position. It is
 * stored as the rows of LetterLayout (fromLayout / toLayout), so the PDF and
 * the data stay exactly as before.
 */
type Simple = {
    header: Area; headerRule: Rule; below: Area; footer: Area; footerRule: Rule;
    /** The header or the footer can instead be one picture printed across the page. */
    headerMode: Mode; headerPicture: string | null; footerMode: Mode; footerPicture: string | null;
};
type Mode = 'parts' | 'picture';

const POSITIONS: Position[] = ['left', 'center', 'right'];
const PAPER_MM: Record<string, [number, number]> = { a4: [210, 297], a5: [148, 210], letter: [215.9, 279.4], legal: [215.9, 355.6] };
const FONT_CSS: Record<string, string> = { abyssinica: "'Abyssinica SIL', 'Nyala', serif", noto_sans: "'Noto Sans Ethiopic', 'Nyala', sans-serif" };
const SWATCHES = ['#111111', '#333333', '#555555', '#122170', '#1D4ED8', '#166534', '#B91C1C', '#92400E'];
const BORDER: Record<string, string> = { thin: '1px solid', thick: '2.5px solid', double: '3px double' };
const MM_PX = 3.7795;
const TOKEN = /\{\{\s*([a-z_.]+)\s*\}\}/g;
const MAX_IMAGE_BYTES = 2 * 1024 * 1024;

const newLine = (text = ''): LayoutItem => ({ type: 'text', text, language: 'both', font_family: '', font_size: 10, bold: false, underline: false, color: '#111111' });
const randomKey = (): string => `img_${Math.random().toString(36).slice(2, 10)}`;
const emptyBlock = (position: Position): Block => ({ logo: null, lines: [], align: position });
const emptyArea = (): Area => ({ left: emptyBlock('left'), center: emptyBlock('center'), right: emptyBlock('right') });
const used = (block: Block): boolean => block.logo !== null || block.lines.length > 0;

function blockOf(cell: LayoutCell): Block {
    const image = cell.items.find((i) => i.type === 'image' && i.image);
    return { logo: image ? { image: image.image as string, width: image.width_mm ?? 22 } : null, lines: cell.items.filter((i) => i.type === 'text'), align: cell.align, width: cell.width };
}

/** One stored row as left / centre / right: three cells fill all three, two fill the sides, one sits where it is aligned. */
function areaOf(rows: LayoutRow[]): Area {
    const area = emptyArea();
    for (const row of rows) {
        const cells = row.cells.slice(0, 3);
        const places: Position[] = cells.length === 3 ? POSITIONS : cells.length === 2 ? ['left', 'right'] : [cells[0]?.align ?? 'center'];
        cells.forEach((cell, index) => {
            const block = blockOf(cell);
            const target = area[places[index]];
            if (!used(target)) {
                target.align = block.align;
                target.width = rows.length === 1 ? block.width : undefined;
            }
            target.logo = target.logo ?? block.logo;
            target.lines = [...target.lines, ...block.lines];
        });
    }
    return area;
}

/** The image of a row that is nothing but one full-width picture. */
function pictureOf(row?: LayoutRow): string | null {
    const item = row && row.cells.length === 1 && row.cells[0].items.length === 1 ? row.cells[0].items[0] : null;
    return item && item.type === 'image' && item.full && item.image ? item.image : null;
}

function pictureRow(image: string, border: LayoutRow['border_position'], rule: Rule): LayoutRow {
    return {
        cells: [{ items: [{ type: 'image', image, width_mm: 120, full: true, language: 'both' }], align: 'center', valign: 'middle', width: 0 }],
        border_position: border, border_style: rule.style, border_color: rule.color, space_after: 3,
    };
}

function fromLayout(header: LayoutSection, footer: LayoutSection): Simple {
    const [first, ...rest] = header.rows;
    const foot = footer.rows[0];
    const headerPicture = pictureOf(first);
    const footerPicture = footer.rows.length === 1 ? pictureOf(foot) : null;

    return {
        headerMode: headerPicture ? 'picture' : 'parts',
        headerPicture,
        footerMode: footerPicture ? 'picture' : 'parts',
        footerPicture,
        header: areaOf(first && !headerPicture ? [first] : []),
        headerRule: { on: first ? ['bottom', 'both'].includes(first.border_position) : true, style: first?.border_style ?? 'thick', color: first?.border_color ?? '#122170' },
        below: areaOf(rest),
        footer: areaOf(footerPicture ? [] : footer.rows),
        footerRule: { on: foot ? ['top', 'both'].includes(foot.border_position) : true, style: foot?.border_style ?? 'thin', color: foot?.border_color ?? '#C7CBE0' },
    };
}

/** The positions an area prints, in the order of the stored cells (also how a click on the preview finds its box). */
function placesOf(area: Area): Position[] {
    const has = (p: Position) => used(area[p]);
    if (!has('left') && !has('center') && !has('right')) return [];
    if (has('center') && (has('left') || has('right'))) return POSITIONS;
    return POSITIONS.filter(has);
}

function rowOf(area: Area, border: LayoutRow['border_position'], rule: Rule, contentMm: number): LayoutRow | null {
    const places = placesOf(area);
    if (places.length === 0) return null;
    // A position that holds only a logo is as wide as the logo needs; an empty side mirrors the other so the centre stays centred.
    const logoOnly = (p: Position) => area[p].logo !== null && area[p].lines.length === 0;
    const logoWidth = (p: Position) => Math.min(40, Math.max(10, Math.round((((area[p].logo?.width ?? 22) + 6) / contentMm) * 100)));
    // A letterhead opened and saved untouched keeps the widths it had.
    const kept = places.every((p) => area[p].width !== undefined);
    const width = (p: Position): number => {
        if (kept) return area[p].width ?? 0;
        if (places.length !== 3 || p === 'center') return places.length === 2 ? 50 : 0;
        if (logoOnly(p)) return logoWidth(p);
        const other: Position = p === 'left' ? 'right' : 'left';
        return !used(area[p]) && logoOnly(other) ? logoWidth(other) : 0;
    };
    const cells: LayoutCell[] = places.map((p) => ({
        items: [...(area[p].logo ? [{ type: 'image', image: area[p].logo.image, width_mm: area[p].logo.width, language: 'both' } as LayoutItem] : []), ...area[p].lines],
        align: area[p].align,
        valign: 'middle',
        width: width(p),
    }));

    return { cells, border_position: border, border_style: rule.style, border_color: rule.color, space_after: 3 };
}

function toLayout(s: Simple, page: LayoutPage): { header: LayoutSection; footer: LayoutSection } {
    const [w, h] = PAPER_MM[page.paper] ?? PAPER_MM.a4;
    const content = Math.max(60, (page.orientation === 'landscape' ? h : w) - page.margin_left - page.margin_right);
    const headerRow = s.headerMode === 'picture'
        ? (s.headerPicture ? pictureRow(s.headerPicture, s.headerRule.on ? 'bottom' : 'none', s.headerRule) : null)
        : rowOf(s.header, s.headerRule.on ? 'bottom' : 'none', s.headerRule, content);
    const belowRow = rowOf(s.below, 'none', s.headerRule, content);
    // With nothing above it, the dividing line still prints, on an empty row.
    const top = headerRow ?? (s.headerRule.on && belowRow ? { cells: [{ items: [], align: 'center', valign: 'middle', width: 0 } as LayoutCell], border_position: 'bottom', border_style: s.headerRule.style, border_color: s.headerRule.color, space_after: 3 } as LayoutRow : null);
    const footerRow = s.footerMode === 'picture'
        ? (s.footerPicture ? pictureRow(s.footerPicture, s.footerRule.on ? 'top' : 'none', s.footerRule) : null)
        : rowOf(s.footer, s.footerRule.on ? 'top' : 'none', s.footerRule, content);

    return { header: { rows: [top, belowRow].filter((r): r is LayoutRow => r !== null) }, footer: { rows: footerRow ? [footerRow] : [] } };
}

/** One header-and-footer layout, as a plain form read from top to bottom with the printed page beside it. */
export default function LetterheadForm({ organizations, platform, organization, layout, previews, options }: Props): JSX.Element {
    const { lt, pick } = useLetters();
    const editing = layout.id !== null;

    const [page, setPage] = useState<LayoutPage>(layout.page);
    const [simple, setSimple] = useState<Simple>(() => fromLayout(layout.header, layout.footer));
    const [newImages, setNewImages] = useState<NewImage[]>([]);
    const [stampUrl, setStampUrl] = useState<string | null>(null);
    const [previewLanguage, setPreviewLanguage] = useState<'am' | 'en'>('am');
    const [imageError, setImageError] = useState<string | null>(null);
    const [footerRatio, setFooterRatio] = useState<number | null>(null);
    const snapshot = (name: string, isDefault: boolean, active: boolean, p: LayoutPage, s: Simple) => JSON.stringify([name, isDefault, active, p, s]);
    const [baseline, setBaseline] = useState(() => snapshot(layout.name, layout.is_default, layout.active, layout.page, fromLayout(layout.header, layout.footer)));

    const form = useForm({
        organization_id: layout.organization_id,
        name: layout.name,
        is_default: layout.is_default,
        active: layout.active,
        stamp: null as File | null,
        remove_stamp: false,
    });
    const errors = form.errors as Record<string, string>;
    const dirty = baseline !== snapshot(form.data.name, form.data.is_default, form.data.active, page, simple) || form.data.stamp !== null || form.data.remove_stamp;

    const rows = useMemo(() => toLayout(simple, page), [simple, page]);
    const setBlock = (area: AreaKey, position: Position, change: (block: Block) => Block) =>
        setSimple((current) => ({ ...current, [area]: { ...current[area], [position]: change(current[area][position]) } }));

    useEffect(() => () => newImages.forEach((i) => URL.revokeObjectURL(i.url)), []); // eslint-disable-line react-hooks/exhaustive-deps

    const imageUrl = (key?: string): string | null => (key ? newImages.find((i) => i.key === key)?.url ?? previews[key] ?? null : null);

    function uploadLogo(area: AreaKey, position: Position, file: File | null) {
        if (!file) return;
        if (!/^image\/(png|jpeg|svg\+xml)$/.test(file.type) || file.size > MAX_IMAGE_BYTES) {
            setImageError(lt('letterheads.simple.imageRule'));
            return;
        }
        setImageError(null);
        const key = randomKey();
        setNewImages((list) => [...list, { key, name: file.name, file, url: URL.createObjectURL(file) }]);
        setBlock(area, position, (block) => ({ ...block, width: undefined, logo: { image: key, width: block.logo?.width ?? 22 } }));
    }

    function uploadPicture(section: 'header' | 'footer', file: File | null) {
        if (!file) return;
        if (!['image/png', 'image/jpeg'].includes(file.type) || file.size > MAX_IMAGE_BYTES) {
            setImageError(lt('letterheads.simple.pictureRule'));
            return;
        }
        setImageError(null);
        const key = randomKey();
        setNewImages((list) => [...list, { key, name: file.name, file, url: URL.createObjectURL(file) }]);
        setSimple((s) => (section === 'header' ? { ...s, headerPicture: key } : { ...s, footerPicture: key }));
    }

    function save(event?: FormEvent) {
        event?.preventDefault();
        // Only what the page prints is sent and kept: a logo left behind in "text and logos" while the picture is used is not.
        const inUse = [...rows.header.rows, ...rows.footer.rows].flatMap((r) => r.cells.flatMap((c) => c.items.flatMap((i) => (i.type === 'image' && i.image ? [i.image] : []))));
        const uploads = newImages.filter((i) => inUse.includes(i.key));
        form.transform((d) => ({
            ...d,
            ...(editing ? { _method: 'put' } : {}),
            organization_id: d.organization_id || null,
            layout: JSON.stringify({ page, ...rows }),
            new_images: Object.fromEntries(uploads.map((i) => [i.key, i.file])),
            image_names: Object.fromEntries(uploads.map((i) => [i.key, i.name])),
            // Stored images no position uses any more go with this save.
            remove_images: layout.images.map((i) => i.key).filter((key) => !inUse.includes(key)),
        }));
        form.post(editing ? route('letters.letterheads.upload', layout.id as string) : route('letters.letterheads.store'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => { setStampUrl(null); setBaseline(snapshot(form.data.name, form.data.is_default, form.data.active, page, simple)); },
        });
    }

    // Sample values for the preview and the "prints as" hints.
    const orgName = (lang: 'am' | 'en') => organization ? ((lang === 'am' ? organization.name_am || organization.name_en : organization.name_en || organization.name_am) ?? '') : lt('letterheads.sampleInstitution');
    const vars: Record<string, string> = {
        'institution.name': orgName(previewLanguage),
        'institution.name_am': orgName('am'),
        'institution.name_en': orgName('en'),
        'institution.code': organization?.code ?? 'ORG',
        'unit.name': lt('letterheads.sample.unit'),
        'letter.number': lt('letterheads.sample.number'),
        'letter.date_ec': lt('letterheads.sample.date_ec'),
        'letter.date_gc': lt('letterheads.sample.date_gc'),
        'today.date_ec': lt('letterheads.sample.date_ec'),
        page: lt('letterheads.sample.page'),
    };

    // A click on a printed line puts the cursor in the box that holds it.
    function focusLine(section: 'header' | 'footer', r: number, c: number, i: number) {
        const area: AreaKey = section === 'footer' ? 'footer' : r === 0 && simple.headerMode === 'parts' && placesOf(simple.header).length > 0 ? 'header' : 'below';
        const position = placesOf(simple[area])[c];
        if (!position) return;
        const el = document.getElementById(`lf-${area}-${position}-${i}`);
        el?.scrollIntoView({ block: 'center', behavior: 'smooth' });
        el?.focus({ preventScroll: true });
    }

    // What the header and footer print, to warn when the number or the date is switched off but printed nowhere.
    const printedText = [...rows.header.rows, ...rows.footer.rows].flatMap((r) => r.cells.flatMap((c) => c.items.map((i) => i.text ?? ''))).join(' ');
    const numberMissing = page.number_align === 'none' && !printedText.includes('letter.number');
    const dateMissing = page.date_align === 'none' && !printedText.includes('letter.date_ec') && !printedText.includes('letter.date_gc');
    // A footer picture must fit inside the bottom margin (it sits 6 mm above the paper's edge).
    const [paperW, paperH] = PAPER_MM[page.paper] ?? PAPER_MM.a4;
    const contentMm = Math.max(60, (page.orientation === 'landscape' ? paperH : paperW) - page.margin_left - page.margin_right);
    const footerHeight = simple.footerMode === 'picture' && simple.footerPicture && footerRatio ? Math.round(contentMm * footerRatio) : 0;
    const footerNeeds = footerHeight > 0 ? footerHeight + 8 : 0;
    const modeOptions: SegmentOption[] = [['parts', lt('letterheads.simple.modeParts')], ['picture', lt('letterheads.simple.modePicture')]];
    const placeOptions: SegmentOption[] = (['left', 'center', 'right', 'none'] as const).map((a) => [a, lt(`letterheads.referenceAligns.${a}`)]);

    const title = editing ? lt('letterheads.editTitle') : lt('letterheads.createTitle');
    const scope = layout.organization_id ? { organization_id: layout.organization_id } : {};
    const stampPreview = stampUrl ?? (layout.has_stamp && !form.data.remove_stamp ? previews.stamp ?? null : null);
    const areaProps = (area: AreaKey) => ({
        id: `lf-${area}`, area: simple[area], options, bodyFont: page.font_family, vars, imageUrl,
        orgLogoKey: options.org_logo, orgLogoOffered: organization === null || !!previews[options.org_logo], platformLayout: organization === null,
        onBlock: (position: Position, change: (block: Block) => Block) => setBlock(area, position, change),
        onUpload: (position: Position, file: File | null) => uploadLogo(area, position, file),
    });

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={lt('letterheads.simple.intro')} backHref={route('letters.letterheads.index', scope)}
            actions={(
                <div className="flex flex-wrap items-center gap-2">
                    {editing && (
                        <a className={cx(secondaryBtn, 'inline-flex items-center gap-1.5')} href={route('letters.letterheads.preview', { letterhead: layout.id as string, language: previewLanguage })} target="_blank" rel="noopener">
                            <EyeIcon className="size-4" aria-hidden="true" />{lt('letterheads.previewPdf')}
                        </a>
                    )}
                    <button type="button" className={primaryBtn} disabled={form.processing} onClick={() => save()}>{form.processing ? lt('letterheads.saving') : lt('letterheads.save')}</button>
                </div>
            )} />}>
            <Head title={title} />
            <ActionErrors />
            <form onSubmit={save} aria-label={title} noValidate className="grid gap-5 pb-4 xl:grid-cols-12">
                <div className="min-w-0 space-y-5 xl:col-span-7">
                    {/* 1. Name */}
                    <Step number={1} title={lt('letterheads.simple.stepName')}>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label className={labelCls} htmlFor="lf-name">{lt('letterheads.name')}</label>
                                <input id="lf-name" className={inputCls} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} maxLength={120} required aria-describedby="lf-name-help" />
                                <p id="lf-name-help" className="mt-1 text-xs text-(--app-muted-foreground)">{lt('letterheads.nameHelp')}</p>
                                {errors.name && <p className={errorCls} role="alert">{errors.name}</p>}
                            </div>
                            <div>
                                <label className={labelCls} htmlFor="lf-org">{lt('letterheads.institution')}</label>
                                {editing ? (
                                    <p id="lf-org" className="flex min-h-10 items-center text-sm">{organization ? pick(organization) : lt('letterheads.platformLayouts')}</p>
                                ) : (
                                    <select id="lf-org" className={inputCls} value={form.data.organization_id} onChange={(e) => form.setData('organization_id', e.target.value)}>
                                        {platform && <option value="">{lt('letterheads.platformLayouts')}</option>}
                                        {organizations.map((o) => <option key={o.id} value={o.id}>{pick(o)}</option>)}
                                    </select>
                                )}
                            </div>
                        </div>
                        <div className="flex flex-wrap gap-x-8 gap-y-1">
                            <Switch label={lt('letterheads.defaultLabel')} checked={form.data.is_default} onChange={(v) => form.setData('is_default', v)} />
                            <Switch label={lt('letterheads.activeLabel')} checked={form.data.active} onChange={(v) => form.setData('active', v)} />
                        </div>
                        {(layout.letters_count > 0 || (layout.updated_by && layout.updated_at)) && (
                            <p className="text-xs text-(--app-muted-foreground)">
                                {layout.letters_count > 0 && <>{lt('letterheads.usedBy', { count: layout.letters_count })} </>}
                                {layout.updated_by && layout.updated_at && <>{lt('letterheads.updatedBy', { name: layout.updated_by })} · <LocalizedDateDisplay value={layout.updated_at} /></>}
                            </p>
                        )}
                    </Step>

                    {/* 2. Header */}
                    <Step number={2} title={lt('letterheads.simple.stepHeader')} help={lt('letterheads.simple.areaHelp')}>
                        <Segmented label={lt('letterheads.simple.modeLabel')} value={simple.headerMode} options={modeOptions} onChange={(v) => setSimple((s) => ({ ...s, headerMode: v as Mode }))} />
                        {simple.headerMode === 'picture'
                            ? <PictureEditor id="lf-header-picture" title={lt('letterheads.simple.stepHeader')} url={imageUrl(simple.headerPicture ?? undefined)}
                                onUpload={(file) => uploadPicture('header', file)} onRemove={() => setSimple((s) => ({ ...s, headerPicture: null }))} />
                            : <AreaEditor {...areaProps('header')} />}
                        {(imageError || errors.new_images) && <p className={errorCls} role="alert">{imageError ?? errors.new_images}</p>}
                        <RuleControl label={lt('letterheads.simple.ruleUnderHeader')} rule={simple.headerRule} onChange={(headerRule) => setSimple((s) => ({ ...s, headerRule }))} />
                        <div>
                            <h3 className="text-sm font-semibold">{lt('letterheads.simple.belowText')}</h3>
                            <p className="mb-2 text-xs text-(--app-muted-foreground)">{lt('letterheads.simple.belowHelp')}</p>
                            <AreaEditor {...areaProps('below')} />
                        </div>
                    </Step>

                    {/* 3. Footer */}
                    <Step number={3} title={lt('letterheads.simple.stepFooter')} help={lt('letterheads.footerHelp')}>
                        <RuleControl label={lt('letterheads.simple.ruleAboveFooter')} rule={simple.footerRule} onChange={(footerRule) => setSimple((s) => ({ ...s, footerRule }))} />
                        <Segmented label={lt('letterheads.simple.modeLabel')} value={simple.footerMode} options={modeOptions} onChange={(v) => setSimple((s) => ({ ...s, footerMode: v as Mode }))} />
                        {simple.footerMode === 'picture'
                            ? (
                                <>
                                    <PictureEditor id="lf-footer-picture" title={lt('letterheads.simple.stepFooter')} url={imageUrl(simple.footerPicture ?? undefined)}
                                        onUpload={(file) => uploadPicture('footer', file)} onRemove={() => { setFooterRatio(null); setSimple((s) => ({ ...s, footerPicture: null })); }} onRatio={setFooterRatio} />
                                    {footerNeeds > page.margin_bottom && (
                                        <div className="rounded-control border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100" role="status">
                                            {footerNeeds > 70
                                                ? lt('letterheads.simple.footerTooTall')
                                                : (
                                                    <>
                                                        <p>{lt('letterheads.simple.footerTall', { height: footerHeight, margin: page.margin_bottom })}</p>
                                                        <button type="button" className={cx(secondaryBtn, 'mt-2')} onClick={() => setPage({ ...page, margin_bottom: footerNeeds })}>{lt('letterheads.simple.footerTallFix', { needed: footerNeeds })}</button>
                                                    </>
                                                )}
                                        </div>
                                    )}
                                </>
                            )
                            : <AreaEditor {...areaProps('footer')} />}
                    </Step>

                    {/* 4. Where the number and the date print */}
                    <Step number={4} title={lt('letterheads.simple.stepReference')} help={lt('letterheads.referenceHelp')}>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Segmented label={lt('letterheads.numberPlace')} value={page.number_align} options={placeOptions} onChange={(v) => setPage({ ...page, number_align: v as LayoutPage['number_align'] })} />
                            <Segmented label={lt('letterheads.datePlace')} value={page.date_align} options={placeOptions} onChange={(v) => setPage({ ...page, date_align: v as LayoutPage['date_align'] })} />
                        </div>
                        <p className="text-xs text-(--app-muted-foreground)">{lt('letterheads.referenceNoneHelp')}</p>
                        {numberMissing && <p className={errorCls} role="alert">{lt('letterheads.referenceHiddenNumber')}</p>}
                        {dateMissing && <p className={errorCls} role="alert">{lt('letterheads.referenceHiddenDate')}</p>}
                    </Step>

                    {/* 5. Stamp */}
                    <Step number={5} title={lt('letterheads.stamp')} help={lt('letterheads.stampHelp')}>
                        <div className="flex flex-wrap items-center gap-4">
                            <div className="flex size-24 shrink-0 items-center justify-center rounded-full border border-dashed border-(--app-border) bg-white dark:bg-gray-100">
                                {stampPreview ? <img src={stampPreview} alt="" className="max-h-20 max-w-20 object-contain" /> : <span className="px-2 text-center text-xs text-gray-500">{lt('letterheads.noStamp')}</span>}
                            </div>
                            <div className="space-y-2">
                                <label className={cx(secondaryBtn, 'inline-flex cursor-pointer items-center gap-1.5 focus-within:outline-2 focus-within:outline-[#122170]')}>
                                    <ImageAddIcon className="size-4" aria-hidden="true" />{lt('letterheads.chooseStamp')}
                                    <input type="file" accept="image/png,image/jpeg,image/svg+xml" className="sr-only" onChange={(e) => { const f = e.target.files?.[0] ?? null; form.setData('stamp', f); setStampUrl(f ? URL.createObjectURL(f) : null); }} />
                                </label>
                                {layout.has_stamp && <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.remove_stamp} onChange={(e) => form.setData('remove_stamp', e.target.checked)} /> {lt('letterheads.removeStamp')}</label>}
                                {errors.stamp && <p className={errorCls} role="alert">{errors.stamp}</p>}
                            </div>
                        </div>
                    </Step>

                    {/* 6. Page set-up, folded away: the defaults suit most letters */}
                    <details className={cx(panelCls, 'group')}>
                        <summary className="flex min-h-14 cursor-pointer list-none items-center gap-3 px-4 focus-visible:outline-2 focus-visible:outline-[#122170] [&::-webkit-details-marker]:hidden">
                            <StepBadge number={6} />
                            <span className="min-w-0">
                                <span className="block text-base font-semibold">{lt('letterheads.simple.stepPage')}</span>
                                <span className="block text-xs text-(--app-muted-foreground)">{lt('letterheads.simple.pageHelp')}</span>
                            </span>
                            <ChevronDown className="ms-auto size-5 shrink-0 transition-transform duration-200 group-open:rotate-180 motion-reduce:transition-none" aria-hidden="true" />
                        </summary>
                        <div className="grid gap-4 border-t border-(--app-border) p-4 sm:grid-cols-2">
                            <SelectField id="lf-paper" label={lt('letterheads.paper')} value={page.paper} onChange={(v) => setPage({ ...page, paper: v })}
                                options={options.papers.map((p) => [p, `${lt(`letterheads.papers.${p}`)} (${(PAPER_MM[p] ?? PAPER_MM.a4).join(' × ')} mm)`])} />
                            <Segmented label={lt('letterheads.orientation')} value={page.orientation} onChange={(v) => setPage({ ...page, orientation: v })} options={options.orientations.map((o) => [o, lt(`letterheads.orientations.${o}`)])} />
                            <fieldset className="sm:col-span-2">
                                <legend className={labelCls}>{lt('letterheads.margins')}</legend>
                                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                    {([['margin_top', 'marginTop'], ['margin_bottom', 'marginBottom'], ['margin_left', 'marginLeft'], ['margin_right', 'marginRight']] as const).map(([key, label]) => (
                                        <Stepper key={key} id={`lf-${key}`} label={lt(`letterheads.${label}`)} value={page[key]} min={5} max={70} step={1} onChange={(v) => setPage({ ...page, [key]: v })} small />
                                    ))}
                                </div>
                                <p className="mt-1 text-xs text-(--app-muted-foreground)">{lt('letterheads.marginHelp')}</p>
                            </fieldset>
                            <SelectField id="lf-body-font" label={lt('letterheads.bodyFont')} value={page.font_family} onChange={(v) => setPage({ ...page, font_family: v })} options={options.fonts.map((f) => [f.key, f.family])} />
                            <Stepper id="lf-body-size" label={lt('letterheads.fontSize')} value={page.font_size} min={8} max={16} step={0.5} onChange={(v) => setPage({ ...page, font_size: v })} />
                        </div>
                    </details>
                    {errors.layout && <p className={errorCls} role="alert">{errors.layout}</p>}

                    <div className="flex flex-wrap items-center gap-3">
                        <button type="submit" className={primaryBtn} disabled={form.processing}>{form.processing ? lt('letterheads.saving') : lt('letterheads.save')}</button>
                        <Link className={secondaryBtn} href={route('letters.letterheads.index', scope)}>{lt('common.cancel')}</Link>
                        {dirty && <span className="inline-flex items-center gap-2 text-sm font-medium text-amber-800 dark:text-amber-300" role="status"><span className="size-2 rounded-full bg-amber-500" aria-hidden="true" />{lt('letterheads.unsavedChanges')}</span>}
                    </div>
                </div>

                {/* The printed page */}
                <aside className="min-w-0 xl:col-span-5" aria-label={lt('letterheads.preview')}>
                    <section className={cx(panelCls, 'p-4 xl:sticky xl:top-4')}>
                        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                            <h2 className="text-base font-semibold">{lt('letterheads.preview')}</h2>
                            <Segmented label={lt('letterheads.previewLanguage')} value={previewLanguage} hideLabel onChange={(v) => setPreviewLanguage(v as 'am' | 'en')}
                                options={[['am', lt('letterheads.previewLanguages.am')], ['en', lt('letterheads.previewLanguages.en')]]} />
                        </div>
                        <Sheet page={page} header={rows.header} footer={rows.footer} vars={vars} language={previewLanguage} imageUrl={imageUrl} stamp={stampPreview} onPick={focusLine} />
                        <p className="mt-3 text-xs text-(--app-muted-foreground)">{lt('letterheads.simple.previewHelp')}</p>
                    </section>
                </aside>
            </form>
        </AuthenticatedLayout>
    );
}

/* ------------------------------------------------------------------ */
/* Form parts                                                          */
/* ------------------------------------------------------------------ */

/** The number and the date as the letter prints them: each on its own side, stacked when they share one. */
function ReferencePreview({ page, number, date }: { page: LayoutPage; number: [string, string]; date: [string, string] }): JSX.Element | null {
    const slots: Record<Position, [string, string][]> = { left: [], center: [], right: [] };
    if (page.number_align !== 'none') slots[page.number_align].push(number);
    if (page.date_align !== 'none') slots[page.date_align].push(date);
    const usedSlots = POSITIONS.filter((p) => slots[p].length > 0);
    const columns: Position[] = usedSlots.length <= 1 ? usedSlots : usedSlots.includes('center') ? POSITIONS : ['left', 'right'];
    if (columns.length === 0) return null;

    return (
        <div className="mt-4 flex w-full">
            {columns.map((p) => (
                <div key={p} style={{ width: `${100 / columns.length}%`, textAlign: p }}>
                    {slots[p].map(([label, value]) => <div key={label}>{label}: <strong>{value}</strong></div>)}
                </div>
            ))}
        </div>
    );
}

/** The header or the footer as one uploaded picture. */
function PictureEditor({ id, title, url, onUpload, onRemove, onRatio }: {
    id: string; title: string; url: string | null; onUpload: (file: File | null) => void; onRemove: () => void; onRatio?: (ratio: number) => void;
}): JSX.Element {
    const { lt } = useLetters();

    return (
        <div className="space-y-3">
            <p className="text-xs text-(--app-muted-foreground)">{lt('letterheads.simple.pictureHelp')}</p>
            {url
                ? <img src={url} alt={title} className="max-h-48 w-full rounded-control border border-(--app-border) bg-white object-contain"
                    onLoad={(e) => { const img = e.currentTarget; if (img.naturalWidth > 0) onRatio?.(img.naturalHeight / img.naturalWidth); }} />
                : <p className="flex min-h-24 items-center justify-center rounded-control border border-dashed border-(--app-border) px-3 text-sm text-(--app-muted-foreground)">{lt('letterheads.simple.noPicture')}</p>}
            <div className="flex flex-wrap items-center gap-2">
                <label className={cx(url ? secondaryBtn : primaryBtn, 'inline-flex cursor-pointer items-center gap-1.5 focus-within:outline-2 focus-within:outline-[#122170]')}>
                    <ImageAddIcon className="size-4" aria-hidden="true" />{url ? lt('letterheads.simple.changePicture') : lt('letterheads.simple.choosePicture')}
                    <input id={id} type="file" accept="image/png,image/jpeg" className="sr-only" aria-label={`${title}: ${url ? lt('letterheads.simple.changePicture') : lt('letterheads.simple.choosePicture')}`}
                        onChange={(e) => { onUpload(e.target.files?.[0] ?? null); e.target.value = ''; }} />
                </label>
                {url && <button type="button" className={cx(secondaryBtn, 'inline-flex items-center gap-1.5')} onClick={onRemove}><TrashIcon className="size-4" aria-hidden="true" />{lt('letterheads.simple.removePicture')}</button>}
            </div>
        </div>
    );
}

function StepBadge({ number }: { number: number }): JSX.Element {
    return <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-[#122170] text-sm font-semibold text-white dark:bg-blue-300 dark:text-slate-900" aria-hidden="true">{number}</span>;
}

function Step({ number, title, help, children }: { number: number; title: string; help?: string; children: ReactNode }): JSX.Element {
    return (
        <section className={cx(panelCls, 'p-4')} aria-labelledby={`lf-step-${number}`}>
            <div className="mb-4 flex items-center gap-3">
                <StepBadge number={number} />
                <div className="min-w-0">
                    <h2 id={`lf-step-${number}`} className="text-base font-semibold">{title}</h2>
                    {help && <p className="text-xs text-(--app-muted-foreground)">{help}</p>}
                </div>
            </div>
            <div className="space-y-5">{children}</div>
        </section>
    );
}

/** A small picture of where a position sits across the page. */
function PositionMark({ position }: { position: Position }): JSX.Element {
    return (
        <span className="inline-flex h-4 w-9 shrink-0 gap-0.5 rounded-sm border border-(--app-border) p-0.5" aria-hidden="true">
            {POSITIONS.map((p) => <span key={p} className={cx('flex-1 rounded-[1px]', p === position ? 'bg-[#122170] dark:bg-blue-300' : 'bg-(--app-muted)')} />)}
        </span>
    );
}

interface AreaEditorProps {
    id: string;
    area: Area;
    options: LayoutEditorOptions;
    bodyFont: string;
    vars: Record<string, string>;
    imageUrl: (key?: string) => string | null;
    orgLogoKey: string;
    orgLogoOffered: boolean;
    platformLayout: boolean;
    onBlock: (position: Position, change: (block: Block) => Block) => void;
    onUpload: (position: Position, file: File | null) => void;
}

/** One area of the page: what prints on the left, in the centre and on the right, each a logo and/or text lines. */
function AreaEditor({ id, area, options, bodyFont, vars, imageUrl, orgLogoKey, orgLogoOffered, platformLayout, onBlock, onUpload }: AreaEditorProps): JSX.Element {
    const { lt } = useLetters();
    const [open, setOpen] = useState<string | null>(null);
    const [focused, setFocused] = useState<{ position: Position; index: number } | null>(null);
    const hasLines = POSITIONS.some((p) => area[p].lines.length > 0);

    const setLines = (position: Position, lines: LayoutItem[]) => onBlock(position, (block) => ({ ...block, lines }));
    const addLine = (position: Position, text = '') => {
        const index = area[position].lines.length;
        setLines(position, [...area[position].lines, newLine(text)]);
        setFocused({ position, index });
        requestAnimationFrame(() => document.getElementById(`${id}-${position}-${index}`)?.focus());
    };

    function insert(key: string) {
        const token = `{{${key}}}`;
        const target = focused && area[focused.position].lines[focused.index] ? focused : null;
        if (!target) {
            const position = POSITIONS.find((p) => area[p].lines.length > 0) ?? 'center';
            addLine(position, token);
            return;
        }
        const el = document.getElementById(`${id}-${target.position}-${target.index}`) as HTMLTextAreaElement | null;
        const lines = area[target.position].lines;
        const text = lines[target.index].text ?? '';
        const at = el?.selectionStart ?? text.length;
        setLines(target.position, lines.map((line, i) => (i === target.index ? { ...line, text: (text.slice(0, at) + token + text.slice(at)).slice(0, 500) } : line)));
        requestAnimationFrame(() => el?.focus());
    }

    return (
        <div className="space-y-3">
            {POSITIONS.map((position) => {
                const block = area[position];
                const label = lt(`letterheads.simple.positions.${position}`);
                const logoUrl = imageUrl(block.logo?.image);
                const usesOrgLogo = block.logo?.image === orgLogoKey;

                return (
                    <fieldset key={position} className={cx('rounded-control border', used(block) ? 'border-(--app-border) bg-(--app-card)' : 'border-dashed border-(--app-border)')}>
                        <legend className="sr-only">{label}</legend>
                        <div className="flex flex-wrap items-center gap-2 px-3 py-2">
                            <PositionMark position={position} />
                            <span className="text-sm font-semibold">{label}</span>
                            {!used(block) && <span className="text-xs text-(--app-muted-foreground)">{lt('letterheads.simple.empty')}</span>}
                            <span className="ms-auto flex flex-wrap items-center gap-1.5">
                                {used(block) && (
                                    <Segmented label={`${label}: ${lt('letterheads.simple.textAlign')}`} value={block.align} iconOnly onChange={(v) => onBlock(position, (b) => ({ ...b, align: v as Align }))}
                                        options={[['left', lt('letterheads.aligns.left'), <AlignLeftIcon key="l" className="size-4" aria-hidden="true" />], ['center', lt('letterheads.aligns.center'), <AlignCenterIcon key="c" className="size-4" aria-hidden="true" />], ['right', lt('letterheads.aligns.right'), <AlignRightIcon key="r" className="size-4" aria-hidden="true" />]]} />
                                )}
                                {block.lines.length < options.max_items && (
                                    <button type="button" className={cx(secondaryBtn, 'inline-flex items-center gap-1')} onClick={() => addLine(position)}>
                                        <TextLineIcon className="size-4" aria-hidden="true" />{lt('letterheads.simple.addText')}
                                    </button>
                                )}
                                {!block.logo && (
                                    <label className={cx(secondaryBtn, 'inline-flex cursor-pointer items-center gap-1 focus-within:outline-2 focus-within:outline-[#122170]')}>
                                        <ImageAddIcon className="size-4" aria-hidden="true" />{lt('letterheads.simple.addLogo')}
                                        <input type="file" accept="image/png,image/jpeg,image/svg+xml" className="sr-only" aria-label={`${label}: ${lt('letterheads.simple.addLogo')}`} onChange={(e) => { onUpload(position, e.target.files?.[0] ?? null); e.target.value = ''; }} />
                                    </label>
                                )}
                                {!block.logo && orgLogoOffered && (
                                    <button type="button" className={secondaryBtn} onClick={() => onBlock(position, (b) => ({ ...b, width: undefined, logo: { image: orgLogoKey, width: 22 } }))}>{lt('letterheads.simple.useOrgLogo')}</button>
                                )}
                            </span>
                        </div>

                        {block.logo && (
                            <div className="flex flex-wrap items-center gap-3 border-t border-(--app-border) px-3 py-2">
                                <div className="flex size-14 shrink-0 items-center justify-center rounded-control border border-dashed border-(--app-border) bg-white p-1 dark:bg-gray-100">
                                    {logoUrl ? <img src={logoUrl} alt="" className="max-h-full max-w-full object-contain" /> : <GalleryIcon className="size-6 text-gray-400" aria-hidden="true" />}
                                </div>
                                <div className="min-w-40 flex-1">
                                    <label htmlFor={`${id}-${position}-logo-size`} className="text-xs text-(--app-muted-foreground)">
                                        {usesOrgLogo ? (platformLayout ? lt('letterheads.simple.orgLogoEach') : logoUrl ? lt('letterheads.simple.orgLogoUsed') : lt('letterheads.simple.orgLogoMissing')) : lt('letterheads.simple.ownImage')}
                                        {' · '}{lt('letterheads.simple.logoSize')}: <span className="tabular-nums">{block.logo.width} mm</span>
                                    </label>
                                    <input id={`${id}-${position}-logo-size`} type="range" min={10} max={60} step={1} value={block.logo.width} className="block w-full accent-[#122170]"
                                        onChange={(e) => onBlock(position, (b) => ({ ...b, width: undefined, logo: b.logo ? { ...b.logo, width: Number(e.target.value) } : null }))} />
                                </div>
                                <label className={cx(secondaryBtn, 'inline-flex cursor-pointer items-center gap-1 focus-within:outline-2 focus-within:outline-[#122170]')}>
                                    <ImageAddIcon className="size-4" aria-hidden="true" />{lt('letterheads.simple.changeLogo')}
                                    <input type="file" accept="image/png,image/jpeg,image/svg+xml" className="sr-only" aria-label={`${label}: ${lt('letterheads.simple.changeLogo')}`} onChange={(e) => { onUpload(position, e.target.files?.[0] ?? null); e.target.value = ''; }} />
                                </label>
                                <IconButton label={`${label}: ${lt('letterheads.simple.removeLogo')}`} danger onClick={() => onBlock(position, (b) => ({ ...b, width: undefined, logo: null }))}><TrashIcon className="size-4" /></IconButton>
                            </div>
                        )}

                        {block.lines.length > 0 && (
                            <ol className="space-y-2 border-t border-(--app-border) p-2">
                                {block.lines.map((line, i) => {
                                    const key = `${position}-${i}`;
                                    const expanded = open === key;
                                    const font = options.fonts.find((f) => f.key === (line.font_family || bodyFont));
                                    const update = (patch: Partial<LayoutItem>) => setLines(position, block.lines.map((l, x) => (x === i ? { ...l, ...patch } : l)));
                                    const move = (delta: number) => {
                                        const next = [...block.lines];
                                        [next[i + delta], next[i]] = [next[i], next[i + delta]];
                                        setLines(position, next);
                                        setOpen(null);
                                    };

                                    return (
                                        <li key={i} className={cx('rounded-control border', expanded ? 'border-[#122170]/50' : 'border-(--app-border)')}>
                                            <div className="flex items-start gap-1 p-1.5">
                                                <textarea id={`${id}-${key}`} rows={Math.min(4, (line.text ?? '').split('\n').length)} className={cx(textareaCls, 'min-w-0 flex-1 resize-none')} value={line.text ?? ''} maxLength={500}
                                                    placeholder={lt('letterheads.simple.linePlaceholder')} aria-label={`${label} ${i + 1}`}
                                                    onFocus={() => setFocused({ position, index: i })} onChange={(e) => update({ text: e.target.value })} />
                                                <button type="button" aria-expanded={expanded} aria-controls={`${id}-${key}-format`} onClick={() => setOpen(expanded ? null : key)}
                                                    className={cx('inline-flex min-h-10 shrink-0 items-center gap-1 rounded-control border px-2.5 text-sm transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-[#122170]',
                                                        expanded ? 'border-[#122170] bg-[#122170]/5 text-[#122170] dark:text-blue-200' : 'border-(--app-border) hover:border-[#122170]/60')}>
                                                    <span className="size-3 rounded-full ring-1 ring-black/15" style={{ backgroundColor: line.color }} aria-hidden="true" />
                                                    <span className="tabular-nums">{line.font_size}</span>
                                                    {line.bold && <BoldIcon className="size-3.5" aria-hidden="true" />}
                                                    <span className="hidden sm:inline">{lt('letterheads.format')}</span>
                                                    <ChevronDown className={cx('size-4 transition-transform duration-200 motion-reduce:transition-none', expanded && 'rotate-180')} aria-hidden="true" />
                                                </button>
                                                <IconButton label={lt('letterheads.moveUp')} disabled={i === 0} onClick={() => move(-1)}><ChevronUp className="size-4" /></IconButton>
                                                <IconButton label={lt('letterheads.moveDown')} disabled={i === block.lines.length - 1} onClick={() => move(1)}><ChevronDown className="size-4" /></IconButton>
                                                <IconButton label={lt('letterheads.simple.removeLine')} danger onClick={() => { setLines(position, block.lines.filter((_, x) => x !== i)); setOpen(null); }}><TrashIcon className="size-4" /></IconButton>
                                            </div>
                                            {/\{\{/.test(line.text ?? '') && (
                                                <p className="px-3 pb-2 text-xs text-(--app-muted-foreground)">
                                                    {lt('letterheads.simple.printsAs')} <span className="whitespace-pre-line font-medium text-(--app-foreground)">{fillText(line.text ?? '', vars).trim() || lt('letterheads.simple.printsNothing')}</span>
                                                </p>
                                            )}
                                            {expanded && (
                                                <div id={`${id}-${key}-format`} className="space-y-4 border-t border-(--app-border) p-3">
                                                    <div className="flex flex-wrap items-end gap-4">
                                                        <Stepper id={`${id}-${key}-size`} label={lt('letterheads.size')} value={line.font_size ?? 10} min={6} max={28} step={0.5} onChange={(v) => update({ font_size: v })} />
                                                        <div>
                                                            <p className={labelCls}>{lt('letterheads.simple.emphasis')}</p>
                                                            <div className="flex gap-2">
                                                                <Toggle label={lt('letterheads.bold')} pressed={!!line.bold} onChange={(v) => update({ bold: v })}><BoldIcon className="size-4" aria-hidden="true" /></Toggle>
                                                                <Toggle label={lt('letterheads.underline')} pressed={!!line.underline} onChange={(v) => update({ underline: v })}><UnderlineIcon className="size-4" aria-hidden="true" /></Toggle>
                                                            </div>
                                                        </div>
                                                        <div className="min-w-44 flex-1">
                                                            <SelectField id={`${id}-${key}-font`} label={lt('letterheads.font')} value={line.font_family ?? ''} onChange={(v) => update({ font_family: v })}
                                                                options={[['', lt('letterheads.fontInherit')], ...options.fonts.map((f) => [f.key, f.family] as [string, string])]} />
                                                        </div>
                                                    </div>
                                                    {line.bold && font && !font.bold && <p className="text-xs text-amber-800 dark:text-amber-300" role="note">{lt('letterheads.noBold')}</p>}
                                                    <ColorPicker label={lt('letterheads.color')} value={line.color ?? '#111111'} onChange={(v) => update({ color: v })} />
                                                    <Segmented label={lt('letterheads.language')} value={line.language} onChange={(v) => update({ language: v as LayoutItem['language'] })}
                                                        options={(['both', 'am', 'en'] as const).map((l) => [l, lt(`letterheads.languages.${l}`)])} />
                                                </div>
                                            )}
                                        </li>
                                    );
                                })}
                            </ol>
                        )}
                    </fieldset>
                );
            })}

            {hasLines && (
                <div>
                    <p className="mb-1 text-xs text-(--app-muted-foreground)">{lt('letterheads.simple.insertField')}</p>
                    <div className="flex flex-wrap gap-1.5" role="group" aria-label={lt('letterheads.simple.insertField')}>
                        {options.placeholders.map((p) => (
                            <button key={p} type="button" title={`{{${p}}}`} onMouseDown={(e) => e.preventDefault()} onClick={() => insert(p)}
                                className="inline-flex min-h-8 items-center gap-1 rounded-full border border-(--app-border) px-2.5 text-xs transition-colors duration-150 hover:border-[#122170] hover:text-[#122170] focus-visible:outline-2 focus-visible:outline-[#122170]">
                                <PlusIcon className="size-3" aria-hidden="true" />{lt(`letterheads.placeholderNames.${p.replace(/\./g, '_')}`)}
                            </button>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}

function RuleControl({ label, rule, onChange }: { label: string; rule: Rule; onChange: (rule: Rule) => void }): JSX.Element {
    const { lt } = useLetters();
    return (
        <div className="rounded-control border border-(--app-border) p-3">
            <Switch label={label} checked={rule.on} onChange={(on) => onChange({ ...rule, on })} />
            {rule.on && (
                <div className="mt-2 grid gap-4 sm:grid-cols-[auto_minmax(0,1fr)]">
                    <Segmented label={lt('letterheads.borderStyle')} value={rule.style} onChange={(v) => onChange({ ...rule, style: v as Rule['style'] })}
                        options={(['thin', 'thick', 'double'] as const).map((s) => [s, lt(`letterheads.borderStyles.${s}`)])} />
                    <ColorPicker label={lt('letterheads.borderColor')} value={rule.color} onChange={(color) => onChange({ ...rule, color })} />
                </div>
            )}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/* The printed page                                                    */
/* ------------------------------------------------------------------ */

function fillText(text: string, vars: Record<string, string>): string {
    return text.replace(TOKEN, (_, key: string) => vars[key] ?? '');
}

function prints(item: LayoutItem, language: string, vars: Record<string, string>): boolean {
    if (!['both', language].includes(item.language ?? 'both')) return false;
    if (item.type === 'image') return true;
    return fillText(item.text ?? '', vars).replace(/[\s·|,\-–—/:]+/g, '') !== '';
}

function Rows({ section, rows, page, vars, language, imageUrl, onPick }: {
    section: 'header' | 'footer'; rows: LayoutRow[]; page: LayoutPage; vars: Record<string, string>; language: string; imageUrl: (key?: string) => string | null;
    onPick: (section: 'header' | 'footer', r: number, c: number, i: number) => void;
}): JSX.Element {
    return (
        <>
            {rows.map((row, r) => {
                const line = `${BORDER[row.border_style] ?? '1px solid'} ${row.border_color}`;
                const top = row.border_position === 'top' || row.border_position === 'both';
                const bottom = row.border_position === 'bottom' || row.border_position === 'both';
                const style: CSSProperties = { marginBottom: `${row.space_after}mm`, borderTop: top ? line : undefined, borderBottom: bottom ? line : undefined, paddingTop: top ? 3 : undefined, paddingBottom: bottom ? 3 : undefined };
                const fixed = row.cells.reduce((sum, c) => sum + (c.width > 0 ? c.width : 0), 0);
                const autos = row.cells.filter((c) => c.width <= 0).length;
                const share = autos > 0 ? Math.max(0, 100 - fixed) / autos : 0;

                return (
                    <div key={r} className="flex w-full" style={style}>
                        {row.cells.map((cell, c) => {
                            let textIndex = -1;
                            return (
                                <div key={c} className="flex flex-col px-0.5" style={{ width: `${cell.width > 0 ? cell.width : share}%`, textAlign: cell.align, justifyContent: 'center' }}>
                                    {cell.items.map((item, i) => {
                                        if (item.type === 'text') textIndex += 1;
                                        if (!prints(item, language, vars)) return null;
                                        if (item.type === 'image') {
                                            const url = imageUrl(item.image);
                                            return url
                                                ? <div key={i} style={{ textAlign: cell.align }}><img src={url} alt="" style={item.full ? { width: '100%', display: 'block' } : { width: `${item.width_mm}mm`, maxHeight: `${(item.width_mm ?? 22) * 1.4}mm`, display: 'inline-block' }} /></div>
                                                : <div key={i} style={{ textAlign: cell.align }}><span className="inline-flex items-center justify-center rounded border border-dashed border-gray-300 text-gray-400" style={{ width: `${item.width_mm}mm`, height: `${item.width_mm}mm` }}><GalleryIcon className="size-6" aria-hidden="true" /></span></div>;
                                        }
                                        const index = textIndex;
                                        return (
                                            <div key={i} className="cursor-pointer rounded-[2px] outline-offset-2 hover:outline hover:outline-1 hover:outline-dashed hover:outline-[#1D4ED8]/60"
                                                onClick={(e: MouseEvent) => { e.stopPropagation(); onPick(section, r, c, index); }}
                                                style={{ fontFamily: FONT_CSS[item.font_family || page.font_family] ?? FONT_CSS.abyssinica, fontSize: `${item.font_size}pt`, lineHeight: 1.3, color: item.color, fontWeight: item.bold ? 700 : 400, textDecoration: item.underline ? 'underline' : undefined, whiteSpace: 'pre-line' }}>
                                                {fillText(item.text ?? '', vars)}
                                            </div>
                                        );
                                    })}
                                </div>
                            );
                        })}
                    </div>
                );
            })}
        </>
    );
}

/** The page to scale: margins, header, a stand-in letter with the reference block and stamp, and the footer in the bottom margin. */
function Sheet({ page, header, footer, vars, language, imageUrl, stamp, onPick }: {
    page: LayoutPage; header: LayoutSection; footer: LayoutSection; vars: Record<string, string>; language: string; imageUrl: (key?: string) => string | null; stamp: string | null;
    onPick: (section: 'header' | 'footer', r: number, c: number, i: number) => void;
}): JSX.Element {
    const { lt } = useLetters();
    const holder = useRef<HTMLDivElement>(null);
    const [scale, setScale] = useState(0.5);
    const [w, h] = (() => { const [a, b] = PAPER_MM[page.paper] ?? PAPER_MM.a4; return page.orientation === 'landscape' ? [b, a] : [a, b]; })();

    // Fit the width, and on wide screens the window's height, so the whole page stays in view while the form scrolls.
    useEffect(() => {
        const el = holder.current;
        if (!el) return;
        const measure = () => {
            const byWidth = el.clientWidth / (w * MM_PX);
            const byHeight = window.matchMedia('(min-width: 1280px)').matches ? (window.innerHeight - 190) / (h * MM_PX) : Infinity;
            setScale(Math.max(0.2, Math.min(1, byWidth, byHeight)));
        };
        measure();
        const observer = new ResizeObserver(measure);
        observer.observe(el);
        window.addEventListener('resize', measure);
        return () => { observer.disconnect(); window.removeEventListener('resize', measure); };
    }, [w, h]);

    return (
        <div ref={holder} className="w-full">
            <div className="mx-auto overflow-hidden rounded-sm" style={{ width: w * MM_PX * scale, height: h * MM_PX * scale }}>
                <div className="relative bg-white text-black shadow-md ring-1 ring-black/10" style={{
                    width: `${w}mm`, height: `${h}mm`, transform: `scale(${scale})`, transformOrigin: 'top left',
                    padding: `${page.margin_top}mm ${page.margin_right}mm ${page.margin_bottom}mm ${page.margin_left}mm`,
                    fontFamily: FONT_CSS[page.font_family] ?? FONT_CSS.abyssinica, fontSize: `${page.font_size}pt`, color: page.text_color,
                }}>
                    <Rows section="header" rows={header.rows} page={page} vars={vars} language={language} imageUrl={imageUrl} onPick={onPick} />
                    <ReferencePreview page={page} number={[lt('common.number'), vars['letter.number']]} date={[lt('common.date'), vars['letter.date_ec']]} />
                    <div className="mt-6 space-y-2" aria-hidden="true">
                        {[92, 100, 96, 88, 100, 70].map((width, i) => <div key={i} className="h-2 rounded bg-gray-200" style={{ width: `${width}%` }} />)}
                    </div>
                    <div className="mt-8 flex items-end justify-between" aria-hidden="true">
                        <div className="h-3 w-32 rounded bg-gray-200" />
                        {stamp && <img src={stamp} alt="" style={{ width: '32mm', opacity: 0.9 }} />}
                    </div>
                    <div className="absolute" style={{ left: `${page.margin_left}mm`, right: `${page.margin_right}mm`, bottom: '6mm' }}>
                        <Rows section="footer" rows={footer.rows} page={page} vars={vars} language={language} imageUrl={imageUrl} onPick={onPick} />
                    </div>
                </div>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/* Controls                                                            */
/* ------------------------------------------------------------------ */

function Switch({ label, checked, onChange }: { label: string; checked: boolean; onChange: (v: boolean) => void }): JSX.Element {
    return (
        <button type="button" role="switch" aria-checked={checked} onClick={() => onChange(!checked)} className="group inline-flex min-h-11 items-center gap-3 text-start text-sm focus-visible:outline-none">
            <span className={cx('relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors duration-200 group-focus-visible:outline-2 group-focus-visible:outline-offset-2 group-focus-visible:outline-[#122170]', checked ? 'bg-[#122170]' : 'bg-gray-300 dark:bg-gray-600')}>
                <span className={cx('inline-block size-5 rounded-full bg-white shadow transition-transform duration-200 motion-reduce:transition-none', checked ? 'translate-x-5.5 rtl:-translate-x-5.5' : 'translate-x-0.5 rtl:-translate-x-0.5')} />
            </span>
            {label}
        </button>
    );
}

function SelectField({ id, label, value, onChange, options }: { id: string; label: string; value: string; onChange: (v: string) => void; options: [string, string][] }): JSX.Element {
    return (
        <div>
            <label htmlFor={id} className={labelCls}>{label}</label>
            <select id={id} className={inputCls} value={value} onChange={(e) => onChange(e.target.value)}>
                {options.map(([v, text]) => <option key={v} value={v}>{text}</option>)}
            </select>
        </div>
    );
}

function Segmented({ label, value, onChange, options, iconOnly, hideLabel }: { label: string; value: string; onChange: (v: string) => void; options: SegmentOption[]; iconOnly?: boolean; hideLabel?: boolean }): JSX.Element {
    return (
        <div>
            {!iconOnly && !hideLabel && <p className={labelCls}>{label}</p>}
            <div role="radiogroup" aria-label={label} className="inline-flex flex-wrap rounded-control border border-(--app-border) bg-(--app-muted)/50 p-0.5">
                {options.map(([v, text, icon]) => (
                    <button key={v} type="button" role="radio" aria-checked={value === v} aria-label={iconOnly ? text : undefined} title={iconOnly ? text : undefined} onClick={() => onChange(v)}
                        className={cx('inline-flex min-h-9 items-center justify-center gap-1 rounded-[5px] px-3 text-sm transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-[#122170]',
                            iconOnly && 'w-9 px-0',
                            value === v ? 'bg-(--app-card) font-medium text-[#122170] shadow-sm dark:text-blue-200' : 'text-(--app-muted-foreground) hover:text-(--app-foreground)')}>
                        {iconOnly ? icon ?? text : text}
                    </button>
                ))}
            </div>
        </div>
    );
}

function Toggle({ label, pressed, onChange, children }: { label: string; pressed: boolean; onChange: (v: boolean) => void; children: ReactNode }): JSX.Element {
    return (
        <button type="button" aria-pressed={pressed} aria-label={label} title={label} onClick={() => onChange(!pressed)}
            className={cx('inline-flex size-10 items-center justify-center rounded-control border transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-[#122170]',
                pressed ? 'border-[#122170] bg-[#122170] text-white' : 'border-(--app-border) hover:border-[#122170]/60')}>
            {children}
        </button>
    );
}

function Stepper({ id, label, value, min, max, step, onChange, small }: { id: string; label: string; value: number; min: number; max: number; step: number; onChange: (v: number) => void; small?: boolean }): JSX.Element {
    const { lt } = useLetters();
    const clamp = (v: number) => Math.min(max, Math.max(min, Math.round(v * 10) / 10));
    const btn = 'inline-flex w-9 items-center justify-center text-lg transition-colors duration-150 hover:bg-(--app-muted) focus-visible:outline-2 focus-visible:outline-[#122170] disabled:opacity-40';
    return (
        <div>
            <label htmlFor={id} className={labelCls}>{label}</label>
            <div className="inline-flex h-10 items-stretch overflow-hidden rounded-control border border-(--app-border) bg-(--app-card)">
                <button type="button" className={btn} aria-label={`${lt('letterheads.decrease')}: ${label}`} disabled={value <= min} onClick={() => onChange(clamp(value - step))}>−</button>
                <input id={id} type="number" inputMode="decimal" value={value} min={min} max={max} step="any"
                    className={cx('border-x border-(--app-border) bg-transparent text-center text-sm tabular-nums focus-visible:outline-2 focus-visible:outline-[#122170]', small ? 'w-12' : 'w-16')}
                    onChange={(e) => { const v = Number(e.target.value); onChange(Number.isFinite(v) ? clamp(v) : min); }} />
                <button type="button" className={btn} aria-label={`${lt('letterheads.increase')}: ${label}`} disabled={value >= max} onClick={() => onChange(clamp(value + step))}>+</button>
            </div>
        </div>
    );
}

function ColorPicker({ label, value, onChange }: { label: string; value: string; onChange: (v: string) => void }): JSX.Element {
    const { lt } = useLetters();
    const current = value.toUpperCase();
    return (
        <div>
            <p className={labelCls}>{label}</p>
            <div className="flex flex-wrap items-center gap-1.5" role="group" aria-label={label}>
                {SWATCHES.map((c) => (
                    <button key={c} type="button" aria-label={c} aria-pressed={current === c} title={c} onClick={() => onChange(c)}
                        className={cx('size-8 rounded-full border-2 border-white transition-transform duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#122170] motion-reduce:transition-none',
                            current === c ? 'scale-110 ring-2 ring-[#122170]' : 'ring-1 ring-black/15 hover:scale-105')}
                        style={{ backgroundColor: c }} />
                ))}
                <label className="ms-1 inline-flex min-h-8 cursor-pointer items-center gap-1.5 rounded-full border border-(--app-border) px-2 text-xs focus-within:outline-2 focus-within:outline-[#122170]" title={lt('letterheads.customColor')}>
                    <input type="color" value={value} onChange={(e) => onChange(e.target.value.toUpperCase())} className="size-5 cursor-pointer rounded-full border-0 bg-transparent p-0" aria-label={lt('letterheads.customColor')} />
                    <span>{lt('letterheads.customColor')}</span>
                </label>
            </div>
        </div>
    );
}

function IconButton({ label, onClick, disabled, danger, children }: { label: string; onClick: () => void; disabled?: boolean; danger?: boolean; children: ReactNode }): JSX.Element {
    return (
        <button type="button" aria-label={label} title={label} disabled={disabled} onClick={onClick}
            className={cx('inline-flex size-9 shrink-0 items-center justify-center rounded-control text-(--app-muted-foreground) transition-colors duration-150 hover:bg-(--app-muted) hover:text-(--app-foreground) focus-visible:outline-2 focus-visible:outline-[#122170] disabled:pointer-events-none disabled:opacity-35',
                danger && 'hover:bg-red-50 hover:text-red-700 dark:hover:bg-red-950/40 dark:hover:text-red-400')}>
            {children}
        </button>
    );
}
