import { useIdCardTemplate, useCardDimensions, textStyleCss, roleStyle, CARD_SURFACE } from '@/Components/IdCards/IdCardTemplateContext';
import type { CSSProperties } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { useSystemSettings } from '@/hooks/useSystemSettings';
import { resolveIdCardTemplate } from '@/Components/IdCards/idCardTemplates';
import IdCardBilingualField, { buildBilingualFields } from '@/Components/IdCards/IdCardBilingualField';

type Props = {
    cardNumber: string;
    fullName?: string | null;
    fullNameAm?: string | null;
    employeeNumber?: string | null;
    organizationName?: string | null;
    organizationNameAm?: string | null;
    organizationUnitName?: string | null;
    organizationLogoUrl?: string | null;
    positionTitle?: string | null;
    positionTitleAm?: string | null;
    positionCode?: string | null;
    jobGrade?: string | null;
    employmentStatus?: string | null;
    gender?: string | null;
    /** Pre-formatted for the active locale — never a raw ISO date. */
    dateOfBirth?: string | null;
    /** Ethiopian-calendar date shown on the Amharic row. */
    dateOfBirthAm?: string | null;
    nationality?: string | null;
    nationalityAm?: string | null;
    phoneNumber?: string | null;
    photoUrl?: string | null;
    issueDate?: string | null;
    expiryDate?: string | null;
    status?: string | null;
    cityLogoUrl?: string | null;
    rootStyle?: CSSProperties;
};

const WATERMARK_STATUSES: Record<string, string> = {
    expired:   'EXPIRED',
    revoked:   'REVOKED',
    lost:      'LOST',
    suspended: 'SUSPENDED',
    replaced:  'REPLACED',
    damaged:   'DAMAGED',
};

export default function IdCardPortraitFront({
    cardNumber,
    fullName,
    fullNameAm,
    employeeNumber,
    organizationName,
    organizationLogoUrl,
    employmentStatus,
    gender,
    dateOfBirth,
    dateOfBirthAm,
    nationality,
    nationalityAm,
    phoneNumber,
    photoUrl,
    issueDate,
    expiryDate,
    status,
    cityLogoUrl,
    rootStyle,
}: Props) {
    const { t, locale } = useLocale();
    const { getString, getBoolean } = useSystemSettings();
    const cardTemplate = useIdCardTemplate();
    const backgroundUrl = cardTemplate?.front_background_url;
    const dimensions = useCardDimensions('portrait');

    const template  = resolveIdCardTemplate(getString('id_cards.template', 'classic'));
    const frontFrom = CARD_SURFACE.from;
    const frontTo   = CARD_SURFACE.to;
    const textPri   = CARD_SURFACE.ink;
    const textSec   = CARD_SURFACE.inkMuted;
    const showLogo         = getBoolean('id_cards.show_organization_logo', true);
    // Same field-visibility settings as the landscape card (IdCardFront) so
    // both orientations always show the same information.
    const showPhoto        = getBoolean('id_cards.show_photo', true);
    const showEmployment   = getBoolean('id_cards.show_employment_status', true);
    // Header content is per template, resolved server-side against the system
    // settings, so a template that overrides nothing reads the same as before.
    const header = cardTemplate?.header_config ?? null;
    const portraitLogoUrl = (header?.show_logo ?? true)
        ? cardTemplate?.logo_primary_url ?? organizationLogoUrl
        : null;
    const portraitSecondaryLogoUrl = (header?.show_secondary_logo ?? true)
        ? cardTemplate?.logo_secondary_url ?? null
        : null;

    const cityName = locale === 'am'
        ? header?.city_name_am || getString('id_cards.city_name_am', 'አዲስ አበባ ከተማ አስተዳደር')
        : header?.city_name_en || getString('id_cards.city_name_en', 'Addis Ababa City Administration');
    const bureauName = locale === 'am'
        ? header?.bureau_name_am || getString('id_cards.bureau_name_am', 'የሲቪል ሰርቪስና ሰው ሃብት ልማት ቢሮ')
        : header?.bureau_name_en || getString('id_cards.bureau_name_en', 'Public Service & HRD Bureau');

    const headerCityNameAm = header?.city_name_am || getString('id_cards.city_name_am', '');
    const headerCityNameEn = header?.city_name_en || getString('id_cards.city_name_en', 'Addis Ababa City Administration');
    // Per-template typography; falls back to the card's own colours.
    const headerStyle = textStyleCss(roleStyle(cardTemplate, 'front', 'header'), textPri);
    const labelStyle = textStyleCss(roleStyle(cardTemplate, 'front', 'label'), textSec);
    const contentStyle = textStyleCss(roleStyle(cardTemplate, 'front', 'value'), textPri);

    const watermarkText = status ? WATERMARK_STATUSES[status] : null;
    const background = template === 'modern'
        ? `linear-gradient(160deg, ${frontFrom} 0%, ${frontFrom} 62%, ${frontTo} 62%, ${frontTo} 100%)`
        : template === 'minimal'
            ? `linear-gradient(180deg, ${frontFrom} 0%, ${frontFrom} 94%, ${frontTo} 94%, ${frontTo} 100%)`
            : `linear-gradient(160deg, ${frontFrom} 0%, ${frontTo} 100%)`;

    return (
        <div style={{ containerType: 'inline-size', width: rootStyle?.width ?? '100%', maxWidth: rootStyle?.maxWidth ?? 260, height: rootStyle?.height }}>
        <div
            data-card-template={template}
            className={[
                'relative flex flex-col overflow-hidden shadow-xl',
                template === 'modern' ? 'rounded-[1.5rem] ring-1 ring-white/20' : '',
                template === 'minimal' ? 'rounded-lg ring-1 ring-white/25' : '',
                template === 'classic' ? 'rounded-2xl' : '',
            ].join(' ')}
            style={{
                aspectRatio: `${dimensions.widthMm} / ${dimensions.heightMm}`,
                width: '100%',
                maxWidth: 260,
                background,
                isolation: 'isolate',
                // Text is sized in `em`; this scales it with the card.
                fontSize: '100cqw',
                ...rootStyle,
            }}
        >
            {backgroundUrl && <img data-card-background="front" src={backgroundUrl} alt="" className="pointer-events-none absolute inset-0 h-full w-full object-cover" style={{ zIndex: -1 }} />}
            {/* Security dot pattern */}
            {!backgroundUrl && template !== 'minimal' && <div
                className="pointer-events-none absolute inset-0"
                style={{
                    backgroundImage: template === 'modern'
                        ? 'repeating-linear-gradient(135deg, rgba(15,23,42,0.04) 0 1px, transparent 1px 12px)'
                        : 'radial-gradient(circle, rgba(15,23,42,0.055) 1px, transparent 1px)',
                    backgroundSize: template === 'classic' ? '10px 10px' : undefined,
                }}
            />}

            {/* Diagonal decorative accent strips */}
            {!backgroundUrl && template === 'classic' && <>
                <div className="pointer-events-none absolute -right-3 bottom-0 top-0 w-10 -skew-x-6 bg-white/[0.04]" />
                <div className="pointer-events-none absolute -right-6 bottom-0 top-0 w-7 -skew-x-6 bg-white/[0.025]" />
            </>}
            {!backgroundUrl && template === 'modern' && <div className="pointer-events-none absolute -right-8 -top-8 h-28 w-28 rounded-full border-[16px] border-slate-900/10" />}

            {/* Background "EMPLOYEE ID" watermark */}
            {!backgroundUrl && template !== 'minimal' && <div
                className="pointer-events-none absolute inset-0 flex select-none items-center justify-center overflow-hidden"
                aria-hidden
            >
                <span
                    className="whitespace-nowrap font-black text-white"
                    style={{ fontSize: '1.3rem', opacity: 0.04, transform: 'rotate(-35deg)', letterSpacing: '0.35em' }}
                >
                    {template === 'modern' ? 'CITY ID' : 'EMPLOYEE ID'}
                </span>
            </div>}

            {/* Status watermark */}
            {watermarkText && (
                <div
                    className="pointer-events-none absolute inset-0 flex select-none items-center justify-center overflow-hidden"
                    aria-hidden
                >
                    <span
                        className="font-black tracking-widest"
                        style={{ fontSize: '1.3rem', color: '#FF0000', opacity: 0.18, transform: 'rotate(-30deg)', whiteSpace: 'nowrap' }}
                    >
                        {watermarkText}
                    </span>
                </div>
            )}

            {/* ── Header ───────────────────────────────────────────── */}
            <div className={`flex shrink-0 items-center gap-2 px-3 py-2 ${template === 'modern' ? 'border-b border-slate-200 bg-slate-900/[0.03]' : 'bg-slate-900/[0.04]'}`}>
                {showLogo && portraitLogoUrl ? (
                    <img
                        src={portraitLogoUrl}
                        alt={organizationName ?? 'Logo'}
                        className="h-9 w-9 shrink-0 object-contain"
                        crossOrigin="anonymous"
                    />
                ) : showLogo && (header?.show_logo ?? true) ? (
                    <div
                        className="flex h-9 w-9 shrink-0 items-center justify-center text-[8px] font-bold"
                        style={headerStyle}
                    >
                        AA
                    </div>
                ) : null}
                <div className="min-w-0 flex-1">
                    {/* Deduplicated: an install whose English setting holds the
                        Amharic text would otherwise print the same name twice. */}
                    {[...new Set([headerCityNameAm, headerCityNameEn]
                        .map((line) => line?.trim())
                        .filter((line): line is string => Boolean(line)))].map((line, row) => (
                        <p key={row} className="truncate font-bold leading-tight text-[8px]" style={headerStyle}>
                            {line}
                        </p>
                    ))}
                </div>
                {showLogo && portraitSecondaryLogoUrl && (
                    <img
                        src={portraitSecondaryLogoUrl}
                        alt=""
                        className="h-9 w-9 shrink-0 object-contain"
                        crossOrigin="anonymous"
                    />
                )}
                <span
                    className="shrink-0 rounded border border-slate-200 bg-slate-900/[0.05] px-1.5 py-0.5 font-mono text-[7px] uppercase tracking-wide"
                    style={headerStyle}
                >
                    {t('idCards.officialIdBadge')}
                </span>
            </div>

            {/* ── Body ─────────────────────────────────────────────── */}
            <div className="flex flex-1 flex-col items-center justify-between px-3 pb-3 pt-5">

                {/* Photo */}
                {showPhoto && <div className="flex flex-col items-center gap-2">
                    {photoUrl ? (
                        <img
                            src={photoUrl}
                            alt={t('employees.photo')}
                            crossOrigin="anonymous"
                            className={`${template === 'modern' ? 'rounded-full' : template === 'minimal' ? 'rounded-sm' : 'rounded-xl'} object-cover`}
                            style={{
                                width: '6rem',
                                height: template === 'modern' ? '6rem' : '7.5rem',
                                border: template === 'modern' ? '3px solid rgba(15,23,42,0.35)' : '2px solid rgba(15,23,42,0.25)',
                                boxShadow: '0 4px 16px rgba(0,0,0,0.3)',
                            }}
                        />
                    ) : (
                        <div
                            className={`flex items-center justify-center ${template === 'modern' ? 'rounded-full' : template === 'minimal' ? 'rounded-sm' : 'rounded-xl'} border border-dashed border-slate-300 bg-slate-100 text-center text-[7px] leading-tight`}
                            style={{
                                width: '6rem',
                                height: template === 'modern' ? '6rem' : '7.5rem',
                                color: textSec,
                                border: '2px solid rgba(15,23,42,0.15)',
                            }}
                        >
                            {t('idCards.photoPlaceholder')}
                        </div>
                    )}
                </div>}

                {/* Identity fields — organization and position live on the back face. */}
                <div className="w-full space-y-0.5 text-left">
                    {(() => {
                        const [nameField, ...gridFields] = buildBilingualFields({
                            fullName, fullNameAm, gender, dateOfBirth, dateOfBirthAm,
                            nationality, nationalityAm,
                            employmentStatus: showEmployment ? employmentStatus : null,
                            phoneNumber, cardNumber, employeeNumber,
                        });
                        return (
                            <>
                                <IdCardBilingualField {...nameField} labelStyle={labelStyle} valueStyle={contentStyle} />
                                <div className="grid grid-cols-2 gap-x-2 gap-y-[2px] pt-0.5">
                                    {gridFields.map(({ key, ...field }) => (
                                        <IdCardBilingualField
                                            key={key}
                                            {...field}
                                            fieldKey={key}
                                            labelStyle={labelStyle}
                                            valueStyle={contentStyle}
                                        />
                                    ))}
                                </div>
                            </>
                        );
                    })()}
                </div>

                {/* Thin divider */}
                <div className="w-full h-px" style={{ background: 'rgba(15,23,42,0.15)' }} />

                {/* Issue / expiry dates print on this front face, so the card's
                    validity reads without turning it over. */}
            </div>

            {/* The front carries no authorisation footer; the dates close the
                card face, matching the landscape front. */}
        </div>
        </div>
    );
}
