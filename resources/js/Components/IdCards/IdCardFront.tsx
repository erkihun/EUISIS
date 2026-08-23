import type { CSSProperties } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { useSystemSettings } from '@/hooks/useSystemSettings';
import enDict from '@/i18n/en';
import amDict from '@/i18n/am';
import { resolveIdCardTemplate } from '@/Components/IdCards/idCardTemplates';

// The card face is bilingual by design (labels show both languages at once),
// so labels are read from both dictionaries instead of the active locale.
const biLabel = (key: 'idLabel' | 'cardLabel' | 'positionNoLabel'): string =>
    `${enDict.idCards[key]}/${amDict.idCards[key]}`;

type IdCardFrontProps = {
    cardNumber: string;
    fullName?: string | null;
    fullNameAm?: string | null;
    employeeNumber?: string | null;
    /** Organization name in English. */
    organizationName?: string | null;
    /** Organization name in Amharic — shown together with the English name. */
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
    organizationNameAm,
    organizationUnitName,
    organizationLogoUrl,
    positionTitle,
    positionTitleAm,
    positionCode,
    jobGrade,
    employmentStatus,
    gender,
    photoUrl,
    issueDate,
    expiryDate,
    status,
    cityLogoUrl,
    rootStyle,
}: IdCardFrontProps) {
    const { t, locale } = useLocale();
    const { getString, getBoolean } = useSystemSettings();

    const template   = resolveIdCardTemplate(getString('id_cards.template', 'classic'));
    const frontFrom  = getString('id_cards.front_bg_from', '#1D4ED8');
    const frontTo    = getString('id_cards.front_bg_to', '#1E3A8A');
    const textPri    = getString('id_cards.front_text_primary', '#FFFFFF');
    const textSec    = getString('id_cards.front_text_secondary', '#BFDBFE');
    const nameFontSz = getString('id_cards.front_name_font_size', 'sm');
    const lblFontSz  = getString('id_cards.front_label_font_size', 'xs');
    const showLogo          = getBoolean('id_cards.show_organization_logo', true);
    const showPhoto         = getBoolean('id_cards.show_photo', true);
    const showFullNameEn    = getBoolean('id_cards.show_full_name_en', true);
    const showFullNameAm    = getBoolean('id_cards.show_full_name_am', true);
    const showEmployeeNo    = getBoolean('id_cards.show_employee_number', true);
    const showCardNo        = getBoolean('id_cards.show_card_number', true);
    const showOrganization  = getBoolean('id_cards.show_organization', true);
    const showUnit          = getBoolean('id_cards.show_organization_unit', true);
    const showPosition      = getBoolean('id_cards.show_position', true);
    const showJobGrade      = getBoolean('id_cards.show_job_grade', true);
    const showEmployment    = getBoolean('id_cards.show_employment_status', true);
    const padding           = getString('id_cards.card_padding', 'normal');
    // Fall back to the system identity logo when no cityLogoUrl is passed as a prop
    const systemLogoUrl     = getString('general.identity_system_logo_url', '');
    const resolvedCityLogo  = cityLogoUrl || (systemLogoUrl || null);

    // The header shows the issuing organization name in both languages at once.
    const cityNameEn = getString('id_cards.city_name_en', 'Addis Ababa City Administration');
    const cityNameAm = getString('id_cards.city_name_am', 'አዲስ አበባ ከተማ አስተዳደር');

    const nameSizeMap: Record<string, string> = { xs: 'text-xs', sm: 'text-sm', base: 'text-base', lg: 'text-lg' };
    const lblSizeMap: Record<string, string>  = { xs: 'text-[9px]', sm: 'text-xs' };
    const padCls = padding === 'compact' ? 'px-3 pb-2' : padding === 'spacious' ? 'px-5 pb-5' : 'px-4 pb-3';

    const nameCls = nameSizeMap[nameFontSz] ?? 'text-sm';
    const lblCls  = lblSizeMap[lblFontSz]  ?? 'text-[9px]';

    const watermarkText = status ? WATERMARK_STATUSES[status] : null;
    const genderAm = gender === 'male' ? 'ወንድ' : gender === 'female' ? 'ሴት' : gender;
    const background = template === 'modern'
        ? `linear-gradient(110deg, ${frontFrom} 0%, ${frontFrom} 62%, ${frontTo} 62%, ${frontTo} 100%)`
        : template === 'minimal'
            ? `linear-gradient(180deg, ${frontFrom} 0%, ${frontFrom} 88%, ${frontTo} 88%, ${frontTo} 100%)`
            : `linear-gradient(135deg, ${frontFrom} 0%, ${frontTo} 100%)`;

    return (
        <div
            data-card-template={template}
            className={[
                'relative overflow-hidden shadow-xl',
                template === 'modern' ? 'rounded-[1.25rem] ring-1 ring-white/20' : '',
                template === 'minimal' ? 'rounded-lg ring-1 ring-white/25' : '',
                template === 'classic' ? 'rounded-xl' : '',
            ].join(' ')}
            style={{
                aspectRatio: '85.6/54',
                width: '100%',
                maxWidth: 400,
                background,
                ...rootStyle,
            }}
        >
            {/* Security dot pattern overlay */}
            {template !== 'minimal' && <div
                className="absolute inset-0 pointer-events-none"
                style={{
                    backgroundImage: template === 'modern'
                        ? 'repeating-linear-gradient(135deg, rgba(255,255,255,0.04) 0 1px, transparent 1px 12px)'
                        : 'radial-gradient(circle, rgba(255,255,255,0.06) 1px, transparent 1px)',
                    backgroundSize: template === 'modern' ? undefined : '12px 12px',
                }}
            />}

            {/* "EMPLOYEE ID" watermark text in background */}
            {template !== 'minimal' && <div
                className="absolute inset-0 flex items-center justify-center pointer-events-none select-none overflow-hidden"
                aria-hidden
            >
                <span
                    className="text-white font-black tracking-[0.4em] whitespace-nowrap"
                    style={{
                        fontSize: '2rem',
                        opacity: 0.04,
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

            {/* Header band: system logo | issuing org name (EN + AM) | org logo */}
            <div className={[
                'absolute inset-x-0 top-0 flex items-center gap-2 px-3 py-1.5',
                template === 'modern' ? 'border-b border-white/20 bg-black/10' : 'bg-white/15',
            ].join(' ')}>
                {/* Logo 1 — system / city logo */}
                {showLogo && resolvedCityLogo ? (
                    <img
                        src={resolvedCityLogo}
                        alt={cityNameEn}
                        className="h-9 w-9 shrink-0 object-contain"
                        crossOrigin="anonymous"
                    />
                ) : (
                    <div
                        className="flex h-9 w-9 shrink-0 items-center justify-center text-[9px] font-bold"
                        style={{ color: textPri }}
                    >
                        AA
                    </div>
                )}
                {/* Center — issuing organization name in both languages */}
                <div className="min-w-0 flex-1 text-center">
                    <p className="truncate text-[9px] font-bold leading-tight" style={{ color: textPri }}>{cityNameAm}</p>
                    {organizationNameAm && (
                        <p className="truncate text-[7px] leading-tight" style={{ color: textSec }}>{organizationNameAm}</p>
                    )}
                    <p className="truncate text-[9px] font-bold leading-tight" style={{ color: textPri }}>{cityNameEn}</p>
                    {organizationName && (
                        <p className="truncate text-[7px] leading-tight" style={{ color: textSec }}>{organizationName}</p>
                    )}
                </div>
                {/* Logo 2 — employee's organization logo */}
                {showLogo && organizationLogoUrl ? (
                    <img
                        src={organizationLogoUrl}
                        alt={organizationName ?? 'Logo'}
                        className="h-9 w-9 shrink-0 object-contain"
                        crossOrigin="anonymous"
                    />
                ) : (
                    <div className="h-7 w-7 shrink-0" aria-hidden="true" />
                )}
            </div>

            {/* Body */}
            <div className={`absolute inset-x-0 top-12 bottom-6 flex gap-3 ${padCls} pt-3`}>
                {/* Photo column */}
                <div className="flex-shrink-0 flex flex-col items-start gap-1">
                    {showPhoto && (photoUrl ? (
                        <img
                            src={photoUrl}
                            alt={t('employees.photo')}
                            crossOrigin="anonymous"
                            className={`${template === 'modern' ? 'rounded-full' : template === 'minimal' ? 'rounded-sm' : 'rounded-lg'} object-cover`}
                            style={{
                                width: template === 'modern' ? '5rem' : '4.5rem',
                                height: template === 'modern' ? '5rem' : '6rem',
                                border: template === 'modern' ? '3px solid rgba(255,255,255,0.35)' : '1px solid rgba(255,255,255,0.2)',
                            }}
                        />
                    ) : (
                        <div
                            className={`${template === 'modern' ? 'rounded-full' : template === 'minimal' ? 'rounded-sm' : 'rounded-lg'} bg-white/15 flex items-center justify-center text-[7px] text-center leading-tight`}
                            style={{
                                width: template === 'modern' ? '5rem' : '4.5rem',
                                height: template === 'modern' ? '5rem' : '6rem',
                                color: textSec,
                                border: '1px solid rgba(255,255,255,0.15)',
                            }}
                        >
                            {t('idCards.photoPlaceholder')}
                        </div>
                    ))}
                    {/* Employee / Card numbers below photo — bilingual labels */}
                    <div className="w-full space-y-0.5">
                        {showEmployeeNo && <div>
                            <span className={`${lblCls} tracking-wide block`} style={{ color: textSec }}>
                                {biLabel('idLabel')}
                            </span>
                            <span className={`${lblCls} font-mono truncate block font-medium`} style={{ color: textPri }}>
                                {employeeNumber ?? '—'}
                            </span>
                        </div>}
                        {showCardNo && <div>
                            <span className={`${lblCls} tracking-wide block`} style={{ color: textSec }}>
                                {biLabel('positionNoLabel')}
                            </span>
                            <span className={`${lblCls} font-mono truncate block`} style={{ color: textPri }}>
                                {positionCode ?? '—'}
                            </span>
                        </div>}
                    </div>
                </div>

                {/* Text fields — Amharic block (name, org, position) then English block (name, org, position) */}
                <div className="min-w-0 flex-1 flex flex-col justify-between">
                    <div className="space-y-0.5">
                        {/* Amharic block — skipped when identical to the English name */}
                        {showFullNameAm && fullNameAm && fullNameAm !== fullName && (
                            <p className={`${nameCls} font-bold leading-tight`} style={{ color: textPri }}>
                                {fullNameAm}
                            </p>
                        )}
                        {showEmployment && gender && (
                            <p className="text-[8px] leading-tight" style={{ color: textSec }}>
                                ፆታ፡ {genderAm}
                            </p>
                        )}
                        {showOrganization && organizationNameAm && (
                            <p className="truncate text-[9px] leading-tight" style={{ color: textSec }}>
                                {organizationNameAm}
                            </p>
                        )}
                        {showPosition && positionTitleAm && (
                            <p className="truncate text-[9px] leading-tight" style={{ color: textSec }}>
                                {positionTitleAm}
                            </p>
                        )}
                        {/* English block */}
                        {showFullNameEn && (
                            <p className={`${nameCls} font-bold leading-tight pt-0.5`} style={{ color: textPri }}>
                                {fullName ?? '—'}
                            </p>
                        )}
                        {showEmployment && gender && (
                            <p className="text-[8px] leading-tight" style={{ color: textSec }}>
                                Sex: {gender}
                            </p>
                        )}
                        {showOrganization && organizationName && (
                            <p className="text-[9px] leading-tight" style={{ color: textSec }}>
                                {organizationName}
                            </p>
                        )}
                        {showPosition && positionTitle && (
                            <p className="text-[9px] leading-tight truncate" style={{ color: textSec }}>
                                {positionTitle}
                            </p>
                        )}
                    </div>
                </div>
            </div>

            {/* Bottom accent bar */}
            <div
                className="absolute inset-x-0 bottom-0 h-5 flex items-center px-4"
                style={{
                    background: `linear-gradient(to right, rgba(255,255,255,0.12), rgba(255,255,255,0.06))`,
                    borderTop: '1px solid rgba(255,255,255,0.1)',
                }}
            >
                <span className="text-[7px] font-mono tracking-widest uppercase" style={{ color: textSec, opacity: 0.7 }}>
                    {t('idCards.authorizedLabel')}
                </span>
            </div>

            {/* Decorative diagonal accent (right edge) */}
            {template === 'classic' && <>
                <div className="absolute -right-4 top-0 bottom-0 w-12 bg-white/5 -skew-x-12 pointer-events-none" />
                <div className="absolute -right-8 top-0 bottom-0 w-8 bg-white/3 -skew-x-12 pointer-events-none" />
            </>}
            {template === 'modern' && <div className="absolute -right-7 -top-7 h-24 w-24 rounded-full border-[14px] border-white/10 pointer-events-none" />}
        </div>
    );
}
