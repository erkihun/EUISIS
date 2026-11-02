import type { CSSProperties } from 'react';
import {
    BACK_FIELD_KEYS, layoutBox, layoutStyle, printedFieldElements, roleStyle, useIdCardTemplate,
    type AnyLayoutElement, type LayoutSide,
} from '@/Components/IdCards/IdCardTemplateContext';
import IdCardBilingualField, { buildCardFields, type FieldSource } from '@/Components/IdCards/IdCardBilingualField';

type Props = {
    side: LayoutSide;
    orientation: 'landscape' | 'portrait';
    /** Every field value the card has; this face shows the ones placed on it. */
    source: FieldSource;
    labelStyle: CSSProperties;
    valueStyle: CSSProperties;
};

/**
 * The printed fields on one face, each in its own `field_<key>` box. A field
 * prints on the face the template put it (layout_config.faces), so the same
 * component draws the front's identity rows or an emergency contact moved
 * there, and the reverse on the back. IdCardSvgRenderer::rowsOn mirrors it.
 *
 * A portrait card's name and ID number are its own badge elements, drawn by
 * the portrait front, so they are left out here.
 */
export default function IdCardFaceFields({ side, orientation, source, labelStyle, valueStyle }: Props) {
    const cardTemplate = useIdCardTemplate(orientation);
    const rows = new Map(buildCardFields(source).map((row) => [row.key, row]));
    // Sizes in the template's px, defaulting as IdCardTextStyle::ROLES does.
    const labelPx = Number.parseFloat(roleStyle(cardTemplate, side, 'label')?.font_size ?? '') || 7;
    const valuePx = Number.parseFloat(roleStyle(cardTemplate, side, 'value')?.font_size ?? '') || (side === 'back' ? 8 : 10);
    // Landscape cards are drawn on an 856-unit canvas, portrait on 540.
    const canvasWidth = orientation === 'portrait' ? 540 : 856;

    return (
        <>
            {printedFieldElements(cardTemplate?.employee_fields, side, orientation, cardTemplate?.layout_config?.faces)
                .filter(({ element }) => element.startsWith('field_'))
                .map(({ key, element }) => {
                    const row = rows.get(key);
                    // An emergency contact with no value prints nothing, as on the server.
                    if (!row || ((BACK_FIELD_KEYS as readonly string[]).includes(key) && !row.valueAm)) return null;
                    const { key: fieldKey, ...field } = row;
                    return (
                        <div key={key} data-card-field={key} className="min-w-0" style={layoutStyle(cardTemplate, element as AnyLayoutElement, side)}>
                            <IdCardBilingualField
                                {...field}
                                fieldKey={fieldKey}
                                labelStyle={labelStyle}
                                valueStyle={valueStyle}
                                fit={{ widthPct: layoutBox(cardTemplate, element as AnyLayoutElement, side).w, labelPx, valuePx, canvasWidth }}
                            />
                        </div>
                    );
                })}
        </>
    );
}
