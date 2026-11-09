import {
    CARD_SURFACE, PORTRAIT_FONT_SIZE, layoutStyle, printedFieldElements, roleStyle, textStyleCss, useCardDimensions, useIdCardTemplate,
} from '@/Components/IdCards/IdCardTemplateContext';
import IdCardFaceFields from '@/Components/IdCards/IdCardFaceFields';
import { resolveIdCardTemplate } from '@/Components/IdCards/idCardTemplates';
import { useLocale } from '@/hooks/useLocale';
import { useSystemSettings } from '@/hooks/useSystemSettings';
import type { CSSProperties } from 'react';

type Props = {
    cardNumber: string;
    fullName?: string | null;
    fullNameAm?: string | null;
    employeeNumber?: string | null;
    organizationName?: string | null;
    organizationNameAm?: string | null;
    organizationLogoUrl?: string | null;
    positionTitle?: string | null;
    positionTitleAm?: string | null;
    photoUrl?: string | null;
    status?: string | null;
    rootStyle?: CSSProperties;
    organizationUnitName?: string | null;
    positionCode?: string | null;
    jobGrade?: string | null;
    employmentStatus?: string | null;
    gender?: string | null;
    dateOfBirth?: string | null;
    dateOfBirthAm?: string | null;
    nationality?: string | null;
    nationalityAm?: string | null;
    phoneNumber?: string | null;
    email?: string | null;
    address?: string | null;
    /** The emergency contact, for a template that prints it on the front. */
    emergencyContactName?: string | null;
    emergencyContactPhone?: string | null;
    cityLogoUrl?: string | null;
};

const WATERMARK_STATUSES: Record<string, string> = {
    expired: 'EXPIRED', revoked: 'REVOKED', lost: 'LOST', suspended: 'SUSPENDED',
    replaced: 'REPLACED', damaged: 'DAMAGED',
};

const uniqueLines = (...values: (string | null | undefined)[]) =>
    values.map((value) => value?.trim()).filter((value, index, all): value is string => Boolean(value) && all.indexOf(value) === index);

export default function IdCardPortraitFront({
    cardNumber, fullName, fullNameAm, employeeNumber, organizationName, organizationNameAm,
    organizationLogoUrl, positionTitle, positionTitleAm, photoUrl,
    status, rootStyle, gender, dateOfBirth, dateOfBirthAm, nationality, nationalityAm,
    employmentStatus, phoneNumber, email, address, emergencyContactName, emergencyContactPhone,
}: Props) {
    const { t } = useLocale();
    const { getString, getBoolean } = useSystemSettings();
    const cardTemplate = useIdCardTemplate('portrait');
    const dimensions = useCardDimensions('portrait');
    const template = resolveIdCardTemplate(getString('id_cards.template', 'classic'));
    const header = cardTemplate?.header_config ?? null;
    const showLogo = getBoolean('id_cards.show_organization_logo', true);
    const showPhoto = getBoolean('id_cards.show_photo', true);
    // A portrait badge represents the employee's actual employer, so its logo
    // takes priority over generic template artwork in the primary header slot.
    const primaryLogo = (header?.show_logo ?? true) ? organizationLogoUrl ?? cardTemplate?.logo_primary_url : null;
    const secondaryLogo = (header?.show_secondary_logo ?? true) ? cardTemplate?.logo_secondary_url : null;
    const headerStyle = textStyleCss(roleStyle(cardTemplate, 'front', 'header'), CARD_SURFACE.ink);
    const labelStyle = textStyleCss(roleStyle(cardTemplate, 'front', 'label'), CARD_SURFACE.inkMuted);
    const valueStyle = textStyleCss(roleStyle(cardTemplate, 'front', 'value'), CARD_SURFACE.ink);
    const employeeNameStyle = textStyleCss(roleStyle(cardTemplate, 'front', 'employee_name'), CARD_SURFACE.ink);
    const employeePositionStyle = textStyleCss(roleStyle(cardTemplate, 'front', 'employee_position'), CARD_SURFACE.inkMuted);
    const backgroundUrl = cardTemplate?.front_background_url;
    const background = template === 'modern'
        ? `linear-gradient(160deg, ${CARD_SURFACE.from} 0%, ${CARD_SURFACE.from} 62%, ${CARD_SURFACE.to} 62%, ${CARD_SURFACE.to} 100%)`
        : `linear-gradient(160deg, ${CARD_SURFACE.from} 0%, ${CARD_SURFACE.to} 100%)`;
    // Portrait identifies only the employee's actual employer. City/bureau
    // header settings are intentionally not repeated above it.
    const amharicOrganizationName = organizationNameAm?.trim();
    const englishOrganizationName = organizationName?.trim();
    const organizationNameClass = 'w-full whitespace-normal wrap-break-word font-bold leading-tight wrap-anywhere';
    const watermark = status ? WATERMARK_STATUSES[status] : null;

    // The template's printed-field list decides the face, as on landscape:
    // name and ID number are the large name and ID elements, every other
    // field its own box (IdCardSvgRenderer::renderPortrait draws the same).
    const shown = printedFieldElements(cardTemplate?.employee_fields, 'front', 'portrait', cardTemplate?.layout_config?.faces);
    const showsField = (key: string) => shown.some((field) => field.key === key);
    const fieldSource = {
        gender, dateOfBirth, dateOfBirthAm, nationality, nationalityAm, employmentStatus, phoneNumber,
        cardNumber, employeeNumber, email, address, emergencyContactName, emergencyContactPhone,
    };

    return (
        <div style={{ containerType: 'inline-size', width: rootStyle?.width ?? '100%', maxWidth: rootStyle?.maxWidth ?? 260, height: rootStyle?.height }}>
            <div
                data-card-template={template}
                className="relative overflow-hidden rounded-panel shadow-xl"
                style={{ aspectRatio: `${dimensions.widthMm} / ${dimensions.heightMm}`, width: '100%', maxWidth: 260, background, isolation: 'isolate', fontSize: PORTRAIT_FONT_SIZE, ...rootStyle }}
            >
                {backgroundUrl && <img data-card-background="front" src={backgroundUrl} alt="" className="pointer-events-none absolute inset-0 h-full w-full object-cover" style={{ zIndex: -1 }} />}
                {!backgroundUrl && <div className="pointer-events-none absolute inset-0" style={{ backgroundImage: 'radial-gradient(circle, rgba(15,23,42,0.045) 1px, transparent 1px)', backgroundSize: '10px 10px' }} />}
                {watermark && <div className="pointer-events-none absolute inset-0 z-20 flex items-center justify-center"><span className="font-black tracking-widest text-red-600/20" style={{ transform: 'rotate(-30deg)', fontSize: '0.1em' }}>{watermark}</span></div>}

                {/* The organization name sits on the card itself, with no band behind it, as it prints. */}
                <div className="flex flex-col items-center justify-center px-2 py-1 text-center" style={{ ...layoutStyle(cardTemplate, 'header'), ...headerStyle }}>
                    {amharicOrganizationName && <p lang="am" className={organizationNameClass}>{amharicOrganizationName}</p>}
                    {englishOrganizationName && <p lang="en" className={organizationNameClass}>{englishOrganizationName}</p>}
                </div>
                {showLogo && (header?.show_logo ?? true) && <div className="flex items-center justify-center" style={layoutStyle(cardTemplate, 'logo_primary')}>
                    {primaryLogo ? <img src={primaryLogo} alt={organizationName ?? 'Logo'} className="h-full w-full object-contain" crossOrigin="anonymous" /> : <span style={headerStyle}>AA</span>}
                </div>}
                {showLogo && secondaryLogo && <div className="flex items-center justify-center" style={layoutStyle(cardTemplate, 'logo_secondary')}><img src={secondaryLogo} alt="" className="h-full w-full object-contain" crossOrigin="anonymous" /></div>}

                {showPhoto && <div className="flex items-center justify-center overflow-hidden" style={layoutStyle(cardTemplate, 'photo')}>
                    {photoUrl
                        ? <img src={photoUrl} alt={t('employees.photo')} crossOrigin="anonymous" className="h-full w-full rounded-card object-cover" />
                        : <div className="flex h-full w-full items-center justify-center rounded-card border-2 border-dashed border-slate-300 bg-slate-100 text-center" style={labelStyle}>{t('idCards.photoPlaceholder')}</div>}
                </div>}

                {showsField('name') && <div className="flex flex-col items-center justify-center overflow-hidden text-center" style={layoutStyle(cardTemplate, 'employee_name')}>
                    {uniqueLines(fullNameAm, fullName).map((name) => <p key={name} className="w-full truncate leading-tight" style={employeeNameStyle}>{name}</p>)}
                </div>}
                <div className="flex flex-col items-center justify-center overflow-hidden text-center" style={layoutStyle(cardTemplate, 'employee_position')}>
                    {uniqueLines(positionTitleAm, positionTitle).map((position) => <p key={position} className="w-full truncate leading-tight" style={employeePositionStyle}>{position}</p>)}
                </div>
                {showsField('idNumber') && <div className="flex flex-col items-center justify-center overflow-hidden text-center" style={layoutStyle(cardTemplate, 'emphasis')}>
                    <p className="truncate font-mono font-semibold" style={valueStyle}>{employeeNumber || cardNumber}</p>
                    <p className="uppercase tracking-wide" style={labelStyle}>{t('idCards.officialIdBadge')}</p>
                </div>}

                <IdCardFaceFields side="front" orientation="portrait" source={fieldSource} labelStyle={labelStyle} valueStyle={valueStyle} />
            </div>
        </div>
    );
}
