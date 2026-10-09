export const ID_CARD_TEMPLATE_VALUES = ['classic', 'modern', 'minimal'] as const;

export type IdCardTemplate = (typeof ID_CARD_TEMPLATE_VALUES)[number];

export function resolveIdCardTemplate(value: unknown): IdCardTemplate {
    return ID_CARD_TEMPLATE_VALUES.includes(value as IdCardTemplate)
        ? (value as IdCardTemplate)
        : 'classic';
}
