import { useIdCardTemplate, useCardDimensions, textStyleCss, roleStyle, CARD_SURFACE, layoutStyle, BACK_PHOTO_DEFAULTS } from '@/Components/IdCards/IdCardTemplateContext';
import type { CSSProperties } from 'react';
import { QRCodeSVG } from 'qrcode.react';
import { useLocale } from '@/hooks/useLocale';
import { useSystemSettings } from '@/hooks/useSystemSettings';
import enDict from '@/i18n/en';
import amDict from '@/i18n/am';
import { resolveIdCardTemplate } from '@/Components/IdCards/idCardTemplates';
import IdCardBilingualField from '@/Components/IdCards/IdCardBilingualField';

// The card face is bilingual by design — labels come from both dictionaries.
const biLabel = (key: 'signatureLabel' | 'emergencyContact' | 'cardNoLabel'): string =>
    `${enDict.idCards[key]}/${amDict.idCards[key]}`;

type IdCardBackProps = {
    cardNumber: string;
    /** Verification URL or payload to encode in the QR. No PII — UUID ref only. */
    qrValue?: string | null;
    /** Who to contact in an emergency — printed on the back. */
    emergencyContactName?: string | null;
    emergencyContactPhone?: string | null;
    /** Employee photo, drawn as a watermark when the template enables it. */
    photoUrl?: string | null;
    /** Extra styles merged onto the root div — use to force explicit height for html-to-image export */
    rootStyle?: CSSProperties;
};

export default function IdCardBack({ cardNumber, qrValue, emergencyContactName, emergencyContactPhone, photoUrl, rootStyle }: IdCardBackProps) {
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
    const footerStyle = textStyleCss(roleStyle(cardTemplate, 'back', 'footer'), textColor);
    const showMagStripe = getBoolean('id_cards.show_magnetic_stripe', true);
    const showQr = getBoolean('id_cards.show_qr', true);
    const showReturnNotice = getBoolean('id_cards.show_return_notice', true);
    const showEmergencyContact = getBoolean('id_cards.show_emergency_contact', true);
    const showCardNumber = getBoolean('id_cards.show_card_number', true);
    const qrSizeRaw     = getString('id_cards.qr_size', '96');
    const qrSize        = parseInt(qrSizeRaw, 10) || 96;
    const padding       = getString('id_cards.card_padding', 'normal');

    const verificationUrl = getString('id_cards.verification_url', '');
    const sealUrl = getString('general.seal_url', '');

    // The back photo is a watermark, off unless the template turns it on.
    // Mirrors IdCardBackPhoto on the server, including the fit mapping.
    const backPhoto = cardTemplate?.back_photo_config ?? BACK_PHOTO_DEFAULTS;
    const backPhotoFit = backPhoto.fit === 'contain' ? 'contain' : backPhoto.fit === 'stretch' ? 'fill' : 'cover';
    const backPhotoBox = layoutStyle(cardTemplate, 'photo', 'back');

    const padCls = padding === 'compact' ? 'px-3' : padding === 'spacious' ? 'px-5' : 'px-4';
    const background = template === 'modern'
        ? `linear-gradient(110deg, ${backFrom} 0%, ${backFrom} 62%, ${backTo} 62%, ${backTo} 100%)`
        : template === 'minimal'
            ? `linear-gradient(180deg, ${backFrom} 0%, ${backFrom} 94%, ${backTo} 94%, ${backTo} 100%)`
            : `linear-gradient(135deg, ${backFrom} 0%, ${backTo} 100%)`;

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
                // Text is sized in `em`; this scales it with the card.
                fontSize: '100cqw',
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

            {/* Employee photo as a security watermark. Drawn before the card's
                content so it sits behind it, matching the SVG export. */}
            {backPhoto.show && photoUrl && (
                <div className="pointer-events-none absolute" style={backPhotoBox}>
                    {backPhoto.background_color && (
                        <div
                            className="absolute inset-0"
                            style={{ backgroundColor: backPhoto.background_color, opacity: backPhoto.opacity / 100 }}
                        />
                    )}
                    <img
                        src={photoUrl}
                        alt=""
                        className="absolute inset-0 h-full w-full"
                        style={{
                            objectFit: backPhotoFit,
                            opacity: backPhoto.opacity / 100,
                            filter: `contrast(${backPhoto.contrast}%)`,
                        }}
                        crossOrigin="anonymous"
                    />
                </div>
            )}

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
                                level="Q"
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
                        {/* Return notice in both languages */}
                        {showReturnNotice && (
                            <>
                                <p className="leading-relaxed" style={{ ...footerStyle, opacity: 0.65 }}>
                                    {enDict.idCards.propertyNotice}
                                </p>
                                <p className="leading-relaxed" style={{ ...footerStyle, opacity: 0.65 }}>
                                    {amDict.idCards.propertyNotice}
                                </p>
                            </>
                        )}
                        {verificationUrl && (
                            <p className="break-all font-mono leading-tight" style={{ ...footerStyle, opacity: 0.5 }}>
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

                {/* Card number — its own movable section. */}
                {showCardNumber && (
                    <div className="min-w-0" style={layoutStyle(cardTemplate, 'card_number', 'back')}>
                        <p className="leading-tight" style={{ ...labelStyle, opacity: 0.7 }}>
                            {biLabel('cardNoLabel')}
                        </p>
                        <p className="break-words font-mono tracking-wider" style={contentStyle}>
                            {cardNumber}
                        </p>
                    </div>
                )}

                {/* Signature — its own movable section. */}
                <div className="flex min-w-0 flex-col justify-end" style={layoutStyle(cardTemplate, 'signature', 'back')}>
                    <span style={{ ...labelStyle, opacity: 0.7 }}>{biLabel('signatureLabel')}</span>
                    <span
                        className="mt-0.5 w-full"
                        style={{ borderBottom: '1px dotted rgba(15,23,42,0.3)', minHeight: 6 }}
                    />
                </div>

            {/* Emergency contact — who to call if the holder needs help. */}
            {(emergencyContactName || emergencyContactPhone) && (
                <div className="min-w-0 space-y-[2px]" style={layoutStyle(cardTemplate, 'emergency', 'back')}>
                    {/* Same stacked amharic-then-english shape as the front face. */}
                    <IdCardBilingualField
                        labelAm={amDict.idCards.emergencyContactNameLabel}
                        valueAm={emergencyContactName}
                        labelEn={enDict.idCards.emergencyContactNameLabel}
                        valueEn={emergencyContactName}
                        labelStyle={labelStyle}
                        valueStyle={contentStyle}
                    />
                    <IdCardBilingualField
                        labelAm={amDict.idCards.emergencyContactPhoneLabel}
                        valueAm={emergencyContactPhone}
                        labelEn={enDict.idCards.emergencyContactPhoneLabel}
                        valueEn={emergencyContactPhone}
                        labelStyle={labelStyle}
                        valueStyle={contentStyle}
                    />
                </div>
            )}

            {/* Issue / expiry dates now print on the front, so the card's
                validity reads without turning it over. */}
            </>

            {/* Bottom thin accent */}
            <div
                className="absolute inset-x-0 bottom-0 h-1 pointer-events-none"
                style={{ background: `linear-gradient(to right, rgba(15,23,42,0.08), rgba(15,23,42,0.03))` }}
            />
        </div>
        </div>
    );
}
