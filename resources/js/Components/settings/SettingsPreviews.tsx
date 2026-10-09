import { CreditCard, QrCodeIcon } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import type { SettingsField } from '@/lib/settings';
import { Button, Card, StatusBadge, cx } from '@euisis/ui';
import { Link } from '@inertiajs/react';
import { useEffect, useMemo, type ReactNode } from 'react';

type Values = Record<string, unknown>;

/** A chosen file's object URL, or the saved asset's URL. */
function useAssetUrl(value: unknown, savedUrl: string | null | undefined): string | null {
    const objectUrl = useMemo(() => (value instanceof File ? URL.createObjectURL(value) : null), [value]);
    useEffect(() => () => { if (objectUrl) URL.revokeObjectURL(objectUrl); }, [objectUrl]);
    return objectUrl ?? savedUrl ?? null;
}

/** Readable text colour over a background: dark on light colours, white on dark ones. */
function textOn(hex: string): string {
    const match = /^#([0-9a-f]{6})$/i.exec(hex);
    if (!match) return '#FFFFFF';
    const [r, g, b] = [0, 2, 4].map((offset) => parseInt(match[1].slice(offset, offset + 2), 16) / 255)
        .map((channel) => (channel <= 0.03928 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4));
    return 0.2126 * r + 0.7152 * g + 0.0722 * b > 0.4 ? '#0F172A' : '#FFFFFF';
}

function colour(values: Values, key: string, fallback: string): string {
    const value = values[key];
    return typeof value === 'string' && /^#[0-9a-f]{6}$/i.test(value) ? value : fallback;
}

function PreviewFrame({ title, note, children }: { title: string; note: string; children: ReactNode }) {
    return (
        <Card className="overflow-hidden p-0">
            <div className="flex flex-wrap items-baseline justify-between gap-2 border-b border-[color:var(--app-border)] px-5 py-3">
                <h3 className="text-sm font-semibold">{title}</h3>
                <p className="text-xs text-[color:var(--app-muted-foreground)]">{note}</p>
            </div>
            <div className="bg-[color:var(--app-surface-muted)] p-4 sm:p-5">{children}</div>
        </Card>
    );
}

/** How the name, logo and favicon read in the browser tab and the app's header. */
export function BrandingPreview({ values, fields, sidebarColor }: { values: Values; fields: SettingsField[]; sidebarColor?: string }) {
    const { t } = useLocale();
    const headerBackground = colour({ sidebarColor }, 'sidebarColor', '#0F172A');
    const saved = (key: string) => fields.find((field) => field.key === key)?.asset_url;
    const logo = useAssetUrl(values.identity_system_logo, saved('identity_system_logo'));
    const favicon = useAssetUrl(values.favicon, saved('favicon'));
    const name = String(values.application_name || '') || t('settings.previewPlaceholders.applicationName');
    const shortName = String(values.application_short_name || '') || t('settings.previewPlaceholders.shortName');
    const organization = String(values.organization_name || '') || t('settings.previewPlaceholders.organization');
    const environment = String(values.system_environment_label || 'production');

    return (
        <PreviewFrame title={t('settings.groups.brandingPreview')} note={t('settings.previewNote')}>
            <div className="overflow-hidden rounded-[var(--radius-card)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)] shadow-sm">
                <div className="flex items-center gap-2 border-b border-[color:var(--app-border)] bg-[color:var(--app-surface-muted)] px-3 py-2">
                    <span aria-hidden="true" className="flex gap-1">{[0, 1, 2].map((dot) => <span key={dot} className="h-2 w-2 rounded-full bg-[color:var(--app-border-strong)]" />)}</span>
                    <span className="flex min-w-0 max-w-xs items-center gap-2 rounded-t-md bg-[color:var(--app-surface)] px-3 py-1 text-xs">
                        {favicon ? <img src={favicon} alt="" className="h-3.5 w-3.5 shrink-0 object-contain" /> : <span aria-hidden="true" className="h-3.5 w-3.5 shrink-0 rounded-sm bg-[color:var(--app-border-strong)]" />}
                        <span className="truncate">{name}</span>
                    </span>
                </div>
                <div className="flex items-center gap-3 px-4 py-3" style={{ backgroundColor: headerBackground, color: textOn(headerBackground) }}>
                    <span className="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-white/10">
                        {logo ? <img src={logo} alt="" className="h-full w-full object-contain p-1" /> : <CreditCard className="h-5 w-5 opacity-70" aria-hidden="true" />}
                    </span>
                    <span className="min-w-0 flex-1">
                        <span className="block truncate text-sm font-semibold">{shortName}</span>
                        <span className="block truncate text-xs opacity-75">{organization}</span>
                    </span>
                    {environment !== 'production' && (
                        <span className="shrink-0 rounded-full bg-amber-400/90 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-amber-950">{environment}</span>
                    )}
                </div>
            </div>
        </PreviewFrame>
    );
}

// Mirrors html[data-card-radius] and html[data-button-style] in resources/css/app.css.
const RADIUS: Record<string, number> = { sm: 2, md: 4, lg: 6, xl: 8, '2xl': 12 };
const ROW_HEIGHT: Record<string, number> = { compact: 28, comfortable: 36, spacious: 44 };
const BUTTON_RADIUS: Record<string, string> = { rounded: '6px', soft: '8px', square: '0' };

/** A small app shell drawn with the chosen colours, radius, density and theme. */
export function AppearancePreview({ values }: { values: Values }) {
    const { t } = useLocale();
    const primary = colour(values, 'primary_color', '#1E40AF');
    const secondary = colour(values, 'secondary_color', '#1D3084');
    const accent = colour(values, 'accent_color', '#D12908');
    const sidebar = colour(values, 'sidebar_color', '#0F172A');
    // "Follow the device" previews in whichever theme this browser is showing now.
    const dark = values.default_theme === 'dark'
        || (values.default_theme === 'system' && typeof document !== 'undefined' && document.documentElement.classList.contains('dark'));
    const radius = RADIUS[String(values.card_radius)] ?? 8;
    const rowHeight = ROW_HEIGHT[String(values.table_density)] ?? 36;
    const buttonRadius = BUTTON_RADIUS[String(values.button_style)] ?? '8px';
    const sidebarText = textOn(sidebar);
    const palette = dark
        ? { page: '#020617', surface: '#0F172A', border: '#1E293B', text: '#E2E8F0', muted: '#94A3B8' }
        : { page: '#F8FAFC', surface: '#FFFFFF', border: '#E2E8F0', text: '#0F172A', muted: '#64748B' };
    const rows = [t('settings.previewPlaceholders.rowOne'), t('settings.previewPlaceholders.rowTwo'), t('settings.previewPlaceholders.rowThree')];

    return (
        <PreviewFrame title={t('settings.groups.appearancePreview')} note={t('settings.previewNote')}>
            <div aria-hidden="true" className="flex h-64 overflow-hidden rounded-[var(--radius-card)] border shadow-sm" style={{ backgroundColor: palette.page, borderColor: palette.border, color: palette.text }}>
                <div className="hidden w-40 shrink-0 flex-col gap-1.5 p-3 min-[480px]:flex" style={{ backgroundColor: sidebar, color: sidebarText }}>
                    <span className="mb-2 h-3 w-20 rounded" style={{ backgroundColor: sidebarText, opacity: 0.85 }} />
                    {[0, 1, 2, 3].map((item) => (
                        <span key={item} className="flex items-center gap-2 rounded-md px-2 py-1.5" style={item === 1 ? { backgroundColor: `${sidebarText}1F` } : undefined}>
                            <span className="h-2.5 w-2.5 rounded-sm" style={{ backgroundColor: item === 1 ? accent : sidebarText, opacity: item === 1 ? 1 : 0.55 }} />
                            <span className="h-2 flex-1 rounded" style={{ backgroundColor: sidebarText, opacity: item === 1 ? 0.9 : 0.45 }} />
                        </span>
                    ))}
                </div>
                <div className="flex min-w-0 flex-1 flex-col gap-3 p-4">
                    <div className="flex items-center justify-between gap-2">
                        <span className="h-3 w-28 rounded" style={{ backgroundColor: palette.text, opacity: 0.8 }} />
                        <span className="rounded-full px-2 py-0.5 text-[10px] font-semibold text-white" style={{ backgroundColor: accent }}>{t('settings.fields.sampleBadge')}</span>
                    </div>
                    <div className="flex-1 overflow-hidden border" style={{ backgroundColor: palette.surface, borderColor: palette.border, borderRadius: radius }}>
                        {rows.map((row, index) => (
                            <div key={row} className="flex items-center justify-between gap-2 px-3 text-xs" style={{ height: rowHeight, borderTop: index ? `1px solid ${palette.border}` : undefined }}>
                                <span className="truncate">{row}</span>
                                <span style={{ color: palette.muted }}>{index + 1}</span>
                            </div>
                        ))}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <span className="px-3 py-1.5 text-xs font-medium text-white" style={{ backgroundColor: primary, borderRadius: buttonRadius }}>{t('common.save')}</span>
                        <span className="border px-3 py-1.5 text-xs font-medium" style={{ borderColor: secondary, color: dark ? palette.text : secondary, borderRadius: buttonRadius }}>{t('common.cancel')}</span>
                    </div>
                </div>
            </div>
        </PreviewFrame>
    );
}

/**
 * Card design (background PNG, colours, fonts, layout) belongs to each ID card
 * template, so this panel points there instead of previewing a sample card
 * built from settings that no longer drive the real card.
 */
export function IdCardTemplatesPanel({ canManage }: { canManage: boolean }) {
    const { t } = useLocale();

    return (
        <Card className="grid gap-5 sm:grid-cols-2">
            <div className="space-y-4">
                <div>
                    <h3 className="text-sm font-semibold">{t('settings.templateManager.title')}</h3>
                    <p className="mt-1 text-sm leading-6 text-[color:var(--app-muted-foreground)]">{t('settings.idCardDesignMovedNotice')}</p>
                </div>
                {canManage && (
                    <Button as={Link} href={route('id-card-templates.index')} variant="outline" size="sm" icon={<CreditCard className="h-4 w-4" />}>
                        {t('settings.manageIdCardTemplates')}
                    </Button>
                )}
            </div>

            {/* The QR symbol the card prints. Shown rather than edited: lowering
                the error correction or the version floor would weaken every card
                issued, so it is a deployment decision in config/id_cards.php. */}
            <div className="border-t border-[color:var(--app-border)] pt-4 sm:border-l sm:border-t-0 sm:pl-5 sm:pt-0">
                <h4 className="flex items-center gap-2 text-sm font-semibold"><QrCodeIcon className="h-4 w-4 text-[color:var(--app-muted-foreground)]" aria-hidden="true" />{t('settings.qrSpec.title')}</h4>
                <dl className="mt-3 space-y-2 text-sm">
                    {([
                        ['model', 'Model 2'],
                        ['minimumVersion', '6'],
                        ['errorCorrection', 'Q'],
                        ['autoUpgrade', t('settings.qrSpec.enabled')],
                    ] as const).map(([key, value]) => (
                        <div key={key} className="flex justify-between gap-3">
                            <dt className="text-[color:var(--app-muted-foreground)]">{t(`settings.qrSpec.${key}`)}</dt>
                            <dd className={cx('font-medium', key === 'autoUpgrade' ? '' : 'font-mono')}>{key === 'autoUpgrade' ? <StatusBadge tone="success">{value}</StatusBadge> : value}</dd>
                        </div>
                    ))}
                </dl>
                <p className="mt-3 text-xs leading-5 text-[color:var(--app-muted-foreground)]">{t('settings.qrSpec.helper')}</p>
            </div>
        </Card>
    );
}
