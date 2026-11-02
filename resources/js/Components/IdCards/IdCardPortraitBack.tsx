import {
    BACK_PHOTO_DEFAULTS, CARD_SURFACE, PORTRAIT_FONT_SIZE, layoutStyle, roleStyle, textStyleCss,
    useCardDimensions, useIdCardTemplate,
} from '@/Components/IdCards/IdCardTemplateContext';
import IdCardFaceFields from '@/Components/IdCards/IdCardFaceFields';
import type { FieldSource } from '@/Components/IdCards/IdCardBilingualField';
import { resolveIdCardTemplate } from '@/Components/IdCards/idCardTemplates';
import { useLocale } from '@/hooks/useLocale';
import { useSystemSettings } from '@/hooks/useSystemSettings';
import { QRCodeSVG } from 'qrcode.react';
import type { CSSProperties } from 'react';

type Props = {
    cardNumber: string;
    qrValue?: string | null;
    emergencyContactName?: string | null;
    emergencyContactPhone?: string | null;
    photoUrl?: string | null;
    rootStyle?: CSSProperties;
} & Omit<FieldSource, 'cardNumber' | 'emergencyContactName' | 'emergencyContactPhone'>;

export default function IdCardPortraitBack({
    cardNumber, qrValue, photoUrl, rootStyle, emergencyContactName, emergencyContactPhone, ...fields
}: Props) {
    const { t } = useLocale();
    const { getString, getBoolean } = useSystemSettings();
    const cardTemplate = useIdCardTemplate('portrait');
    const dimensions = useCardDimensions('portrait');
    const template = resolveIdCardTemplate(getString('id_cards.template', 'classic'));
    const backgroundUrl = cardTemplate?.back_background_url;
    const labelStyle = textStyleCss(roleStyle(cardTemplate, 'back', 'label'), CARD_SURFACE.inkMuted);
    const showQr = getBoolean('id_cards.show_qr', true);
    const sealUrl = cardTemplate?.seal_url ?? getString('general.seal_url', '');
    const photo = cardTemplate?.back_photo_config ?? BACK_PHOTO_DEFAULTS;
    const background = `linear-gradient(160deg, ${CARD_SURFACE.from} 0%, ${CARD_SURFACE.to} 100%)`;
    const valueStyle = textStyleCss(roleStyle(cardTemplate, 'back', 'value'), CARD_SURFACE.ink);

    return (
        <div style={{ containerType: 'inline-size', width: rootStyle?.width ?? '100%', maxWidth: rootStyle?.maxWidth ?? 260, height: rootStyle?.height }}>
            <div
                data-card-template={template}
                className="relative overflow-hidden rounded-panel shadow-xl"
                style={{ aspectRatio: `${dimensions.widthMm} / ${dimensions.heightMm}`, width: '100%', maxWidth: 260, background, isolation: 'isolate', fontSize: PORTRAIT_FONT_SIZE, ...rootStyle }}
            >
                {backgroundUrl && <img data-card-background="back" src={backgroundUrl} alt="" className="pointer-events-none absolute inset-0 h-full w-full object-cover" style={{ zIndex: -1 }} />}
                {!backgroundUrl && <div className="pointer-events-none absolute inset-0" style={{ backgroundImage: 'radial-gradient(circle, rgba(15,23,42,0.035) 1px, transparent 1px)', backgroundSize: '10px 10px' }} />}

                {photo.show && photoUrl && <div className="overflow-hidden" style={{ ...layoutStyle(cardTemplate, 'photo', 'back'), opacity: photo.opacity / 100, backgroundColor: photo.background_color ?? undefined }}>
                    <img src={photoUrl} alt="" className="h-full w-full" style={{ objectFit: photo.fit === 'stretch' ? 'fill' : photo.fit, filter: `contrast(${photo.contrast}%)` }} crossOrigin="anonymous" />
                </div>}

                {showQr && <div className="flex flex-col items-center justify-center gap-1 overflow-hidden" style={layoutStyle(cardTemplate, 'qr', 'back')}>
                    <p className="w-full truncate text-center font-semibold" style={labelStyle}>{t('employees.feedbackSuggestionQr')}</p>
                    {qrValue
                        ? <div className="aspect-square max-h-[82%] max-w-[82%] bg-white p-1 shadow-sm"><QRCodeSVG value={qrValue} size={220} level="Q" marginSize={2} bgColor="#FFFFFF" fgColor="#0F172A" className="h-full w-full" /></div>
                        : <div className="flex aspect-square max-h-[82%] max-w-[82%] items-center justify-center border-2 border-dashed border-slate-300 bg-slate-100 text-center" style={labelStyle}>{t('idCards.qrOnPrint')}</div>}
                </div>}

                <div className="flex items-center justify-center overflow-hidden" style={layoutStyle(cardTemplate, 'seal', 'back')}>
                    {sealUrl && <img src={sealUrl} alt="" className="h-full w-full object-contain drop-shadow-md" />}
                </div>

                {/* The fields placed on the back, each in its own box (IdCardSvgRenderer::renderPortrait). */}
                <IdCardFaceFields
                    side="back"
                    orientation="portrait"
                    source={{ ...fields, cardNumber, emergencyContactName, emergencyContactPhone }}
                    labelStyle={labelStyle}
                    valueStyle={valueStyle}
                />
            </div>
        </div>
    );
}
