import {
    BACK_PHOTO_DEFAULTS, CARD_SURFACE, PORTRAIT_FONT_SIZE, layoutBox, layoutStyle, printedFieldElements, roleStyle, textStyleCss,
    useCardDimensions, useIdCardTemplate,
} from '@/Components/IdCards/IdCardTemplateContext';
import IdCardBilingualField from '@/Components/IdCards/IdCardBilingualField';
import enDict from '@/i18n/en';
import amDict from '@/i18n/am';
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
};

export default function IdCardPortraitBack({
    qrValue, photoUrl, rootStyle, emergencyContactName, emergencyContactPhone,
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
    // Emergency contacts print when the template lists them, each in its own
    // box (IdCardSvgRenderer::renderPortrait draws the same).
    const valueStyle = textStyleCss(roleStyle(cardTemplate, 'back', 'value'), CARD_SURFACE.ink);
    const labelPx = Number.parseFloat(roleStyle(cardTemplate, 'back', 'label')?.font_size ?? '') || 7;
    const valuePx = Number.parseFloat(roleStyle(cardTemplate, 'back', 'value')?.font_size ?? '') || 8;
    const values: Record<string, string | null | undefined> = {
        emergency_contact_name: emergencyContactName,
        emergency_contact_phone: emergencyContactPhone,
    };
    const captions = {
        emergency_contact_name: 'emergencyContactNameLabel',
        emergency_contact_phone: 'emergencyContactPhoneLabel',
    } as const;
    const contacts = printedFieldElements(cardTemplate?.employee_fields, 'back', 'portrait')
        .filter(({ key }) => Boolean(values[key]));

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

                {contacts.map(({ key, element }) => (
                    <div key={key} data-card-field={key} className="min-w-0" style={layoutStyle(cardTemplate, element as never, 'back')}>
                        <IdCardBilingualField
                            labelAm={amDict.idCards[captions[key as keyof typeof captions]]}
                            valueAm={values[key]}
                            labelEn={enDict.idCards[captions[key as keyof typeof captions]]}
                            valueEn={values[key]}
                            labelStyle={labelStyle}
                            valueStyle={valueStyle}
                            fit={{ widthPct: layoutBox(cardTemplate, element as never, 'back').w, labelPx, valuePx, canvasWidth: 540 }}
                        />
                    </div>
                ))}
            </div>
        </div>
    );
}
