import { useIdCardTemplate, useCardDimensions, textStyleCss, roleStyle, CARD_SURFACE, layoutStyle } from '@/Components/IdCards/IdCardTemplateContext';
import type { CSSProperties } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { useSystemSettings } from '@/hooks/useSystemSettings';
import { resolveIdCardTemplate } from '@/Components/IdCards/idCardTemplates';
import IdCardBilingualField, { buildBilingualFields } from '@/Components/IdCards/IdCardBilingualField';

type IdCardFrontProps = {
    cardNumber: string;
    fullName?: string | null;
    fullNameAm?: string | null;
    employeeNumber?: string | null;
    /** Organization name in English. */
    organizationName?: string | null;
    /** Organization name in Amharic. Back-face data; accepted but not rendered on the front. */
    organizationNameAm?: string | null;
    organizationUnitName?: string | null;
    organizationLogoUrl?: string | null;
    /** Position title in English. */
    positionTitle?: string | null;
    /** Position title in Amharic, displayed beside the Amharic organization. */
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
    /** When supplied and not 'active', a diagonal watermark is shown */
    status?: string | null;
    /** Show the city/system logo in header */
    cityLogoUrl?: string | null;
    /** Extra styles merged onto the root div — use to force explicit height for html-to-image export */
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

export default function IdCardFront({
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
}: IdCardFrontProps) {
    const { t, locale } = useLocale();
    const { getString, getBoolean } = useSystemSettings();
    const cardTemplate = useIdCardTemplate();
    const backgroundUrl = cardTemplate?.front_background_url;
    const dimensions = useCardDimensions('landscape');

    const template   = resolveIdCardTemplate(getString('id_cards.template', 'classic'));
    const frontFrom  = CARD_SURFACE.from;
    const frontTo    = CARD_SURFACE.to;
    const textPri    = CARD_SURFACE.ink;
    const textSec    = CARD_SURFACE.inkMuted;
    const nameFontSz = getString('id_cards.front_name_font_size', 'sm');
    const lblFontSz  = getString('id_cards.front_label_font_size', 'xs');
    const showLogo          = getBoolean('id_cards.show_organization_logo', true);
    const showPhoto         = getBoolean('id_cards.show_photo', true);
    const showEmployment    = getBoolean('id_cards.show_employment_status', true);
    const padding           = getString('id_cards.card_padding', 'normal');
    // Fall back to the system identity logo when no cityLogoUrl is passed as a prop
    const systemLogoUrl     = getString('general.identity_system_logo_url', '');
    const resolvedCityLogo  = cityLogoUrl || (systemLogoUrl || null);

    // The header shows the issuing organization name in both languages at once.
    const cityNameEn = getString('id_cards.city_name_en', 'Addis Ababa City Administration');
    const cityNameAm = getString('id_cards.city_name_am', 'አዲስ አበባ ከተማ አስተዳደር');
    // The card artwork carries two institution blocks; both read from settings.
    const bureauNameAm = getString('id_cards.bureau_name_am', '');
    const bureauNameEn = getString('id_cards.bureau_name_en', '');
    const footerText = locale === 'am'
        ? `${cityNameAm} ${t('idCards.authorizedLabel')}`
        : `${cityNameEn} ${t('idCards.authorizedLabel')}`;


    const nameSizeMap: Record<string, string> = { xs: 'text-xs', sm: 'text-sm', base: 'text-base', lg: 'text-lg' };
    const lblSizeMap: Record<string, string>  = { xs: 'text-[9px]', sm: 'text-xs' };
    const padCls = padding === 'compact' ? 'px-3 pb-2' : padding === 'spacious' ? 'px-5 pb-5' : 'px-4 pb-3';

    const nameCls = nameSizeMap[nameFontSz] ?? 'text-sm';
    const lblCls  = lblSizeMap[lblFontSz]  ?? 'text-[9px]';

    // Per-template typography; falls back to the card's own colours.
    const headerStyle = textStyleCss(roleStyle(cardTemplate, 'front', 'header'), textPri);
    const footerStyle = textStyleCss(roleStyle(cardTemplate, 'front', 'footer'), textSec);
    const labelStyle = textStyleCss(roleStyle(cardTemplate, 'front', 'label'), textSec);
    const contentStyle = textStyleCss(roleStyle(cardTemplate, 'front', 'value'), textPri);

    // Name, sex, date of birth, nationality and employment status fill the body;
    // ID number and phone number are emphasised near the footer.
    const allFields = buildBilingualFields({
        fullName, fullNameAm, gender, dateOfBirth, dateOfBirthAm,
        nationality, nationalityAm,
        employmentStatus: showEmployment ? employmentStatus : null,
        phoneNumber, cardNumber,
    });
    const identityFields = allFields.filter((field) => !['phone', 'idNumber'].includes(field.key));
    const emphasisFields = allFields.filter((field) => ['idNumber', 'phone'].includes(field.key));

    const watermarkText = status ? WATERMARK_STATUSES[status] : null;
    const background = template === 'modern'
        ? `linear-gradient(110deg, ${frontFrom} 0%, ${frontFrom} 62%, ${frontTo} 62%, ${frontTo} 100%)`
        : template === 'minimal'
            ? `linear-gradient(180deg, ${frontFrom} 0%, ${frontFrom} 88%, ${frontTo} 88%, ${frontTo} 100%)`
            : `linear-gradient(135deg, ${frontFrom} 0%, ${frontTo} 100%)`;

    return (
        <div style={{ containerType: 'inline-size', width: rootStyle?.width ?? '100%', maxWidth: rootStyle?.maxWidth ?? 400, height: rootStyle?.height }}>
        <div
            data-card-template={template}
            className={[
                'relative overflow-hidden shadow-xl',
                template === 'modern' ? 'rounded-[1.25rem] ring-1 ring-white/20' : '',
                template === 'minimal' ? 'rounded-lg ring-1 ring-white/25' : '',
                template === 'classic' ? 'rounded-xl' : '',
            ].join(' ')}
            style={{
                aspectRatio: `${dimensions.widthMm} / ${dimensions.heightMm}`,
                width: '100%',
                maxWidth: 400,
                background,
                isolation: 'isolate',
                // Text is sized in `em`, so the card's own font size scales it.
                // The wrapper below provides the inline-size container that
                // 100cqw resolves against, keeping preview and capture identical.
                fontSize: '100cqw',
                ...rootStyle,
            }}
        >
            {backgroundUrl && <img data-card-background="front" src={backgroundUrl} alt="" className="pointer-events-none absolute inset-0 h-full w-full object-cover" style={{ zIndex: -1 }} />}
            {/* Security dot pattern overlay */}
            {!backgroundUrl && template !== 'minimal' && <div
                className="absolute inset-0 pointer-events-none"
                style={{
                    backgroundImage: template === 'modern'
                        ? 'repeating-linear-gradient(135deg, rgba(15,23,42,0.05) 0 1px, transparent 1px 12px)'
                        : 'radial-gradient(circle, rgba(15,23,42,0.07) 1px, transparent 1px)',
                    backgroundSize: template === 'modern' ? undefined : '12px 12px',
                }}
            />}

            {/* "EMPLOYEE ID" watermark text in background */}
            {!backgroundUrl && template !== 'minimal' && <div
                className="absolute inset-0 flex items-center justify-center pointer-events-none select-none overflow-hidden"
                aria-hidden
            >
                <span
                    className="font-black tracking-[0.4em] whitespace-nowrap"
                    style={{
                        color: CARD_SURFACE.ink,
                        fontSize: '2rem',
                        opacity: 0.05,
                        transform: 'rotate(-20deg)',
                        userSelect: 'none',
                    }}
                >
                    {template === 'modern' ? 'CITY ID' : 'EMPLOYEE ID'}
                </span>
            </div>}

            {/* Status watermark (EXPIRED / REVOKED / LOST / SUSPENDED) */}
            {watermarkText && (
                <div
                    className="absolute inset-0 flex items-center justify-center pointer-events-none select-none overflow-hidden"
                    aria-hidden
                >
                    <span
                        className="font-black tracking-widest"
                        style={{
                            fontSize: '1.6rem',
                            color: '#FF0000',
                            opacity: 0.18,
                            transform: 'rotate(-30deg)',
                            userSelect: 'none',
                            whiteSpace: 'nowrap',
                        }}
                    >
                        {watermarkText}
                    </span>
                </div>
            )}

            {/* Header: two logo + institution-text groups, mirroring the card artwork. */}
            <div className="flex items-start gap-2 px-3 pt-2" style={layoutStyle(cardTemplate, 'header')}>
                {([
                    { logo: resolvedCityLogo, lines: [cityNameAm, bureauNameAm], align: 'text-left' as const },
                    { logo: organizationLogoUrl, lines: [cityNameEn, bureauNameEn], align: 'text-left' as const },
                ]).map((group, index) => (
                    <div key={index} className="flex min-w-0 flex-1 items-start gap-1.5">
                        {showLogo && group.logo ? (
                            <img
                                src={group.logo}
                                alt=""
                                className="h-8 w-8 shrink-0 rounded-full object-contain"
                                crossOrigin="anonymous"
                            />
                        ) : (
                            <div className="h-8 w-8 shrink-0 rounded-full border border-slate-300 bg-slate-100" aria-hidden="true" />
                        )}
                        <div className={`min-w-0 flex-1 ${group.align}`}>
                            {group.lines.filter(Boolean).map((line, row) => (
                                <p key={row} className="break-words leading-tight text-[7px]" style={headerStyle}>
                                    {line}
                                </p>
                            ))}
                        </div>
                    </div>
                ))}
            </div>

            {/* Body: photo on the left, identity fields to its right. */}
            {/* Photo is positioned against the card so it can be moved freely. */}
            {showPhoto && (
                <div style={layoutStyle(cardTemplate, 'photo')}>
                    {photoUrl ? (
                        <img
                            src={photoUrl}
                            alt={t('employees.photo')}
                            crossOrigin="anonymous"
                            className="h-full w-full rounded-sm object-cover"
                            style={{ border: '1px solid rgba(0,0,0,0.15)' }}
                        />
                    ) : (
                        <div
                            className="flex h-full w-full items-center justify-center rounded-sm border border-dashed border-slate-300 bg-slate-100 text-center leading-tight"
                            style={{ color: textSec, fontSize: '0.018em' }}
                        >
                            {t('idCards.photoPlaceholder')}
                        </div>
                    )}
                </div>
            )}

            <div className={`flex gap-3 ${padCls}`} style={layoutStyle(cardTemplate, 'fields')}>
                {/* Identity fields in two columns; name leads the left column. */}
                <div className="grid min-w-0 flex-1 grid-cols-2 self-start" style={{ columnGap: '0.035em', rowGap: '0.022em' }}>
                    {identityFields.map(({ key, ...field }) => (
                        <IdCardBilingualField
                            key={key}
                            {...field}
                            labelStyle={labelStyle}
                            valueStyle={contentStyle}
                        />
                    ))}
                </div>
            </div>

            {/* Emphasised ID number and phone number above the footer. */}
            <div className={`grid grid-cols-2 ${padCls}`} style={{ ...layoutStyle(cardTemplate, 'emphasis'), columnGap: '0.035em' }}>
                {emphasisFields.map(({ key, ...field }) => (
                    <IdCardBilingualField
                        key={key}
                        {...field}
                        labelStyle={labelStyle}
                        valueStyle={contentStyle}
                    />
                ))}
            </div>

            {/* Footer: authorisation line, centred. */}
            <div className="flex flex-col justify-center px-3 text-center" style={layoutStyle(cardTemplate, 'footer')}>
                <span className="text-[7px] leading-tight" style={footerStyle}>
                    {footerText}
                </span>
            </div>

            {/* Decorative diagonal accent (right edge) */}
            {!backgroundUrl && template === 'classic' && <>
                <div className="absolute -right-4 top-0 bottom-0 w-12 bg-slate-900/5 -skew-x-12 pointer-events-none" />
                <div className="absolute -right-8 top-0 bottom-0 w-8 bg-slate-900/[0.03] -skew-x-12 pointer-events-none" />
            </>}
            {!backgroundUrl && template === 'modern' && <div className="absolute -right-7 -top-7 h-24 w-24 rounded-full border-[14px] border-slate-900/10 pointer-events-none" />}
        </div>
        </div>
    );
}
