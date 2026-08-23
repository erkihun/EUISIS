import type { CSSProperties } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { useSystemSettings } from '@/hooks/useSystemSettings';
import { resolveIdCardTemplate } from '@/Components/IdCards/idCardTemplates';

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
}: Props) {
    const { t, locale } = useLocale();
    const { getString, getBoolean } = useSystemSettings();

    const template  = resolveIdCardTemplate(getString('id_cards.template', 'classic'));
    const frontFrom = getString('id_cards.front_bg_from', '#1D4ED8');
    const frontTo   = getString('id_cards.front_bg_to',   '#1E3A8A');
    const textPri   = getString('id_cards.front_text_primary',   '#FFFFFF');
    const textSec   = getString('id_cards.front_text_secondary', '#BFDBFE');
    const showLogo         = getBoolean('id_cards.show_organization_logo', true);
    // Same field-visibility settings as the landscape card (IdCardFront) so
    // both orientations always show the same information.
    const showPhoto        = getBoolean('id_cards.show_photo', true);
    const showFullNameEn   = getBoolean('id_cards.show_full_name_en', true);
    const showFullNameAm   = getBoolean('id_cards.show_full_name_am', true);
    const showEmployeeNo   = getBoolean('id_cards.show_employee_number', true);
    const showCardNo       = getBoolean('id_cards.show_card_number', true);
    const showOrganization = getBoolean('id_cards.show_organization', true);
    const showUnit         = getBoolean('id_cards.show_organization_unit', true);
    const showPosition     = getBoolean('id_cards.show_position', true);
    const showJobGrade     = getBoolean('id_cards.show_job_grade', true);
    const showEmployment   = getBoolean('id_cards.show_employment_status', true);
    const portraitLogoUrl = organizationLogoUrl;

    const cityName = locale === 'am'
        ? getString('id_cards.city_name_am', 'አዲስ አበባ ከተማ አስተዳደር')
        : getString('id_cards.city_name_en', 'Addis Ababa City Administration');
    const bureauName = locale === 'am'
        ? getString('id_cards.bureau_name_am', 'የሲቪል ሰርቪስና ሰው ሃብት ልማት ቢሮ')
        : getString('id_cards.bureau_name_en', 'Public Service & HRD Bureau');

    const headerCityNameAm = getString('id_cards.city_name_am', '');
    const headerCityNameEn = getString('id_cards.city_name_en', 'Addis Ababa City Administration');
    const watermarkText = status ? WATERMARK_STATUSES[status] : null;
    const genderAm = gender === 'male' ? 'ወንድ' : gender === 'female' ? 'ሴት' : gender;
    const background = template === 'modern'
        ? `linear-gradient(160deg, ${frontFrom} 0%, ${frontFrom} 62%, ${frontTo} 62%, ${frontTo} 100%)`
        : template === 'minimal'
            ? `linear-gradient(180deg, ${frontFrom} 0%, ${frontFrom} 94%, ${frontTo} 94%, ${frontTo} 100%)`
            : `linear-gradient(160deg, ${frontFrom} 0%, ${frontTo} 100%)`;

    return (
        <div
            data-card-template={template}
            className={[
                'relative flex flex-col overflow-hidden shadow-xl',
                template === 'modern' ? 'rounded-[1.5rem] ring-1 ring-white/20' : '',
                template === 'minimal' ? 'rounded-lg ring-1 ring-white/25' : '',
                template === 'classic' ? 'rounded-2xl' : '',
            ].join(' ')}
            style={{
                aspectRatio: '54 / 85.6',
                width: '100%',
                maxWidth: 260,
                background,
                ...rootStyle,
            }}
        >
            {/* Security dot pattern */}
            {template !== 'minimal' && <div
                className="pointer-events-none absolute inset-0"
                style={{
                    backgroundImage: template === 'modern'
                        ? 'repeating-linear-gradient(135deg, rgba(255,255,255,0.04) 0 1px, transparent 1px 12px)'
                        : 'radial-gradient(circle, rgba(255,255,255,0.055) 1px, transparent 1px)',
                    backgroundSize: template === 'classic' ? '10px 10px' : undefined,
                }}
            />}

            {/* Diagonal decorative accent strips */}
            {template === 'classic' && <>
                <div className="pointer-events-none absolute -right-3 bottom-0 top-0 w-10 -skew-x-6 bg-white/[0.04]" />
                <div className="pointer-events-none absolute -right-6 bottom-0 top-0 w-7 -skew-x-6 bg-white/[0.025]" />
            </>}
            {template === 'modern' && <div className="pointer-events-none absolute -right-8 -top-8 h-28 w-28 rounded-full border-[16px] border-white/10" />}

            {/* Background "EMPLOYEE ID" watermark */}
            {template !== 'minimal' && <div
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
            <div className={`flex shrink-0 items-center gap-2 px-3 py-2 ${template === 'modern' ? 'border-b border-white/20 bg-black/10' : 'bg-white/15'}`}>
                {showLogo && portraitLogoUrl ? (
                    <img
                        src={portraitLogoUrl}
                        alt={organizationName ?? 'Logo'}
                        className="h-9 w-9 shrink-0 object-contain"
                        crossOrigin="anonymous"
                    />
                ) : (
                    <div
                        className="flex h-9 w-9 shrink-0 items-center justify-center text-[8px] font-bold"
                        style={{ color: textPri }}
                    >
                        AA
                    </div>
                )}
                <div className="min-w-0 flex-1">
                    <p className="truncate text-[8px] font-bold leading-tight" style={{ color: textPri }}>{headerCityNameAm}</p>
                    {organizationNameAm && (
                        <p className="truncate text-[7px] leading-tight" style={{ color: textSec }}>{organizationNameAm}</p>
                    )}
                    <p className="truncate text-[8px] font-bold leading-tight" style={{ color: textPri }}>{headerCityNameEn}</p>
                    {organizationName && (
                        <p className="truncate text-[7px] leading-tight" style={{ color: textSec }}>{organizationName}</p>
                    )}
                </div>
                <span
                    className="shrink-0 rounded border border-white/20 bg-white/20 px-1.5 py-0.5 text-[7px] font-mono uppercase tracking-wide"
                    style={{ color: textPri }}
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
                                border: template === 'modern' ? '3px solid rgba(255,255,255,0.35)' : '2px solid rgba(255,255,255,0.25)',
                                boxShadow: '0 4px 16px rgba(0,0,0,0.3)',
                            }}
                        />
                    ) : (
                        <div
                            className={`flex items-center justify-center ${template === 'modern' ? 'rounded-full' : template === 'minimal' ? 'rounded-sm' : 'rounded-xl'} bg-white/15 text-center text-[7px] leading-tight`}
                            style={{
                                width: '6rem',
                                height: template === 'modern' ? '6rem' : '7.5rem',
                                color: textSec,
                                border: '2px solid rgba(255,255,255,0.15)',
                            }}
                        >
                            {t('idCards.photoPlaceholder')}
                        </div>
                    )}
                </div>}

                {/* Name / Position / Org — same fields as the landscape card */}
                <div className="w-full space-y-0.5 text-center">
                    {showFullNameAm && fullNameAm && fullNameAm !== fullName && (
                        <p className="text-[10px] font-semibold leading-tight" style={{ color: textSec }}>
                            {fullNameAm}
                        </p>
                    )}
                    {showEmployment && gender && (
                        <p className="text-[8px] leading-tight" style={{ color: textSec }}>ፆታ፡ {genderAm}</p>
                    )}
                    {showOrganization && organizationNameAm && (
                        <p className="truncate text-[8px] leading-tight" style={{ color: textSec, opacity: 0.8 }}>
                            {organizationNameAm}
                        </p>
                    )}
                    {showPosition && positionTitleAm && (
                        <p className="truncate text-[8px] leading-tight" style={{ color: textSec }}>{positionTitleAm}</p>
                    )}
                    {showFullNameEn && <p className="pt-0.5 text-[12px] font-bold leading-snug" style={{ color: textPri }}>
                        {fullName ?? '—'}
                    </p>}
                    {showEmployment && gender && (
                        <p className="text-[8px] leading-tight" style={{ color: textSec }}>Sex: {gender}</p>
                    )}
                    {showOrganization && organizationName && (
                        <p className="truncate text-[8px] leading-tight" style={{ color: textSec, opacity: 0.8 }}>
                            {organizationName}
                        </p>
                    )}
                    {showPosition && positionTitle && (
                        <p className="truncate text-[9px] leading-tight" style={{ color: textSec }}>
                            {positionTitle}
                        </p>
                    )}
                </div>

                {/* Thin divider */}
                <div className="w-full h-px" style={{ background: 'rgba(255,255,255,0.15)' }} />

                {/* Employee ID + Card Number */}
                {(showEmployeeNo || showCardNo) && <div className="flex w-full justify-around gap-2">
                    {showEmployeeNo && <div className="text-center">
                        <span className="block text-[7px] uppercase tracking-wider" style={{ color: textSec }}>
                            {t('idCards.idLabel')}
                        </span>
                        <span className="block text-[9px] font-mono font-semibold" style={{ color: textPri }}>
                            {employeeNumber ?? '—'}
                        </span>
                    </div>}
                    {showEmployeeNo && showCardNo && <div className="w-px" style={{ background: 'rgba(255,255,255,0.12)' }} />}
                    {showCardNo && <div className="text-center">
                        <span className="block text-[7px] tracking-wider" style={{ color: textSec }}>
                            Pos.No/የመ.መ.ቁ
                        </span>
                        <span className="block text-[9px] font-mono" style={{ color: textPri }}>
                            {positionCode ?? '—'}
                        </span>
                    </div>}
                </div>}

                {/* Issue / expiry dates are rendered on the card back (IdCardPortraitBack). */}
            </div>

            {/* ── Bottom accent bar ─────────────────────────────────── */}
            <div
                className="flex h-5 shrink-0 items-center px-4"
                style={{
                    background: `linear-gradient(to right, rgba(255,255,255,0.12), rgba(255,255,255,0.06))`,
                    borderTop: '1px solid rgba(255,255,255,0.1)',
                }}
            >
                <span className="text-[6px] font-mono uppercase tracking-widest" style={{ color: textSec, opacity: 0.7 }}>
                    {t('idCards.authorizedLabel')}
                </span>
            </div>
        </div>
    );
}
