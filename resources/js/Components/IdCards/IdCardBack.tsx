import type { CSSProperties } from 'react';
import { QRCodeSVG } from 'qrcode.react';
import { useLocale } from '@/hooks/useLocale';
import { useSystemSettings } from '@/hooks/useSystemSettings';
import enDict from '@/i18n/en';
import amDict from '@/i18n/am';
import { resolveIdCardTemplate } from '@/Components/IdCards/idCardTemplates';

// The card face is bilingual by design — labels come from both dictionaries.
const biLabel = (key: 'issueDate' | 'expLabel' | 'signatureLabel'): string =>
    `${enDict.idCards[key]}/${amDict.idCards[key]}`;

type IdCardBackProps = {
    cardNumber: string;
    /** Verification URL or payload to encode in the QR. No PII — UUID ref only. */
    qrValue?: string | null;
    /** Card issue date (already formatted for display). */
    issueDate?: string | null;
    /** Card expiry date (already formatted for display). */
    expiryDate?: string | null;
    /** Extra styles merged onto the root div — use to force explicit height for html-to-image export */
    rootStyle?: CSSProperties;
};

export default function IdCardBack({ cardNumber, qrValue, issueDate, expiryDate, rootStyle }: IdCardBackProps) {
    const { t, locale } = useLocale();
    const { getString, getBoolean } = useSystemSettings();

    const template      = resolveIdCardTemplate(getString('id_cards.template', 'classic'));
    const backFrom      = getString('id_cards.back_bg_from', '#1E293B');
    const backTo        = getString('id_cards.back_bg_to', '#0F172A');
    const textColor     = getString('id_cards.back_text_color', '#94A3B8');
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
                aspectRatio: '85.6/54',
                width: '100%',
                maxWidth: 400,
                background,
                ...rootStyle,
            }}
        >
            {/* Security dot pattern */}
            {template !== 'minimal' && <div
                className="absolute inset-0 pointer-events-none"
                style={{
                    backgroundImage: template === 'modern'
                        ? 'repeating-linear-gradient(135deg, rgba(255,255,255,0.04) 0 1px, transparent 1px 12px)'
                        : 'radial-gradient(circle, rgba(255,255,255,0.04) 1px, transparent 1px)',
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
            <div className={`absolute inset-x-0 bottom-0 flex flex-col ${padCls} pt-0 pb-2`} style={{ top: showMagStripe ? '2.5rem' : '0.75rem' }}>
            <div className="flex min-h-0 flex-1 gap-3">

                {/* QR code block — maximised, centered vertically */}
                {showQr && <div className="flex shrink-0 flex-col items-center justify-center gap-1">
                    <span className="text-[7px] font-semibold uppercase tracking-widest text-center" style={{ color: textColor }}>
                        {enDict.idCards.scanToVerify}
                    </span>
                    <span className="text-[7px] text-center mb-0.5" style={{ color: textColor, opacity: 0.8 }}>
                        {amDict.idCards.scanToVerify}
                    </span>
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
                            className="flex items-center justify-center rounded-md border-2 border-dashed border-white/20 bg-white/5"
                            style={{ width: qrSize + 8, height: qrSize + 8 }}
                        >
                            <span className="text-center text-[7px] leading-tight text-white/40 px-1">
                                {t('idCards.qrOnPrint')}
                            </span>
                        </div>
                    )}
                    <span className="text-[6px] text-center leading-tight mt-0.5 max-w-[80px]" style={{ color: textColor, opacity: 0.55 }}>
                        {t('idCards.qrNoPersonalInfo')}
                    </span>
                </div>}

                {/* Text column */}
                <div className="flex min-w-0 flex-1 flex-col justify-between self-stretch">
                    <div className="space-y-1">
                        <p className="text-[9px] font-bold uppercase tracking-wider" style={{ color: textColor }}>
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

                    <div className="space-y-0.5 mt-auto">
                        {sealUrl && (
                            <div className="flex min-h-0 flex-1 items-center justify-center py-1">
                                <img
                                    src={sealUrl}
                                    alt=""
                                    className="h-24 w-24 max-h-full max-w-full object-contain drop-shadow-md"
                                />
                            </div>
                        )}
                        {/* Signature line */}
                        <div className="mb-1 flex items-end gap-1.5">
                            <span className="shrink-0 text-[7px]" style={{ color: textColor, opacity: 0.7 }}>
                                {biLabel('signatureLabel')}
                            </span>
                            <span
                                className="mb-0.5 flex-1"
                                style={{ borderBottom: '1px dotted rgba(255,255,255,0.3)', minHeight: 10 }}
                            />
                        </div>
                        {showCardNumber && <p className="font-mono text-[8px] font-semibold tracking-wider" style={{ color: textColor }}>
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
                </div>
            </div>

            {/* Bottom row — issue / expiry dates with bilingual labels */}
            {(showIssueDate || showExpiryDate) && (
                <div className="mt-1 flex gap-3 border-t pt-1" style={{ borderColor: 'rgba(255,255,255,0.1)' }}>
                    {showIssueDate && (
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-[7px] leading-tight" style={{ color: textColor, opacity: 0.7 }}>
                                {biLabel('issueDate')}
                            </p>
                            <p
                                className="font-mono text-[8px] font-semibold leading-tight"
                                style={{ color: textColor, borderBottom: '1px dotted rgba(255,255,255,0.25)' }}
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
                                style={{ color: textColor, borderBottom: '1px dotted rgba(255,255,255,0.25)' }}
                            >
                                {expiryDate ?? ' '}
                            </p>
                        </div>
                    )}
                </div>
            )}
            </div>

            {/* Bottom thin accent */}
            <div
                className="absolute inset-x-0 bottom-0 h-1 pointer-events-none"
                style={{ background: `linear-gradient(to right, rgba(255,255,255,0.08), rgba(255,255,255,0.03))` }}
            />
        </div>
    );
}
