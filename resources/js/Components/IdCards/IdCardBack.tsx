import { useIdCardTemplate, useCardDimensions, textStyleCss, roleStyle, CARD_SURFACE, layoutStyle } from '@/Components/IdCards/IdCardTemplateContext';
import type { CSSProperties } from 'react';
import { QRCodeSVG } from 'qrcode.react';
import { useLocale } from '@/hooks/useLocale';
import { useSystemSettings } from '@/hooks/useSystemSettings';
import enDict from '@/i18n/en';
import amDict from '@/i18n/am';
import { resolveIdCardTemplate } from '@/Components/IdCards/idCardTemplates';

// The card face is bilingual by design — labels come from both dictionaries.
const biLabel = (key: 'issueDate' | 'expLabel' | 'signatureLabel' | 'emergencyContact'): string =>
    `${enDict.idCards[key]}/${amDict.idCards[key]}`;

type IdCardBackProps = {
    cardNumber: string;
    /** Verification URL or payload to encode in the QR. No PII — UUID ref only. */
    qrValue?: string | null;
    /** Card issue date (already formatted for display). */
    issueDate?: string | null;
    /** Card expiry date (already formatted for display). */
    expiryDate?: string | null;
    /** Who to contact in an emergency — printed on the back. */
    emergencyContactName?: string | null;
    emergencyContactPhone?: string | null;
    /** Extra styles merged onto the root div — use to force explicit height for html-to-image export */
    rootStyle?: CSSProperties;
};

export default function IdCardBack({ cardNumber, qrValue, issueDate, expiryDate, emergencyContactName, emergencyContactPhone, rootStyle }: IdCardBackProps) {
    const { t, locale } = useLocale();
    const { getString, getBoolean } = useSystemSettings();
    const cardTemplate = useIdCardTemplate();
    const backgroundUrl = cardTemplate?.back_background_url;
    const dimensions = useCardDimensions('landscape');

    const template      = resolveIdCardTemplate(getString('id_cards.template', 'classic'));
    const backFrom      = CARD_SURFACE.from;
    const backTo        = CARD_SURFACE.to;
    const textColor     = CARD_SURFACE.inkMuted;
    // Per-template typography; falls back to the card's own back colour.
    const labelStyle = textStyleCss(roleStyle(cardTemplate, 'back', 'label'), textColor);
    const contentStyle = textStyleCss(roleStyle(cardTemplate, 'back', 'value'), textColor);
    const showMagStripe = getBoolean('id_cards.show_magnetic_stripe', true);
    const showQr = getBoolean('id_cards.show_qr', true);
    const showReturnNotice = getBoolean('id_cards.show_return_notice', true);
    const showEmergencyContact = getBoolean('id_cards.show_emergency_contact', true);
    const showCardNumber = getBoolean('id_cards.show_card_number', true);
    const showIssueDate  = getBoolean('id_cards.show_issue_date', true);
    const showExpiryDate = getBoolean('id_cards.show_expiry_date', true);
    const qrSizeRaw     = getString('id_cards.qr_size', '96');
    const qrSize        = parseInt(qrSizeRaw, 10) || 96;
    const padding       = getString('id_cards.card_padding', 'normal');

    const returnAddress = locale === 'am'
        ? getString('id_cards.return_address_am', 'አዲስ አበባ ከተማ አስተዳደር፣ የሲቪል ሰርቪስና ሰው ሃብት ልማት ቢሮ')
        : getString('id_cards.return_address_en', 'Addis Ababa City Administration, Public Service & HRD Bureau');

    const supportContact = getString('id_cards.support_contact', '');
    const verificationUrl = getString('id_cards.verification_url', '');
    const sealUrl = getString('general.seal_url', '');

    const padCls = padding === 'compact' ? 'px-3' : padding === 'spacious' ? 'px-5' : 'px-4';
    const background = template === 'modern'
        ? `linear-gradient(110deg, ${backFrom} 0%, ${backFrom} 62%, ${backTo} 62%, ${backTo} 100%)`
        : template === 'minimal'
            ? `linear-gradient(180deg, ${backFrom} 0%, ${backFrom} 94%, ${backTo} 94%, ${backTo} 100%)`
            : `linear-gradient(135deg, ${backFrom} 0%, ${backTo} 100%)`;

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
                aspectRatio: `${dimensions.widthMm} / ${dimensions.heightMm}`,
                width: '100%',
                maxWidth: 400,
                background,
                isolation: 'isolate',
                ...rootStyle,
            }}
        >
            {backgroundUrl && <img data-card-background="back" src={backgroundUrl} alt="" className="pointer-events-none absolute inset-0 h-full w-full object-cover" style={{ zIndex: -1 }} />}
            {/* Security dot pattern */}
            {!backgroundUrl && template !== 'minimal' && <div
                className="absolute inset-0 pointer-events-none"
                style={{
                    backgroundImage: template === 'modern'
                        ? 'repeating-linear-gradient(135deg, rgba(15,23,42,0.04) 0 1px, transparent 1px 12px)'
                        : 'radial-gradient(circle, rgba(15,23,42,0.04) 1px, transparent 1px)',
                    backgroundSize: template === 'modern' ? undefined : '10px 10px',
                }}
            />}

            {/* Magnetic stripe simulation */}
            {showMagStripe && (
                <div className={[
                    'absolute bg-black/50 pointer-events-none',
                    template === 'modern' ? 'left-[34%] right-0 top-4 h-4 rounded-l-full' : '',
                    template === 'minimal' ? 'inset-x-0 top-3 h-2' : '',
                    template === 'classic' ? 'inset-x-0 top-3 h-6' : '',
                ].join(' ')} />
            )}

            {/* Main content area */}
            <>
                {/* QR code block — positioned by the template. */}
                {showQr && <div className="flex flex-col items-center justify-center gap-1" style={layoutStyle(cardTemplate, 'qr', 'back')}>
                    {qrValue ? (
                        <div
                            className="rounded-md bg-white shadow-md"
                            style={{ padding: '4px' }}
                        >
                            <QRCodeSVG
                                value={qrValue}
                                size={qrSize}
                                level="M"
                                bgColor="#FFFFFF"
                                fgColor="#0F172A"
                            />
                        </div>
                    ) : (
                        <div
                            className="flex items-center justify-center rounded-md border-2 border-dashed border-slate-300 bg-slate-100"
                            style={{ width: qrSize + 8, height: qrSize + 8 }}
                        >
                            <span className="text-center text-[7px] leading-tight text-white/40 px-1">
                                {t('idCards.qrOnPrint')}
                            </span>
                        </div>
                    )}
                </div>}

                {/* Notes and details column — positioned by the template. */}
                <div className="flex min-w-0 flex-col justify-between" style={layoutStyle(cardTemplate, 'notes', 'back')}>
                    <div className="space-y-1">
                        <p className="text-[9px] font-bold uppercase tracking-wider" style={labelStyle}>
                            {t('idCards.officialCard')}
                        </p>
                        {/* Return notice in both languages */}
                        {showReturnNotice && (
                            <>
                                <p className="text-[7px] leading-relaxed" style={{ color: textColor, opacity: 0.65 }}>
                                    {enDict.idCards.propertyNotice}
                                </p>
                                <p className="text-[7px] leading-relaxed" style={{ color: textColor, opacity: 0.65 }}>
                                    {amDict.idCards.propertyNotice}
                                </p>
                            </>
                        )}
                        {verificationUrl && (
                            <p className="text-[7px] font-mono break-all leading-tight" style={{ color: textColor, opacity: 0.5 }}>
                                {verificationUrl}
                            </p>
                        )}
                    </div>

                </div>

                {/* Seal — positioned against the card so it can be moved freely. */}
                {sealUrl && (
                    <div
                        className="flex items-center justify-center"
                        style={layoutStyle(cardTemplate, 'seal', 'back')}
                    >
                        <img
                            src={sealUrl}
                            alt=""
                            className="h-full w-full object-contain drop-shadow-md"
                        />
                    </div>
                )}

                {/* Signature, card number and addresses — their own movable block. */}
                <div className="space-y-0.5" style={layoutStyle(cardTemplate, 'details', 'back')}>
                        {/* Signature line */}
                        <div className="mb-1 flex items-end gap-1.5">
                            <span className="shrink-0 text-[7px]" style={{ ...labelStyle, opacity: 0.7 }}>
                                {biLabel('signatureLabel')}
                            </span>
                            <span
                                className="mb-0.5 flex-1"
                                style={{ borderBottom: '1px dotted rgba(15,23,42,0.3)', minHeight: 10 }}
                            />
                        </div>
                        {showCardNumber && <p className="font-mono text-[8px] font-semibold tracking-wider" style={contentStyle}>
                            Card NO: {cardNumber}
                        </p>}
                        {showEmergencyContact && supportContact && (
                            <p className="text-[7px] leading-tight" style={{ color: textColor, opacity: 0.55 }}>
                                {supportContact}
                            </p>
                        )}
                        <p className="text-[7px] leading-tight truncate" style={{ color: textColor, opacity: 0.45 }}>
                            {returnAddress}
                        </p>
                </div>

            {/* Emergency contact — who to call if the holder needs help. */}
            {(emergencyContactName || emergencyContactPhone) && (
                <div className="min-w-0" style={layoutStyle(cardTemplate, 'emergency', 'back')}>
                    <p className="truncate text-[7px] font-semibold leading-tight" style={labelStyle}>
                        {biLabel('emergencyContact')}
                    </p>
                    {emergencyContactName && (
                        <p className="truncate text-[8px] leading-tight" style={contentStyle}>
                            {emergencyContactName}
                        </p>
                    )}
                    {emergencyContactPhone && (
                        <p className="truncate text-[8px] leading-tight" style={contentStyle}>
                            {emergencyContactPhone}
                        </p>
                    )}
                </div>
            )}

            {/* Bottom row — issue / expiry dates with bilingual labels */}
            {(showIssueDate || showExpiryDate) && (
                <div className="flex gap-3" style={layoutStyle(cardTemplate, 'dates', 'back')}>
                    {showIssueDate && (
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-[7px] leading-tight" style={{ color: textColor, opacity: 0.7 }}>
                                {biLabel('issueDate')}
                            </p>
                            <p
                                className="font-mono text-[8px] font-semibold leading-tight"
                                style={{ color: textColor, borderBottom: '1px dotted rgba(15,23,42,0.25)' }}
                            >
                                {issueDate ?? ' '}
                            </p>
                        </div>
                    )}
                    {showExpiryDate && (
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-[7px] leading-tight" style={{ color: textColor, opacity: 0.7 }}>
                                {biLabel('expLabel')}
                            </p>
                            <p
                                className="font-mono text-[8px] font-semibold leading-tight"
                                style={{ color: textColor, borderBottom: '1px dotted rgba(15,23,42,0.25)' }}
                            >
                                {expiryDate ?? ' '}
                            </p>
                        </div>
                    )}
                </div>
            )}
            </>

            {/* Bottom thin accent */}
            <div
                className="absolute inset-x-0 bottom-0 h-1 pointer-events-none"
                style={{ background: `linear-gradient(to right, rgba(15,23,42,0.08), rgba(15,23,42,0.03))` }}
            />
        </div>
    );
}
