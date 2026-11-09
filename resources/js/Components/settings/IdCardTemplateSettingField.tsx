import {
    ID_CARD_TEMPLATE_VALUES,
    resolveIdCardTemplate,
    type IdCardTemplate,
} from '@/Components/IdCards/idCardTemplates';
import { useLocale } from '@/hooks/useLocale';
import { FieldError } from '@/Components/ui';

type Props = {
    label: string;
    description?: string | null;
    value: string;
    error?: string;
    disabled?: boolean;
    onChange: (value: IdCardTemplate) => void;
};

export default function IdCardTemplateSettingField({
    label,
    description,
    value,
    error,
    disabled = false,
    onChange,
}: Props) {
    const { t } = useLocale();
    const selected = resolveIdCardTemplate(value);

    return (
        <div className="space-y-3 px-5 py-4">
            <div>
                <span className="text-sm font-medium text-(--app-foreground)">{label}</span>
                {description && (
                    <p className="mt-1 text-xs text-(--app-muted-foreground)">{description}</p>
                )}
            </div>

            <div className="grid gap-3 sm:grid-cols-3">
                {ID_CARD_TEMPLATE_VALUES.map((template) => {
                    const isSelected = selected === template;

                    return (
                        <button
                            key={template}
                            type="button"
                            disabled={disabled}
                            aria-pressed={isSelected}
                            onClick={() => onChange(template)}
                            className={[
                                'rounded-card border p-3 text-left transition',
                                isSelected
                                    ? 'border-primary bg-blue-50 ring-2 ring-primary/20 dark:bg-blue-950/30'
                                    : 'border-(--app-border) bg-(--app-surface) hover:border-blue-300',
                                disabled ? 'cursor-not-allowed opacity-60' : '',
                            ].join(' ')}
                        >
                            <TemplateThumbnail template={template} />
                            <span className="mt-2 block text-sm font-semibold text-(--app-foreground)">
                                {t(`settings.idCardTemplates.${template}.name`)}
                            </span>
                            <span className="mt-0.5 block text-xs leading-5 text-(--app-muted-foreground)">
                                {t(`settings.idCardTemplates.${template}.description`)}
                            </span>
                        </button>
                    );
                })}
            </div>

            <FieldError>{error}</FieldError>
        </div>
    );
}

function TemplateThumbnail({ template }: { template: IdCardTemplate }) {
    const background = template === 'modern'
        ? 'linear-gradient(110deg, #2563EB 0%, #2563EB 62%, #172554 62%, #172554 100%)'
        : template === 'minimal'
            ? 'linear-gradient(180deg, #1D4ED8 0%, #1D4ED8 88%, #F59E0B 88%, #F59E0B 100%)'
            : 'linear-gradient(135deg, #2563EB 0%, #1E3A8A 100%)';

    return (
        <span
            className={[
                'relative block aspect-[85.6/54] overflow-hidden shadow-xs',
                template === 'modern' ? 'rounded-card' : template === 'minimal' ? 'rounded-md' : 'rounded-lg',
            ].join(' ')}
            style={{ background }}
            aria-hidden="true"
        >
            <span className="absolute inset-x-0 top-0 h-3 bg-[color-mix(in_srgb,var(--app-surface)_15%,transparent)]" />
            <span
                className={[
                    'absolute left-2 top-5 block bg-[color-mix(in_srgb,var(--app-surface)_80%,transparent)]',
                    template === 'modern' ? 'h-7 w-7 rounded-full' : 'h-8 w-6 rounded-xs',
                ].join(' ')}
            />
            <span className="absolute left-11 right-3 top-6 h-1.5 rounded-sm bg-[color-mix(in_srgb,var(--app-surface)_80%,transparent)]" />
            <span className="absolute left-11 right-6 top-10 h-1 rounded-sm bg-[color-mix(in_srgb,var(--app-surface)_45%,transparent)]" />
            {template === 'modern' && <span className="absolute -right-3 -top-3 h-14 w-14 rounded-full border-8 border-white/10" />}
        </span>
    );
}
